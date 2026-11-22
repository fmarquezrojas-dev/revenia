# Respaldo y restauración

Herramienta del operador de plataforma para Revenia local en Windows. El paquete reúne información de todas las empresas, incluidos clientes, documentos, pagos, sesiones y conexiones cifradas. Los usuarios de las empresas no tienen acceso a esta herramienta desde Revenia.

## Antes de la primera copia

Usa **Windows PowerShell 5.1**, Git y Docker Desktop con el motor Linux local y Compose v2. Abre PowerShell en la raíz de esta copia. El origen usual es el proyecto Compose `facturly`, con PostgreSQL 17 en `db`, el backend en `app`, Horizon en `worker` y el planificador en `scheduler`. El script comprueba la identidad efectiva de la base mediante Laravel y PostgreSQL. Para un nombre de proyecto personalizado añade `-SourceProject nombre`. La aplicación y la base deben estar en ejecución al identificar el origen. Respeta el estado previo de los procesos que ya estuvieran detenidos.

Antes de confirmar la pausa, guarda en tu gestor de secretos la **APP_KEY original**, las claves anteriores si configuraste `APP_PREVIOUS_KEYS`, la configuración confidencial de `backend/.env` y las claves del despliegue. Laravel necesita las claves originales para descifrar el token y la firma del comercio y las credenciales del conector Odoo. Conserva también los accesos de PostgreSQL, SMTP, Mercado Pago y la configuración de destinos autorizados que emplee tu instalación. El visor funciona sin esas claves. La migración aislada usa una clave efímera y no descifra ni vuelve a cifrar registros.

Conserva por separado la revisión exacta del código, los archivos locales pendientes de Git, las migraciones, los archivos de bloqueo de dependencias y los complementos. El manifiesto registra rama, SHA y estado del árbol. Con `dirty: true`, el SHA por sí solo es insuficiente para reconstruir la instalación. Incluye en tu copia privada de fuentes la consulta `backend/app/Actions/Incidents/operations.sql`. Las imágenes de PHP y PostgreSQL se identifican por su ID. Consérvalas si necesitas reproducirlas exactamente en otro equipo.

## Primer respaldo

Desde la raíz, en la misma sesión donde harás la restauración:

```powershell
$copia = .\scripts\Respaldo-Revenia.ps1 -Action Backup
```

El comando muestra el proyecto, la base, los discos, los procesos que pausará y la ruta de salida. Cierra las sesiones de trabajo, espera a que terminen las operaciones en curso y detén cualquier otro cliente, tarea programada o proceso del host que pueda escribir en la base o en los archivos privados. Mantén esta ventana de exclusividad hasta que termine la copia. Para continuar, escribe **RESPALDAR**. Puedes cancelar antes de esta confirmación. El script impide que se solapen dos respaldos del mismo origen, identifica los contenedores por ID, detiene los escritores activos con un plazo de salida de 120 segundos y rechaza otros clientes SQL, procesos auxiliares y cierres forzados. Los demás servicios conservan su estado.

Durante la pausa obtiene un inventario con una transacción de solo lectura, ejecuta `pg_dump` en formato personalizado y empaqueta los archivos mediante un proceso sin red y con los montajes de origen en solo lectura. Vuelve a leer el ZIP, coteja los PDF con PostgreSQL y compara un segundo inventario. Tras reanudar los escritores, lee el volcado completo con `pg_restore` sin conectarse a ninguna base. Solo entonces calcula las sumas y publica el paquete. La exclusión de todos los escritores abarca tanto SQL como el sistema de archivos. El volcado circula entre Windows y Docker como bytes. El script intenta reanudar los escritores que estaban activos incluso cuando falla una comprobación. Si se interrumpe Windows, revisa en Compose los servicios de la pausa y reanuda solo los que estaban activos.

La carpeta final se crea por defecto en **`%LOCALAPPDATA%\Revenia\Respaldos\revenia-…`**. Puedes pasar, por ejemplo, `-Destination 'D:\Respaldos\Revenia'`. Usa un disco NTFS local fuera del repositorio con espacio para el volcado, los PDF y una segunda copia restaurada. Se crean permisos de Windows para tu cuenta y SYSTEM. El paquete contiene datos en claro. Conserva también una copia cifrada en un medio distinto y guarda la huella del manifiesto por un canal independiente. La suma que viaja con el paquete detecta daños accidentales. La huella custodiada permite comprobar su procedencia.

