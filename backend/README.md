# Backend de Revenia

API Laravel 13 sobre PHP 8.4, PostgreSQL y Redis. Sesion y Sanctum, permisos por empresa, importes en centavos, emision simulada, PDF privado, correo, cobranza y cuotas transaccionales.

Consultar el [README de instalacion](../README.md) y el [informe de validacion](../docs/testing/test-report.md). Ejecutar comandos Docker desde la raiz del repositorio. Las pruebas con base se lanzan mediante `scripts/test-backend.ps1`; nunca sobre la base de demostracion.

Contratos y decisiones: [docs](../docs/). La emision no se envia a SUNAT. Los pagos externos permanecen deshabilitados sin configuracion sandbox autorizada. No existe todavia el dominio de notas de credito.
