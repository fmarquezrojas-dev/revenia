import { request } from './api'
import type { Usage } from './subscriptions'

export type DashboardMetric = 'accepted' | 'rejected' | 'payments' | 'outstanding' | 'excess' | 'review' | 'queued' | 'failed' | 'pdf_failed' | 'email_failed'
export const metricLabels: Record<DashboardMetric, string> = {
  accepted: 'Emisión aceptada', rejected: 'Rechazos del periodo', payments: 'Cobrado en el periodo',
  outstanding: 'Saldo pendiente actual', excess: 'Exceso pagado actual', review: 'Documentos con pagos por revisar',
  queued: 'En cola o procesamiento', failed: 'Fallos de emisión', pdf_failed: 'PDF con error', email_failed: 'Correo fallido o incierto',
}
export interface DashboardFilters { from: string; to: string; currency: string }
export interface DashboardRow {
  id: string; document_id: string; number: string; customer: string; amount_cents: string; currency: string
  status: string; payment_status: string | null; needs_review: boolean; relevant_at: string; href: string
}
export interface DashboardDay { date: string; accepted_cents: string; collected_cents: string }
export interface DashboardData {
  currency: string; period: DashboardFilters; timezone: string; calculated_at: string
  activity: { accepted_cents: string; accepted_count: number; credit_notes_cents: null; credit_notes_supported: false; collected_cents: string; payments_count: number; rejected_count: number }
  portfolio: { outstanding_cents: string; outstanding_count: number; excess_cents: string; excess_count: number; review_count: number }
  series: DashboardDay[]; alerts: Record<'queued' | 'failed' | 'pdf_failed' | 'email_failed', number>
  recent: DashboardRow[]; usage: Usage; actions: { create_document: boolean; manage_subscription: boolean }
}
export interface DashboardDetail { currency: string; period: DashboardFilters; calculated_at: string; detail: { metric: DashboardMetric; rows: DashboardRow[]; page: number; per_page: number; total: number; last_page: number } }
export async function getDashboard(query: string, signal: AbortSignal): Promise<DashboardData> {
  return (await request<{ data: DashboardData }>('/api/v1/dashboard?' + query, { signal })).data
}
export async function getDashboardDetail(query: string, signal: AbortSignal): Promise<DashboardDetail> {
  return (await request<{ data: DashboardDetail }>('/api/v1/dashboard?' + query, { signal })).data
}
/** Integer strings stay exact even when a portfolio exceeds Number.MAX_SAFE_INTEGER. */
export function dashboardMoney(cents: string, currency: string): string {
  const value = BigInt(cents), negative = value < 0n, absolute = negative ? -value : value
  return `${currency} ${negative ? '−' : ''}${new Intl.NumberFormat('es-PE').format(absolute / 100n)}.${String(absolute % 100n).padStart(2, '0')}`
}
export function calendarMonth(previous = false, now = new Date()): DashboardFilters {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit' }).formatToParts(now)
  const year = Number(parts.find(p => p.type === 'year')!.value), month = Number(parts.find(p => p.type === 'month')!.value)
  const start = new Date(Date.UTC(year, month - 1 - Number(previous), 1))
  const end = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 0))
  return { from: start.toISOString().slice(0, 10), to: end.toISOString().slice(0, 10), currency: 'PEN' }
}
export function dashboardQuery(filters: DashboardFilters, metric?: DashboardMetric, page = 1): string {
  const query = new URLSearchParams({ ...filters })
  if (metric) { query.set('metric', metric); query.set('page', String(page)) }
  return query.toString()
}
