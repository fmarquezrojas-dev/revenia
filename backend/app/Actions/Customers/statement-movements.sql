WITH sales AS (
    SELECT id, company_id, currency, issued_at, total_cents,
           series_code || '-' || lpad(number::text, 8, '0') AS number
    FROM documents
    WHERE company_id = ? AND customer_id = ? AND currency = ?
      AND status = 'accepted' AND doc_type IN ('01','03')
      AND (?::uuid IS NULL OR id = ?::uuid)
), notes AS (
    SELECT n.id, s.id AS document_id, n.company_id, n.issued_at,
           n.series_code || '-' || lpad(n.number::text, 8, '0') AS number,
           sum(i.total_cents) AS amount_cents
    FROM sales s
    JOIN documents n ON (n.company_id,n.source_document_id,n.currency)=(s.company_id,s.id,s.currency)
    JOIN credit_note_lines c ON (c.company_id,c.note_id,c.source_document_id)=(s.company_id,n.id,s.id)
    JOIN document_items i ON (i.company_id,i.document_id,i.id)=(s.company_id,s.id,c.source_item_id)
    WHERE n.doc_type='07' AND n.issued_at IS NOT NULL
    GROUP BY n.id,s.id
), movements (document_id,kind,source_id,receipt_id,occurred_at,reference,cents,order_state,sandbox) AS (
    SELECT id,'sale',id,NULL::uuid,issued_at,number,total_cents,NULL::text,false FROM sales
    UNION ALL
    SELECT document_id,'reserve',id,NULL,issued_at,number,amount_cents,NULL,false FROM notes
    UNION ALL
    SELECT n.document_id,'credit',n.id,NULL,min(e.created_at),n.number,n.amount_cents,NULL,false
    FROM notes n JOIN document_events e ON e.document_id=n.id AND e.company_id=n.company_id
    WHERE e.to_status='accepted'
    GROUP BY n.document_id,n.id,n.number,n.amount_cents
    UNION ALL
    SELECT s.id,'order',o.id,NULL,o.created_at,o.id::text,o.amount_cents,
           CASE WHEN o.status='open' AND o.expires_at <= now() THEN 'expired' ELSE o.status END, o.environment='sandbox'
    FROM sales s JOIN payment_orders o ON (o.company_id,o.document_id,o.currency)=(s.company_id,s.id,s.currency)
    WHERE o.subscription_id IS NULL AND o.provider='mercadopago'
    UNION ALL
    SELECT s.id,CASE WHEN p.provider='mercadopago' THEN 'mp_payment' ELSE 'provider_payment' END,
           p.id,NULL,p.confirmed_at,p.provider_id,p.applied_cents,NULL,o.environment='sandbox'
    FROM sales s JOIN payments p ON (p.company_id,p.document_id,p.currency)=(s.company_id,s.id,s.currency)
    JOIN payment_orders o ON (o.company_id,o.document_id,o.id)=(p.company_id,p.document_id,p.order_id)
    WHERE p.subscription_id IS NULL AND p.applied_cents > 0 AND p.confirmed_at IS NOT NULL
    /* bank applications */
)
SELECT s.id AS document_id,s.number,m.kind,m.source_id,m.receipt_id,m.occurred_at,m.reference,
       m.cents::text AS amount_cents,m.order_state,coalesce(m.sandbox,false) AS sandbox
FROM movements m JOIN sales s ON s.id=m.document_id
