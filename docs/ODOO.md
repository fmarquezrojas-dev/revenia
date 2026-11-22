# Integraciones → Odoo

## Preparar Odoo Community 18.0

El complemento `revenia_bridge` versión `18.0.1.0.0` depende de Facturación (`account`) en Odoo Community 18.0 autohospedado. Puede instalarse en una instancia local de la rama 18.0 o de la imagen oficial `odoo:18.0`, con su propia base PostgreSQL. Añade **el directorio que contiene** la carpeta `revenia_bridge` al `addons_path` del servidor. Con la imagen oficial se puede montar `integrations/odoo` en `/mnt/extra-addons`. Reinicia Odoo, actualiza la lista de aplicaciones e instala el módulo por nombre técnico. Elimina el filtro «Aplicaciones» al buscar módulos técnicos. [Instalación de Odoo 18](https://www.odoo.com/documentation/18.0/administration/on_premise/source.html) · [Módulo oficial de Facturación](https://github.com/odoo/odoo/blob/18.0/addons/account/__manifest__.py).

Como opción local, el archivo [compose.odoo.yaml](../compose.odoo.yaml) monta el complemento y usa volúmenes y PostgreSQL propios para Odoo. Define `ODOO_LOCAL_DB_PASSWORD` en tu entorno de Compose (el archivo `.env` de la raíz o tu sesión). Ejecuta solo cuando quieras iniciar tu instalación:

```powershell
docker compose -f compose.yaml -f compose.override.yaml -f compose.odoo.yaml --profile odoo up -d odoo
```

Abre `http://localhost:8069`, crea tu base con contraseña maestra y cuenta de administración propias, y deja desmarcados los datos de demostración. Con este perfil, `app` y `worker` acceden a `http://odoo:8069`. El nombre de base en Revenia debe coincidir con el que creaste.

En **Ajustes → Usuarios y compañías → Compañías**, el administrador de Odoo registra el UUID mostrado en Revenia en la compañía receptora. Prepara su plan contable y un usuario técnico de facturación con acceso a esa compañía. Genera su clave en la seguridad de su cuenta de Odoo. El identificador numérico de cada registro puede consultarse con **Ver metadatos** en el modo desarrollador de Odoo.

## Autorizar el despliegue

Ejemplo para `backend/.env` cuando el servidor Odoo ya es alcanzable desde los contenedores `app` y `worker`. Sustituye el host y el nombre de base por los de tu instalación. En Docker, `localhost` dentro del trabajador designa al propio contenedor. El perfil opcional de Odoo se inicia por separado.

```dotenv
ODOO_ENABLED=true
ODOO_ENDPOINTS='{"contabilidad":{"url":"http://odoo:8069","database":"contabilidad"}}'
```

El ejemplo HTTP corresponde a una red local de desarrollo. El adaptador exige HTTPS fuera de local y testing, consulta el origen exacto autorizado, bloquea redirecciones y valida TLS. El formulario solo admite alias del despliegue. La clave API se cifra con la clave de Laravel y nunca se devuelve al navegador. Mantén estable `APP_KEY`.

```powershell
docker compose exec -T app php artisan migrate:status
docker compose exec -T app php artisan migrate
docker compose exec -T app php artisan config:clear
docker compose restart app worker scheduler
```

En **Configuración → Integraciones**, **Verificar y guardar** autentica la clave y comprueba la versión, el contrato del complemento, el UUID de empresa de Revenia, la identidad de la base y la compañía seleccionadas. Comprueba permisos de lectura y creación de facturas y líneas y la visibilidad de un diario de ventas y una cuenta de ingresos. Las reglas de registro y parametrizaciones específicas también se aplican al crear cada borrador. **Habilitar transferencias** vuelve a verificar el destino. La primera conexión queda desactivada hasta esta acción.

## Correspondencias y documento histórico

| Origen en Revenia | Registro en Odoo |
| --- | --- |
| UUID del cliente capturado al emitir | Contacto de la compañía o compartido, seleccionado por ID |
| Boleta sin cliente identificado (`anonymous`) y su rectificativa | Contacto genérico elegido por el dueño para esa compañía. Se conserva el carácter anónimo del comprobante |
| PEN / USD | Moneda activa del mismo código y precisión de céntimos |
| NIU / ZZ | Unidad de medida activa |
| Afectaciones 10 / 20 / 30 | Impuestos de venta de la compañía, porcentuales, excluidos del precio, de 18 %, 0 % y 0 %. Configura la identidad fiscal correcta para cada categoría |
| `sales` / `income` | Diario de ventas y cuenta de ingresos de la compañía |

Una correspondencia se actualiza guardando el mismo origen con otro ID. **Quitar** retira esa asociación del destino. Cada conjunto pertenece a una empresa y un destino verificado. Las líneas de factura se crean con descripción y cuenta contable usando los datos aceptados. Conservan los identificadores del producto y línea de Revenia. La integración no requiere crear productos ni clientes en Odoo. El modelo local expresa cantidades en milésimas y precios en céntimos, sin descuentos separados. Se usa descuento cero y se comprueba el descuento devuelto. Configura en Odoo la precisión decimal de las cantidades para admitir tres decimales si tus ventas usan milésimas. Una diferencia de redondeo queda en revisión. El vencimiento proviene del documento y, si la fecha histórica está ausente, el borrador usa su fecha de emisión.

El origen 01 o 03 se transfiere a `account.move` con `move_type=out_invoice`. El origen 07 usa `out_refund`, conserva el motivo y relaciona `reversed_entry_id` con el original. La pestaña **Revenia** del borrador identifica los comprobantes aceptados por el simulador, sin validez tributaria. También contiene la clave, el número y los datos históricos del cliente. La conexión utiliza `/jsonrpc`, los servicios `common.authenticate` y `object.execute_kw`, y los métodos de contrato `revenia_probe`, `revenia_lookup` y `revenia_import`. [API externa de Odoo 18](https://github.com/odoo/documentation/blob/18.0/content/developer/reference/external_api.rst) · [Controlador JSON-RPC](https://github.com/odoo/odoo/blob/18.0/odoo/addons/base/controllers/rpc.py) · [Modelo contable](https://github.com/odoo/odoo/blob/18.0/addons/account/models/account_move.py).

## Estados y recuperación

| Estado | Interpretación |
| --- | --- |
| Sin transferencia registrada | El comprobante no tiene solicitud. El dueño puede seleccionar individualmente un histórico aceptado |
| En cola / Transfiriendo | El outbox conserva la solicitud. Horizon procesa la cola Redis `odoo` con una reserva de 90 segundos. Incidencias muestra una transferencia en curso como demorada si vence esa reserva sin que el trabajador registre el resultado |
| Reintento programado | Falló la comunicación antes de una escritura conocida. El planificador programa nuevos intentos limitados |
| Espera el comprobante original | Transfiere el original al mismo destino. El planificador retoma la nota tras comprobar su referencia externa |
| Resultado externo por confirmar | La llamada pudo haber guardado el borrador. Desde Incidencias el dueño solicita su recuperación por clave |
| Requiere revisión | Comprueba el motivo, las correspondencias o la compañía. Un borrador existente se vuelve a cotejar sin sobrescribir sus cambios |
| Transferencia comprobada | Revenia releyó la referencia en Odoo y verificó compañía, origen, cliente, diario, fechas, moneda, líneas, importes y estado borrador. El estado contable mostrado corresponde a esa lectura |

La clave de origen es `UUID_EMPRESA/UUID_COMPROBANTE`. El outbox crea una transferencia por empresa y documento. En Odoo una restricción SQL única protege la clave incluso si llegan dos creaciones concurrentes. Antes de enviar, el trabajador consulta la clave. Guarda localmente los datos destinados al envío antes de la llamada externa. El complemento comprueba lo que efectivamente calculó Odoo dentro de la transacción de creación. Ante una diferencia, Odoo revierte esa creación. Revenia vuelve a leer el documento antes de dar la transferencia por comprobada.

Desactivar la conexión detiene los nuevos envíos y pausa la cola. Una llamada ya recibida por Odoo puede concluir. Rotar la clave para la misma identidad conserva las referencias. Cambiar de compañía o base envía los pendientes a revisión y requiere habilitación explícita. Cuando consta que aún no pudo crearse un documento externo, el dueño puede **Autorizar nuevo destino** desde el comprobante, después de revisar ambos nombres. Si ya pudo existir un borrador, recupera primero la conexión original y comprueba su referencia. Una operación con resultado incierto queda protegida frente a la reasignación. El historial conserva las compañías e intentos anteriores. Habilitar una conexión no recorre comprobantes históricos.

## Evidencia de la candidata

En Odoo Community 18.0 con `revenia_bridge`, el laboratorio comprobó factura, boleta y nota en borrador y recuperó un documento ya creado tras perderse la respuesta. El operador valida sus propios permisos y mapeos. [Resultados del bloque 11](testing/test-report.md)

## Revisión funcional local

Con datos que elijas, comprueba una factura y una boleta en borrador, una nota cuyo original siga pendiente, una boleta sin cliente identificado con su contacto genérico, un impuesto incorrecto y una respuesta perdida después de guardar. Coteja líneas, total, compañía y referencia en ambos sistemas. Al repetir una solicitud debe permanecer un único borrador por clave. Comprueba también la separación de dos empresas, una rotación de credenciales y la revisión explícita de pendientes al cambiar de destino. La configuración y las pruebas con Odoo quedan a cargo del operador local.
