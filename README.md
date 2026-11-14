# Revenia

**Facturación y cobranza para empresas.** SaaS multiempresa de demostración orientado a pymes peruanas: organiza clientes y productos, prepara comprobantes, sigue su emisión y consulta saldos e indicadores por empresa.

Proyecto de portafolio backend **PHP/Laravel** y full stack **React/TypeScript**. Trabaja con datos sintéticos y un proveedor tributario simulado: **no se conecta ni está certificado por SUNAT**, aplica reglas tributarias simplificadas y no acredita uso comercial. Los pagos son de prueba; la verificación externa sandbox está pendiente.

![Dashboard real de Revenia con datos sintéticos](docs/images/dashboard.png)

No hay demo pública ni video alojado. Puedes explorar el sistema localmente o seguir la [galería](docs/gallery.md) y el [recorrido de tres minutos](docs/portfolio.md).

## Qué permite hacer

- Administrar dos empresas de demostración con acceso separado, clientes, productos y series. Roles **owner**, **issuer** y **accountant**, con permisos comprobados en backend.
- Preparar facturas y boletas en PEN/USD, con cantidades fraccionarias, importes en centavos, IGV simplificado y datos históricos capturados. Guardar un borrador no consume número ni cuota.
- Emitir mediante el simulador, seguir estados y eventos y recuperar fallos sin duplicar el número ni el consumo.
- Descargar PDF privados y solicitar su entrega al correo local **Mailpit**.
- Consultar saldo e historial de cobranza. El adaptador Mercado Pago sandbox existe; el recorrido interno se verifica con un proveedor sintético exclusivo del entorno E2E.
- Consultar plan, consumo y periodos; renovar mediante un pago único de prueba. La renovación vigente se programa para el periodo siguiente.
- Revisar indicadores por empresa, moneda y fechas de Lima, con detalle reconciliable y una interfaz responsive.

**No implementado:** notas de crédito, reservas asociadas y facturación tributaria real. Consulta la [matriz de alcance](docs/ESTADO_PROYECTO.md) antes de evaluar un flujo.

## Arquitectura

Monorepo con API Laravel (`backend/`) y SPA React (`frontend/`). PostgreSQL conserva documentos, solicitudes idempotentes, consumos y outbox. Redis transporta trabajos; Horizon ejecuta workers y el scheduler publica y recupera tareas. Los PDF se guardan en un disco privado compartido y el correo local llega a Mailpit.

La autenticación usa cookies de sesión con Sanctum; el frontend accede a la API a través del proxy de Vite en desarrollo. El aislamiento combina consultas explícitas por empresa, policies y restricciones de integridad. **No se implementa un global scope tenant.**

[Diagramas y responsabilidades](docs/architecture/overview.md) · [Decisiones técnicas con código y pruebas](docs/architecture/decisions.md) · [ADR](docs/README.md#decisiones)

### Decisiones destacadas

1. Importes enteros y cantidades en milésimas para evitar cálculos monetarios con `float`.
2. Numeración y consumo en transacciones con bloqueos PostgreSQL para resolver solicitudes simultáneas.
3. Idempotencia y outbox persistente para separar el commit del negocio de la disponibilidad de Redis.
4. Aislamiento por empresa validado también con identificadores ajenos reales.
5. Estados de resultado incierto y reconciliación para evitar asumir que un timeout equivale a un rechazo.

## Stack

PHP 8.4, Laravel 13, Sanctum, Horizon, PostgreSQL 17, Redis 7.4, Dompdf y Mailpit; React 19, TypeScript 5.8 y Vite 7. PHPUnit 12, Vitest y Testing Library. Docker Compose y GitHub Actions. Las versiones resueltas están en los lockfiles.

## Ejecución local

Requisitos: **Docker con Compose** y PowerShell. Para E2E, Chrome y Node compatible (`^22.22.2`, `^24.15.0` o `>=26`). Las dependencias normales se instalan en contenedores.

En una copia nueva, desde la raíz:

```powershell
Copy-Item backend/.env.example backend/.env
docker compose build
docker compose up -d db redis mailpit
docker compose run --rm --no-deps -e COMPOSER_PROCESS_TIMEOUT=1200 app composer install --no-interaction --prefer-dist
docker compose run --rm --no-deps app php artisan key:generate
docker compose run --rm --no-deps frontend npm ci --ignore-scripts
docker compose run --rm --no-deps app php artisan migrate --force
docker compose up -d
docker compose exec -T app php scripts/prepare-demo.php
```

No sobrescribas un `.env` existente ni regeneres su clave. **Reinicio:** `docker compose up -d`. Para actualizar, conserva la clave y los volúmenes y sigue la [guía de desarrollo](docs/development.md).

| Servicio | URL local |
| --- | --- |
| Interfaz | http://localhost:5173 |
| API | http://localhost:8000 |
| Mailpit | http://localhost:8025 |

Demo Alfa: `owner@alfa.example.test`, `issuer@alfa.example.test` y `accountant@alfa.example.test`. Contraseña **exclusivamente sintética local**: `FacturlyLocal2026!`. No usar estas credenciales para desplegar. [Datos demo y repetición segura](docs/DATOS_LOCALES.md).

La infraestructura conserva el identificador heredado `facturly` para bases, proyecto Compose, cookies y prefijos. Es intencional: renombrarlo crearía recursos distintos. No hace falta Mercado Pago para explorar catálogos, borradores, simulación, PDF, correo y dashboard.

## Pruebas y calidad

```powershell
# Base de testing preparada según docs/testing/test-report.md
powershell -NoProfile -File scripts/test-backend.ps1
docker compose exec -T frontend npm test -- --maxWorkers=2
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run build
# Entorno completo aislado, requiere Chrome y puertos 8001/5174/8026 libres
node scripts/run-integral-e2e.cjs
```

La base efectiva y el usuario se verifican antes de `RefreshDatabase`. Los scripts de concurrencia usan procesos PostgreSQL independientes. E2E comprueba navegador, Redis, interrupción del worker, PDF real y recepción SMTP en Mailpit; no sustituye una verificación externa de Mercado Pago.

[Resultados y alcance de las pruebas](docs/testing/test-report.md) · [Workflow](.github/workflows/ci.yml). No se publica cobertura no medida ni certificación de seguridad. El estado de CI debe consultarse en la ejecución real del repositorio.

## Documentación y estado

[Índice de documentación](docs/README.md) · [API](docs/api/comprobantes.md) · [Modelo de datos](docs/MODELO_DATOS.md) · [Operación y recuperación](docs/operations.md) · [Presentación laboral](docs/portfolio.md)

El siguiente trabajo prioriza la comprobación externa sandbox, el dominio de notas de crédito y una preparación de despliegue separada. No se presenta una cuenta sandbox compartida como una solución comercial para varios comercios. No hay débitos automáticos, reembolsos automáticos ni garantía de entrega al buzón final. [Limitaciones conocidas](docs/testing/known-issues.md).

## Autor y licencia

Proyecto de [fmarquezrojas-dev](https://github.com/fmarquezrojas-dev). Licencia [MIT](LICENSE), copyright 2026 fmarquezrojas-dev, para el código propio. Las dependencias y los recursos de terceros conservan sus titulares, avisos y licencias originales. El cambio de marca no acredita registro ni disponibilidad comercial del nombre.
