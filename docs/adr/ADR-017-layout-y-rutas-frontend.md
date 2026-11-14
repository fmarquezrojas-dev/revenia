# ADR-017: Layouts separados y rutas frontend

Fecha: 2026-11-11. Estado: aceptado.

La navegacion local dentro de cada formulario no soportaba enlaces directos ni
aprovechaba el escritorio. Adoptamos React Router, AuthLayout y AppShell, con
URLs por modulo y filtros en query parameters. /acceso evita conflicto con /login.
Lucide aporta iconos SVG; el sistema visual sigue en CSS nativo y tokens.
El dialog nativo evita incorporar una biblioteca de overlays completa.

Se conservan contratos, cookies, CSRF y permisos del backend. El despliegue debe
resolver rutas SPA despues de los endpoints backend. FormGuard protege cambios
y guardados pendientes. El coste nuevo es mantener rutas, bloqueo y foco en las
pruebas. No se agregan cache global ni una segunda biblioteca de formularios.

Ver [arquitectura y limites](../ARQUITECTURA_FRONTEND.md).
