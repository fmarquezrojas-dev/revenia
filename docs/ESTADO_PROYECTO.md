# Estado del proyecto

Revenia, revision de portafolio del 2026-11-13. Se publica el alcance existente, sin afirmar integracion tributaria real ni uso comercial.

| Area | Estado | Alcance |
| --- | --- | --- |
| Sanctum, empresas, usuarios y roles | Implementado y verificado | Sesion, actividad, permisos, limite cinco y ultimo owner |
| Aislamiento multiempresa | Implementado y verificado | Consultas explicitas, policies, FKs compuestas e IDs ajenos |
| Clientes, productos y series | Implementado y verificado | Validacion, estados, monedas y contador protegido |
| Borradores y calculos | Implementado y verificado | Version, snapshots, PEN/USD, 1-50 lineas e importes enteros |
| Emision e idempotencia | Implementado y verificado | Proveedor simulado, numeracion transaccional y cuota |
| Outbox y recuperacion | Implementado y verificado | Redis real, lease, interrupcion y resultado tardio |
| PDF y correo | Implementado y verificado localmente | Dompdf real, descarga privada y SMTP Mailpit; no correo externo |
| Notas de credito | No implementado | Sin notas 07, reservas ni acreditacion parcial/total |
| Cobranza | Disponible con limitaciones | Contratos y adaptador interno probados; sandbox externo pendiente |
| Planes y cuotas | Disponible con limitaciones | Concurrencia y periodos probados; pago externo pendiente |
| Dashboard y responsive | Implementado y verificado | Moneda, fechas Lima, detalle, permisos y 360/768/1280 px |
| Dataset demo | Implementado y verificado | Identidades estables y carga repetible |
| CI | Configurado | Consultar ejecuciones reales en GitHub Actions; no inferir verde del YAML |

Roadmap corto: verificar checkout/webhook sandbox externo; implementar notas y reservas en un bloque propio; preparar despliegue y medir carga representativa. [Testing](testing/test-report.md), [limites](testing/known-issues.md), [CHANGELOG](../CHANGELOG.md).
