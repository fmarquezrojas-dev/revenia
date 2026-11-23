# Candidata v0.1.0-rc.2

Revenia permite seguir la factura hasta el estado de cuenta. La candidata incorpora notas de crédito simuladas, cuentas por cobrar, responsables y compromisos, cobros bancarios con distribución y reversión, historial de incidencias, transferencia a Odoo en borrador, consultas MCP de lectura y respaldo aislado.

## Actualizar o evaluar

- **Copia nueva.** Siga la [instalación](development.md) y prepare las cuentas sintéticas si va a explorar la interfaz.
- **Instalación anterior.** Conserve la `APP_KEY` y prepare un [respaldo](RESPALDO_Y_RESTAURACION.md). Hay siete migraciones desde la base de 16. Compruebe `php artisan migrate:status` en su instalación antes de aplicar las pendientes.
- **Documentación 1.5.1.** [Word editable](ficha/Revenia-v1.5.1.docx) y [PDF](ficha/Revenia-v1.5.1.pdf). La actualización documenta las consultas de cobranza y el lanzador de la rama actual. La [publicación original](https://github.com/fmarquezrojas-dev/revenia/releases/tag/v0.1.0-rc.2) conserva el tag y sus archivos 1.5.

El [README ilustrado](../README.md#guía-visual-por-tarea) permite seguir una factura y el mismo abono a través de sus pantallas.

## Evidencia y límites

El bloque 11 comprobó saldos compartidos entre detalle, cartera y estado de cuenta, la concurrencia bancaria y de créditos, la recuperación del trabajador y restauración en otro clúster. Odoo Community 18 se comprobó en un laboratorio real. La validación externa de Mercado Pago y la aceptación en la compañía del operador siguen pendientes. La emisión fiscal es simulada. [Informe](testing/test-report.md) · [CI por revisión](https://github.com/fmarquezrojas-dev/revenia/actions/workflows/ci.yml)
