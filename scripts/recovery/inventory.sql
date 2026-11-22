-- psql -XqAt -v ON_ERROR_STOP=1. One JSON object per line; stable ordering for comparison.
BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL search_path = pg_catalog, public;

SELECT json_build_object('kind','migration','name',migration,'batch',batch)
FROM public.migrations ORDER BY migration COLLATE "C", batch;

SELECT $$SELECT json_build_object('kind','company','id',id) FROM public.companies ORDER BY id$$
WHERE to_regclass('public.companies') IS NOT NULL
\gexec

-- A missing object is only an error once its creating migration was recorded.
WITH required(migration, tbl, col) AS (VALUES
 ('2026_11_09_185024_create_companies_table','companies','id'),
 ('2026_11_11_081808_create_documents_and_items_tables','documents','id'),
 ('2026_11_11_081808_create_documents_and_items_tables','document_items','id'),
 ('2026_11_12_211601_add_simulated_issuance','emission_outbox','id'),
 ('2026_11_12_224500_add_private_document_delivery','document_pdf_files','path'),
 ('2026_11_12_224500_add_private_document_delivery','document_pdfs','current_file_id'),
 ('2026_11_12_224500_add_private_document_delivery','document_email_deliveries','file_id'),
 ('2026_11_13_055044_add_document_collections','payments','order_id'),
 ('2026_11_13_055044_add_document_collections','payment_orders','document_id'),
 ('2026_11_13_181309_add_subscription_periods_and_usage','payments','subscription_id'),
 ('2026_11_20_000001_add_credit_notes','documents','source_document_id'),
 ('2026_11_20_000001_add_credit_notes','credit_note_lines','source_item_id'),
 ('2026_11_20_000002_add_document_payment_terms','documents','due_date'),
 ('2026_11_20_000003_bind_payment_receivers','merchant_connections','collector_id'),
 ('2026_11_20_000004_add_incident_follow_up','incident_follow_ups','operation_id'),
 ('2026_11_20_000004_add_incident_follow_up','incident_queue_events','publication_id'),
 ('2026_11_20_000005_add_odoo_transfers','odoo_transfers','document_id'),
 ('2026_11_20_000005_add_odoo_transfers','odoo_connections','destination'),
 ('2026_11_20_000005_add_odoo_transfers','odoo_mappings','destination'),
 ('2026_11_20_000005_add_odoo_transfers','odoo_events','transfer_id')
)
SELECT json_build_object('kind','check','name','schema.'||tbl||'.'||col,'bad',1)
FROM required r JOIN public.migrations m USING (migration)
WHERE NOT EXISTS (SELECT FROM information_schema.columns c WHERE c.table_schema='public' AND c.table_name=r.tbl AND c.column_name=r.col)
ORDER BY tbl COLLATE "C", col COLLATE "C";

-- Include empty tables and global rows. No business names or payment credentials in this inventory.
SELECT format('SELECT json_build_object(''kind'',''total'',''table'',%L,''rows'',count(*)) FROM public.%I', tablename,tablename)
FROM pg_tables WHERE schemaname='public' ORDER BY tablename COLLATE "C"
\gexec
SELECT format('SELECT json_build_object(''kind'',''count'',''table'',%L,''company'',company_id,''rows'',count(*)) FROM public.%I GROUP BY company_id ORDER BY company_id NULLS FIRST', table_name,table_name)
FROM information_schema.columns WHERE table_schema='public' AND column_name='company_id' ORDER BY table_name COLLATE "C"
\gexec

-- Business breakdowns in addition to the physical table counts.
SELECT $$SELECT json_build_object('kind','group','table','documents','company',company_id,'type',doc_type,'status',status,'rows',count(*))
FROM documents GROUP BY company_id,doc_type,status ORDER BY company_id,doc_type COLLATE "C",status COLLATE "C"$$
WHERE to_regclass('public.documents') IS NOT NULL
\gexec
SELECT $$SELECT json_build_object('kind','group','table','payments','company',company_id,'destination',CASE WHEN document_id IS NULL THEN 'subscription' ELSE 'document' END,'rows',count(*))
FROM payments GROUP BY company_id,(document_id IS NULL) ORDER BY company_id,(document_id IS NULL)$$
WHERE to_regclass('public.payments') IS NOT NULL
\gexec
SELECT $$SELECT json_build_object('kind','group','table','incident_follow_ups','company',company_id,'process',process,'rows',count(*))
FROM incident_follow_ups GROUP BY company_id,process ORDER BY company_id,process COLLATE "C"$$
WHERE to_regclass('public.incident_follow_ups') IS NOT NULL
\gexec

