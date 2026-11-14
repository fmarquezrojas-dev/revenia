# Planes, suscripciones y cuotas de emision

Decision: [ADR-020](adr/ADR-020-planes-periodos-y-cuotas.md). API: [suscripciones](api/suscripciones.md). Fecha de transicion: 2026-11-13 (America/Lima).

## Comportamiento

Catalogo ficticio de demostracion: Basico (demo-basic, 19.00 PEN, 50 emisiones/periodo) y Plus (demo-plus, 49.00 PEN, 200 emisiones/periodo). No son una oferta comercial. Se conserva el maximo de cinco usuarios totales por empresa, incluidos inactivos. No hay debitos automaticos, prorrateos ni upgrades inmediatos.

Configurar el catalogo en backend/config/subscriptions.php y sincronizar explicitamente con subscriptions:maintain --sync-catalog. El comando hace upsert por codigo y no renueva contratos ni altera sus terminos. Desactivar available impide nuevas contrataciones, sin invalidar derechos pagados. Los contratos guardan plan/nombre, precio entero, moneda, limite y duracion de un mes; PostgreSQL impide modificarlos retroactivamente.

Primera compra confirmada: un mes desde la conciliacion. Durante vigencia: un solo periodo siguiente desde el fin actual, sin sumar cuota al actual. Tras vencimiento: reactivacion desde confirmacion, nueva ancla. Las fechas usan inicio incluido y fin excluido, UTC persistido y America/Lima para presentacion. El aniversario mensual se calcula desde el ancla original: 31 enero -> 28/29 febrero -> 31 marzo. Exclusion PostgreSQL con tstzrange y btree_gist impide solapamientos.

## Cuota transaccional

RequestIssuance bloquea empresa y documento; consume la capacidad del periodo vigente antes de reservar numero. Registro unico por documento, contador, numero, estado, eventos, idempotencia y outbox quedan en la misma transaccion. Una emision confirmada en base consume, aunque luego sea rechazada. Reintentos tecnicos, repeticiones idempotentes, PDF, correo, pagos, borradores y previews no consumen. Los reintentos terminan una emision ya consumida incluso si posteriormente vence la suscripcion.

Notas de credito: el dominio 07 sigue ausente. No se habilitan ni se declaran exentas de cuota; requieren una decision y pruebas propias antes de incorporarlas. Guardar/editar borradores sigue permitido sin suscripcion o con cuota agotada. Historicos, PDFs, cobranza y renovacion mantienen sus permisos. Vencimiento no desactiva usuario ni empresa.

La fecha y capacidad se comprueban en cada primera emision; no depende del scheduler. Cada periodo tiene su propio company_usage y registros emission_consumptions. No se reinician contadores. La auditoria compara contador y numero de registros sin repararlos automaticamente; una discrepancia exige investigacion y reparacion explicita.

## Contratacion y pagos

Owner selecciona plan, confirma y envia solo plan_code + Idempotency-Key. El servidor captura terminos y persiste subscription + payment_order + trabajo durable en una transaccion. Se reutilizan MercadoPagoGateway, el webhook HMAC, PaymentWork, payment_requests, payments y payment_audit.

Cada orden y pago tiene document_id o subscription_id, exactamente uno; CHECK y claves compuestas impiden ambos destinos o ninguno y relaciones de otra empresa. La empresa y destino proceden de la orden persistida. Un pago de plan no reduce saldo de documentos, no aparece como cobranza de clientes ni crea una factura de plataforma; un pago de documento no concede periodos.

Crear un checkout o volver con status=approved no activa derechos. La consulta autoritativa verifica id, referencia, receptor, importe, moneda, modo de pruebas y preferencia. Un pago aprobado activa/programa una sola vez bajo bloqueo de empresa. Dos identificadores de pago distintos se conservan; duplicate_subscription_payment marca revision, sin crear otra vigencia. Reembolsos, contracargos y cambios contradictorios requieren revision: bloquean nuevas emisiones, sin borrar documentos o consumos ni ejecutar devoluciones.

