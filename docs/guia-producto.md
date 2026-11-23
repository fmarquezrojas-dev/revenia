# Guía de producto

## Acceso

Abre la interfaz local en `http://localhost:5173`. En **Inicia sesión**, usa tu correo y contraseña y pulsa **Ingresar**. El navegador puede guardar y autocompletar las credenciales. **Mantener mi sesión en este dispositivo** permite a Laravel restaurar una sesión autorizada. Al volver desde un enlace interno, Revenia recupera la pantalla y comprueba los permisos. **Cerrar sesión** invalida el recuerdo en ese navegador.

## Navegación

| Sección | Pantallas |
| --- | --- |
| Operación | **Resumen**, **Comprobantes**, **Cuentas por cobrar**, **Incidencias**, **Clientes**, **Productos y servicios** |
| Tesorería | **Cobros bancarios** |
| Configuración | **Integraciones**, **Cobros**, **Mi empresa**, **Usuarios**, **Series**. Disponibles para el dueño |
| Cuenta | **Plan y consumo**, **Mi cuenta** |

En **Resumen**, la actividad del periodo muestra las ventas, notas de crédito y pagos confirmados. La cartera actual se consulta por moneda, con independencia del periodo elegido.

## Facturación

1. En **Comprobantes**, el dueño o emisor pulsa **Nuevo comprobante**, elige factura o boleta y selecciona serie, cliente, moneda y productos. **Guardar borrador** conserva las líneas sin consumir un número.
2. En **Condición de pago**, **Contado** vence en la fecha de emisión. **Crédito** requiere un vencimiento igual o posterior. Al iniciar la emisión se fijan los datos del comprobante.
3. Desde el detalle, pulsa **Emitir comprobante** y **Confirmar emisión**. Sigue el estado del envío y, cuando se acepte, consulta **PDF privado y correo**.
4. En una factura o boleta aceptada, **Crear nota de crédito** permite seleccionar líneas completas e indicar el motivo. La emisión reserva las líneas y la aceptación reduce el saldo del documento de origen.

Los comprobantes, sus PDF y el correo identifican la **Emisión tributaria simulada**. El proveedor local aplica reglas simplificadas. Los documentos carecen de validez tributaria y la plataforma no los presenta a SUNAT.

### Reproducir la devolución de 236 PEN

En una instalación nueva con el conjunto de demostración y un período vigente, ejecute `docker compose exec -T app php artisan demo:credit-note`. El comando prepara una factura de 1.180,00 PEN con un cobro histórico sintético de 1.000,00 PEN. Abra FR01-00000001 en una demo recién preparada y cree una nota por la línea de 236,00 PEN. Puede identificar el número exacto en la salida del comando. Con la nota aceptada, el neto se reduce a 944,00 PEN y aparecen 56,00 PEN en exceso para revisión. [Resultados de las pruebas](testing/test-report.md)

## Cuentas por cobrar

En **Cuentas por cobrar**, busca por cliente o número, elige PEN o USD y filtra por vencimiento. El corte usa America/Lima. Las tarjetas resumen toda la selección y los tramos de atraso abren sus comprobantes. Las ventas históricas sin fecha figuran como **Sin vencimiento informado**. **Saldo pendiente actual** en **Resumen** abre la cartera en la moneda seleccionada.

En **Vista de cobranza**, **Pendientes de confirmación** y **Por revisar** reúnen las operaciones que necesitan seguimiento, incluso cuando su comprobante ya está saldado. El número abre el detalle. Los créditos aceptados y los pagos aplicados reducen el saldo. Las reservas pendientes y los excesos conservan su tratamiento financiero.

### Estado de cuenta por cliente

En **Clientes**, abre la ficha y selecciona **Estado de cuenta**. Desde **Cuentas por cobrar** puedes elegir un cliente con **Estado de cuenta por cliente** o abrir el enlace de una fila. El dueño, el emisor y el contador activos pueden consultar e imprimir los datos de su empresa, incluso si el cliente está inactivo.

