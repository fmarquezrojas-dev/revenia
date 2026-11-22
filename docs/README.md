# Documentación de Revenia

## Documentación técnica v1.5

[Ficha editable en Word](ficha/Revenia-v1.5.docx) · [Ficha en PDF](ficha/Revenia-v1.5.pdf) · [Guía de producto por rol](guia-producto.md) · [Recorrido visual](../README.md#guía-visual-por-tarea)

La [captura de Resumen utilizada en la ficha técnica](images/resumen-cartera.png) corresponde al ensayo de navegador del bloque 11.

## Producto y ejecución

- [Inicio](../README.md), [estado y alcance](ESTADO_PROYECTO.md), [galería histórica](gallery.md), [presentacion laboral](portfolio.md).
- [Desarrollo local](development.md), [datos demo](DATOS_LOCALES.md), [operacion y recuperacion](operations.md).
- [Resultados de pruebas](testing/test-report.md), [matriz](testing/acceptance-matrix.md), [limites conocidos](testing/known-issues.md).

## Arquitectura y reglas

- [Componentes y flujos](architecture/overview.md), [decisiones con evidencia](architecture/decisions.md), [frontend](ARQUITECTURA_FRONTEND.md).
- [Modelo de datos](MODELO_DATOS.md), [borradores/importes](api/comprobantes.md), [emision](EMISION_SIMULADA.md), [PDF/correo](PDF_Y_CORREO.md).
- [Cobranza](COBRANZA.md), [planes y cuotas](PLANES_Y_CUOTAS.md), [dashboard](DASHBOARD.md).

## API

[Clientes](api/clientes.md), [productos](api/productos.md), [series](api/series.md), [comprobantes](api/comprobantes.md), [cobranza](api/cobranza.md), [suscripciones](api/suscripciones.md). Los archivos OpenAPI existentes cubren contratos parciales: no se presentan como una especificacion completa de todos los endpoints. Las [rutas ejecutables](../backend/routes/api.php) completan la referencia.

## Decisiones

- [ADR-014: limite de usuarios](adr/ADR-014-limite-de-usuarios.md)
- [ADR-015: moneda y precio](adr/ADR-015-moneda-y-precio-del-catalogo.md)
- [ADR-016: series](adr/ADR-016-series-y-correlativos-transaccionales.md)
- [ADR-017: rutas frontend](adr/ADR-017-layout-y-rutas-frontend.md)
- [ADR-018: importes](adr/ADR-018-importes-y-borradores.md)
- [ADR-019: cobranza sandbox](adr/ADR-019-cobranza-mercado-pago-sandbox.md)
- [ADR-020: periodos y cuotas](adr/ADR-020-planes-periodos-y-cuotas.md)
- [ADR-021: dashboard](adr/ADR-021-dashboard-negocio.md)

La numeracion conserva la estructura existente. Informes locales, rutas personales, documentos Word antiguos y artefactos privados quedan fuera de la publicacion; las evidencias seleccionadas son sinteticas.
