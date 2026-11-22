import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { beforeEach, expect, it, vi } from 'vitest'
import CollectionFollowupPanel from './CollectionFollowupPanel'
import CollectionAgenda from './CollectionAgenda'
import { request } from '../lib/api'
import type { Agenda, Followup } from '../lib/followup'

vi.mock('../lib/api', async original => ({...await original<typeof import('../lib/api')>(), request: vi.fn(), prepareCsrf: vi.fn()}))
const file: Followup = { id: 'sale', number: 'F001-00000001', currency: 'PEN', customer: 'Cliente de prueba', today: '2026-11-21',
  outstanding_cents: '3045', credits_cents: '0', paid_cents: '0', due_date: '2026-11-01', needs_review: false,
  assignee: null, last_contact: null, next_action_on: null, promise: null, can_assign: false, can_manage: true,
  revision: 4, people: [], history: [], page: 1, last_page: 1 }
function openFile(data: Followup) {
  vi.mocked(request).mockResolvedValue({data})
  render(<MemoryRouter initialEntries={['/receivables/sale/followup']}><Routes><Route path="/receivables/:id/followup" element={<CollectionFollowupPanel onSessionExpired={vi.fn()}/>}/></Routes></MemoryRouter>)
}
beforeEach(() => vi.resetAllMocks())
it('sends the agreed amount in exact cents with the revision read from the file', async () => {
  openFile(file)
  fireEvent.click(await screen.findByRole('button', {name: 'Registrar compromiso'}))
  fireEvent.change(screen.getByLabelText('Importe comprometido (PEN)'), {target: {value: '20,05'}})
  fireEvent.change(screen.getByLabelText('Fecha acordada'), {target: {value: '2026-11-25'}})
  fireEvent.click(screen.getByRole('button', {name: 'Guardar'}))
  await waitFor(() => expect(request).toHaveBeenCalledWith('/api/v1/receivables/sale/followup/promise', expect.objectContaining({
    method: 'POST', body: JSON.stringify({revision: 4, due_on: '2026-11-25', amount_cents: '2005', currency: 'PEN'}),
  })))
})
it('shows the history in read-only mode, including corrections and a closure under review', async () => {
  openFile({...file, can_manage: false, history: [{id:'e',kind:'fulfill',revision:3,promise_id:'p',corrects_id:null,author:'Contabilidad',
    recorded_at:'2026-11-21T01:00:00.123456Z',details:{covered_cents:'1000'},evidence_review:true,confirmed_cents:'200'}]})
  expect(await screen.findByText(/Cumplimiento en revisión/)).toHaveTextContent('PEN 2.00')
  expect(screen.queryByRole('button', {name: 'Registrar gestión'})).not.toBeInTheDocument()
})
it('keeps the team scope, customer, currency and date when opening an agenda counter', async () => {
  const data: Agenda = { rows:[],total:0,page:1,last_page:1,people:[],today:'2026-11-21',currency:'USD',
    counts:{total:0,action_today:0,action_late:0,promise_late:0,unassigned:0} }
  vi.mocked(request).mockResolvedValue({data})
  render(<MemoryRouter initialEntries={['/receivables/agenda?scope=team&q=Sol&currency=USD&from=2026-11-01&page=3']}>
    <CollectionAgenda role="owner" onSessionExpired={vi.fn()}/></MemoryRouter>)
  const link = await screen.findByRole('link', {name: /Compromisos vencidos/})
  expect(link.getAttribute('href')).toBe('/receivables/agenda?scope=team&q=Sol&currency=USD&from=2026-11-01&task=promise_late')
})
