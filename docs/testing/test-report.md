# Verificacion del alcance de portafolio

## Bloque 11 · validación de la candidata del 21 de noviembre de 2026

**Decisión: lista con limitaciones documentadas.** El ensayo cubrió PostgreSQL, la recuperación con PDF privados, la cobranza entre módulos y el conector contra Odoo Community. Quedan pendientes Mercado Pago en su sandbox externo y la aceptación del operador con sus usuarios, datos y claves. La emisión fue simulada. La publicación en GitHub corresponde al bloque 12.

### Código y aislamiento

La rama fue `feature/notas-credito-simuladas`. `git rev-parse HEAD`, `git rev-parse origin/main` y `git ls-remote origin refs/heads/main` devolvieron `936e87a918495f4d37ec7fa34163fd242a94dbe5`. Ese SHA identifica el esquema publicado de 16 migraciones. Las pruebas de la candidata incluyen **los cambios locales** de cobranza, incidencias, Odoo, respaldo, MCP, seguimiento, bancos y Estado de cuenta. Al comenzar había 190 entradas en `git status --porcelain -uall`. Se copiaron los 467 archivos versionables de la carpeta abierta, incluidos los nuevos. Estado de cuenta quedó cubierto desde la ruta de cliente, sus consultas de cartera y tesorería y la vista imprimible.

| Contenido identificado | SHA-256 |
| --- | --- |
| Copia de trabajo inicial, incluido el informe histórico | `b3a9efabb22ef718adacfde80fd6c585d37abd810aeafd9173e63b8841ef5bb8` |
| Código y documentación revisados al cierre, 470 archivos, excluidos este informe y su JSON de evidencia | `860a4cb5c42939380793f0b25c4c3f81a5c44712e3b6831bdf195deb846391e9` |

Las huellas usan rutas Git ordenadas, bytes UTF-8 de la ruta, NUL, SHA-256 hexadecimal en minúsculas de los bytes del archivo y salto de línea LF. El registro incluye las huellas individuales de los archivos modificados en este bloque, las 23 migraciones y los resultados medidos. [Evidencia de la candidata](rc11-evidence.json).

Se creó una copia temporal bajo `%TEMP%\rv11-wb5ue461\candidate`, con código local y dependencias de los archivos de bloqueo. Los datos, archivos privados, credenciales de empresa y sesiones de trabajo quedaron fuera de la copia. Se comprobó la identidad efectiva de Laravel y PostgreSQL mediante `current_database()`, `current_user` y `pg_control_system()`, junto con las etiquetas, montajes y redes de Docker. Las migraciones limpias comenzaron con esquemas públicos vacíos.

| Recurso | Identidad efectiva |
| --- | --- |
| Laboratorio principal | `facturly-e2e-b111ca0d7e51`, clúster `7710796596069457958`, rol `facturly_test` |
| Base de los recorridos y del respaldo | `facturly_series_testing_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee` |
| PHPUnit y actualización | `facturly_testing` y `rc_upgrade_final`, dentro del clúster del laboratorio. Las carreras usaron bases temporales individuales |
| Recorrido de Chrome definitivo | `facturly-e2e-11ba5f00e210`, clúster `7710801702123454502`, con almacenamiento, cola y Mailpit propios |
| Restauración definitiva | `revenia-restore-212687e677e5`, clúster `7710802777495359527`. Visor comprobado en `127.0.0.1:18613` |

Docker Desktop estaba detenido. Al iniciarlo, Docker reanudó automáticamente el worker y el scheduler de `facturly` por su política de reinicio. La base y Redis de trabajo siguieron detenidos. Las suites y los comandos de negocio se dirigieron a los recursos de ensayo. El acceso del navegador entró por loopback. La aplicación, los proveedores sintéticos y los datos del laboratorio usaron una red interna. Se retiraron los cinco proyectos de ensayo y se comprobó que quedaban cero contenedores, redes y volúmenes con sus etiquetas. Docker Desktop se devolvió a su estado inicial detenido. El paquete sintético, las capturas, los PDF y los registros con huellas en la evidencia quedan en `%TEMP%\rv11-wb5ue461`. Las imágenes construidas específicamente para la prueba se retiraron. La imagen descargada de Odoo puede reutilizarse.

