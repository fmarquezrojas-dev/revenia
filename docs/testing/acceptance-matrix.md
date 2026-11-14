# Matriz de aceptacion

La [matriz de alcance vigente](../ESTADO_PROYECTO.md) es la referencia principal. Los [resultados](test-report.md) distinguen PHPUnit, frontend, E2E interno y comprobaciones externas.

| Recorrido | Resultado observado |
| --- | --- |
| A: cliente, producto, borrador, edicion, emision, PDF y Mailpit | Aprobado en entorno aislado real |
| B: notas totales/parciales | Bloqueado: dominio ausente |
| C: cobranza y notificaciones repetidas | Aprobado con adaptador sintetico; externo pendiente |
| D: ultimo cupo y renovacion futura | Aprobado con adaptador sintetico; externo pendiente |
| E: roles, empresa ajena, logout y expiracion | Aprobado en navegador y API |
| Caida Redis y worker interrumpido despues de aceptacion | Aprobado: una identidad, un numero y un consumo |
| PDF largo, Unicode, PEN/USD y totales | Aprobado con Dompdf y extraccion Poppler; inspeccion visual |
| CI remoto | Revisar el workflow real del commit publicado |