| Archivo | Contenido |
| --- | --- |
| `database.dump` | Base lógica completa de Revenia, con datos, tablas, índices, restricciones, secuencias, auditoría y registro de migraciones |
| `private.zip` | Archivos de los discos privados local y de documentos, incluidas versiones anteriores de los PDF. Se conservan los nombres relativos bajo `local/` y `documents/` |
| `files.json` | Ruta lógica, bytes y SHA-256 de cada archivo privado |
| `inventory.ndjson` | Migraciones aplicadas con su lote, empresas, recuentos de tablas y por empresa, secuencias, relaciones y referencias PDF |
| `manifest.json` y `manifest.sha256` | Origen, versiones, estado del código, migraciones aplicadas y pendientes respecto al código, inventarios, tamaños, duración y sumas del paquete |

Una carpeta **`.partial`** identifica una copia incompleta y la restauración la rechaza. Si faltaban PDF o había relaciones incorrectas en el origen, consulta `integridad-incompleta.json` cuando esté disponible. Conserva la carpeta con la misma protección que el paquete. Corrige el problema y crea una **copia nueva**. El resultado de PowerShell contiene `$copia.Package` y `$copia.ManifestHash`.

## Alcance de la recuperación

- **PostgreSQL.** Conserva empresas, usuarios, clientes, series, comprobantes y notas, pagos de clientes y suscripciones, outbox de emisión/PDF/correo, trabajo durable de pagos, auditoría, seguimiento de Incidencias y, si su migración estaba aplicada, conexiones, correspondencias, transferencias y eventos de Odoo. La bandeja de Incidencias se calcula sobre las fuentes que existían. El volcado usa el propietario de la base nueva al recuperar. Usuarios globales de PostgreSQL, privilegios del clúster y tablespaces personalizados se administran aparte.
- **Archivos.** El origen estándar es `backend/storage/app/private`, con los PDF en `documents`. Se comprueba la ruta efectiva de `DOCUMENT_STORAGE_ROOT`. Para una ubicación externa al disco privado se requiere un montaje dedicado accesible desde `app`. El entorno aislado guarda ambos discos en su propio volumen y conserva la asociación con los registros históricos.
- **Redis y Horizon.** Las colas de transporte, bloqueos, caché y métricas de Horizon son transitorios. La recuperación conserva las solicitudes durables, reservas y resultados en PostgreSQL. No se reproducen mensajes de Redis, correos de Mailpit ni trabajos al abrir la copia. Antes de reanudar una instalación operativa hay que revisar resultados externos inciertos con su proveedor. Una copia local no permite deducir que un pago se recibió o un correo llegó.
- **Odoo.** Revenia conserva la clave de origen, la identidad del destino, el ID externo observado, el resultado y los intentos que constaban en su base. La instalación de Odoo necesita **su propio respaldo coherente** de PostgreSQL y del filestore de esa base, además de módulos, configuración y secretos. En el perfil opcional están en los volúmenes de Odoo `odoo_database` y `odoo_files` (filestore bajo `/var/lib/odoo/filestore/<base>`). Este procedimiento no los monta. El conector permanece desactivado en recuperación.

## Restaurar y abrir la copia

```powershell
$recuperacion = .\scripts\Respaldo-Revenia.ps1 -Action Restore -Package $copia.Package -ExpectedManifestHash $copia.ManifestHash
Start-Process $recuperacion.Url
```

Si cambiaste de sesión, usa la ruta del paquete y la huella SHA-256 que guardaste. Para elegir otra ubicación o puerto, añade `-RecoveryRoot 'D:\Revenia\Recuperaciones' -Port 18081`. Por defecto se crea **`%LOCALAPPDATA%\Revenia\Recuperaciones\revenia-restore-…`** y el visor escucha en **`http://127.0.0.1:18080/`**. Al restaurar, elige una clave temporal para el visor. El navegador pide el usuario **operador** y esa clave. El contenedor del visor recibe únicamente su huella de verificación. Las credenciales de las empresas son independientes.

La recuperación usa `compose.recovery.yaml` y, para las instancias nuevas, `compose.recovery.viewer.yaml`. Comprueba el manifiesto y los SHA-256 antes de crear recursos, descarta nombres o puertos ocupados y asigna un proyecto, una base y dos volúmenes nuevos. Antes de iniciar PostgreSQL, un proceso sin red monta ambos volúmenes en solo lectura y exige que estén vacíos. El script verifica también el identificador de propiedad de los recursos, los montajes efectivos, la identidad del clúster, la versión mayor, la configuración regional y la ausencia de objetos anteriores en la base de destino. La importación usa una sola transacción. Un fallo deja el estado y el informe disponibles para diagnóstico. Inicia el siguiente intento con `Restore` en un entorno nuevo.

Se usan las imágenes locales del respaldo y `backend/vendor` de esta copia. Si preparas otro equipo, recupera el código y las dependencias correspondientes a los archivos de bloqueo y carga las imágenes por separado. Si reconstruiste las imágenes, puedes indicar referencias locales explícitas con `-PhpImage` y `-PostgresImage`. La imagen PHP debe incluir PDO PostgreSQL, ZIP y las extensiones del Dockerfile del proyecto. La herramienta no descarga imágenes al restaurar.

