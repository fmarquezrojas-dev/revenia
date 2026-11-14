import type { DocumentSeries } from './api'
export const seriesTypeLabels = { '01': 'Factura', '03': 'Boleta', '07': 'Nota de crédito' } as const
export function lastSeriesNumber(series: DocumentSeries): string {
  return series.current_number === 0 ? 'Sin emisiones' : `${series.code}-${String(series.current_number).padStart(8, '0')}`
}
