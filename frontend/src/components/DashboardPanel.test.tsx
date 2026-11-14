import { beforeEach, expect, it, vi } from 'vitest'
import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { render } from '../test/render'
import DashboardPanel from './DashboardPanel'
import { ApiError } from '../lib/api'
import { calendarMonth, dashboardMoney, getDashboard, getDashboardDetail } from '../lib/dashboard'
import type { DashboardData } from '../lib/dashboard'
vi.mock('../lib/dashboard', async original => ({ ...await original<typeof import('../lib/dashboard')>(), getDashboard: vi.fn(), getDashboardDetail: vi.fn() }))
const data: DashboardData = {
  currency: 'PEN', period: { from: '2026-11-01', to: '2026-11-30', currency: 'PEN' }, timezone: 'America/Lima', calculated_at: '2026-11-13T15:00:00Z',
  activity: { accepted_cents: '12000', accepted_count: 2, credit_notes_cents: null, credit_notes_supported: false, collected_cents: '10000', payments_count: 1, rejected_count: 0 },
  portfolio: { outstanding_cents: '2000', outstanding_count: 1, excess_cents: '0', excess_count: 0, review_count: 0 },
  series: [{ date: '2026-11-01', accepted_cents: '12000', collected_cents: '10000' }], alerts: { queued: 0, failed: 0, pdf_failed: 0, email_failed: 0 }, recent: [],
  usage: { status: 'active', period_id: 'period', plan_name: 'Básico', starts_at: '2026-11-01T05:00:00Z', ends_at: '2026-12-01T05:00:00Z', limit: 50, consumed: 6, remaining: 44, can_issue: true, expiring_soon: false, timezone: 'America/Lima', rule: '' },
  actions: { create_document: true, manage_subscription: true },
}
const path = '/dashboard?from=2026-11-01&to=2026-11-30&currency=PEN'
const props = () => ({ companyName: 'Empresa de pruebas', onSessionExpired: vi.fn() })
beforeEach(() => { vi.clearAllMocks(); vi.mocked(getDashboard).mockResolvedValue(data); vi.mocked(getDashboardDetail).mockResolvedValue({ currency: 'PEN', period: data.period, calculated_at: data.calculated_at, detail: { metric: 'accepted', page: 1, last_page: 1, per_page: 20, total: 0, rows: [] } }) })
it('uses backend amounts and links to the exact acceptance selection', async () => {
  const { router } = render(<DashboardPanel {...props()} />, path)
  const link = await screen.findByRole('link', { name: /Emisión aceptada/ })
  expect(link).toHaveTextContent('PEN 120.00')
  expect(link).toHaveAttribute('href', expect.stringContaining('metric=accepted'))
  await userEvent.click(link)
  await waitFor(() => expect(getDashboardDetail).toHaveBeenCalledWith('from=2026-11-01&to=2026-11-30&currency=PEN&metric=accepted&page=1', expect.any(AbortSignal)))
  expect(router.state.location.search).toContain('metric=accepted')
  await screen.findByText(/Primera fecha de resultado/)
})
it('applies custom currency and dates through URL filters', async () => {
  const { router } = render(<DashboardPanel {...props()} />, path)
  await screen.findByText('Cartera actual')
  const user = userEvent.setup()
  await user.selectOptions(screen.getByLabelText('Moneda'), 'USD')
  await user.click(screen.getByRole('button', { name: 'Aplicar filtros' }))
  await waitFor(() => expect(router.state.location.search).toContain('currency=USD'))
  expect(getDashboard).toHaveBeenLastCalledWith('from=2026-11-01&to=2026-11-30&currency=USD', expect.any(AbortSignal))
})
it('shows zero activity without hiding the portfolio scope or inventing credit notes', async () => {
  vi.mocked(getDashboard).mockResolvedValue({ ...data, series: [{ date: '2026-11-01', accepted_cents: '0', collected_cents: '0' }] })
  render(<DashboardPanel {...props()} />, path)
  await screen.findByText('No hay actividad en este periodo.')
  expect(screen.getByText('No disponible')).toBeInTheDocument()
  expect(screen.getByText(/Todos los comprobantes aceptados/)).toHaveTextContent('sin filtro de fechas')
})
it('does not convert API failure to an empty state or expose server internals', async () => {
  vi.mocked(getDashboard).mockRejectedValue(new ApiError(500, { message: 'SQL secret implementation' }))
  render(<DashboardPanel {...props()} />, path)
  expect(await screen.findByRole('alert')).toHaveTextContent('No se pudo actualizar')
  expect(screen.queryByText(/SQL secret/)).not.toBeInTheDocument()
  expect(screen.queryByText('No hay actividad en este periodo.')).not.toBeInTheDocument()
})
it('retries and preserves visible data on refresh failure', async () => {
  render(<DashboardPanel {...props()} />, path)
  await screen.findByText('Cartera actual')
  vi.mocked(getDashboard).mockRejectedValueOnce(new TypeError('offline'))
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Se conservan los últimos datos')
  expect(screen.getByText('Cartera actual')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Actualizar' }))
  await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
})
it('limits quick actions to permissions returned by the server', async () => {
  vi.mocked(getDashboard).mockResolvedValue({ ...data, actions: { create_document: false, manage_subscription: false } })
  render(<DashboardPanel {...props()} />, path)
  await screen.findByText('Cartera actual')
  expect(screen.queryByRole('link', { name: 'Crear comprobante' })).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /Gestionar plan/ })).not.toBeInTheDocument()
  expect(screen.getByText(/contacta al dueño/)).toBeInTheDocument()
})
it('ignores a slow previous response after filters change', async () => {
  let finish!: (result: DashboardData) => void
  vi.mocked(getDashboard).mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
  const { router } = render(<DashboardPanel {...props()} />, path)
  await act(() => router.navigate('/dashboard?from=2026-10-01&to=2026-10-31&currency=USD'))
  await screen.findByText('Cartera actual')
  await act(async () => finish({ ...data, activity: { ...data.activity, accepted_cents: '999999' } }))
  expect(screen.queryByText('PEN 9,999.99')).not.toBeInTheDocument()
  expect(vi.mocked(getDashboard).mock.calls[0][1].aborted).toBe(true)
})
it.each([[403, 'Tu rol no tiene acceso'], [429, 'Hay demasiadas solicitudes'], [422, 'Revisa las fechas']])('handles API status %s', async (status, message) => {
  vi.mocked(getDashboard).mockRejectedValue(new ApiError(status, {}))
  render(<DashboardPanel {...props()} />, path)
  expect(await screen.findByRole('alert')).toHaveTextContent(message)
})
it('expires the session on 401', async () => {
  vi.mocked(getDashboard).mockRejectedValue(new ApiError(401, {}))
  const p = props(); render(<DashboardPanel {...p} />, path)
  await waitFor(() => expect(p.onSessionExpired).toHaveBeenCalledOnce())
})
it('formats integer strings without losing cents and uses Lima month boundaries', () => {
  expect(dashboardMoney('18014398509481982', 'PEN')).toBe('PEN 180,143,985,094,819.82')
  expect(dashboardMoney('0', 'USD')).toBe('USD 0.00')
  expect(calendarMonth(false, new Date('2026-12-01T04:59:59Z')).from).toBe('2026-11-01')
  expect(calendarMonth(true, new Date('2028-03-15T15:00:00Z')).to).toBe('2028-02-29')
})
