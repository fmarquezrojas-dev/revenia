import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router'
import { request } from '../lib/api'
import type { CustomerStatementData } from '../lib/customerStatement'
import CustomerStatement from './CustomerStatement'

vi.mock('../lib/api', async (importActual) => ({
  ...(await importActual<typeof import('../lib/api')>()),
  request: vi.fn(),
}))
afterEach(cleanup)
beforeEach(() => vi.resetAllMocks())

const empty = { total: 0, rows: [], page: 1, last_page: 1 }
const report: CustomerStatementData = {
  company: { legal_name: 'Empresa de prueba', ruc: '20100000001' },
  customer: { id: 'client', name: 'Cliente de prueba', doc_type: 'ruc', doc_number: '20100000002' },
  currency: 'PEN',
  calculated_at: '2026-10-07T12:00:00-05:00',
  timezone: 'America/Lima',
  period: { from: '2026-10-01', to: '2026-10-07' },
  movement_document: null,
  summary: {
    documents: 1,
    review: 0,
    outstanding_cents: '10000',
    overdue_cents: '8000',
    excess_cents: '0',
    available_cents: '2000',
  },
  documents: {
    ...empty,
    total: 1,
    rows: [
      {
        id: 'sale',
        number: 'F001-00000001',
        issue_date: '2026-09-09',
        due_date: null,
        total_cents: '15000',
        credits_cents: '0',
        paid_cents: '5000',
        excess_cents: '0',
        outstanding_cents: '10000',
        needs_review: false,
        pending_confirmation: false,
        reserved_cents: '0',
      },
    ],
  },
  movements: {
    ...empty,
    total: 1,
    rows: [
      {
        kind: 'bank_reversal',
        source_id: 'app',
        document_id: 'sale',
        number: 'F001-00000001',
        occurred_at: '2026-10-07T10:00:00-05:00',
        reference: 'AB-10',
        receipt_id: 'receipt',
        amount_cents: '500',
        order_state: null,
        sandbox: false,
      },
    ],
  },
  receipts: empty,
}

it('mantiene la moneda y el período al imprimir, con referencias al origen y solo lecturas', async () => {
  vi.mocked(request).mockResolvedValue({ data: report })
  const print = vi.spyOn(window, 'print').mockImplementation(() => {})
  try {
    render(
      <MemoryRouter initialEntries={['/customers/client/statement?currency=PEN&page=2']}>
        <Routes>
          <Route path="/customers/:id/statement" element={<CustomerStatement onSessionExpired={vi.fn()} />} />
        </Routes>
      </MemoryRouter>,
    )
    expect(await screen.findByText('Empresa de prueba · RUC 20100000001')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'AB-10' })).toHaveAttribute(
      'href',
      '/bank-receipts/receipt?application=app#app-app',
    )
    expect(screen.getByRole('link', { name: 'Cartera de este cliente' })).toHaveAttribute(
      'href',
      '/receivables?customer_id=client&currency=PEN',
    )
    fireEvent.click(screen.getByRole('link', { name: 'Vista imprimible completa' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Imprimir' }))
    expect(print).toHaveBeenCalledOnce()
    const calls = vi.mocked(request).mock.calls
    const params = new URLSearchParams(calls.at(-1)![0].split('?')[1])
    expect(Object.fromEntries(params)).toEqual({
      currency: 'PEN',
      view: 'print',
      from: '2026-10-01',
      to: '2026-10-07',
    })
    expect(calls.every(([, options]) => (options?.method ?? 'GET') === 'GET')).toBe(true)
    expect(
      within(screen.getByRole('table', { name: 'Saldos actuales por comprobante' })).getByText('PEN 100.00'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Seguimiento' })).toBeNull()
  } finally {
    print.mockRestore()
  }
})