### Resultados financieros, permisos y recuperación

| Prueba ejecutada | Evidencia |
| --- | --- |
| Estado de cuenta, detalle y cartera | Un abono de 90,00 PEN se distribuyó en 25,00 y 50,00 PEN. Tras revertir la primera aplicación, quedaron 40,00 PEN disponibles. El detalle mostró 50,00 y 0,00 PEN pendientes, cartera y estado de cuenta dieron 50,00 PEN. Ambas aplicaciones y la reversión siguieron en la cronología. |
| Créditos, compromisos, monedas y fechas | Una nota aceptada de 236,00 PEN sobre 1.180,00 PEN dejó un neto de 944,00 PEN. Los 1.000,00 PEN cobrados produjeron 56,00 PEN de exceso. Se probaron reservas, evidencia de cumplimiento, abonos sin identificar, suscripciones, PEN/USD, períodos de movimientos y paginación de pantalla e impresión. |
| Políticas | La suite ejercitó dueño, emisor y contador, asignación, contacto, confirmación y reversión de abonos, conciliación e impresión. Chrome comprobó restricciones de emisión, correo y acceso a usuarios, además de 404 para documento, PDF, eventos y cobros ajenos. |
| Concurrencia PostgreSQL | Dos procesos intentaron reservar la misma línea de crédito con resultados 202/409. Dos procesos intentaron distribuir el mismo abono de 50,00 PEN con resultados 200/409. Quedaron una aplicación, 50,00 PEN imputados y 50,00 PEN de cartera. Se verificaron PID de PostgreSQL distintos. También pasaron las carreras de numeración, edición, última cuota y último dueño activo. |
| Emisión y caída de Redis | La emisión quedó en outbox al caer Redis y se publicó al recuperarlo. Tras interrumpir el worker después de la aceptación del proveedor simulado, el reemplazo obtuvo la aceptación en el segundo intento con un número, un consumo y una llamada de emisión al proveedor. |
| Mercado Pago sintético | Diez avisos repetidos produjeron un pago. Una prueba adicional terminó el proceso después de que el proveedor sintético guardara la preferencia y antes de que Revenia guardara su respuesta. El nuevo proceso recuperó la preferencia original y dos trabajadores procesaron avisos concurrentes sin duplicar el importe. Se comprobó también que dos identificadores de pago realmente distintos se conservan. |
| Odoo real | Odoo Community `18.0-20261112` con `account` y `revenia_bridge`, base `rc_odoo` y usuario de facturación de laboratorio. Una factura de 29,50 USD incluyó 4,50 USD de impuesto. Odoo guardó una boleta de 11,80 USD y se terminó el proceso PHP antes de guardar la referencia en Revenia. El segundo proceso recuperó el borrador por clave de origen. La nota pasó de dependencia a borrador de rectificación por 11,80 USD, vinculado al original. La lectura directa de `account_move` confirmó los ID 3, 4 y 5 y el estado `draft`. Una regresión de PHPUnit cubre por separado la respuesta HTTP perdida y el reintento autorizado. |
| MCP | Un cliente JSON-RPC sobre stdio negoció `2025-03-26` con `mcp:start revenia`. `tools/list` devolvió las tres herramientas. Las tres llamadas autenticadas funcionaron. Resumen y detalle dieron 85,00 PEN, el listado encontró el documento, y los UUID ajeno e inexistente devolvieron el mismo error. La siguiente consulta fue denegada tras revocar la credencial sintética. La extensión del operador y su credencial empresarial quedan para su propia aceptación. |

### Instalación, actualización y restauración

La instalación limpia aplicó las 23 migraciones. Se repitió `migrate:fresh` en la misma base desechable para detectar objetos PostgreSQL que sobreviven al borrado de tablas. En otra base se instalaron las 16 migraciones extraídas directamente de la revisión publicada. Antes de aplicar las siete posteriores se insertaron una empresa, cliente, comprobante aceptado, orden y pago históricos. El comprobante mantuvo 120,00 PEN, el pago aplicado 35,00 PEN y el pendiente 85,00 PEN. Se preservaron los identificadores, relaciones, número, fecha e importes. El vencimiento continuó nulo y el receptor histórico quedó en revisión con `receiver_kind=legacy`. La huella de esos campos coincidió antes y después. Las siete adiciones comprenden notas de crédito, vencimientos, receptores de cobros, incidencias, Odoo, seguimiento y cobros bancarios.

