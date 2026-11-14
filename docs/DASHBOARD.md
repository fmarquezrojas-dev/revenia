# Resumen de negocio y cierre del frontend

Implementacion sobre los modulos existentes. Decision: [ADR-021](adr/ADR-021-dashboard-negocio.md). La emision sigue siendo simulada; los pagos, de demostracion. No se alteran datos historicos para poblar graficos.

## Diccionario de indicadores

Todos los importes se expresan en centimos como cadenas decimales enteras. PEN y USD se consultan por separado, sin conversion. No representan utilidad ni ingresos contables.

| Indicador / clave de detalle | Definicion | Fecha y alcance | Exclusiones y reconciliacion |
| --- | --- | --- | --- |
| Emision aceptada / accepted | Suma de total_cents de facturas 01 y boletas 03 con primera aceptacion en el rango | MIN(document_events.created_at) para to_status=accepted; America/Lima | No borradores, pendientes, rechazos ni fallos sin aceptacion. No depende del estado actual; cada documento aparece una vez. Detalle accepted |
| Notas aceptadas | No disponible, valor null y credit_notes_supported=false | Sin fuente de datos: notas y reservas no existen en este repositorio | No se inventa cero como capacidad soportada, no se calcula emision neta ni se excluyen documentos historicos por una acreditacion inexistente |
| Cobrado / payments | Suma de applied_cents de pagos de clientes que tuvieron confirmacion validada | confirmed_at dentro del rango | Excluye destinos de suscripcion, pendientes/rechazados nunca aplicados y ordenes sin pago. Cada pago persistido cuenta una vez; dos identificadores distintos cuentan dos veces y pueden generar exceso. Detalle payments, una fila por pago |
| Rechazos / rejected | Documentos con primera respuesta rechazada del simulador en el rango | MIN(event.created_at) para to_status=rejected | No se confunde fallo tecnico con rechazo. Detalle rejected |
| Saldo pendiente / outstanding | SUM(max(total_cents - pagos aplicados, 0)) | Cartera actual: todos los documentos aceptados de la moneda, SIN filtro de fechas | Agregacion de pagos previa al join. No descuenta ordenes pendientes ni pagos de suscripcion. Detalle solo documentos con saldo positivo |
| Exceso pagado / excess | SUM(max(pagos aplicados - total_cents, 0)) | Misma cartera actual | Nunca saldo negativo. Detalle solo documentos con exceso |
| Pagos por revisar / review | Cantidad de documentos con exceso, pagos needs_review o trabajo de conciliacion review/failed | Misma cartera actual | DISTINCT por documento. El detalle muestra total original, no valor de ajuste; enlace al historial financiero del comprobante |
| En cola / queued | Documentos con estado queued o sending | Estado actual, moneda seleccionada | Una fila por documento; excluye borradores y finalizados |
| Fallos de emision / failed | Documentos con estado failed | Estado actual, moneda seleccionada | No se confunde con rechazo tributario |
| PDF con error / pdf_failed | Documentos con PDF failed | Estado actual, moneda seleccionada | EXISTS por documento, sin duplicar importes |
| Correo fallido o incierto / email_failed | Documentos con al menos un envio failed o unknown | Estado actual, moneda seleccionada | Incluye envios anteriores no resueltos; varios intentos no duplican el documento. Revisar historial de envio |
| Cuota | Limite, consumido, disponible y fechas del derecho vigente | Todas las monedas, periodo de suscripcion independiente del filtro de actividad | Reutiliza Subscriptions. Una primera emision confirmada en base consume; rechazos no devuelven cuota; reintentos no consumen otra vez |

Las cantidades de aceptaciones/pagos/pendientes/excesos usan las mismas selecciones que sus importes. La actividad reciente son las cinco ultimas aceptaciones del rango y moneda, con cliente capturado en el snapshot y enlace al documento.

## Fechas y ajustes financieros

Rango inicial: mes calendario actual en America/Lima. Mes anterior y rango personalizado disponibles. From/to son dias incluidos para el usuario; consulta UTC con inicio incluido y medianoche del dia posterior a to excluida. Maximo 366 dias incluidos. Serie diaria fija, incluidos dias sin actividad. El periodo vacio nunca se reemplaza por otro.

