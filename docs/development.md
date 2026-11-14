# Desarrollo local

## Requisitos y primera instalacion

Docker con Compose y PowerShell. PHP 8.4, PostgreSQL 17, Redis 7.4 y Node 22 se ejecutan en contenedores. Para navegador E2E se necesita Chrome y Node host compatible con frontend/package.json. No se comparten node_modules instalados en Windows con Linux.

Ejecutar desde una copia nueva:

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

La copia de .env y generacion de APP_KEY son exclusivas de la primera instalacion. Las credenciales Compose y del seeder son publicas, sinteticas y solo para desarrollo local. Los puertos se vinculan a localhost. No reutilizarlas para publicar una instancia.

Interfaz http://localhost:5173; API http://localhost:8000; Mailpit http://localhost:8025. El frontend usa proxy para /api, /login, /logout y /sanctum. SANCTUM_STATEFUL_DOMAINS debe incluir el origen local. Compose fija PostgreSQL/Redis/SMTP y el ejemplo conserva los prefijos operativos heredados facturly. No cambiar name: facturly ni los volumenes para renombrar la marca.

Worker y scheduler se incorporan mediante compose.override.yaml. Todos montan backend y comparten disco privado, base y Redis. El worker consume default, emissions, documents y payments. Horizon global no esta autorizado para roles de empresas; diagnosticar mediante logs.

## Reinicio y actualizacion

Reinicio: `docker compose up -d`. Detener sin borrar datos: `docker compose stop`. No regenerar APP_KEY. No usar migrate:fresh, db:wipe ni down -v como arranque normal.

Actualizar una copia existente: conservar .env; instalar Composer/npm desde lockfiles; ejecutar `php artisan migrate --force`; reconstruir frontend si se usa dist y recrear worker/scheduler para cargar codigo nuevo con los mismos prefijos. Antes de migraciones en una instancia con datos propios se requiere una copia de seguridad y revision del cambio.

```powershell
docker compose exec -T app composer install --no-interaction --prefer-dist
docker compose exec -T frontend npm ci --ignore-scripts
docker compose exec -T app php artisan migrate --force
docker compose restart worker scheduler
```

## Pagos y demo

El flujo normal funciona sin credenciales externas: login, catalogos, borradores, emision simulada, PDF, Mailpit, plan demo y dashboard. Cobranza externa queda deshabilitada hasta configurar las variables MP_* de ejemplo. Nunca colocar tokens en Git ni compartirlos en capturas.

El E2E usa un PaymentGateway sintetico exclusivo de su imagen; no es un modo comercial ni una prueba de Mercado Pago externo. `node scripts/run-integral-e2e.cjs` crea un proyecto separado con puertos 8001/5174/8026 y retira sus contenedores al terminar, conservando el volumen. Requiere puertos libres.

Las instrucciones interactivas anteriores se verifican en PowerShell. Los jobs Linux de CI son otra ruta comprobable; no se presentan comandos shell no ejecutados como validacion local.

El timeout de Composer se amplía a 1200 segundos para extraer dependencias en directorios compartidos de Windows. No cambia las versiones del lockfile ni desactiva verificaciones.
