# API: planes y consumo

Sesion Sanctum, empresa desde usuario, account.active y policies. POST con CSRF. No se acepta company_id, precio, cuota, estado ni fechas desde el navegador. Importes enteros en centimos.

| Metodo/ruta | Acceso | Contrato |
| --- | --- | --- |
| GET /api/v1/plans | owner | data[]: code,name,description,price_cents,currency,emission_limit; solo disponibles |
| GET /api/v1/usage | owner/issuer/accountant | data: estado operativo, periodo, fechas, nombre, limite, consumed, remaining, can_issue, expiring_soon, timezone, rule |
| GET /api/v1/subscription | owner | data: usage, history (ultimas 50), enabled, pending |
| GET /api/v1/subscriptions/history | owner | mismo resumen e historial acotado |
| GET /api/v1/subscriptions/{uuid} | owner | contratacion propia o 404 |
| POST /api/v1/subscriptions | owner | {plan_code}; Idempotency-Key; 202 data.id,status,order_id |
| POST /api/v1/subscriptions/{uuid}/cancel | owner | {}; Idempotency-Key; 200 data.id,status=cancelled |

Claves de idempotencia: 16..100 alfanumericos/guion/guion bajo; persistidas por empresa y operacion en payment_requests. Misma clave con otro plan/contrato devuelve 409 idempotency_conflict. Misma intencion pendiente y plan reutiliza orden; otro plan devuelve 409 subscription_pending. Contratos de otra empresa: 404.

usage.status: active, absent, expired, exhausted o review. remaining nunca negativo. Umbral expiring_soon: tres dias. Sin vigencia, los contadores del resumen son cero y el historial conserva cada consumo anterior. La consulta operativa no revela precios, enlaces ni pagos de suscripcion a issuer/accountant.

Subscriptions: terminos capturados, source (checkout/demo), status (pending/paid/review/cancelled), phase derivada por servidor (pending/active/future/expired/review/cancelled/verification_error), fechas y ancla, consumed, review_code, order con estado/enlace seguro y payments minimos. paid con source=demo es una concesion local sin pago. Un enlace solo se expone si sandbox esta configurado, la orden sigue abierta vigente y no hay revision.

Errores de emision: 422 subscription_missing, subscription_expired, subscription_review o quota_exhausted. Mensaje legible en message. Estas comprobaciones ocurren dentro de RequestIssuance, no solo en UI. No consumen correlativo si fallan. Reintentos/idempotencia de emisiones previamente consumidas conservan la operacion original.

Errores de contratacion: 422 plan_unavailable/invalid_payload/invalid_idempotency_key; 409 subscription_pending/renewal_scheduled/subscription_review/idempotency_conflict/subscription_conflict/checkout_uncertain; 503 payments_not_configured. 401 cuenta/empresa inactiva o sesion ausente; 403 permiso insuficiente.

Webhook y recuperacion reutilizan /api/v1/webhooks/mercadopago y payments:reconcile. Se agrega subscription_id a la persistencia financiera; exactamente un destino por orden/pago. El retorno /subscription?payment_return=1 nunca confirma pago ni activa derechos.
