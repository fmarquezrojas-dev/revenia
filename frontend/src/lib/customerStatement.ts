import type { Page } from './bank'

export const movementLabels = {
  sale: 'Emisión del comprobante',
  reserve: 'Reserva de crédito',
  credit: 'Nota de crédito aceptada',
  order: 'Solicitud de orden de pago',
  mp_payment: 'Pago confirmado de Mercado Pago',
  provider_payment: 'Pago confirmado del proveedor',
  bank_application: 'Aplicación bancaria',
  bank_reversal: 'Reversión de aplicación bancaria',
} as const

export type StatementDocument = {
  id: string
  number: string
  issue_date: string
  due_date: string | null
  needs_review: boolean
  pending_confirmation: boolean
  total_cents: string
  credits_cents: string
  reserved_cents: string
  paid_cents: string
  outstanding_cents: string
  excess_cents: string
}

export type StatementMovement = {
  document_id: string
  number: string
  kind: keyof typeof movementLabels
  occurred_at: string
  source_id: string
  receipt_id: string | null
  reference: string
  amount_cents: string
  order_state: string | null
  sandbox: boolean
}

export type CustomerStatementData = {
  customer: { id: string; name: string; doc_type: string; doc_number: string }
  company: { legal_name: string; ruc: string }
  period: { from: string; to: string }
  calculated_at: string
  currency: string
  timezone: string
  movement_document: string | null
  summary: {
    documents: number
    review: number
    outstanding_cents: string
    overdue_cents: string
    excess_cents: string
    available_cents: string | null
  }
  documents: Page<StatementDocument>
  movements: Page<StatementMovement>
  receipts: Page<{
    id: string
    bank_date: string
    reference: string
    amount_cents: string
    applied_cents: string
    available_cents: string
  }> | null
}
