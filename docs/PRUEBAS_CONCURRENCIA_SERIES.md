# Concurrencia real de correlativos

Verificado el 10 de noviembre de 2026 en PostgreSQL 17 con Docker.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-series-concurrency.ps1
```

El script genera un nombre facturly_series_testing_<32 hex>, crea esa base vacía
con propietario facturly_test y la elimina en finally. Nunca limpia facturly ni
facturly_testing. Ejecuciones distintas usan bases distintas; la suite PHPUnit
puede usar facturly_testing sin compartir fixtures o limpieza.
El bootstrap PHP valida APP_ENV, driver, nombre esperado, current_database() y
current_user antes de migrar o escribir. Usa ruta de caché de configuración aislada.

El coordinador migra exclusivamente esa base y crea empresa/serie en autocommit,
fuera de RefreshDatabase. Verifica un rollback completo y luego inicia cuatro
procesos PHP con cuatro conexiones PostgreSQL. Cada proceso comprueba que ve el
fixture confirmado y anuncia readiness. Una barrera libera a todos cuando están
listos. Cada uno realiza cinco asignaciones en transacciones independientes y
mantiene el bloqueo 50 ms para que las operaciones se solapen. Se verifican
cuatro pg_backend_pid distintos, 20 números únicos exactamente 1..20 y contador 20.

Resultado observado:

```json
{
  "result": "PASS",
  "database": "facturly_series_testing_5c0709e339c04e29ac1153864bbbc428",
  "independent_connections": 4,
  "workers": 4,
  "assignments": 20,
  "unique_numbers": 20,
  "first": 1,
  "last": 20,
  "final_counter": 20,
  "full_rollback": "PASS"
}
```

La salida final DROP DATABASE confirmó limpieza. No se consumieron números de
desarrollo ni se crearon comprobantes ficticios. Los procesos tienen timeout,
las conexiones lock_timeout/statement_timeout, y el coordinador detiene procesos
pendientes y elimina archivos de barrera en finally incluso ante excepciones.
El wrapper elimina exclusivamente la base generada incluso si falla PHP.

Esta prueba demuestra asignación concurrente de la primitiva, no emisión
idempotente, concurrencia HTTP de documentos ni capacidad de carga de producción.
Las pruebas de validación, permisos, agotamiento y rollback de savepoint están
en DocumentSeriesTest, ReserveNextNumberTest y DocumentNumberTest.
