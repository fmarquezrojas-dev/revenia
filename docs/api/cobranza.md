# API de cobranza v1

Todas las rutas internas requieren sesion activa, empresa activa y aislamiento por company_id. Recursos ajenos: 404. CSRF para POST. No admiten campos de importe ni datos de tarjeta.

| Metodo y ruta | Permiso | Respuesta |
| --- | --- | --- |
| GET /api/v1/merchant-connection | owner | Estado, entorno, disponibilidad del transporte, receptor, fecha y URL pública |
| PUT /api/v1/merchant-connection | owner, CSRF | Recibe access_token y webhook_secret, verifica y vincula. 422 para credencial incompatible, 503 para indisponibilidad |
| DELETE /api/v1/merchant-connection | owner, CSRF | Desactiva nuevos enlaces y conserva la identidad de las órdenes |
| POST /api/v1/webhooks/mercadopago/companies/{merchantCompany}?data.id={paymentId} | Firma de la aplicación del vendedor | 200 al registrar el evento del comercio |
| GET /api/v1/documents/{documentId}/collections | owner, issuer, accountant | 200 data con saldo, capacidades, ordenes y pagos; private, no-store |
| POST /api/v1/documents/{documentId}/payment-link | owner, issuer | 202 data.id y data.status de intencion local |
| POST /api/v1/documents/{documentId}/payments/reconcile | owner | 202 data.status=pending y data.orders |
| POST /api/v1/webhooks/mercadopago?data.id={paymentId} | HMAC del proveedor, sin sesion | 200 received=true despues de persistir |

Las acciones sobre documentos aceptan cuerpo {}. Para conciliar una orden específica, envía únicamente `order_id` con su UUID. Las acciones sobre documentos usan sesión y cabecera Idempotency-Key (16-100 caracteres alfanumericos, guion o guion bajo). La clave se conserva al repetir tras timeout. 409 para conflicto, sin saldo, estado incompatible, revision, reserva o creacion incierta. 422 payload/clave/moneda; 429 conciliacion reciente; 503 configuracion incompleta; 401 sesion inactiva; 403 permiso insuficiente.

El resumen contiene original_cents, credits_cents, reserved_cents, net_cents, paid_cents, outstanding_cents, excess_cents, currency, status (unpaid/partial/paid/review), needs_review, credit_notes_supported, enabled, blocked_reason, can_create, can_reconcile, pending, orders y payments. Los importes son centimos enteros; paid_cents y excess_cents pueden ser cadenas de digitos para agregados mayores que 9007199254740991. El frontend usa BigInt para presentarlos. No se admiten operaciones monetarias float.

Las notas aceptadas reducen el neto del comprobante de origen. La reserva de una línea impide publicar un checkout por un importe que pueda cambiar. Los pagos confirmados se aplican por el importe validado de la orden original. El exceso activa la revisión del documento.

El resumen también incluye empresa emisora, entorno e incidencias por orden. La cartera admite `operations=pending|review` para seguir comprobantes con operaciones abiertas aunque su saldo sea cero. Cada orden incluye `receiver_kind`, `collector_id`, `connection_id` y `environment`. El tipo `legacy` identifica el historial sin receptor comprobable.

Las ordenes exponen id, provider, amount_cents, currency, status, checkout_url, expires_at, error_code, created_at, last_reconciled_at. checkout_url solo aparece para una orden abierta vigente, saldo equivalente, sin reserva/revision y host/ruta validados. Los estados internos son pending/creating/open/unknown/closed/expired/stale/failed.

Los pagos exponen id, order_id, provider_id, amount_cents, applied_cents, refunded_cents, currency, status original, needs_review, review_code, provider_updated_at y created_at. No se exponen tokens, payload completo del proveedor ni datos del pagador. El estado de la orden no se utiliza como prueba de pago.

Webhook: JSON de evento payment.created/payment.updated, live_mode=false, id de notificacion y data.id coincidente con el identificador firmado. Cabeceras x-request-id y x-signature=ts=<timestamp>,v1=<hex>. Manifest exacto: id:<data.id>;request-id:<x-request-id>;ts:<ts>;. HMAC SHA-256 con la clave cifrada del comercio para comprobantes o MP_WEBHOOK_SECRET para suscripciones; sin ventana arbitraria de antiguedad. 400 cuerpo/identificador ambiguo, 401 firma, 413 tamano, 415 tipo, 422 evento/modo, 503 deshabilitado. Una firma valida no basta para aplicar: el worker consulta y valida el pago autoritativo. La empresa procede de la orden local, nunca de un campo libre del webhook.

El retorno /documents/{id}?payment_return=1 no confirma pagos y no utiliza status, collection_status ni payment_id del navegador para acreditar fondos.