-- A restored serial/identity sequence must also resume at the same value.
SELECT format('SELECT json_build_object(''kind'',''sequence'',''name'',%L,''last'',last_value,''called'',is_called) FROM public.%I',sequencename,sequencename)
FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename COLLATE "C"
\gexec

-- Test the actual foreign keys, including composite company/document keys, in this schema.
SELECT format('SELECT json_build_object(''kind'',''check'',''name'',%L,''bad'',count(*)) FROM %s c WHERE %s AND NOT EXISTS (SELECT FROM %s p WHERE %s)',
 child.relname||'.'||f.conname, f.conrelid::regclass,
 string_agg(format('c.%I IS NOT NULL',a.attname),' AND ' ORDER BY k.n), f.confrelid::regclass,
 string_agg(format('p.%I=c.%I',b.attname,a.attname),' AND ' ORDER BY k.n))
FROM pg_constraint f JOIN pg_class child ON child.oid=f.conrelid
CROSS JOIN LATERAL unnest(f.conkey,f.confkey) WITH ORDINALITY AS k(c,p,n)
JOIN pg_attribute a ON a.attrelid=f.conrelid AND a.attnum=k.c
JOIN pg_attribute b ON b.attrelid=f.confrelid AND b.attnum=k.p
WHERE f.contype='f' AND child.relnamespace='public'::regnamespace
GROUP BY f.oid,child.relname,f.conname,f.conrelid,f.confrelid ORDER BY child.relname COLLATE "C",f.conname COLLATE "C"
\gexec

SELECT $$SELECT json_build_object('kind','check','name','credit.original','bad',count(*)) FROM documents n
WHERE n.doc_type='07' AND NOT EXISTS (SELECT FROM documents d WHERE d.company_id=n.company_id AND d.id=n.source_document_id AND d.doc_type IN ('01','03') AND d.status='accepted' AND d.currency=n.currency)$$
WHERE to_regclass('public.credit_note_lines') IS NOT NULL
\gexec
SELECT $$SELECT json_build_object('kind','check','name','payment.destination','bad',count(*)) FROM payments p WHERE
NOT EXISTS (SELECT FROM payment_orders o WHERE o.company_id=p.company_id AND o.id=p.order_id
 AND o.document_id IS NOT DISTINCT FROM p.document_id AND o.subscription_id IS NOT DISTINCT FROM p.subscription_id)
 OR num_nonnulls(p.document_id,p.subscription_id)<>1$$
WHERE EXISTS (SELECT FROM information_schema.columns WHERE table_schema='public' AND table_name='payments' AND column_name='subscription_id')
\gexec

-- Follow-ups refer to a different source table for each process (email uses the document UUID).
WITH targets(process,tbl,op,doc) AS (VALUES ('emission','documents','id','id'),('pdf','document_pdfs','id','document_id'),
 ('email','document_email_deliveries','document_id','document_id'),('collection','payment_orders','id','document_id'),('odoo','odoo_transfers','id','document_id'))
SELECT format('SELECT json_build_object(''kind'',''check'',''name'',%L,''bad'',count(*)) FROM incident_follow_ups f WHERE process=%L AND %s',
 'incident.'||process,process,CASE WHEN to_regclass('public.'||tbl) IS NULL THEN 'true' ELSE
 format('NOT EXISTS (SELECT FROM public.%I s WHERE s.company_id=f.company_id AND s.%I=f.operation_id AND s.%I=f.document_id)',tbl,op,doc) END)
FROM targets WHERE to_regclass('public.incident_follow_ups') IS NOT NULL ORDER BY process COLLATE "C"
\gexec
SELECT $$SELECT json_build_object('kind','check','name','incident.process','bad',count(*)) FROM incident_follow_ups
WHERE process NOT IN ('emission','pdf','email','collection','odoo')$$ WHERE to_regclass('public.incident_follow_ups') IS NOT NULL
\gexec
SELECT $$SELECT json_build_object('kind','check','name','pdf.ready','bad',count(*)) FROM document_pdfs
WHERE status='ready' AND current_file_id IS NULL$$ WHERE to_regclass('public.document_pdfs') IS NOT NULL
\gexec
SELECT $$SELECT json_build_object('kind','pdf','id',id,'company',company_id,'document',document_id,'attempt',attempt_id,
 'disk',disk,'path',path,'bytes',size,'sha256',sha256) FROM document_pdf_files ORDER BY id$$
WHERE to_regclass('public.document_pdf_files') IS NOT NULL
\gexec
COMMIT;

