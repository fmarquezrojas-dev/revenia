import { request } from './api'

export const situationLabels = {
  upcoming: 'Por vencer',
  today: 'Vence hoy',
  overdue: 'Vencido',
  undated: 'Sin vencimiento informado',
  late_1_30: '1–30 días',
  late_31_60: '31–60 días',
  late_61_90: '61–90 días',
  late_over_90: 'Más de 90 días',
} as const

export type Situation = keyof typeof situationLabels
export interface Receivables {
  currency: 'PEN' | 'USD'
  cutoff: string
  timezone: string
  summary: Record<Situation | 'total', string>
  rows: {
    id: string
    customer: string
    customer_id: string | null
    number: string
    currency: 'PEN' | 'USD'
    issue_date: string
    due_date: string | null
    outstanding_cents: string
    pending_confirmation: boolean
    needs_review: boolean
    days_late: number | null
    bucket: Exclude<Situation, 'overdue'> | 'settled'
  }[]
  total: number
  page: number
  last_page: number
  per_page: number
}

export async function getReceivables(query: string, signal: AbortSignal): Promise<Receivables> {
  const response = await request<{ data: Receivables }>('/api/v1/receivables?' + query, { signal })
  return response.data
}