Las intenciones pendientes equivalentes reutilizan una orden. Otra seleccion mientras hay una pendiente devuelve conflicto. El owner puede cancelar una intencion pendiente (excepto creacion incierta, que exige conciliacion). La cancelacion es local, no promete cancelar el checkout externo ni devolver dinero. Cualquier pago tardio de una intencion cancelada/incompatible se conserva en payments y queda en revision, sin sustituir periodos posteriores. Tras expirar un checkout, cancelar su intencion y contratar de nuevo; un pago tardio sigue siendo conciliable.

## Permisos

| Operacion | owner | issuer | accountant |
| --- | --- | --- | --- |
| Plan y disponibilidad operativa | Si | Si | Si |
| Catalogo/precios e historial de contrataciones/pagos | Si | No | No |
| Contratar/renovar/cancelar intencion | Si | No | No |
| Emitir con vigencia y cupo | Si | Si | No |
| Consulta historica autorizada tras vencimiento | Si | Si | Si |

No se amplian permisos de clientes, series, usuarios o documentos. Recursos de otra empresa devuelven 404; usuario/empresa inactivos siguen rechazados por account.active.

## Transicion de los datos existentes

Migracion 2026_11_13_181309_add_subscription_periods_and_usage.php: plans, subscriptions, company_usage, emission_consumptions, subscription_demo_grants; ampliacion de las tablas de pagos existentes, claves exclusivas de destino y protecciones de periodos. Requiere PostgreSQL btree_gist (extension confiable disponible en el contenedor). No borra tablas/datos, reinicia series ni cambia credenciales. El rollback generico se bloquea para proteger historial y contratos.

Concesion local explicita, una sola vez por empresa registrada en local_demo_records, nunca al iniciar la aplicacion. Se usa demo-basic; el mes inicial es el calendario de Lima. Cada emision numerada con issued_at se incorpora al registro del mes correspondiente, incluidos rechazos y fallos. Fechas ausentes bloquean el procedimiento; no se inventan. Si un mes historico supera el limite, conserva su consumo y disponibilidad cero. source=demo identifica concesion local sin pago; el estado interno paid habilita el derecho, pero no constituye evidencia financiera: payments permanece vacio.

Empresas existentes autorizadas: la empresa de demostracion (12 comprobantes, 6 emisiones historicas) y Demo Beta (1 borrador). la empresa de demostracion: periodo 2026-11-01 00:00 a 2026-12-01 00:00 America/Lima, limite 50, consumo 6, disponible 44. Beta: mismo periodo, consumo 0, disponible 50. No se cambio ningun comprobante ni correlativo. Repetir grant-demo no renueva aunque haya vencido; demo:load-local no concede periodos. Las factories de testing incluyen una concesion explicita de fixture; el codigo de produccion no concede derechos automaticamente.

## Comandos

```powershell
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan subscriptions:maintain --sync-catalog
docker compose exec -T app php artisan subscriptions:maintain --company=<uuid> --grant-demo
docker compose exec -T app php artisan subscriptions:maintain --company=<uuid> --limit=100
docker compose exec -T app php artisan payments:reconcile --company=<uuid> --order=<uuid> --limit=10
docker compose exec -T app php artisan payments:reconcile --company=<uuid> --work=<uuid>
docker compose restart worker scheduler
```

Grant solo en local/testing y para las empresas del dataset autorizado en local. La auditoria es de solo lectura, limite 1..1000, salida differences y codigo de salida 1 si hay diferencias. Sin --company inspecciona como maximo limit periodos; no equivale a una auditoria completa si se supera ese limite. Scheduler la ejecuta cada hora con 100; pagos conserva su recuperacion limitada por minuto. No hay tarea de reset mensual ni correo comercial.

## Recorrido manual

