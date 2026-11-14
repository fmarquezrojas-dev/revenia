# ADR-021: resumen de negocio y trazabilidad de indicadores

Estado: implementado; decision registrada antes de consultas, 2026-11-13.

Se reutilizan documentos, eventos, pagos y cuota existentes. No existen notas de credito ni reservas: el esquema solo admite 01/03. La tarjeta de notas informa no disponible, con valor null, sin fingir soporte ni calcular emision neta. El documento tecnico v1.2 original no esta disponible; prevalecen los contratos y ADR del repositorio.

Actividad y cartera se separan. La actividad usa primera aceptacion/rechazo registrada en document_events, nunca issue_date ni updated_at. Un documento cuenta una vez en cada resultado. No se filtra la emision historica por su estado actual. La cartera comprende todos los documentos actualmente aceptados de la moneda seleccionada, sin filtro de fechas.

Los pagos conservan confirmed_at: date_last_updated de la primera confirmacion aprobada validada, estable tras nuevas notificaciones. Es fecha efectiva de confirmacion del proveedor observada por Revenia, no fecha bancaria de liquidacion. Para pagos historicos aplicados sin este campo, la migracion conserva provider_updated_at disponible; su precision historica es limitada. Actualmente la base demo no contiene pagos. Ajustes observados no revierten automaticamente applied_cents: se muestran pendientes de revision, coherente con cobranza. No se restan reembolsos por segunda vez ni se representan como saldo negativo.

Todos los roles activos pueden consultar importes de documentos y cobranza segun DocumentPolicy.view/PaymentController.show existentes; reciben esas mismas agregaciones. Solo owner recibe enlace de gestion de suscripcion, owner/issuer crean borradores. Cuota operativa visible a todos, sin precios ni historial de pagos de suscripcion. Alertas limitadas a documentos de la empresa y moneda; no se exponen trabajos globales ni payloads.

Importes agregados siempre cadenas de centimos decimales, calculados por PostgreSQL numeric; React formatea con BigInt. PEN/USD independientes. Consultas separadas evitan multiplicacion por lineas/pagos. Una transaccion de lectura REPEATABLE READ mantiene una instantanea coherente por respuesta. Sin cache.

Rango de calendario inclusivo en Lima, transformado a UTC [inicio, dia siguiente al final), maximo 366 dias. Serie diaria rellenada con ceros. Detalle paginado dedicado reutiliza exactamente la seleccion de cada metrica, sin cambiar el filtro issue_date de Comprobantes. Filtros persistidos en URL; cada fila enlaza al documento autorizado.

Grafico SVG nativo con series diferenciadas por trazo y color, tabla equivalente y sin animacion. No se incorporan bibliotecas visuales. Resumen pasa a ser ruta inicial; se mantienen rutas directas y protecciones existentes.
