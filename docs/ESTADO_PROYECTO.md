# Estado del proyecto

La candidata de software **v0.1.0-rc.2** reúne el circuito de cobranza y la validación del bloque 11. La edición documental vigente es **1.5.1**. [Ficha técnica](ficha/Revenia-v1.5.1.pdf) · [Informe y resultados](testing/test-report.md)

| Área | Evidencia disponible | Validación restante |
| --- | --- | --- |
| Sesión, catálogos, roles y empresa | Sanctum, políticas, referencias compuestas y denegación de UUID ajenos en API y MCP | Permisos del operador con sus cuentas |
| Comprobantes, nota 07, PDF y correo | Emisión simulada, reserva exclusiva, ajuste al aceptar, PDF privado y SMTP en Mailpit | Fiscalidad real fuera del alcance; correo externo por validar |
| Cobros bancarios y cartera | Abono repartido, reversión, remanente, excesos, PEN/USD y protección frente a distribuciones concurrentes | Conciliación con extractos propios |
| Seguimiento y Estado de cuenta | Agenda, responsable, compromisos con evidencia de pagos, movimientos del período y saldo actual imprimible | Aceptación por cobranza y contabilidad |
| Resumen y planes | Actividad por período, posición actual y cuotas. Pagos de suscripción separados del cobro a clientes | Sandbox de plataforma |
| Incidencias | Historial por operación, recuperación con Redis y trabajador interrumpido, controles por rol | Revisión técnica de resultados inciertos de la instalación |
| Odoo 18 | Factura, boleta y rectificación en borrador mediante el complemento real de una instancia aislada | Compañía, permisos y correspondencias del operador |
| Mercado Pago | Contratos probados con proveedor sintético y avisos repetidos | Checkout y webhook externos para comercio y plataforma |
| Consultas locales de cobranza | Descubrimiento, tres lecturas autenticadas, aislamiento y revocación | Autorizar y revocar el acceso desde el cliente local elegido |
| Respaldo | Restauración aislada con 8 empresas, 10 saldos y 8 referencias PDF coincidentes | Primer respaldo y recuperación del operador |

Los siguientes hitos son la aceptación con usuarios propios, las pruebas de proveedores y la evaluación de seguridad, carga, secretos y supervisión para un despliegue comercial. [Limitaciones conocidas](testing/known-issues.md)
