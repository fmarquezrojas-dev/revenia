# Cobros por empresa y conciliación de pagos

Implementacion: [ADR-019](adr/ADR-019-cobranza-mercado-pago-sandbox.md). API: [contrato](api/cobranza.md) y [OpenAPI](api/openapi.json).

## Configuración por receptor

El administrador configura `MP_SANDBOX_ENABLED`, `MP_WEBHOOK_URL` (URL base pública terminada en `/api/v1/webhooks/mercadopago`) y `MP_RETURN_ORIGIN` en el entorno. La cuenta de la plataforma se configura con `MP_TEST_ACCESS_TOKEN`, `MP_TEST_COLLECTOR_ID` y `MP_WEBHOOK_SECRET` para las suscripciones. Una empresa puede cobrar comprobantes aunque Revenia aún no haya configurado su receptor de suscripciones.

El dueño entra en **Configuración → Cobros**. En su aplicación de Mercado Pago selecciona el evento Pagos y registra la URL de pruebas que muestra Revenia, terminada en `/companies/{UUID_EMPRESA}`. Copia en el formulario el Access Token del vendedor y la clave secreta de Webhooks de esa aplicación. **Verificar y vincular** consulta la identidad de la cuenta y comprueba el sitio peruano y la condición de usuario de pruebas. La identificación no depende del prefijo del token. Laravel cifra ambos secretos con la clave de la aplicación. La API devuelve únicamente el estado, receptor, URL y fecha de verificación.

Al rotar credenciales para el mismo receptor, las referencias de las órdenes siguen vigentes. Al cambiar de receptor o desactivarlo se ocultan los enlaces locales de esa cuenta. Las credenciales históricas cifradas se conservan para consultar pagos tardíos. Un enlace ya compartido puede recibir un pago hasta que expire en el proveedor. La conciliación utiliza el receptor fijado al crear la orden. Una orden histórica sin ese dato requiere comprobación manual con el proveedor.

El webhook valida HMAC-SHA256 y consulta el pago y la orden comercial antes de aplicar importes. El identificador del vendedor recibido en el cuerpo permite localizar la clave candidata de esa empresa. La identidad final proviene de la respuesta autenticada del proveedor y debe coincidir con la orden. Las peticiones de usuarios y las incidencias de procesamiento se registran en `payment_audit`, con empresa, actor para acciones manuales, referencia y fecha. La operación automática conserva el identificador del trabajo.

