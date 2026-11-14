# Interfaz Facturly

Desde la raíz del repositorio:

```powershell
docker compose up -d
docker compose exec -T frontend npm run build
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm test
node scripts/test-users-ui.cjs
powershell -ExecutionPolicy Bypass -File scripts/test-series-concurrency.ps1
```

Abrir http://localhost:5173. Series aparece solo para el dueño y permite crear,
consultar y activar/desactivar, sin editar correlativos. Productos y servicios aparece para los tres roles;
dueño y emisor escriben y contador consulta. El backend debe tener las migraciones
aplicadas mediante `docker compose exec -T app php artisan migrate`.
La prueba concurrente usa una base temporal exclusiva y cuatro procesos PostgreSQL;
ver [evidencia](../docs/PRUEBAS_CONCURRENCIA_SERIES.md).
El script de navegador requiere Chrome local y Node 22+, usa API simulada y no
escribe en la base de desarrollo. FRONTEND_URL y CHROME_PATH son configurables.

Ver [estado y comprobaciones](../docs/ESTADO_PROYECTO.md) y
[contrato Productos](../docs/api/productos.md). Las pruebas PHP se lanzan desde
la raíz con `scripts/test-backend.ps1`, exclusivamente en facturly_testing.

Ver [arquitectura frontend](../docs/ARQUITECTURA_FRONTEND.md) para rutas, layouts,
pruebas responsivas y fallback SPA de despliegue.

Comprobantes: abrir /documents. Owner e issuer crean, editan y eliminan
borradores; accountant consulta. Guardar no emite ni asigna numero.
Ver [contrato y reglas](../docs/api/comprobantes.md) y
[pruebas reales y capturas](../docs/PRUEBAS_BORRADORES.md).