El visor de recuperación solo sirve el informe y `/resultado.json` mediante GET/HEAD. La web comercial, login, Horizon y los endpoints de negocio permanecen fuera de este despliegue. PostgreSQL y el servicio de operaciones comparten una red interna. El visor usa otra red del proyecto para publicar únicamente en el puerto de loopback de Windows. El proceso se ejecuta sin privilegios y su perfil seccomp permite aceptar conexiones entrantes y responder. El núcleo bloquea la apertura de conexiones salientes. El visor solo monta el informe, su código y la huella de la clave temporal. El servicio de operaciones espera órdenes locales del operador. No se arrancan workers, scheduler, Redis, SMTP ni Odoo. La interfaz carece de rutas para emitir comprobantes, enviar correo, crear enlaces de pago, recibir webhooks o transferir documentos.

## Consultar y repetir la verificación

```powershell
Get-Content -Raw -Encoding UTF8 $recuperacion.Report | ConvertFrom-Json
.\scripts\Respaldo-Revenia.ps1 -Action Verify -Instance $recuperacion.Instance
```

El informe **`reports\resultado.json`** separa integridad del paquete, importación PostgreSQL, archivos, relaciones, comparación con el respaldo y pruebas funcionales pendientes. **`reports\origen-comprobado.json`** conserva el resultado inicial de la copia exacta. Los inventarios de las verificaciones quedan junto a `instance.json`. Después de un fallo usa la ruta de instancia que el script imprimió al crearla. Desde otra sesión puedes pasar esa ruta directamente a `-Instance`.

La verificación usa transacciones SQL de solo lectura y lee los archivos restaurados. Compara los lotes de migraciones, el inventario de todas las tablas, los recuentos por empresa y las relaciones de la versión respaldada, incluidas las claves compuestas de empresa. Para cada PDF contrasta disco, empresa, comprobante, intento, ruta, bytes y SHA-256. Un grupo de empresa ausente en el recuento de una tabla equivale a cero. El desglose distingue facturas, boletas y notas por estado, pagos de comprobantes y de suscripciones, y seguimientos por proceso. Cada seguimiento de Incidencias se comprueba contra su operación de origen. Una tabla introducida por una migración pendiente es admisible; la ausencia de una tabla exigida por una migración aplicada se informa como problema. Los conteos de una copia posterior a las migraciones se identifican por separado.

Revisa las cifras por empresa, las notas de crédito, los cobros y los documentos que esperabas recuperar. La validación funcional con tu copia corresponde al operador. Solo una ejecución real de restauración y comprobación puede acreditar ese resultado.

## Aplicar las cuatro migraciones en la copia

Primero revisa y conserva el informe del estado exacto. Después, desde la misma raíz:

```powershell
.\scripts\Respaldo-Revenia.ps1 -Action Migrate -Instance $recuperacion.Instance
```

Las operaciones sobre una misma instancia se ejecutan de una en una. El comando vuelve a comprobar el destino y pide escribir **MIGRAR**. Invoca Laravel dentro del contenedor aislado, limitado a `2026_11_20_000002_add_document_payment_terms`, `000003_bind_payment_receivers`, `000004_add_incident_follow_up` y `000005_add_odoo_transfers`. Laravel omite las ya registradas. El resultado posterior exige que figuren las cuatro migraciones y que los recuentos históricos y las referencias PDF coincidan con el inventario del paquete. El verificador solo tiene permiso de lectura sobre los archivos restaurados. Si falla una migración, revisa el estado y repite el mismo comando en la copia. Los workers, el scheduler y las integraciones siguen desactivados.

## Retirar solo la instancia aislada

```powershell
.\scripts\Respaldo-Revenia.ps1 -Action Remove -Instance $recuperacion.Instance
```

Escribe el nombre completo del proyecto de recuperación mostrado en pantalla. El script comprueba el motor, las etiquetas, los montajes y los nombres reservados y retira los contenedores, los dos volúmenes y las redes de esa instancia. Elimina la contraseña generada para esa base y conserva el paquete y los informes externos. También admite retirar recursos parciales de un intento fallido. Evita ejecutar `docker compose down -v` en la raíz de Revenia.

## Archivos de operación

El punto de entrada es [Respaldo-Revenia.ps1](../scripts/Respaldo-Revenia.ps1). La definición del despliegue está en [compose.recovery.yaml](../compose.recovery.yaml). Los mecanismos de copia, inventario, verificación, migración y presentación del informe están en [scripts/recovery](../scripts/recovery/).