| Comprobación con datos sintéticos | Resultado medido |
| --- | --- |
| Respaldo manual con los escritores en pausa | 8,91 s y 447.039 bytes de contenido. SHA-256 del manifiesto `60f69b5eaccce6e310caafbeacac5a8bf6bd2ab4efdc718be226ca3b3b1cb1dc` |
| Restauración definitiva y verificación independiente | 8,73 s para restaurar y comprobar la copia. Una segunda ejecución de `Verify` pasó. Coincidieron inventario, 8 empresas, 23 migraciones, 85 controles de relaciones, 9 archivos privados y 8 referencias PDF. Cero faltantes o diferencias. |
| Finanzas después de restaurar | Coincidieron las 10 filas de saldos por empresa, documento y moneda generadas por `DocumentBalances::portfolio`, SHA-256 `a28706334c13c2e6b4042628675f7047c7ced69e911daa8d8a4ebad6e9911356`. Un abono de 50,00 PEN conservó dos aplicaciones, una revertida, 25,00 PEN aplicados y 25,00 disponibles. |
| Paquete alterado | Se cambió un byte de otra copia del volcado. `Restore` rechazó el SHA-256 antes de crear recursos. |
| Aislamiento | Solo se ejecutaron PostgreSQL, operaciones y visor. Pruebas HTTP desde Windows dieron 401 al visitante, 200 con credencial de prueba, 405 a un POST de webhook y 404 a una ruta comercial. El proceso del visor recibió `EPERM` al intentar abrir una conexión TCP incluso a su propio puerto. |

### Defectos corregidos y repeticiones

- Una segunda ejecución de PHPUnit reproducía el fallo de `migrate:fresh` por funciones de PostgreSQL supervivientes. Las migraciones de receptores y abonos ahora reinstalan sus funciones con `CREATE OR REPLACE`. Se añadió una regresión que exige una base nueva y compara ambos historiales.
- Docker Desktop aceptaba el mapeo del puerto del visor en una red interna, pero Windows no recibía respuesta. El visor dispone ahora de una red dedicada con publicación exclusiva en loopback, sin privilegios ni capacidades y con un perfil seccomp que bloquea conexiones salientes. PostgreSQL y operaciones conservan la red interna. El procedimiento comprueba la respuesta HTTP antes de anunciar el visor. Las instancias anteriores conservan su procedimiento de verificación y retiro.
- El primer respaldo rechazó la pausa forzada de la aplicación tras el plazo de espera y dejó un directorio `.partial`. Se conservó este control. Se incorporó `init: true` a la aplicación y a los escritores que heredan su servicio. Se repitió el respaldo con los escritores de ensayo creados con esa opción. También se comprobó el apagado de la aplicación después de usarla desde Chrome.
- Se añadieron regresiones para la distribución simultánea de un abono, la igualdad de saldos en las tres superficies y las respuestas perdidas de cobros y Odoo. En la preparación del ensayo se corrigieron la fecha bancaria de la prueba para Lima, un indicador de simulación heredado por PHPUnit y la comprobación de salud de Redis del laboratorio. En el primer recorrido Chrome agotó la espera en la pantalla de suscripción. Después se comprobó esa ruta y se repitió el recorrido completo en otro clúster desde cero. Pasaron las cinco jornadas, incluidos 360, 768 y 1280 píxeles, sin excepciones de JavaScript.

### Controles técnicos y comandos ejecutados

Windows PowerShell 5.1, Docker Engine 29.9.2 y Compose 5.6.1. PHP 8.4.28, Laravel 13.40.0, PHPUnit 12.5.43, Composer 2.11.2, PostgreSQL 17.11, Node 22.23.4 en el frontend y 24.15.0 para el cliente de Chrome en Windows. Se utilizó un entorno Python temporal para `openapi-spec-validator 0.7.2`. Los archivos de bloqueo del producto se conservaron.

