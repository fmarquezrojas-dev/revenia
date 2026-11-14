# Verificacion de borradores e importes

Referencia actual: [contrato](api/comprobantes.md), [ADR-018](adr/ADR-018-importes-y-borradores.md) y [resultados](testing/test-report.md).

La suite DraftDocumentsTest verifica tipos 01/03, referencias por empresa, PEN/USD, cantidades, 1-50 lineas, redondeo, snapshots, precios manipulados, version y atomicidad. MoneyTest verifica el calculo exacto. El navegador verifica crear, editar y emitir con API real; guardar borradores no asigna numero ni consume cuota.

Concurrencia real: `scripts/test-documents-concurrency.ps1`, con procesos independientes y base temporal propia. No ejecutar fixtures contra la base del usuario. La prueba E2E integral utiliza servicios separados y guarda evidencias locales ignoradas por Git.

[Galeria vigente](gallery.md). No se certifica WCAG ni se afirma validacion de todos los navegadores. Notas de credito no implementadas.