La cobranza anterior no almacenaba fecha de primera confirmacion. Se agrega payments.confirmed_at, capturada desde date_last_updated del primer pago aprobado validado por el conciliador. Reintentos, estados posteriores y webhooks atrasados no la cambian. No es fecha bancaria de liquidacion. La migracion usa provider_updated_at como aproximacion para pagos historicos aplicados; no se inventan fechas. La base local no tenia pagos al migrar.

Un reembolso/contracargo observado conserva applied_cents y exige revision segun la regla vigente de cobranza. Por ello el indicador historico sigue reflejando la confirmacion original, no una liquidacion neta bancaria. La cartera informa la revision y no descuenta automaticamente ajustes externos. No hay reparacion ni reembolso automatico.

Notas parciales/totales, reservas y documentos totalmente acreditados no pueden probarse de extremo a extremo porque el dominio 07 no existe. Su incorporacion requiere definir la integracion financiera antes de habilitar esas metricas. La consulta de aceptaciones ya evita depender del estado actual.

## API y permisos

`GET /api/v1/dashboard`: auth:sanctum, account.active, DocumentPolicy.viewAny. Acepta from/to (Y-m-d), currency (PEN/USD), group_by=day. Sin parametros usa mes actual/PEN. Rechaza company_id, columnas, ordenes y parametros desconocidos con 422.

La respuesta data contiene currency, period (from/to/start_utc/end_exclusive_utc), timezone, calculated_at, activity, portfolio, series, alerts, recent, usage y actions. Importes siempre string; cantidades integer; notas null con soporte false. Cache-Control private,no-store.

`GET /api/v1/dashboard?metric=<clave>&page=1`: detalle de la misma seleccion, 20 filas/pagina, paginacion con total/last_page; devuelve period/currency/timezone/calculated_at/detail. No retorna snapshots, tokens, payloads de proveedor ni datos de otras empresas. Cada fila tiene document_id, numero, cliente, fecha relevante, importe, estado tributario, estado de pago cuando aplica y href al documento. La paginacion se ordena por fecha descendente e identidad estable.

| Operacion | owner | issuer | accountant |
| --- | --- | --- | --- |
| Actividad, cartera y detalle de documentos de la empresa | Si | Si | Si |
| Alertas y cuota operativa | Si | Si | Si |
| Crear comprobante | Si | Si | No |
| Gestionar plan | Si | No | No |
| Conciliar pago | Si, desde detalle | No | No |

Se preserva la visibilidad financiera existente: PaymentController.show usa DocumentPolicy.view para los tres roles. No se agregan precios, pagos ni contratos de suscripcion al dashboard. Las acciones permanecen protegidas tambien en sus endpoints originales. Empresa se obtiene de la sesion; no existe selector arbitrario.

## Consultas, indices y presentacion

Agregaciones SQL independientes, una fila por documento antes de unir pagos o eventos. No se cargan documentos completos para calcular tarjetas. Listados paginados y serie acotada a 366 puntos. Instantanea REPEATABLE READ READ ONLY por respuesta; detalle posterior puede variar si llegan operaciones, por eso muestra calculated_at.

Indices nuevos: document_events(company_id,to_status,document_id,created_at) para primera respuesta; documents(company_id,currency,status) para cartera y atencion; payments(company_id,currency,confirmed_at) parcial para pagos aplicados de documentos. Se conservan los indices previos por documento y empresa. Sin cache ni promesas de escala no medida.

Grafico SVG nativo sin dependencias nuevas, escala comun por moneda, emision continua azul y cobranza discontinua verde, alternativa en tabla diaria. Posiciones del grafico se aproximan exclusivamente despues de escalar enteros; importes y etiquetas conservan centimos exactos mediante BigInt. No hay porcentajes ni animaciones. Tablas desplazan dentro de su contenedor.

Resumen es ruta inicial y destino seguro tras login sin ruta previa. Rutas profundas siguen funcionando; filtros, detalle y paginas quedan en URL para recarga/atras/adelante. AppShell conserva menu movil con Escape y retorno del foco, proteccion de cambios sin guardar, titulos y rutas restringidas. No hay menu de notas ni cobranza separada: cobranza sigue en el detalle del comprobante.

## Operacion y comprobacion

