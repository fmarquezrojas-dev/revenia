# API de cobranza v1

Todas las rutas internas requieren sesion activa, empresa activa y aislamiento por company_id. Recursos ajenos: 404. CSRF para POST. No admiten campos de importe ni datos de tarjeta.

| Metodo y ruta | Permiso | Respuesta |
| --- | --- | --- |
| GET /api/v1/documents/{documentId}/collections | owner, issuer, accountant | 200 data con saldo, capacidades, ordenes y pagos; private, no-store |
| POST /api/v1/documents/{documentId}/payment-link | owner, issuer | 202 data.id y data.status de intencion local |
| POST /api/v1/documents/{documentId}/payments/reconcile | owner | 202 data.status=pending y data.orders |
| POST /api/v1/webhooks/mercadopago?data.id={paymentId} | HMAC del proveedor, sin sesion | 200 received=true despues de persistir |

POST internos: cuerpo {} y cabecera Idempotency-Key (16-100 caracteres alfanumericos, guion o guion bajo). La clave se conserva al repetir tras timeout. 409 para conflicto, sin saldo, estado incompatible, revision, reserva o creacion incierta. 422 payload/clave/moneda; 429 conciliacion reciente; 503 configuracion incompleta; 401 sesion inactiva; 403 permiso insuficiente.

El resumen contiene original_cents, credits_cents, reserved_cents, net_cents, paid_cents, outstanding_cents, excess_cents, currency, status (unpaid/partial/paid/review), needs_review, credit_notes_supported, enabled, blocked_reason, can_create, can_reconcile, pending, orders y payments. Los importes son centimos enteros; paid_cents y excess_cents pueden ser cadenas de digitos para agregados mayores que 9007199254740991. El frontend usa BigInt para presentarlos. No se admiten operaciones monetarias float.

Las ordenes exponen id, amount_cents, currency, status, checkout_url, expires_at, error_code, created_at, last_reconciled_at. checkout_url solo aparece para una orden abierta vigente, saldo equivalente, sin reserva/revision y host/ruta validados. Los estados internos son pending/creating/open/unknown/closed/expired/stale/failed.

Los pagos exponen id, order_id, provider_id, amount_cents, applied_cents, refunded_cents, currency, status original, needs_review, review_code, provider_updated_at y created_at. No se exponen tokens, payload completo del proveedor ni datos del pagador. El estado de la orden no se utiliza como prueba de pago.

Webhook: JSON de evento payment.created/payment.updated, live_mode=false, id de notificacion y data.id coincidente con el identificador firmado. Cabeceras x-request-id y x-signature=ts=<timestamp>,v1=<hex>. Manifest exacto: id:<data.id>;request-id:<x-request-id>;ts:<ts>;. HMAC SHA-256 con MP_WEBHOOK_SECRET; sin ventana arbitraria de antiguedad. 400 cuerpo/identificador ambiguo, 401 firma, 413 tamano, 415 tipo, 422 evento/modo, 503 deshabilitado. Una firma valida no basta para aplicar: el worker consulta y valida el pago autoritativo. La empresa procede de la orden local, nunca de un campo libre del webhook.

El retorno /documents/{id}?payment_return=1 no confirma pagos y no utiliza status, collection_status ni payment_id del navegador para acreditar fondos.
