import { beforeEach, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router'
import BankReceiptsPanel from './BankReceiptsPanel'
import { request } from '../lib/api'
import type { Detail, Candidate, Receipt } from '../lib/bank'
vi.mock('../lib/api', async (original) => ({
  ...(await original<typeof import('../lib/api')>()),
  request: vi.fn(),
  prepareCsrf: vi.fn(),
}))
const receipt: Receipt = {
  id: 'r',
  revision: 3,
  account_id: 'a',
  alias: 'Caja local',
  customer: 'Cliente',
  customer_id: 'c',
  currency: 'PEN',
  bank_date: '2026-11-20',
  method: 'deposit',
  origin: 'customer',
  reference: 'REF-18',
  situation: 'available',
  recorded_at: '2026-11-20 14:30:00+00',
  confirmed_at: '2026-11-20 15:30:00+00',
  amount_cents: '10000',
  applied_cents: '0',
  available_cents: '10000',
}
const empty = { rows: [], page: 1, last_page: 1, total: 0 }
function open(role: 'issuer' | 'owner' | 'accountant', r: Receipt) {
  const detail: Detail = { receipt: r, note: null, history: empty, applications: empty }
  vi.mocked(request).mockImplementation(async (path, options) => {
    if (options?.method === 'POST') return { data: { id: 'r' } }
    if (path.includes('/candidates')) {
      const second = path.includes('page=2')
      const doc: Candidate = {
        id: second ? 'b' : 'a',
        number: second ? 'F002' : 'F001',
        needs_review: false,
        pending_confirmation: false,
        outstanding_cents: '5000',
        reserved_cents: '0',
        available_cents: '5000',
        due_date: null,
      }
      return { data: { rows: [doc], page: second ? 2 : 1, last_page: 2, total: 21 } }
    }
    return { data: detail }
  })
  render(
    <MemoryRouter initialEntries={['/bank-receipts/r']}>
      <Routes>
        <Route
          path="/bank-receipts/:id"
          element={<BankReceiptsPanel role={role} onSessionExpired={vi.fn()} />}
        />
      </Routes>
    </MemoryRouter>,
  )
}
beforeEach(() => vi.resetAllMocks())
it('preserves allocations across pages and writes only when the owner confirms', async () => {
  open('owner', receipt)
  fireEvent.click(await screen.findByRole('button', { name: 'Distribuir abono' }))
  fireEvent.change(await screen.findByRole('textbox', { name: 'Aplicar a F001' }), {
    target: { value: '12,34' },
  })
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
  fireEvent.change(await screen.findByRole('textbox', { name: 'Aplicar a F002' }), {
    target: { value: '3.00' },
  })
  expect(screen.getByText(/Remanente después de aplicar/)).toHaveTextContent('PEN 84.66')
  expect(vi.mocked(request).mock.calls.some(([, opts]) => opts?.method === 'POST')).toBe(false)
  fireEvent.click(screen.getByRole('button', { name: 'Confirmar distribución' }))
  await waitFor(() =>
    expect(request).toHaveBeenCalledWith(
      '/api/v1/bank-receipts/r/apply',
      expect.objectContaining({ method: 'POST' }),
    ),
  )
  const call = vi.mocked(request).mock.calls.find(([, opts]) => opts?.method === 'POST')!
  expect(JSON.parse(String(call[1]?.body))).toMatchObject({
    revision: 3,
    request_id: expect.any(String),
    lines: [
      { document_id: 'a', amount_cents: '1234' },
      { document_id: 'b', amount_cents: '300' },
    ],
  })
})
it.each(['issuer', 'accountant'] as const)('restricts manual verification for %s', async (role) => {
  open(role, { ...receipt, confirmed_at: null, situation: 'pending' })
  await screen.findByText('REF-18 · Caja local')
  expect(screen.queryByRole('button', { name: 'Confirmar ingreso bancario' })).toBeNull()
  expect(screen.queryByRole('button', { name: 'Distribuir abono' })).toBeNull()
  expect(screen.queryByRole('link', { name: 'Cuentas receptoras' })).toBeNull()
})
