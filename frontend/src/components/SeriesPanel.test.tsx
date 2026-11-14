import { render } from '../test/render'
import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, expect, it, vi } from 'vitest'
import SeriesPanel from './SeriesPanel'
import SeriesForm from './SeriesForm'
import AuthenticatedView from './AuthenticatedView'
import { ApiError, createSeries, getSeries, getSeriesDetail, updateSeries } from '../lib/api'
import type { DocumentSeries, SeriesResponse, SessionUser } from '../lib/api'
vi.mock('../lib/api', async (importOriginal) => ({
  ...await importOriginal<typeof import('../lib/api')>(),
  getSeries: vi.fn(), getSeriesDetail: vi.fn(), createSeries: vi.fn(), updateSeries: vi.fn(),
}))
const series: DocumentSeries = { id: 'series-1', doc_type: '01', code: 'F001', current_number: 0, is_active: true, created_at: null, updated_at: null }
const response = (data = [series]): SeriesResponse => ({ data, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length } })
const callbacks = () => ({ onSessionExpired: vi.fn(), onSavingChange: vi.fn() })
beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(getSeries).mockResolvedValue(response())
  vi.mocked(getSeriesDetail).mockResolvedValue(series)
  vi.mocked(createSeries).mockResolvedValue(series)
  vi.mocked(updateSeries).mockResolvedValue(series)
})
it.each(['owner', 'issuer', 'accountant'] as SessionUser['role'][])('solo owner ve Series en navegacion: %s', async (role) => {
  const user: SessionUser = { id: 'user', name: 'Usuario', email: 'test@example.test', role, role_label: role, company: { id: 'company', legal_name: 'Empresa', trade_name: null } }
  render(<AuthenticatedView user={user} busy={false} message="" onLogout={vi.fn()} onSessionExpired={vi.fn()} onCompanyUpdated={vi.fn()} onUserUpdated={vi.fn()} />)
  expect(Boolean(screen.queryByRole('link', { name: 'Series' }))).toBe(role === 'owner')
  expect(screen.getByRole('link', { name: 'Productos y servicios' })).toBeInTheDocument()
  if (role === 'owner') {
    await userEvent.click(screen.getByRole('link', { name: 'Series' }))
    await screen.findByText('F001')
  }
})
it.each(['issuer', 'accountant'] as const)('no consulta Series al montar con rol %s', (role) => {
  render(<SeriesPanel role={role} {...callbacks()} />)
  expect(screen.getByRole('alert')).toHaveTextContent('Solo el dueño')
  expect(getSeries).not.toHaveBeenCalled()
  expect(screen.queryByRole('button', { name: 'Nueva serie' })).not.toBeInTheDocument()
})
it('muestra Sin emisiones, crea, cancela y vuelve al listado', async () => {
  const user = userEvent.setup()
  render(<SeriesPanel role="owner" {...callbacks()} />)
  await screen.findByText(/Sin emisiones/)
  await user.click(screen.getByRole('button', { name: 'Nueva serie' }))
  await user.type(screen.getByLabelText('Código de serie'), 'Temporal')
  await user.click(screen.getByRole('button', { name: 'Cancelar' }))
  expect(createSeries).not.toHaveBeenCalled()
  await user.click(screen.getByRole('button', { name: 'Nueva serie' }))
  expect(screen.getByLabelText('Código de serie')).toHaveValue('')
  await user.type(screen.getByLabelText('Código de serie'), ' fc01 ')
  await user.selectOptions(screen.getByLabelText('Tipo de comprobante'), '07')
  await user.click(screen.getByRole('button', { name: 'Crear serie' }))
  await screen.findByText('Serie creada correctamente.')
  expect(createSeries).toHaveBeenCalledWith({ code: ' fc01 ', doc_type: '07' })
})
it('muestra errores por campo sin perder valores', async () => {
  vi.mocked(createSeries).mockRejectedValue(new ApiError(422, { errors: { code: ['Serie duplicada.'] } }))
  render(<SeriesForm {...callbacks()} onCancel={vi.fn()} onSaved={vi.fn()} />)
  await userEvent.type(screen.getByLabelText('Código de serie'), 'F001')
  await userEvent.click(screen.getByRole('button', { name: 'Crear serie' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Serie duplicada.')
  expect(screen.getByLabelText('Código de serie')).toHaveAttribute('aria-invalid', 'true')
  expect(screen.getByLabelText('Código de serie')).toHaveValue('F001')
})
it.each([403, 419, 500])('gestiona error %s y permite reintentar', async (status) => {
  vi.mocked(createSeries).mockRejectedValue(new ApiError(status, {}))
  render(<SeriesForm {...callbacks()} onCancel={vi.fn()} onSaved={vi.fn()} />)
  await userEvent.type(screen.getByLabelText('Código de serie'), 'F001')
  await userEvent.click(screen.getByRole('button', { name: 'Crear serie' }))
  await screen.findByRole('alert')
  expect(screen.getByRole('button', { name: 'Crear serie' })).toBeEnabled()
  expect(screen.getByLabelText('Código de serie')).toHaveValue('F001')
})
it('notifica sesion expirada', async () => {
  const props = callbacks()
  vi.mocked(getSeries).mockRejectedValue(new ApiError(401, {}))
  render(<SeriesPanel role="owner" {...props} />)
  await waitFor(() => expect(props.onSessionExpired).toHaveBeenCalledOnce())
})
it('bloquea envios repetidos y navegacion mientras guarda', async () => {
  let finish!: (value: DocumentSeries) => void
  vi.mocked(createSeries).mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  const props = callbacks()
  render(<SeriesForm {...props} onCancel={vi.fn()} onSaved={vi.fn()} />)
  await userEvent.type(screen.getByLabelText('Código de serie'), 'F001')
  await userEvent.dblClick(screen.getByRole('button', { name: 'Crear serie' }))
  expect(createSeries).toHaveBeenCalledOnce()
  expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled()
  expect(props.onSavingChange).toHaveBeenLastCalledWith(true)
  await act(async () => finish(series))
  expect(props.onSavingChange).toHaveBeenLastCalledWith(false)
})
it('confirma cambio de estado sin editar ni perder el correlativo', async () => {
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
  vi.mocked(getSeriesDetail).mockResolvedValue({ ...series, current_number: 42 })
  render(<SeriesPanel role="owner" {...callbacks()} />)
  await userEvent.click(await screen.findByRole('button', { name: 'Ver serie F001' }))
  await screen.findByText('F001-00000042')
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Editar/ })).not.toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Desactivar' }))
  expect(updateSeries).not.toHaveBeenCalled()
  confirm.mockReturnValue(true)
  vi.mocked(updateSeries).mockResolvedValue({ ...series, current_number: 42, is_active: false })
  await userEvent.click(screen.getByRole('button', { name: 'Desactivar' }))
  await screen.findByRole('button', { name: 'Activar' })
  expect(updateSeries).toHaveBeenCalledWith(series.id, { is_active: false })
  expect(screen.getByText('F001-00000042')).toBeInTheDocument()
  vi.mocked(updateSeries).mockResolvedValue({ ...series, current_number: 42 })
  await userEvent.click(screen.getByRole('button', { name: 'Activar' }))
  await screen.findByRole('button', { name: 'Desactivar' })
  expect(updateSeries).toHaveBeenLastCalledWith(series.id, { is_active: true })
})
it('descarta respuestas antiguas y aplica filtros', async () => {
  let finishOld!: (value: SeriesResponse) => void
  vi.mocked(getSeries).mockImplementationOnce(() => new Promise((resolve) => { finishOld = resolve }))
  render(<SeriesPanel role="owner" {...callbacks()} />)
  await waitFor(() => expect(getSeries).toHaveBeenCalledOnce())
  const signal = vi.mocked(getSeries).mock.calls[0][1]
  await userEvent.selectOptions(screen.getByLabelText('Tipo de comprobante'), '03')
  await screen.findByText('F001')
  expect(signal?.aborted).toBe(true)
  await act(async () => finishOld(response([{ ...series, code: 'FOLD' }])))
  expect(screen.queryByText('FOLD')).not.toBeInTheDocument()
  expect(getSeries).toHaveBeenLastCalledWith({ doc_type: '03', is_active: '', page: 1 }, expect.any(AbortSignal))
})
it('pagina y muestra estado vacio despues de filtrar', async () => {
  vi.mocked(getSeries).mockResolvedValue({ ...response(), meta: { current_page: 1, last_page: 2, per_page: 15, total: 16 } })
  render(<SeriesPanel role="owner" {...callbacks()} />)
  await userEvent.click(await screen.findByRole('button', { name: 'Siguiente' }))
  await waitFor(() => expect(getSeries).toHaveBeenLastCalledWith({ doc_type: '', is_active: '', page: 2 }, expect.any(AbortSignal)))
  vi.mocked(getSeries).mockResolvedValue(response([]))
  await userEvent.selectOptions(screen.getByLabelText('Estado'), '0')
  await screen.findByText('No hay series para estos filtros.')
  expect(getSeries).toHaveBeenLastCalledWith({ doc_type: '', is_active: '0', page: 1 }, expect.any(AbortSignal))
})