Elige **PEN** o **USD**. El encabezado indica la hora de cálculo en **America/Lima**. Tanto el resumen como los comprobantes y abonos disponibles muestran la posición actual completa en esa moneda. **Movimientos desde / Hasta** filtra únicamente la cronología por días inclusivos. La consulta inicial abarca desde el primer día del mes hasta hoy. **Ver movimientos** permite seguir un solo comprobante. **Vista imprimible completa** incluye todas las páginas en una nueva lectura consistente y conserva el período y el comprobante elegidos.

| Cifra | Fuente y alcance |
| --- | --- |
| Saldo pendiente y parte vencida | Facturas y boletas aceptadas asociadas al cliente. De cada total original se descuentan sus notas aceptadas y pagos aplicados. Se suman los saldos positivos. Se considera vencido el saldo con vencimiento anterior a hoy en Lima. |
| Créditos y reservas | La emisión mantiene las líneas históricas acreditadas y las reservadas. Solo las acreditadas reducen la deuda. La cronología usa la emisión de la nota para la reserva y el evento de aceptación para el crédito. |
| Pagos aplicados | Cartera y Resumen consultan los pagos aplicados del proveedor y las aplicaciones bancarias vigentes. La cronología identifica la confirmación registrada de Mercado Pago, la solicitud de orden y el estado actual de esa orden. Cada distribución bancaria muestra su propia porción y enlaza al abono original. Una reversión se fecha por separado. |
| Excesos y comprobantes en revisión | El exceso se calcula por comprobante cuando el importe pagado supera el neto. Permanece separado de las demás deudas. La revisión usa las mismas señales financieras que Cuentas por cobrar. |
| Abonos disponibles por aplicar | Tesorería aporta el remanente de abonos directos confirmados e identificados a ese cliente. Los abonos por identificar, las liquidaciones de Mercado Pago y las suscripciones quedan fuera. |

**Seguimiento** abre el contexto comercial del comprobante. Los compromisos no descuentan deuda. El proveedor conserva la fecha de confirmación y el importe aplicado vigente, pero su conciliación actualiza la fila del pago. Esta versión ofrece saldo actual y movimientos fechados, sin emitir saldos históricos a una fecha de corte. La consulta y la impresión leen PostgreSQL sin comunicarse con proveedores. El bloque utiliza el esquema existente.

### Seguimiento de cobranza y compromisos de pago

Abre **Seguimiento** desde una fila de **Cuentas por cobrar** o desde el detalle de una factura o boleta aceptada. La ficha reúne saldo vigente, créditos aceptados, pagos aplicados, vencimiento original, responsable, próxima acción, última gestión e historial. Puedes consultar una ficha saldada desde **Comprobantes**.

| Rol activo | Acceso al seguimiento |
| --- | --- |
| Dueño | Consulta ambas agendas y todas las fichas de su empresa. Asigna o retira responsables, registra y corrige gestiones, crea, reprograma, cancela y confirma compromisos. |
| Emisor | Consulta fichas de su empresa y **Mi agenda**. Puede gestionar únicamente los comprobantes que el dueño le asignó expresamente. |
| Contador | Consulta fichas, historial y **Agenda del equipo** en modo de lectura. |

El dueño puede elegir a otro dueño o a un emisor activo de la misma empresa. Un responsable desactivado o que pasa al rol contador conserva su historial. El documento aparece en **Requieren responsable** hasta la reasignación. Cada gestión registra canal, resultado, observación, próxima acción, autor y hora del servidor. Para rectificarla, abre **Corregir gestión** en el historial e indica el motivo. La ficha conserva el registro original y la corrección. El usuario realiza la comunicación por su cuenta. Las observaciones se consultan dentro de Seguimiento.

**Compromisos.** Introduce un importe positivo en la moneda del documento, hasta el saldo vigente, y la fecha acordada. Hay un solo compromiso activo por comprobante. Si dos personas modifican la misma ficha a la vez, la segunda debe actualizarla antes de guardar. Al reprogramar, indica el **nuevo importe pendiente** y el motivo. El total del acuerdo pasa a ser lo ya acreditado más ese pendiente. Las fechas e importes anteriores quedan en el historial.