1. Entrar como owner y abrir Plan y consumo. la empresa de demostracion debe mostrar Basico, Vigente, 6 consumidas y 44 disponibles. Revisar fechas e historial con origen demo y sin pago.
2. Abrir un borrador: indicador de cupo. Guardar cambios no consume. Al confirmar una primera emision, cuota, numero y outbox se registran juntos. Repetir la misma solicitud no duplica consumo; el rechazo posterior mantiene la unidad.
3. Para probar ultimo cupo y agotamiento sin alterar la empresa de demostracion, ejecutar test-subscription-concurrency.ps1: crea una base temporal aislada con limite uno. Dos procesos intentan emitir y uno recibe 422 quota_exhausted sin numero. Las pruebas frontend muestran el enlace de gestion del owner y el aviso para contactar al titular.
4. Con credenciales sandbox y URL autorizada configuradas conforme a COBRANZA.md, seleccionar un plan y confirmar. La intencion queda pendiente; pagar con medios oficiales de prueba. El retorno no activa por si solo. El worker consulta y valida el pago, activa/programa una unica vigencia y muestra el proximo plan.
5. En vencimiento, seguir consultando documentos y preparar borradores; una nueva emision devuelve subscription_expired, y owner conserva renovacion. No modificar fechas de contratos para simularlo: los tests viajan en el tiempo sin tocar datos locales.
6. Consultar como issuer/accountant: solo plan y disponibilidad operativa, sin precios ni checkout de suscripcion. Revisar la navegacion movil.

## Verificacion

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-backend.ps1
powershell -ExecutionPolicy Bypass -File scripts/test-backend.ps1 -Filter SubscriptionsTest
powershell -ExecutionPolicy Bypass -File scripts/test-subscription-concurrency.ps1
docker compose exec -T frontend npm test -- --maxWorkers=1 --pool=vmThreads
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build
```

Las pruebas usan exclusivamente facturly_testing o una base temporal facturly_series_testing_<uuid>, con guardias de entorno y usuario. Concurrencia verifica conexiones PostgreSQL distintas y barrera entre procesos. El adaptador de pagos falso vive exclusivamente en tests. La prueba de navegador local scripts/subscriptions-local-browser.cjs consulta la API real sin emitir ni modificar documentos; evidencia en capturas/suscripciones.

## Limites

No se dispone de credenciales sandbox ni URL publica autorizada: flujo externo deshabilitado y ninguna compra externa observada. Solo se valida activacion mediante fixtures/adaptador falso. No se publica ni abre tunel. La prueba externa sigue pendiente y no se declara cerrada. Se conserva la limitacion del dominio de notas de credito, la resolucion contable manual de pagos observados y la ausencia del documento tecnico v1.2 original.

## Resultados observados - 13 de noviembre de 2026

- Regresion completa del backend: 346 pruebas, 1642 aserciones, sin fallos (antes de las ultimas pruebas adicionales de catalogo, cuenta inactiva y consulta historica).
- Frontend: 140 pruebas en 10 archivos, sin fallos; lint y build correctos.
- Concurrencia real aislada: ultimo cupo devuelve 202/422, un solo correlativo/consumo/outbox; renovaciones simultaneas reutilizan una orden; pago repetido activa un solo periodo. Procesos con conexiones PostgreSQL diferentes, base temporal eliminada al finalizar.
- Navegador y API local: owner consulta plan/historial; issuer y accountant solo disponibilidad; vista de 390 px sin desbordamiento. Capturas y reporte en capturas/suscripciones.
- Migracion aplicada localmente; concesion demo repetida devuelve created=false; auditoria differences=[]; worker y scheduler reiniciados.
- Verificacion final de datos locales: 13 comprobantes preservados, 6 consumos historicos, 0 pagos y 0 ordenes de pago. la empresa de demostracion 6/50, Demo Beta 0/50. No se emitieron comprobantes durante el recorrido de navegador.
- Contratacion externa Mercado Pago: NO ejecutada. Las validaciones de activacion y renovacion usan exclusivamente adaptadores de prueba y HTTP simulado.

Comprobacion final posterior a los ajustes: SubscriptionsTest y MercadoPagoContractTest, 46 pruebas y 180 aserciones, sin fallos. Incluye consulta individual fuera de los 50 registros recientes, sincronizacion del catalogo sin modificar contratos, cuenta inactiva y URLs de retorno independientes para ambos destinos. Pint: 18 archivos verificados/formateados. Build final: correcto, 2136 modulos.
