# ADR-019: cobranza de demostracion con Checkout Pro

Fecha: 2026-11-13. Estado: implementado localmente; validacion externa pendiente.

## Decision

Checkout Pro alojado de Mercado Pago Peru, mediante Preferences API. Una cuenta receptora de pruebas por entorno; no OAuth ni incorporacion de comercios. Las referencias externas son UUID de orden local, sin datos del cliente. Cada orden pertenece a una empresa mediante claves foraneas compuestas. No representa una plataforma comercial para receptores independientes.

Se admite PEN. USD queda bloqueado sin conversion. El navegador no envia importes: el backend calcula el saldo completo bajo bloqueo del documento. Accepted sigue siendo el estado fiscal aunque el estado financiero sea paid.

## Contrato externo consultado

- [Crear preferencia](https://www.mercadopago.com.pe/developers/es/reference/online-payments/checkout-pro/preferences/create-preference/post): POST https://api.mercadopago.com/checkout/preferences.
- [Buscar preferencias](https://www.mercadopago.com.pe/developers/es/reference/online-payments/checkout-pro-preferences/search-preferences/get): GET /checkout/preferences/search por external_reference; recuperacion acotada a una coincidencia, dentro del historial disponible del proveedor.
- [Consultar preferencia](https://www.mercadopago.com.pe/developers/es/reference/online-payments/checkout-pro-preferences/get-preference/get): GET /checkout/preferences/{id}.
- [Consultar pago](https://www.mercadopago.com.pe/developers/es/reference/online-payments/checkout-api-payments/get-payment/get): GET /v1/payments/{id}.
- [Orden comercial](https://www.mercadopago.com.pe/developers/es/reference/online-payments/checkout-pro-preferences/merchant-orders/get-merchant-order/get): GET /merchant_orders/{id}, para comprobar preference_id, referencia y receptor de Checkout Pro.
- [Buscar pagos](https://www.mercadopago.com.pe/developers/es/reference/online-payments/checkout-pro/search-payments/get): GET /v1/payments/search por external_reference, limite 50; un resultado mayor requiere revision, sin perderlo silenciosamente.
- [Notificaciones](https://www.mercadopago.com.pe/developers/es/docs/checkout-pro-preferences/payment-notifications): HMAC SHA-256, x-signature y x-request-id.
- [Pruebas oficiales](https://www.mercadopago.com.pe/developers/es/docs/checkout-pro-preferences/integration-test/test-purchases): comprador de pruebas distinto del vendedor, medios oficiales de prueba.
- [Credenciales](https://www.mercadopago.com.pe/developers/es/docs/checkout-pro-preferences/additional-content/credentials).
- [Uso oficial de users/me con Access Token](https://www.mercadopago.com.pe/developers/en/docs/checkout-api-payments/best-practices/credentials-best-practices/secure-credentials).
- [Identidad del usuario](https://developers.mercadolibre.com.pe/es_ar/sobre-nuestra-api/consulta-usuarios): GET https://api.mercadolibre.com/users/me; se exige id del receptor configurado, site_id=MPE y tag test_user antes de crear/recuperar preferencias.

No se confia en el prefijo del token ni en el nombre de la variable. Cada pago consultado exige live_mode=false. Se utiliza el init_point validado del proveedor con la cuenta de pruebas. Solo se permiten enlaces HTTPS del host www.mercadopago.com.pe y la ruta /checkout/v1/redirect con pref_id; cualquier variacion exige revisar el contrato, no relajar automaticamente el filtro.

## Idempotencia y consistencia

No se presupone una garantia de X-Idempotency-Key para POST Preferences. Una intencion local y su trabajo durable se confirman juntos antes de HTTP. La primera ejecucion puede hacer POST una sola vez; tras timeout, caida o lease vencido, se consulta por referencia. Si no hay resultado inequ?voco, la orden queda incierta y bloquea otra creacion. El operador puede reconsultar, nunca repetir ciegamente el POST.

Idempotency-Key local persistente por empresa y operacion; huella por documento; otra huella con la misma clave devuelve 409. Un indice parcial impide dos ordenes activas por documento. Workers usan lease de 90 segundos y token de reclamacion. HTTP ocurre fuera de las transacciones. Se vuelve a validar saldo antes de publicar el enlace.

Inbox durable antes de responder 200 al webhook. Firma: id:<data.id>;request-id:<x-request-id>;ts:<ts>;. Comparacion constante; data.id se obtiene de QUERY_STRING sin perder el punto por normalizacion PHP; duplicados ambiguos se rechazan. No se aplica una ventana temporal arbitraria a reentregas oficiales. El cuerpo no es prueba de pago: se consulta la API y se comprueban identidad, receptor, referencia, moneda, importe, modo y preferencia. Solo esta ruta esta excluida del middleware de sesion/CSRF stateful.

La deduplicacion del inbox usa notificacion + identificador firmado. La clave unica provider/provider_id y el bloqueo del documento impiden aplicar dos veces un mismo pago. Fechas anteriores se ignoran. Una regresion posterior o estados contradictorios exigen revision. Dos pagos distintos se conservan y el exceso queda visible.

## Saldo y ajustes

Neto = original - creditos aceptados. Pendiente = max(neto - aplicado, 0). Exceso = max(aplicado - neto, 0). Importes en centimos enteros; los tokens numericos JSON del proveedor se preservan como cadenas antes de convertirlos, sin float. Agregados que exceden el entero seguro JavaScript viajan como cadenas decimales de centimos y se presentan con BigInt.

Reembolsos, contracargos y mediaciones marcan revision; no se inventa una devolucion local ni se resta automaticamente un importe ya aplicado. El saldo queda provisional. Se bloquean nuevos cobros mientras exista revision. Esta version no ofrece resolucion contable manual de esos ajustes.

## Dependencia de notas de credito

El repositorio no tiene dominio de notas de credito ni reservas: documents admite 01/03; disponer de series 07 no equivale a tener notas. Por ello credit_notes_supported=false y no se habilitan escenarios de cobranza con notas. La fuente actual devuelve cero creditos para los documentos compatibles existentes. Hay un contrato creditExposure y pruebas con una fuente de prueba para reservas y pago tardio, pero NO constituyen notas fiscales implementadas ni una prueba de concurrencia real entre notas y pagos. Antes de habilitarlas deben implementarse sus estados/reservas, fuente persistente y el mismo bloqueo de documents, y ejecutar pruebas de carreras credito/pago. Esa dependencia sigue pendiente.

## Recuperacion

Scheduler cada minuto publica trabajo pendiente en payments. Cinco intentos, espera exponencial de 30 a 900 segundos, lease y publicaciones vencidas recuperables; agotados pasan a failed/review. El tick consulta como maximo 25 ordenes recientes (ultimos siete dias), cada cinco minutos, y publica como maximo el limite indicado. El comando manual permite ordenes historicas por empresa y evento fallido concreto; un evento sin empresa solo puede seleccionarse explicitamente por --work. Los resultados recuperados conservan auditoria y marcan resolved el fallo tecnico correspondiente; no limpian ajustes financieros reales.

## Evidencia y limites

Sin credenciales ni URL publica autorizada en el entorno revisado. Integracion externa deshabilitada. Las pruebas locales usan adaptadores falsos exclusivamente en tests; no se insertan pagos ficticios en la empresa de demostracion. La validacion real Checkout Pro sigue pendiente. No se abre un tunel ni se publica esta instalacion.