**Cumplimiento.** Revenia acredita pagos aprobados, confirmados después de abrir el acuerdo, aplicados al mismo documento y moneda, sin reembolso ni revisión. Los pagos que ya estaban aplicados al registrar el compromiso quedan excluidos. Una reprogramación mantiene el inicio original y vuelve a calcular la evidencia. **Confirmar cumplimiento** comprueba los pagos nuevamente y guarda cuánto aportó cada uno. Si un ajuste posterior invalida esa evidencia, el cierre histórico muestra **en revisión**. Los créditos aceptados reducen la cartera y pueden requerir revisar el acuerdo. El acuerdo conserva por separado el dinero acreditado y el importe pendiente. Crear o cerrar un compromiso conserva los importes financieros y el vencimiento del comprobante.

En **Cuentas por cobrar → Mi agenda / Agenda del equipo**, filtra por cliente, moneda, fechas de acción o compromiso y, en la vista de equipo, por responsable. Cada indicador abre su listado con los mismos filtros. Las acciones para hoy y los atrasos usan **America/Lima**. Un compromiso vence al pasar la fecha acordada si todavía queda importe por cubrir. La agenda muestra una fila por comprobante con saldo positivo y páginas de 20 documentos.

Para habilitar el módulo, respalda primero la instalación y aplica las migraciones pendientes desde la raíz con `docker compose exec -T app php artisan migrate`. El seguimiento incorpora `2026_11_21_000001_add_collection_follow_up.php` y utiliza PostgreSQL local.

## Tesorería → Cobros bancarios

El dueño configura las **Cuentas receptoras** mediante alias, entidad financiera, moneda y estado. La moneda se fija al crear la cuenta. Desactivarla impide registrar nuevos ingresos y permite terminar de gestionar los históricos. Revenia conserva la referencia a la cuenta y prescinde de números de cuenta, claves y conexiones bancarias.

| Rol | Operaciones |
| --- | --- |
| Dueño | Administra cuentas, registra y confirma abonos, identifica clientes, distribuye importes y revierte aplicaciones. |
| Emisor | Consulta tesorería y registra abonos pendientes con o sin cliente identificado. |
| Contador | Consulta la bandeja, las aplicaciones, los remanentes y su historial. |

1. En **Registrar abono**, selecciona la cuenta y el origen, e indica transferencia o depósito, fecha bancaria, referencia e importe. La observación es opcional. La **verificación bancaria es manual**. El dueño contrasta el extracto y pulsa **Confirmar ingreso bancario**. El detalle distingue fecha efectiva, registro y confirmación e identifica a sus autores en el historial.
2. Para un pago directo confirmado, identifica al cliente y abre **Distribuir abono**. Elige hasta 50 facturas o boletas aceptadas de ese cliente y moneda. Puedes cubrirlas total o parcialmente y conservar un remanente. Al confirmar, el servidor bloquea el abono y los comprobantes, vuelve a calcular sus saldos y descuenta las reservas de crédito. Si algún documento tiene confirmación o revisión pendiente, o si algún importe excede lo disponible, rechaza la distribución completa.
3. La referencia se contrasta dentro de la cuenta receptora ignorando diferencias de mayúsculas y espacios repetidos. Un registro exacto abre el abono existente. Si los datos difieren, la interfaz ofrece **Revisar abono existente** y conserva los importes originales. Las liquidaciones de Mercado Pago tienen su propio origen y únicamente representan el ingreso en banco. Las suscripciones de la plataforma siguen en **Plan y consumo**.
4. En el detalle, **Revertir aplicación** solicita un motivo. La reversión identifica al autor, la fecha y la aplicación original. Devuelve el importe al disponible y recalcula la deuda. Es una corrección interna. Si el cliente fue identificado por error, el dueño puede cambiarlo cuando haya revertido todas las aplicaciones vigentes. La ficha del cliente enlaza su bandeja y el comprobante enlaza sus aplicaciones bancarias.

**Indicadores por moneda.** En **Resumen**, los ingresos bancarios confirmados se cuentan una sola vez por abono, según su fecha bancaria, e incluyen las liquidaciones identificadas por separado. El importe bancario aplicado muestra las aplicaciones vigentes según la fecha de distribución en America/Lima. El disponible por aplicar suma hoy los remanentes de abonos directos confirmados de cualquier fecha, incluidos los pendientes de identificar. Cartera, dashboard y las tres herramientas MCP comparten la misma fuente de pagos vigentes.