Los comandos de contenedor de la tabla se ejecutaron mediante `docker exec` dirigido al ID comprobado del laboratorio. Los de concurrencia recibieron `DB_DATABASE=facturly_series_testing_<UUID sin guiones>` y el rol `facturly_test`. Los scripts de navegador recibieron el proyecto de ensayo y un directorio externo de evidencia a través de `E2E_PROJECT` y `E2E_EVIDENCE_DIR`.

| Comandos o punto de entrada | Resultado |
| --- | --- |
| `php vendor/bin/phpunit --no-progress`, `php vendor/bin/pint --test` | 407 pruebas, 2.290 aserciones. Pint pasó sobre 237 archivos. |
| `composer validate --strict`, `composer check-platform-reqs`, `composer audit --locked --format=json`, `npm audit --json` | Manifiesto y plataforma correctos. Los registros consultados devolvieron cero avisos para las versiones bloqueadas. |
| `npm run lint`, `npm run build`, `npm test -- --maxWorkers=2` | ESLint, TypeScript y Vite correctos. Vitest pasó 161 pruebas en 14 archivos. |
| `python -m openapi_spec_validator docs/api/openapi.json` y `docs/api/openapi-products.json` | Ambos contratos OpenAPI 3.1 válidos. El catálogo principal llega al bloque Odoo. Las rutas posteriores de seguimiento, bancos y estado de cuenta se probaron contra Laravel. |
| `php tests/Concurrency/{issuance,payments,series,users,documents,subscriptions,migrations}.php` | Se invocó cada archivo individualmente en una base aislada. Se contrastaron resultados de dominio y conexiones independientes. |
| `php artisan migrate --force --realpath --path=/tmp/published`, `php artisan migrate --force`, `php tests/EndToEnd/fixtures.php` | Instalación, actualización y comparación con filas históricas correctas. |
| `node scripts/test-integral-ui.cjs`, `node scripts/test-integral-recovery.cjs`, `php tests/EndToEnd/pdf-samples.php`, `node scripts/test-integral-pdf.cjs` | Interfaz, Redis, reinicio y PDF reales. Las tres muestras de PDF incluyeron una factura de 50 líneas y 8 páginas. En la repetición se añadió captura de excepciones de Chrome a la copia del script. |
| `php artisan mcp:start revenia`, JSON-RPC `initialize`, `tools/list`, `tools/call` | Descubrimiento y autorización real de Laravel comprobados con el cliente stdio de ensayo. |
| `odoo shell -d rc_odoo`, `OdooGateway::verify`, `OdooTransfers::run`, lectura de `account_move` | Contrato de Odoo Community comprobado por JSON-RPC con el módulo instalado y dos procesos de transferencia independientes. |
| `scripts/Respaldo-Revenia.ps1 -Action Backup -SourceProject facturly-e2e-b111ca0d7e51`, `-Action Restore -ExpectedManifestHash …`, `-Action Verify`, `-Action Remove` | Se usaron directorios temporales fuera del repositorio. En la restauración definitiva se usó `-Port 18613`. Se validaron la sintaxis de PowerShell 5.1, el modelo Compose y los recursos efectivos. [Procedimiento del operador](../RESPALDO_Y_RESTAURACION.md). |

### Aceptación a cargo del operador

1. Custodiar la `APP_KEY` original, respaldar la instalación antes de migrarla y restaurar el paquete propio en una instancia vacía. Comparar las empresas, saldos y PDF que conoce.
2. Elegir un cliente por moneda. Contrastar detalle, cartera, estado de cuenta e impresión antes y después de distribuir y revertir un abono de prueba. Verificar remanente, vencimiento y tratamiento de una nota de crédito.
3. Probar dueño, emisor y contador con cuentas propias, incluida una empresa distinta. Revisar agenda, asignación, impresión e incidencias. Autorizar y revocar una credencial local de Codex.
4. Si utilizará Mercado Pago, comprobar el sandbox externo con sus credenciales y notificaciones de prueba. Si utiliza Odoo, validar compañía, correspondencias contables y revisión de borradores en su instalación. La prueba real de Odoo se circunscribió al laboratorio.

