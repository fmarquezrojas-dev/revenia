# Estado del proyecto

Revenia, revisión comercial del 2026-11-20. Se publica el alcance existente, sin afirmar integracion tributaria real ni uso comercial.

| Area | Estado | Alcance |
| --- | --- | --- |
| Sanctum, empresas, usuarios y roles | Implementado y verificado | Sesion, actividad, permisos, limite cinco y ultimo owner |
| Aislamiento multiempresa | Implementado y verificado | Consultas explicitas, policies, FKs compuestas e IDs ajenos |
| Clientes, productos y series | Implementado y verificado | Validacion, estados, monedas y contador protegido |
| Borradores y calculos | Implementado y verificado | Version, snapshots, PEN/USD, 1-50 lineas e importes enteros |
| Emision e idempotencia | Implementado y verificado | Proveedor simulado, numeracion transaccional y cuota |
| Outbox y recuperacion | Implementado y verificado | Redis real, lease, interrupcion y resultado tardio |
| PDF y correo | Implementado y verificado localmente | Dompdf real, descarga privada y SMTP Mailpit; no correo externo |
| Notas de crédito simuladas | Implementado y verificado | Facturas y boletas aceptadas, líneas completas, reserva al emitir, liberación por rechazo y aplicación única. PDF y navegación a origen |
| Cobranza | Disponible con limitaciones | Créditos aplicados, reservas, pagos tardíos y exceso en revisión. Sandbox externo pendiente |
| Cobros por empresa y conciliación | Implementado, validación funcional y externa pendiente | Conexión de vendedor verificada, receptor por orden, webhook por empresa y revisión de pagos desde cartera |
| Cuentas por cobrar | Implementado, validación funcional pendiente | Condiciones y vencimiento por venta, cartera por moneda, filtros y tramos de atraso a fecha de Lima |
| Acceso recordado y autocompletado | Implementado, validación funcional pendiente | Formulario compatible con el gestor del navegador, recuerdo voluntario del guard web y retorno a la ruta interna autorizada |
| Planes y cuotas | Disponible con limitaciones | Concurrencia y periodos probados; pago externo pendiente |
| Dashboard y responsive | Implementado y verificado | Ventas, notas y pagos por periodo de Lima. Cartera actual por moneda y detalle por documento |
| Dataset demo | Implementado y verificado | Identidades estables y carga repetible |
| CI | Configurado | Consultar ejecuciones reales en GitHub Actions; no inferir verde del YAML |

Roadmap corto: verificar checkout/webhook sandbox externo; preparar despliegue y medir carga representativa. [Testing](testing/test-report.md), [limites](testing/known-issues.md), [CHANGELOG](../CHANGELOG.md).
