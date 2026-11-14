# Presentación de Revenia

Revenia es una aplicación de demostración para administrar facturación y cobranza por empresa. Permite preparar comprobantes, emitir mediante un simulador y seguir documentos, entregas y saldos. Combina una API Laravel con una SPA React y procesos asíncronos sobre PostgreSQL y Redis. No acredita clientes comerciales ni integración tributaria real.

## Competencias demostradas

- Modelado y transacciones: [importe y borrador](../backend/app/Actions/Documents/DraftDocuments.php), [numeración](../backend/app/Actions/Series/ReserveNextNumber.php) y [cuotas](../backend/app/Actions/Subscriptions/Subscriptions.php).
- APIs y autorización: [rutas](../backend/routes/api.php), [policies](../backend/app/Policies) y [pruebas multiempresa](../backend/tests/Feature).
- Procesamiento asíncrono: [outbox](../backend/app/Actions/Issuance/EmissionOutbox.php), [leases y resultados tardíos](../backend/app/Actions/Issuance/ProcessEmission.php).
- Full stack: [editor](../frontend/src/components/DocumentEditor.tsx), [dashboard](../frontend/src/components/DashboardPanel.tsx), [pruebas React](../frontend/src/components/DashboardPanel.test.tsx).
- Entrega reproducible: [Docker](../compose.yaml), [CI](../.github/workflows/ci.yml), [pruebas de recuperación](../scripts/test-integral-recovery.cjs).

## Tres decisiones para explicar en entrevista

1. Por qué guardar un borrador no asigna número, y qué filas se bloquean cuando dos solicitudes intentan consumir el último cupo.
2. Qué ocurre entre un commit exitoso y un Redis no disponible, y por qué la idempotencia del negocio no significa entrega exactamente una vez.
3. Por qué un timeout después de aceptación necesita consulta y conciliación, en lugar de un reenvío inmediato.

Cada decisión tiene alternativas y costes en [decisiones técnicas](architecture/decisions.md).

## Recorrido de unos tres minutos

| Tiempo | Acción y explicación |
| --- | --- |
| 0:00–0:30 | Acceder a Demo Alfa. Mostrar el dashboard, filtro PEN/USD y separación entre emisión y cobro |
| 0:30–1:15 | Abrir un borrador, seleccionar cliente y producto y cambiar cantidad. Explicar precio capturado, IGV y control de versión |
| 1:15–1:55 | Emitir un borrador válido con cuota. Mostrar cola, aceptación del simulador y número único. Aclarar que no se conecta con SUNAT |
| 1:55–2:25 | Descargar PDF privado y enviar a Mailpit. Mostrar el aviso de simulación y distinguir aceptación SMTP de lectura del correo |
| 2:25–3:00 | Mostrar plan/consumo y permisos del accountant. Explicar que Mercado Pago externo y notas siguen pendientes; enseñar la prueba de recuperación en código |

El dataset incluye fallos deliberados. Para el recorrido de éxito usar un borrador normal, no un escenario configurado de rechazo. Sin credenciales sandbox no se promete completar un checkout externo.

## Problemas resueltos y evidencia

- Solicitudes simultáneas y repetidas: [procesos de concurrencia](../backend/tests/Concurrency) y [cuotas](../backend/tests/Feature/SubscriptionsTest.php).
- Pérdida de transporte después de commit e interrupción de worker: [prueba con Redis real](../scripts/test-integral-recovery.cjs).
- Acceso global a Horizon por excepción local: [gate explícito](../backend/app/Providers/HorizonServiceProvider.php) y [regresión](../backend/tests/Feature/HorizonAccessTest.php).
- Refresco de estado bloqueado por cuota agotada: [regresión React](../frontend/src/components/EmissionControls.test.tsx).

No se atribuyen ahorros de negocio, disponibilidad productiva ni escala no medida. Evoluciones: verificar sandbox externo, implementar notas con reservas, endurecer operación de despliegue y medir rendimiento con una carga representativa.