## Bloque comercial de notas 07 · 20 de noviembre de 2026

Rama local `feature/notas-credito-simuladas`. Las notas devuelven líneas completas de una factura o boleta aceptada, con moneda, impuestos y redondeos históricos. El borrador deja las líneas disponibles. La primera emisión reserva líneas, importe, número y una unidad de cuota en una transacción. Un rechazo definitivo libera el importe y conserva el número y el consumo. Un resultado incierto retiene la reserva durante la recuperación.

| Caso en PEN | Original | Crédito aceptado | Reserva | Neto | Pago aplicado | Pendiente | Exceso en revisión |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Antes de la nota | 1.180 | 0 | 0 | 1.180 | 1.000 | 180 | 0 |
| Emisión solicitada | 1.180 | 0 | 236 | 1.180 | 1.000 | 180 | 0 |
| Nota aceptada | 1.180 | 236 | 0 | 944 | 1.000 | 0 | 56 |
| Mismo ajuste sin pago previo | 1.180 | 236 | 0 | 944 | 0 | 944 | 0 |

**Migración local.** `php artisan migrate --force --no-interaction` aplicó `2026_11_20_000001_add_credit_notes` sobre la base existente. Antes y después había 13 comprobantes 01/03. La huella MD5 de sus identificadores y totales ordenados por ID fue `d85d95361d105fa55e5cd88aab943a77` en ambas consultas. Las claves compuestas vinculan empresa, moneda, documento e ítems. El índice único parcial impide ocupar dos veces una línea. El rollback se detiene si ya existen notas.

| Comando ejecutado | Resultado |
| --- | --- |
| `powershell -NoProfile -File scripts/test-backend.ps1` | 382 pruebas, 1.931 aserciones correctas. PostgreSQL 17 aislado, base y rol de testing. La ejecución completa final usó las dependencias Linux de la imagen y el código del workspace |
| `powershell -NoProfile -File scripts/test-backend.ps1 -Filter CreditNotesTest` | 9 pruebas, 161 aserciones correctas. Incluyen nota total, USD con cantidad fraccionaria original, redondeo al céntimo, cambio de catálogo y rechazo de FK entre empresas |
| `powershell -NoProfile -File scripts/test-issuance-concurrency.ps1` | PASS. Dos conexiones PostgreSQL compitieron por la misma línea. Respuestas 202 y 409, una reserva, un número y un consumo. El script retiró su base temporal |
| `php vendor/bin/pint --test` | Correcto en todo el backend |
| `npm test -- --maxWorkers=2`, `npm run lint`, `npm run build` | 154 pruebas React en 11 archivos, ESLint y compilación TypeScript/Vite correctos en la imagen E2E. Una ejecución sobre el montaje Windows agotó el tiempo de arranque de procesos Vitest. La imagen utiliza las dependencias del lockfile en Linux |
| `node scripts/run-integral-e2e.cjs` | PASS en Chrome. El recorrido creó el borrador, lo emitió con el worker, leyó del PDF el identificador original y S/ 236, y cotejó cobranza y dashboard. Pasaron además la recuperación de Redis/worker y las muestras PDF |
| `docker compose exec -T app php artisan demo:credit-note` (dos veces) | La misma factura local FR01-00000001 y un único pago histórico sintético. La consulta autenticada de la web confirmó líneas de S/ 944 y S/ 236, S/ 180 pendientes y PDF disponible |

El pago tardío se prueba con un checkout abierto antes de emitir la nota y una confirmación aprobada posterior. Se conserva el importe validado y el exceso aparece en la cartera para revisión. La respuesta perdida se reproduce con `timeout_after`. La recuperación consulta la misma identidad y aplica la reserva una vez.