**Compromisos.** Una aplicación bancaria aporta únicamente el importe asignado a ese comprobante. Tanto la confirmación manual del ingreso como la aplicación deben ser posteriores al inicio del compromiso, y su fecha bancaria debe ser el mismo día del acuerdo o posterior en Lima. Un abono pendiente, una liquidación o un remanente no acreditan el compromiso. Revertir una aplicación retira su evidencia y permite revisar el acuerdo o el cumplimiento histórico afectado.

La migración `2026_11_21_000002_add_bank_receipts.php` sigue a la de Seguimiento. Desde la raíz y después del respaldo, `docker compose exec -T app php artisan migrate` aplica las migraciones pendientes. El módulo reutiliza Laravel, PostgreSQL y React.

## Pagos y conciliación

| Operación | Quién recibe el pago | Quién la gestiona |
| --- | --- | --- |
| Pago de factura o boleta en PEN | La cuenta de la empresa emisora vinculada en **Configuración → Cobros** | Dueño y emisor crean enlaces. El dueño solicita conciliación. El contador consulta |
| Contratación o renovación en **Plan y consumo** | La cuenta de la plataforma Revenia | El dueño contrata o renueva. Todos los roles pueden consultar el consumo |

**Entorno de pruebas.** El adaptador utiliza Checkout Pro de Mercado Pago Perú. El dueño registra el Access Token de su vendedor de pruebas y la clave de firma de su aplicación en **Cobros**. **Verificar y vincular** comprueba la identidad y muestra el identificador del receptor y la fecha de verificación. La pantalla muestra la URL de Webhooks de la empresa cuando el entorno está listo. Una conexión puede desactivarse o sustituirse. Las órdenes conservan su receptor original.

En **Comprobantes → Cobranza**, **Crear enlace de pago** solicita un enlace por el saldo vigente. **Solicitar conciliación** consulta las órdenes recientes en Mercado Pago. **Conciliar esta orden** consulta una referencia concreta. **Actualizar cobranza** muestra el estado, la referencia y las incidencias. El regreso del checkout consulta el historial disponible. La acreditación requiere una respuesta del proveedor que corresponda al receptor, referencia, preferencia, importe, moneda y entorno de la orden.

