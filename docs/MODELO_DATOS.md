# Modelo de datos: extension de borradores

Migracion: 2026_11_11_081808_create_documents_and_items_tables.php.

- documents: UUID, company_id, customer_id nullable, series_id, created_by,
  doc_type01/03, currencyPEN/USD, status, issue_date, number nullable,
  issued_at nullable, cinco totales BIGINT, version INTEGER, timestamps TZ.
- document_items: UUID, company_id, document_id, product_id, line_number,
  description, unit_code, tax_type, unit_price_cents, quantity_millis,
  base_cents, igv_cents, total_cents. No timestamps propios.
- UNIQUE company_id/id en customers/products/document_series/users/documents.
- FKs compuestas desde documents a customer/series/created_by y desde items
  a document/product. Relaciones ajenas son rechazadas en SQL.
- UNIQUE document_id/line_number y document_id/product_id diferidos a commit.
- CHECKs: enums, version positiva, cantidades y line_number1..50, importes seguros
  JavaScript, totales consistentes, draft sin numero/issued_at.
- Listado indexado por empresa/fecha/UUID; filtro empresa/tipo/estado/cliente.

El borrado de documents tiene cascada para integridad de items, pero el servicio
comprueba permiso, estado draft y version bajo bloqueo antes de borrar. No se
expone borrado de documentos emitidos. Minimo de una linea y compatibilidad de
actividad/tipo/moneda se validan en el servicio transaccional.
Los datos fiscales de empresa/cliente no son snapshots definitivos hasta emision.


## Emision simulada (2026-11-12)

Documents incorpora snapshot fiscal, identidad estable de envio, intento/lease, resultado y siguiente reintento. Emission_requests persiste idempotencia por empresa/operacion/clave. Emission_outbox guarda publicaciones reclamables por generacion; emission_attempts y document_events registran procesamiento. Simulated_submissions conserva deduplicacion del proveedor; simulation_scenarios solo controla demos locales habilitadas. Las primeras cinco tablas referencian documentos mediante FK compuesta company_id/document_id. Ver [flujo y restricciones](EMISION_SIMULADA.md).


## PDF privado y correo

Document_pdfs mantiene estado de trabajo, version, intentos, lease y referencia actual. Document_pdf_files conserva versiones inmutables con disco/ruta internos, hash, tamano y plantilla. Document_email_deliveries fija destinatario y file_id por solicitud, estados y Message-ID. Document_delivery_requests implementa idempotencia y document_delivery_attempts registra intentos/revisiones separados de document_events. La outbox existente discrimina emission/pdf/email por kind y target_id. [Detalles y recuperacion](PDF_Y_CORREO.md).
