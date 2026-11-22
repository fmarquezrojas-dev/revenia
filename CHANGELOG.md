# Historial de cambios

## Mantenimiento posterior a v0.1.0-rc.2

- La ficha técnica 1.5.1 y la guía describen las consultas locales de cobranza, sus permisos y la revocación.
- El lanzador genérico se encuentra en `scripts/mcp/revenia.ps1`. La configuración de clientes se mantiene en cada equipo. Los perfiles locales y las credenciales vigentes conservan su validez.
- Se retiraron dos herramientas de desarrollo sin uso y sus dependencias exclusivas. Las versiones de las dependencias de ejecución se conservan.

## v0.1.0-rc.2 · candidata de cobranza

- El estado de cuenta enlaza facturas, notas, pagos, aplicaciones y reversiones. Los saldos actuales se consultan e imprimen por cliente y moneda.
- Cuentas por cobrar incorpora seguimiento, compromisos, responsables y agendas. Tesorería registra abonos e identifica remanentes.
- Incidencias, MCP, Odoo en borrador y el respaldo aislado completan el circuito operativo. Se incorporan siete migraciones sobre la base de 16.
- Se verificaron las carreras de créditos y dinero, la recuperación con respuesta perdida y la restauración sintética. La edición documental v1.5 incluye Word, PDF y la guía visual. [Notas de la candidata](docs/release-notes.md) · [Evidencia](docs/testing/test-report.md)

La guía visual incluye 35 vistas de la interfaz y tres imágenes de documentos y resultados del laboratorio. [Recorrido por funcionalidad](README.md#guía-visual-por-tarea)

## Antecedente de portafolio

### Preparacion de portafolio

Documentacion de producto, arquitectura, decisiones con pruebas, arranque reproducible, galeria sintetica y CI. Licencia MIT elegida por el titular. Las verificaciones externas pendientes se declaran expresamente. Publicacion de codigo no equivale a despliegue.

## 2026-11-13

### Cambio de nombre de Facturly a Revenia

Interfaz, monograma R, favicon, metadatos, paquetes raiz y marca de nuevos PDF/correos actualizados. Se preservaron datos, documentos historicos, credenciales y configuracion operativa. Compose, bases, cookies y prefijos conservan identificadores facturly por compatibilidad.

### Testing integral y estabilizacion

Regresion backend de 373 pruebas/1769 aserciones y frontend de 153 pruebas. Se verificaron concurrencia PostgreSQL, navegador, Redis, PDF real y Mailpit. Se corrigieron acceso publico local a Horizon, refresco bloqueado por cuota agotada y dependencia transitiva vulnerable. Notas de credito y validacion externa sandbox permanecen pendientes.