[Reproducir la devolución en la web](../../README.md#demo-de-devolución-por-s-236) · [Captura del dashboard en el E2E](../images/nota-credito-dashboard.png) · [Límites vigentes](known-issues.md).

## Evidencia anterior al bloque de notas 07

La estabilizacion previa verifico 373 pruebas backend con 1769 aserciones; el frontend completo tiene 153 pruebas en 11 archivos. El cambio de marca posterior ejecuto 18 pruebas afectadas y repitio el recorrido E2E completo. Los resultados de publicacion se registran abajo y deben contrastarse con el commit y GitHub Actions, no inferirse del texto.

Las pruebas separan: dominio/HTTP en PHPUnit; PostgreSQL real; procesos independientes de concurrencia; React en Vitest; navegador Chrome con API real; Redis y worker reales; Dompdf y Mailpit. El gateway de pagos del E2E es sintetico y no demuestra integracion externa.

## Backend

Con los servicios locales preparados, crear rol/base de testing solo si no existen. No modificar credenciales de roles ya existentes:

```powershell
docker compose exec -T db psql -U facturly -d postgres -c "CREATE ROLE facturly_test LOGIN PASSWORD 'facturly_test_local';"
docker compose exec -T db psql -U facturly -d postgres -c "CREATE DATABASE facturly_testing OWNER facturly_test;"
powershell -NoProfile -File scripts/test-backend.ps1
docker compose exec -T app vendor/bin/pint --test
docker compose exec -T app composer audit
```

El script fuerza APP_ENV=testing, pgsql, facturly_testing/facturly_test y una ruta propia de cache. DatabaseTestCase verifica current_database/current_user antes de RefreshDatabase. La instancia local del usuario no se cambia a testing.

## Frontend y E2E

```powershell
docker compose exec -T frontend npm ci --ignore-scripts
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm test -- --maxWorkers=2
docker compose exec -T frontend npm run build
docker compose exec -T frontend npm audit
node scripts/run-integral-e2e.cjs
```

Chrome usa CHROME_PATH si no esta en la ubicacion habitual. E2E utiliza puertos separados, base nueva, cache/sesiones/colas propias, transporte Mailpit y almacenamiento privado propio. El ejecutor termina con error si falla un paso; retira contenedores, conserva volumenes y no borra datos del usuario. Para evidencias locales separadas definir E2E_EVIDENCE_DIR.

Recorridos comprobados: login/CSRF/logout/expiracion; cliente/producto/borrador/edicion/emision/PDF/correo; pago duplicado con adaptador; ultimo cupo y renovacion futura; roles y empresa ajena; 360/768/1280 px. Recuperacion mata solo el worker E2E tras aceptar el simulador y espera los leases reales: una llamada al proveedor, un numero y un consumo. PDF incluye 50 lineas, Unicode, PEN/USD y totales.

## Concurrencia

```powershell
powershell -NoProfile -File scripts/test-users-concurrency.ps1
powershell -NoProfile -File scripts/test-series-concurrency.ps1
powershell -NoProfile -File scripts/test-documents-concurrency.ps1
powershell -NoProfile -File scripts/test-issuance-concurrency.ps1
powershell -NoProfile -File scripts/test-payment-concurrency.ps1
powershell -NoProfile -File scripts/test-subscription-concurrency.ps1
```

Cada script crea una base temporal con UUID y barreras entre procesos, valida identidad efectiva y elimina solo su propia base. No se llama concurrencia a peticiones secuenciales.

## CI y limites

[Workflow](../../.github/workflows/ci.yml): backend con PostgreSQL, Composer/Pint/PHPUnit; frontend con npm ci/lint/test/build/audit; integracion con imagenes nuevas y Chrome. Sin continue-on-error. Los artefactos de ejecucion estan en Actions y no contienen entornos privados.

Sin cobertura porcentual medida, sin Larastan configurado y sin certificacion de seguridad. El sandbox externo y los límites de las devoluciones siguen declarados en [limites](known-issues.md).

## Verificacion previa a publicacion

Backend completo: 373 pruebas, 1769 aserciones. Pint: 183 archivos. Frontend: 153 pruebas, lint, TypeScript/build y audit correctos en la imagen E2E. Composer audit sin avisos conocidos. Compose local y E2E validos. E2E completo, recuperacion de Redis/worker y PDF real: PASS. Instalacion separada con migraciones y dos ejecuciones de prepare-demo: 14 documentos y dos asignaciones demo, sin duplicacion. Se amplio el timeout de Composer para extraccion en Windows. La ejecucion remota de Actions se comprobara tras publicar.
