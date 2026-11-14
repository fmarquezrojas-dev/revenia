# Verificacion del alcance de portafolio

## Evidencia disponible

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

Sin cobertura porcentual medida, sin Larastan configurado y sin certificacion de seguridad. Sandbox externo y notas de credito no ejecutables siguen declarados en [limites](known-issues.md).

## Verificacion previa a publicacion

Backend completo: 373 pruebas, 1769 aserciones. Pint: 183 archivos. Frontend: 153 pruebas, lint, TypeScript/build y audit correctos en la imagen E2E. Composer audit sin avisos conocidos. Compose local y E2E validos. E2E completo, recuperacion de Redis/worker y PDF real: PASS. Instalacion separada con migraciones y dos ejecuciones de prepare-demo: 14 documentos y dos asignaciones demo, sin duplicacion. Se amplio el timeout de Composer para extraccion en Windows. La ejecucion remota de Actions se comprobara tras publicar.
