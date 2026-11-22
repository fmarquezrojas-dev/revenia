# Presentación de Revenia

Revenia es una plataforma de facturación y cobranza para empresas. Permite preparar comprobantes, emitir mediante un simulador y seguir documentos, entregas y saldos. Combina una API Laravel con una SPA React y procesos asíncronos sobre PostgreSQL y Redis. No acredita clientes comerciales ni integración tributaria real.

## Competencias demostradas

- Modelado y transacciones: [importe y borrador](../backend/app/Actions/Documents/DraftDocuments.php), [numeración](../backend/app/Actions/Series/ReserveNextNumber.php) y [cuotas](../backend/app/Actions/Subscriptions/Subscriptions.php).
- APIs y autorización: [rutas](../backend/routes/api.php), [policies](../backend/app/Policies) y [pruebas multiempresa](../backend/tests/Feature).
- Procesamiento asíncrono: [outbox](../backend/app/Actions/Issuance/EmissionOutbox.php), [leases y resultados tardíos](../backend/app/Actions/Issuance/ProcessEmission.php).
- Full stack: [editor](../frontend/src/components/DocumentEditor.tsx), [dashboard](../frontend/src/components/DashboardPanel.tsx), [pruebas React](../frontend/src/components/DashboardPanel.test.tsx).
- Entrega reproducible: [Docker](../compose.yaml), [CI](../.github/workflows/ci.yml), [pruebas de recuperación](../scripts/test-integral-recovery.cjs).

## Decisiones de diseño

1. La numeración y el consumo de cuota se reservan al solicitar la primera emisión. La transacción serializa las solicitudes que compiten por el último cupo.
2. El outbox guarda la solicitud en PostgreSQL. Si Redis queda fuera de servicio, el publicador puede recuperar después la misma operación.
3. Ante una respuesta externa incierta, el trabajador consulta la identidad de la operación antes de intentar otro envío.

Cada decisión tiene alternativas y costes en [decisiones técnicas](architecture/decisions.md).

## Recorrido de unos tres minutos

| Tiempo | Acción y explicación |
| --- | --- |
| 0:00–0:30 | Acceder a Demo Alfa. Mostrar el dashboard, filtro PEN/USD y separación entre emisión y cobro |
| 0:30–1:15 | Abrir un borrador, seleccionar cliente y producto y cambiar cantidad. Explicar precio capturado, IGV y control de versión |
| 1:15–1:55 | Emitir un borrador válido con cuota. Mostrar cola, aceptación del simulador y número único. Aclarar que no se conecta con SUNAT |
| 1:55–2:25 | Descargar PDF privado y enviar a Mailpit. Mostrar el aviso de simulación y distinguir aceptación SMTP de lectura del correo |
| 2:25–3:00 | Abrir Estado de cuenta. Distinguir la nota aceptada del pago y mostrar la referencia de la aplicación bancaria. El sandbox externo de Mercado Pago queda pendiente |

El dataset incluye fallos deliberados. Para el recorrido de éxito usar un borrador normal, no un escenario configurado de rechazo. Sin credenciales sandbox no se promete completar un checkout externo.

## Problemas resueltos y evidencia

- Solicitudes simultáneas y repetidas: [procesos de concurrencia](../backend/tests/Concurrency) y [cuotas](../backend/tests/Feature/SubscriptionsTest.php).
- Pérdida de transporte después de commit e interrupción de worker: [prueba con Redis real](../scripts/test-integral-recovery.cjs).
- Acceso global a Horizon por excepción local: [gate explícito](../backend/app/Providers/HorizonServiceProvider.php) y [regresión](../backend/tests/Feature/HorizonAccessTest.php).
- Refresco de estado bloqueado por cuota agotada: [regresión React](../frontend/src/components/EmissionControls.test.tsx).

No se atribuyen ahorros de negocio, disponibilidad productiva ni escala no medida. Evoluciones: verificar sandbox externo, endurecer operación de despliegue y medir rendimiento con una carga representativa.