```powershell
docker compose exec -T app php artisan migrate --force --no-interaction
docker compose restart worker scheduler
powershell -ExecutionPolicy Bypass -File scripts/test-backend.ps1 -Filter 'DashboardTest|CollectionsTest|SubscriptionsTest'
docker compose exec -T frontend npm test -- --maxWorkers=1 --pool=vmThreads
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build
```

Recorrido: iniciar sesion, abrir Resumen, comparar Emision aceptada con Ver detalle, abrir comprobante, volver/avanzar/recargar, cambiar PEN/USD y mes anterior. Probar rango sin actividad y observar que la cartera actual conserva su alcance. Abrir tabla del grafico, alertas y Plan y consumo. Como accountant comprobar ausencia de Crear comprobante y gestionar plan; como issuer comprobar gestion de planes restringida. En movil abrir menu, Escape y verificar foco. Un error de red debe mostrar error recuperable, nunca un estado vacio.

Pruebas de backend exclusivamente en facturly_testing, con factories y fixtures; no modifican la empresa de demostracion. Navegador local de solo consulta en scripts/dashboard-local-browser.cjs, reutilizando Chrome/CDP existente y las credenciales demo sin imprimirlas. Evidencias en capturas/dashboard. No ejecutar fixtures historicos contra la base local.

## Pendientes para testing integral

- Recorrer CRUD de empresa, usuarios, clientes, productos, series y borradores con los tres roles; verificar conflictos de version y cambios sin guardar.
- Revisar flujos completos de emision, rechazo, recuperacion, PDF privado y correo en Mailpit; estados tributario/financiero/PDF/correo separados.
- Ejecutar checkout sandbox real cuando existan credenciales y URL autorizada; las pruebas actuales de proveedor son simuladas.
- Integrar notas de credito y reservas en un bloque propio, seguido de pruebas de acreditacion parcial/total y emision neta.
- Ampliar accesibilidad con lector de pantalla y navegadores adicionales; la validacion automatica no sustituye esa revision.
- Medir con volumen y distribucion representativos del despliegue antes de incorporar cache o prometer rendimiento.
- Revalidar todas las rutas en 360/768/1280 px, datos largos, formularios, paginacion, sesion expirada y restauracion del foco.

Sin commit, push, despliegue, tuneles, cambios de credenciales ni pagos reales. La demo tiene historial limitado; los dias vacios son correctos.

## Resultados de verificacion (2026-11-13)

- Backend: 72 pruebas, 365 aserciones correctas en DashboardTest, CollectionsTest y SubscriptionsTest. Incluye reconciliacion, pagos duplicados/distintos, reversion observada, exclusion de suscripciones, roles, moneda, fechas, alertas y paginacion.
- Medicion aislada con 501 documentos: 15 consultas, 197.4 ms por solicitud y 171.5 ms SQL en esa ejecucion. Es una medicion de testing local, no un compromiso de latencia para despliegue ni millones de registros.
- Pint aplicado a ocho archivos PHP. Migracion aplicada localmente y servicios worker/scheduler reiniciados.
- Frontend final: 152 pruebas correctas en 11 archivos; lint y build correctos. La comprobacion focalizada de dashboard/navegacion paso 26 pruebas.
- Verificacion visual de capturas desktop y 360 px: identidad preservada, controles legibles y sin desbordamiento de pagina. Recorrido completo de navegador PASS, sin excepciones JavaScript: rutas inicial/directa, detalle reconciliable, recarga, atras/adelante, moneda, mes vacio, ocho modulos, roles, logout y menu movil. Evidencia en capturas/dashboard/verification.json.

- Capturas: resumen-desktop.png, resumen-360.png, resumen-768.png y resumen-1280.png en capturas/dashboard.
- Datos locales finales preservados: 13 comprobantes, 6 consumos, 0 pagos y 0 ordenes. Resumen PEN de noviembre: 4 aceptados, 14481 centimos emitidos, 0 cobrados, 14481 pendientes, 1 rechazo. Cuota de la empresa de demostracion 6/50.
- Migracion aplicada: 2026_11_13_184544_add_dashboard_payment_confirmation_and_indexes. Backend focalizado ejecutado sobre PostgreSQL aislado; no se declara una nueva regresion completa del backend ni una contratacion externa sandbox.