La prueba externa sigue pendiente. [Identidad del vendedor](https://developers.mercadolibre.com.pe/consulta-usuarios) · [Cuentas de prueba](https://www.mercadopago.com.pe/developers/es/docs/checkout-pro-preferences/test-accounts) · [Firma y notificaciones de Checkout Pro](https://www.mercadopago.com.pe/developers/es/docs/checkout-pro-preferences/payment-notifications).

## Configuración de infraestructura

En backend/.env, fuera de Git: MP_SANDBOX_ENABLED, MP_TEST_ACCESS_TOKEN, MP_TEST_COLLECTOR_ID, MP_WEBHOOK_SECRET, MP_WEBHOOK_URL, MP_RETURN_ORIGIN y PAYMENT_QUEUE_CONNECTION. El ejemplo permanece deshabilitado y sin credenciales. Usar solo la cuenta receptora de pruebas Peru verificada y comprador de pruebas distinto; no tarjetas ni dinero reales. No enviar secretos por chat ni registrarlos en logs.

MP_WEBHOOK_URL debe apuntar a https://<host-autorizado>/api/v1/webhooks/mercadopago, accesible al proveedor. MP_RETURN_ORIGIN es el origen HTTPS de la interfaz. Localhost no recibe webhooks externos. La ruta publica valida HMAC, limita el cuerpo a 16 KiB, exige evento payment de pruebas y persiste antes de confirmar. Configurar las notificaciones de pagos en la aplicacion de pruebas del proveedor. No publicar ni abrir tuneles implicitamente.

## Instalacion y operacion

La migración de receptores es `2026_11_20_000003_bind_payment_receivers.php`. La migración base es `2026_11_13_055044_add_document_collections.php`. Crea payment_orders, payments, payment_requests, payment_work y payment_audit, sin modificar comprobantes ni datos demo. El rollback se niega a eliminar evidencia financiera existente.

```powershell
docker compose exec -T app php artisan migrate --force
docker compose restart worker scheduler
docker compose exec -T app php artisan payments:reconcile --tick --limit=50
docker compose exec -T app php artisan payments:reconcile --company=<uuid> --order=<uuid> --limit=10
docker compose exec -T app php artisan payments:reconcile --company=<uuid> --work=<uuid>
```

El worker consume payments. La programacion publica cada minuto; nunca espera a una llamada HTTP dentro del webhook. --work sin --company solo encuentra eventos aun sin empresa. La consulta manual puede dirigirse a una sola orden o a las diez más recientes del comprobante. Hay un minuto de espera por orden. Repetir la misma clave reproduce la respuesta.

## Recorrido manual

1. Iniciar sesion como owner o issuer. Abrir un comprobante accepted en PEN.
2. Revisar Cobranza: original, creditos, neto, cobrado, pendiente, exceso, historial y indicación de entorno de pruebas. Si falta configuracion, no existe accion de crear enlace.
3. Con el entorno externo configurado, crear enlace y confirmar el saldo calculado. Esperar a la orden abierta; copiar/abrir el checkout validado. No introducir un importe manual.
4. Pagar solo con comprador y medios oficiales de pruebas. El retorno muestra informacion, nunca acredita por parametros de URL. La sesion permanece privada.
5. El webhook/consulta verifica el pago y actualiza el saldo. El comprobante sigue accepted. Actualizar cobranza o, como owner, solicitar conciliacion si falta la notificacion.
6. Consultar como accountant: ve importes e historial, sin crear enlaces ni conciliar. USD informa la limitacion PEN; no convierte moneda.

Polling local cada tres segundos mientras hay trabajo, maximo 40 consultas; peticiones abortadas al desmontar y timeout de consulta 15 segundos. Mutaciones con clave estable en memoria/sessionStorage hasta confirmacion; el retorno no dispara pagos.

## Pruebas reproducibles

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-backend.ps1
powershell -ExecutionPolicy Bypass -File scripts/test-payment-concurrency.ps1
docker compose exec -T frontend npm test -- --maxWorkers=1 --pool=vmThreads
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build
```

Backend usa facturly_testing con guardia de usuario/entorno. Concurrencia usa una base temporal exclusiva facturly_series_testing_<uuid>, conexiones PostgreSQL distintas y barrera entre procesos; elimina solo esa base y sus artefactos. Comprueba solicitudes de igual/diferente clave, workers duplicados, mismo pago y pagos distintos simultaneos. El proveedor es falso: no es una prueba sandbox externa.

Revision visual local: scripts/payments-local-browser.cjs, con FACTURLY_DEMO_EMAIL y FACTURLY_DEMO_PASSWORD solo en entorno del proceso; consulta datos existentes sin editarlos. Las evidencias locales sin depurar no se publican; el informe vigente está en testing/test-report.md. No ejecutar el script anterior de carga para verificar pagos.

## Pendientes explicitos

- Recorrido externo real: faltan credenciales de vendedor/comprador de pruebas y URL HTTPS autorizada. No hay evidencia de pago confirmado por Mercado Pago.
- Las notas 07 reservan las líneas al emitir. Un pago válido iniciado antes del ajuste se aplica por el importe de la orden. Todo exceso aparece en el detalle y en el indicador de revisión.
- Resolucion contable de ajustes/reembolsos/contracargos: requiere otro bloque; se muestra revision y se bloquea otro cobro.
- No se implementa OAuth de comercios, portal publico, pagos reales ni suscripciones recurrentes automaticas. Los pagos unicos de planes reutilizan esta pasarela: ver PLANES_Y_CUOTAS.md.

Servidor local: compose.yaml configura PHP_CLI_SERVER_WORKERS=4 para atender en paralelo los paneles de emision, cobranza y entrega. Esto evita que el servidor de desarrollo serialice las consultas y agote sus tiempos de espera; no cambia el despliegue de produccion.

## Resultados verificados del bloque

- Regresion backend completa: 315 pruebas, 1494 aserciones, correcta.
- Revision posterior focalizada de cobranza y contratos: 36 pruebas, 134 aserciones, correcta (incluye nuevas pruebas de recuperacion y expiracion).
- Contrato HTTP final, incluida busqueda incompleta/ambigua: 17 pruebas, 25 aserciones, correctas.
- Concurrencia PostgreSQL: PASS, cinco carreras con conexiones independientes: misma clave, claves distintas, workers de creacion, mismo pago y pagos distintos. Proveedor falso exclusivamente de pruebas.
- Frontend completo: 127 pruebas correctas. Tras ampliar los casos del panel, suite focalizada final: 12 pruebas correctas.
- Lint y build frontend: correctos. Formato PHP aplicado con Pint.
- Navegador real contra API local: PASS; saldo 40.65 PEN, retorno con status=approved sin acreditar fondos, limitacion USD, consulta accountant sin acciones, movil 390px sin desbordamiento. Capturas en capturas/cobranza; verification.json contiene el resultado.
- Migracion de cobranza aplicada localmente; worker y scheduler recargados. El comando payments:reconcile confirma integracion deshabilitada por configuracion incompleta.
- Conservacion: la empresa de demostracion mantiene 12 comprobantes y Demo Beta uno; tablas locales payment_orders y payments vacias. No se cargo evidencia falsa de pagos.
- Sandbox externo: NO EJECUTADO. Faltan credenciales y URL HTTPS autorizada.

La primera regresion detecto que PHPUnit heredaba SIMULATION_DEMO_CONTROLS del entorno local; se aislo esa variable y las credenciales de pago en phpunit.xml, y la suite completa paso al repetirla. La revision visual detecto serializacion de peticiones en el servidor PHP de desarrollo; compose.yaml ahora usa cuatro procesos y --no-reload, y se repitio la verificacion con resultado PASS.
