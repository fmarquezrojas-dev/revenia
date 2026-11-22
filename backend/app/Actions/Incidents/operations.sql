WITH tenant AS (SELECT CAST(? AS uuid) AS id),
outbox AS NOT MATERIALIZED (
    SELECT * FROM emission_outbox WHERE company_id = (SELECT id FROM tenant)
),
-- Aggregate the tenant's failed transitions once when the dashboard scans its documents.
emission_history AS MATERIALIZED (
    SELECT document_id, min(created_at) AS detected FROM document_events
    WHERE company_id = (SELECT id FROM tenant) AND to_status IN ('failed','rejected')
    GROUP BY document_id
),
delivery_history AS (
    SELECT document_id, CASE WHEN kind = 'pdf' THEN 'pdf' ELSE 'email' END AS process,
        min(coalesce(finished_at,created_at)) AS detected
    FROM document_delivery_attempts
    WHERE company_id = (SELECT id FROM tenant) AND status IN ('failed','unknown')
    GROUP BY 1,2
),
latest_email AS (
    SELECT DISTINCT ON (document_id) * FROM document_email_deliveries
    WHERE company_id = (SELECT id FROM tenant) ORDER BY document_id, sequence DESC
),
local_operations AS (
    SELECT 'emission' AS process, d.id AS operation_id, d.id AS document_id, d.submission_id AS source_id,
        d.status AS source_status, d.result_code AS reason, d.failure_kind = 'unknown' AS uncertain,
        d.lease_until, d.next_attempt_at, d.version AS revision, d.can_retry AND d.attempt_count < 5 AS recoverable,
        h.detected, d.updated_at AS changed,
        CASE WHEN d.status='sending' THEN d.version-1 ELSE d.version END AS generation
    FROM documents d LEFT JOIN emission_history h ON h.document_id=d.id
    WHERE d.company_id=(SELECT id FROM tenant) AND d.submission_id IS NOT NULL
    UNION ALL
    SELECT 'pdf', p.id, p.document_id, p.id, p.status, p.error_code, false,
        p.lease_until, p.next_attempt_at, p.version, p.attempts < 5,
        h.detected, p.updated_at, p.version
    FROM document_pdfs p LEFT JOIN delivery_history h ON h.document_id=p.document_id AND h.process='pdf'
    WHERE p.company_id=(SELECT id FROM tenant)
    UNION ALL
    SELECT 'email', e.document_id, e.document_id, e.id, e.status, e.error_code, e.status='unknown',
        e.lease_until, NULL, e.sequence, true, h.detected, e.updated_at, e.sequence
    FROM latest_email e LEFT JOIN delivery_history h ON h.document_id=e.document_id AND h.process='email'
),
local_history AS (
    SELECT b.document_id,b.kind,min(e.occurred_at) AS detected,max(e.occurred_at) AS changed
    FROM outbox b JOIN incident_queue_events e ON e.publication_id=b.id GROUP BY b.document_id,b.kind
),
local_states AS (
    SELECT l.*, b.last_error, b.available_at, b.published_at, q.changed AS last_error_at, q.detected AS queue_detected,
        CASE
            WHEN source_status IN ('accepted','ready','sent') THEN 'resolved'
            WHEN uncertain THEN 'confirmation'
            WHEN source_status IN ('failed','rejected') THEN
                CASE WHEN next_attempt_at IS NOT NULL THEN 'retry'
                    WHEN source_status='failed' AND recoverable AND process IN ('emission','pdf')
                        AND reason IS DISTINCT FROM 'snapshot_incomplete' THEN 'manual'
                    ELSE 'failed' END
            WHEN lease_until <= now() OR (source_status IN ('pending','queued') AND b.published_at <= now()-interval '180 seconds') THEN 'delayed'
            WHEN source_status IN ('pending','queued') AND b.last_error IS NOT NULL THEN 'retry'
            ELSE 'processing' END AS state
    FROM local_operations l
    LEFT JOIN outbox b ON b.document_id=l.document_id AND b.kind=l.process AND b.generation=l.generation
    LEFT JOIN local_history q ON q.document_id=l.document_id AND q.kind=l.process
),
work AS (
    SELECT order_id,
        bool_or(status='review') AS review, bool_or(status='failed') AS failed,
        bool_or(status='processing' AND lease_until<=now()) AS delayed,
        bool_or(status='pending' AND (attempts>0 OR error_code IS NOT NULL)) AS retry,
        bool_or(status IN ('processing','pending')) AS processing,
        min(available_at) FILTER (WHERE status='pending' AND (attempts>0 OR error_code IS NOT NULL)) AS next_attempt,
        min(lease_until) FILTER (WHERE status='processing' AND lease_until<=now()) AS deadline,
        min(updated_at) FILTER (WHERE error_code IS NOT NULL OR status IN ('failed','review','resolved')) AS detected,
        (array_agg(error_code ORDER BY CASE status WHEN 'review' THEN 1 WHEN 'failed' THEN 2 ELSE 3 END, updated_at DESC,id)
            FILTER (WHERE error_code IS NOT NULL AND status IN ('pending','failed','review'))) [1] AS reason,
        max(updated_at) AS changed
    FROM payment_work WHERE company_id=(SELECT id FROM tenant) AND order_id IS NOT NULL GROUP BY order_id
),
receipts AS (
    SELECT order_id, bool_or(needs_review) AS review, bool_or(status IN ('pending','authorized','in_process')) AS awaiting,
        bool_or(applied_cents>0) AS applied,
        (array_agg(status ORDER BY provider_updated_at DESC,id) FILTER (WHERE status IN ('rejected','cancelled')))[1] AS declined,
        min(provider_updated_at) FILTER (WHERE needs_review OR status IN ('pending','authorized','in_process','cancelled','rejected')) AS detected,
        max(provider_updated_at) AS changed
    FROM payments WHERE company_id=(SELECT id FROM tenant) AND document_id IS NOT NULL AND subscription_id IS NULL GROUP BY order_id
),
audit AS (
    SELECT order_id, min(created_at) AS detected FROM payment_audit WHERE company_id=(SELECT id FROM tenant)
    AND order_id IS NOT NULL AND (event IN ('work_failed','payment_mismatch') OR
        (event='provider_status_observed' AND code IN ('pending','in_process','authorized','refunded','charged_back','in_mediation','rejected','cancelled')))
    GROUP BY order_id
),
unlinked_failures AS (
    SELECT w.connection_id,w.payment_id,min(a.created_at) AS detected
    FROM payment_audit a JOIN payment_work w ON w.id=a.work_id AND w.company_id=a.company_id
    WHERE a.company_id=(SELECT id FROM tenant) AND a.order_id IS NULL AND a.event='work_failed'
        AND w.webhook_scope='merchant' AND w.connection_id IS NOT NULL AND w.payment_id IS NOT NULL
    GROUP BY w.connection_id,w.payment_id
),
linked_history AS (
    SELECT linked.order_id,min(f.detected) AS detected
    FROM unlinked_failures f JOIN LATERAL (
        SELECT w.order_id FROM payment_work w WHERE w.company_id=(SELECT id FROM tenant)
            AND w.connection_id=f.connection_id AND w.payment_id=f.payment_id AND w.order_id IS NOT NULL
        UNION
        SELECT p.order_id FROM payments p JOIN payment_orders o ON o.company_id=p.company_id AND o.id=p.order_id
        WHERE p.company_id=(SELECT id FROM tenant) AND p.provider_id=f.payment_id AND o.connection_id=f.connection_id AND o.document_id IS NOT NULL
    ) linked ON true GROUP BY linked.order_id
),
order_states AS (
    SELECT o.*, w.next_attempt, least(a.detected,w.detected,r.detected,w.deadline,h.detected) AS detected,
        greatest(o.updated_at,w.changed,r.changed,w.deadline) AS changed,
        CASE WHEN o.receiver_kind='legacy' THEN 'receiver_unknown'
            WHEN r.review THEN 'external_adjustment_review' ELSE coalesce(w.reason,o.error_code,
                CASE WHEN NOT r.applied AND NOT r.awaiting THEN r.declined END) END AS reason,
        CASE WHEN o.receiver_kind='legacy' OR r.review OR w.review OR o.status='unknown' OR r.awaiting THEN 'confirmation'
            WHEN w.failed OR o.status='failed' THEN 'failed'
            WHEN w.delayed THEN 'delayed' WHEN w.retry THEN 'retry'
            WHEN NOT r.applied AND r.declined IS NOT NULL THEN 'failed'
            WHEN w.processing OR o.status IN ('pending','creating') THEN 'processing'
            ELSE 'resolved' END AS state
    FROM payment_orders o LEFT JOIN work w ON w.order_id=o.id LEFT JOIN receipts r ON r.order_id=o.id
    LEFT JOIN audit a ON a.order_id=o.id
    LEFT JOIN linked_history h ON h.order_id=o.id
    WHERE o.company_id=(SELECT id FROM tenant) AND o.provider='mercadopago' AND o.document_id IS NOT NULL AND o.subscription_id IS NULL
),
operations AS (
    SELECT process, operation_id, document_id, source_id, source_status, state,
        CASE WHEN state='retry' THEN coalesce(reason,last_error) ELSE reason END AS reason,
        CASE WHEN state IN ('confirmation','retry') THEN coalesce(next_attempt_at, CASE WHEN last_error IS NOT NULL THEN available_at END) END AS next_attempt_at,
        least(detected,queue_detected,CASE WHEN last_error IS NOT NULL THEN last_error_at END,
            CASE WHEN state='delayed' THEN coalesce(lease_until,published_at+interval '180 seconds') END,
            CASE WHEN source_status IN ('failed','rejected','unknown') THEN changed END) AS first_detected_at,
        greatest(changed,last_error_at,CASE WHEN state='delayed' THEN coalesce(lease_until,published_at+interval '180 seconds') END) AS updated_at,
        revision, recoverable
    FROM local_states
    UNION ALL
    SELECT 'collection', id, document_id, id, status,state,reason,next_attempt,
        least(detected,CASE WHEN receiver_kind<>'legacy' AND state IN ('failed','manual','confirmation','retry') THEN changed END),
        changed,0, receiver_kind='merchant' AND error_code IS DISTINCT FROM 'test_receiver_unverified'
            AND (last_reconciled_at IS NULL OR last_reconciled_at<=now()-interval '60 seconds')
    FROM order_states
),
unmatched AS (
    SELECT (array_agg(w.id ORDER BY w.created_at,w.id))[1] AS operation_id, w.payment_id,
        (array_agg(w.error_code ORDER BY w.updated_at DESC,w.id) FILTER (WHERE w.error_code IS NOT NULL))[1] AS reason,
        min(w.available_at) FILTER (WHERE w.status='pending' AND w.attempts>0) AS next_attempt,
        least(min(f.detected),min(w.updated_at) FILTER (WHERE w.error_code IS NOT NULL OR w.status IN ('failed','review'))) AS detected,
        min(w.lease_until) FILTER (WHERE w.status='processing' AND w.lease_until<=now()) AS deadline,
        max(w.updated_at) AS changed,
        CASE WHEN bool_or(w.status IN ('failed','review')) THEN 'confirmation'
            WHEN bool_or(w.status='processing' AND w.lease_until<=now()) THEN 'delayed'
            WHEN bool_or(w.status='pending' AND (w.attempts>0 OR w.error_code IS NOT NULL)) THEN 'retry'
            ELSE 'processing' END AS state
    FROM payment_work w JOIN merchant_connections c ON c.id=w.connection_id AND c.company_id=w.company_id AND c.collector_id=w.webhook_collector_id
    LEFT JOIN unlinked_failures f ON f.connection_id=w.connection_id AND f.payment_id=w.payment_id
    WHERE w.company_id=(SELECT id FROM tenant) AND w.webhook_scope='merchant' AND w.payment_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM payments p JOIN payment_orders o ON o.id=p.order_id AND o.company_id=p.company_id
            WHERE p.company_id=w.company_id AND p.provider_id=w.payment_id AND o.connection_id=c.id AND o.document_id IS NOT NULL)
    GROUP BY w.connection_id,w.payment_id
    HAVING bool_and(w.order_id IS NULL) AND bool_or(w.status IN ('failed','review','pending','processing'))
)
SELECT o.*, d.currency, d.series_code || '-' || lpad(d.number::text,8,'0') AS number, o.source_id::text AS reference
FROM operations o JOIN documents d ON d.company_id=(SELECT id FROM tenant) AND d.id=o.document_id
UNION ALL
SELECT 'collection',operation_id,NULL::uuid,operation_id,'unmatched',state,coalesce(reason,'order_not_found'),
    next_attempt,least(detected,deadline),greatest(changed,deadline),0,false,NULL,NULL,payment_id
FROM unmatched
UNION ALL
SELECT 'odoo',t.id,t.document_id,t.document_id,t.status,
    CASE t.status WHEN 'done' THEN 'resolved' WHEN 'review' THEN 'manual' WHEN 'uncertain' THEN 'confirmation'
        WHEN 'dependency' THEN 'confirmation' WHEN 'retry' THEN 'retry'
        WHEN 'processing' THEN CASE WHEN t.lease_until<=now() THEN 'delayed' ELSE 'processing' END ELSE 'processing' END,
    t.error_code,CASE WHEN t.status='retry' AND c.enabled THEN t.available_at END,t.first_error_at,t.updated_at,t.revision,
    coalesce(c.enabled AND c.destination=t.destination AND t.status IN ('uncertain','review'),false),
    d.currency,d.series_code || '-' || lpad(d.number::text,8,'0'),t.company_id::text || '/' || t.document_id::text
FROM odoo_transfers t JOIN documents d ON d.company_id=t.company_id AND d.id=t.document_id
LEFT JOIN odoo_connections c ON c.company_id=t.company_id
WHERE t.company_id=(SELECT id FROM tenant)