La verificación externa del cobro y la entrega de un webhook firmado sigue pendiente. Requiere cuentas de prueba y una URL HTTPS autorizada. [Configuración de cobros](COBRANZA.md) · [Notificaciones de Checkout Pro](https://www.mercadopago.com.pe/developers/es/docs/checkout-pro-preferences/payment-notifications).

## Incidencias

Abre **Operación → Incidencias** o selecciona una alerta de **Resumen → Atención operativa**. La alerta conserva moneda y fechas de última actualización, en hora de Lima. La bandeja permite buscar por número de comprobante, referencia de envío, orden o pago y filtrar por proceso y situación. **Requieren atención** es la vista inicial. **En proceso** y **Resuelta en origen** permiten seguir una recuperación.

| Proceso | Qué alimenta la cronología | Recuperación |
| --- | --- | --- |
| Emisión de facturas, boletas y notas de crédito | Estado del comprobante, eventos de emisión e intentos de publicación en cola | Dueño y emisor pueden recuperar un fallo habilitado. El trabajador consulta primero la referencia original del simulador |
| Generación de PDF | Estado y versión del PDF, solicitudes e intentos de generación | El dueño puede regenerar el PDF cuando corresponde un reintento manual |
| Entrega de correo | Solicitudes, intentos de entrega y comprobaciones del transporte, agrupados por comprobante | Dueño y emisor pueden solicitar otro envío tras un fallo confirmado, con PDF disponible y dentro de los límites. Un resultado incierto requiere comprobación técnica del transporte |
| Transferencia a Odoo | Outbox de cada comprobante, intentos, comprobación del borrador y destino autorizado | El dueño puede recuperar una transferencia mediante su clave. Las notas de crédito esperan al original en la misma compañía |
| Cobranza de clientes | Orden, trabajos de conciliación, auditoría y estados informados por Mercado Pago | El dueño puede conciliar la orden identificada cuando el dominio lo permite. La orden conserva el receptor original |

Una notificación autenticada del comercio que aún no tiene orden ni moneda confirmadas aparece en la bandeja sin filtro de moneda. Operación técnica investiga la referencia. La bandeja enlaza el comprobante cuando el sistema confirma la correspondencia.

Los tres roles activos consultan su empresa. Dueño y emisor pueden **Registrar revisión**. La cronología identifica al solicitante y el resultado de la solicitud. Solo el proceso de origen determina la resolución. La fecha de envío de un correo indica aceptación por el transporte. Los vencimientos siguen en **Cuentas por cobrar** y las suscripciones en **Plan y consumo**.

**Reintento automático** muestra el siguiente intento programado. **Demorada** requiere que haya vencido el plazo registrado de un trabajador (90 segundos), o que una publicación de emisión, PDF o correo siga esperando ejecución después de 180 segundos. Un pendiente ordinario figura **En proceso**. La bandeja y el detalle consultan el historial local. Horizon y la verificación de correos inciertos siguen bajo control de operación técnica.

## Configuración → Integraciones → Odoo

Revenia transfiere facturas y boletas aceptadas a facturas de cliente en borrador de **Odoo Community 18.0**. Las notas de crédito aceptadas se crean como rectificativas en borrador, vinculadas al original por su ID de Odoo. Se conservan la numeración de Revenia, el cliente histórico, las líneas, moneda, impuestos e importes. La aceptación tributaria simulada en Revenia y la revisión contable en Odoo son estados independientes.

1. Aplica las migraciones locales e instala en tu Odoo el módulo de Facturación (`account`) y el [módulo de Revenia](../integrations/odoo/revenia_bridge). La [guía local](ODOO.md) incluye un perfil Docker opcional para Odoo con base propia. Prepara el diario, la cuenta de ingresos, las monedas, unidades, clientes e impuestos de la compañía. Usa un usuario técnico con acceso de facturación a esa compañía y una clave API.
2. El administrador de despliegue autoriza el destino y su base mediante `ODOO_ENDPOINTS` y activa `ODOO_ENABLED` en el entorno del backend. Ambos vienen desactivados o vacíos. Recarga la configuración de Laravel, el trabajador y el planificador.
3. En **Configuración → Integraciones**, el dueño copia el UUID de Revenia al campo **UUID de empresa en Revenia** de la compañía en Odoo. Selecciona el destino, indica el ID de compañía, usuario y clave, y pulsa **Verificar y guardar**. Revisa el nombre de la compañía obtenido del servidor.
4. Registra las **Correspondencias** por sus IDs estables y pulsa **Habilitar transferencias**. Las próximas aceptaciones entran en cola. Para un comprobante histórico aceptado, el dueño puede abrir el detalle y pulsar **Transferir este comprobante**.
5. En **Comprobantes → Transferencia a Odoo** se muestran el último resultado, la compañía y el ID del documento externo. En **Incidencias → Transferencia a Odoo** se investiga la misma operación. El dueño configura, autoriza cambios de destino y solicita la recuperación. Emisor y contador consultan el resultado. El emisor conserva su permiso general para registrar una revisión en Incidencias.

El conector crea únicamente borradores. La contabilización, el inventario y la emisión tributaria externa quedan fuera de la transferencia. El bloque 11 comprobó la transferencia a una instalación real aislada de Odoo 18. El operador valida su compañía y sus correspondencias. [Instalación, estados y recuperación](ODOO.md).

## Respaldo y restauración

Antes de aplicar migraciones, el operador puede copiar PostgreSQL y los archivos privados y abrir una revisión aislada en Windows. La guía incluye la pausa de escritura, la custodia de la clave, la comprobación y el retiro de la copia. [Respaldo y restauración](RESPALDO_Y_RESTAURACION.md).

## Consultas locales de cobranza

Un cliente compatible con Model Context Protocol (MCP) puede consultar la cartera por entrada y salida estándar (`stdio`). El dueño, emisor o contador necesita una cuenta activa con permiso para consultar comprobantes de su empresa.

### Preparar la conexión en Windows

Instale Revenia desde la rama actual siguiendo la [guía de desarrollo](development.md). Se requieren Windows PowerShell 5.1, el contexto local de Docker Desktop, la imagen de `app` y las dependencias de `backend/composer.lock`. Para leer saldos, PostgreSQL debe estar disponible con las migraciones de cartera aplicadas.

1. En PowerShell, desde la raíz de la copia que desea consultar, obtenga las dos rutas.

   ```powershell
   $ejecutable = (Get-Command powershell.exe -CommandType Application).Source
   $lanzador = (Resolve-Path -LiteralPath '.\scripts\mcp\revenia.ps1').Path
   $ejecutable
   $lanzador
   ```

2. En la configuración **personal** de su cliente, registre un servidor llamado `revenia` con transporte `stdio`. En el campo de ejecutable use el valor de `$ejecutable`. Pase cada argumento por separado, en este orden. El último argumento es la ruta completa del archivo, incluso si contiene espacios.

   ```powershell
   $argumentos = @('-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'RemoteSigned', '-File', $lanzador)
   [pscustomobject]@{ command = $ejecutable; args = $argumentos } | ConvertTo-Json
   ```

   El comando imprime valores de configuración para copiar al formato que admita el cliente. El lanzador localiza Compose a partir de su propia ruta y abre un contenedor temporal sin publicar puertos. La configuración personal solo necesita el ejecutable y los argumentos.

3. Desde una terminal interactiva, inicie la base de esa instalación cuando corresponda y autorice el acceso. Si su instalación está en una pausa de escritura por respaldo, termine el respaldo antes de autorizar.

   ```powershell
   docker compose up -d db
   .\scripts\mcp\revenia.ps1 -Action Authorize
   ```

   Introduzca su correo y contraseña de Revenia. La autorización dura 30 días. Sanctum guarda la huella de la credencial y Windows protege el secreto con DPAPI para el usuario y la copia del proyecto. Una autorización nueva sustituye a la anterior. Si ya tenía una credencial vigente en esta misma copia y cuenta de Windows, actualice la ruta del lanzador en el cliente. La credencial conserva su vencimiento.

4. Conecte o reinicie el servidor en su cliente y consulte su catálogo de herramientas (`tools/list`). El catálogo es público en el transporte local. Leer datos requiere autorización.

| Herramienta | Consulta |
| --- | --- |
| `resumen_cobranza` | Suma y número de cobros aplicados entre `from` y `to` inclusive, junto al saldo de cartera vigente en el momento de la consulta. Devuelve moneda, período, instante de cálculo y zona `America/Lima`. Predetermina el mes actual y PEN. Para USD solicita otro resumen. |
| `listar_comprobantes_pendientes` | Facturas y boletas aceptadas con saldo positivo. Admite `q` para cliente o número, `currency`, `due_from`, `due_to`, `situation`, `sort` y `page`. Usa los tramos de antigüedad de Cuentas por cobrar, páginas de 20 y enlaces internos al detalle. |
| `consultar_comprobante` | Recibe el UUID `id` y devuelve identificación, estado de emisión simulada e importe original. Para ventas aceptadas incluye créditos, reservas, pagos, saldo y revisión. Si hay una transferencia a Odoo, devuelve su estado, fecha y referencia registrados en Revenia. |

Los importes se expresan en céntimos de la moneda indicada. Los cobros pertenecen a clientes de la empresa. El dueño, emisor y contador activos consultan su propia empresa según la política de comprobantes. Cada llamada vuelve a validar el acceso y se ejecuta en una transacción PostgreSQL de solo lectura. El registro local `backend/storage/logs/mcp-AAAA-MM-DD.log` guarda el nombre de la herramienta, el resultado y la duración.

Para revocar el acceso, ejecute el siguiente comando desde la raíz y vuelva a identificarse. La siguiente consulta comprobará la revocación incluso si el cliente continúa conectado.

```powershell
.\scripts\mcp\revenia.ps1 -Action Revoke
```
