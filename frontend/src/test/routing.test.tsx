import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, expect, it, vi } from 'vitest'
import { render } from './render'
import App from '../App'
import { safeDestination } from '../app/navigation'
import { ApiError, getSession, login, logout, getProducts, getCustomers, getSeries } from '../lib/api'
import type { SessionUser } from '../lib/api'
vi.mock('../lib/api', async (original) => ({
  ...await original<typeof import('../lib/api')>(), getSession: vi.fn(), login: vi.fn(), logout: vi.fn(),
  getProducts: vi.fn(), getCustomers: vi.fn(), getSeries: vi.fn(),
}))
const owner: SessionUser = { id: '1', name: 'Dueño Demo', role: 'owner', role_label: 'Dueño', email: 'demo@example.test', company: { id: 'company', legal_name: 'Empresa Demo', trade_name: null } }
const empty = { data: [], meta: { current_page: 1, last_page: 2, per_page: 15, total: 0 } }
beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(getSession).mockResolvedValue(owner)
  vi.mocked(login).mockResolvedValue(owner)
  vi.mocked(logout).mockResolvedValue(undefined)
  vi.mocked(getProducts).mockResolvedValue(empty)
  vi.mocked(getCustomers).mockResolvedValue(empty)
  vi.mocked(getSeries).mockResolvedValue(empty)
})
it('no muestra contenido privado mientras comprueba la sesion', async () => {
  let finish!: (user: SessionUser) => void
  vi.mocked(getSession).mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  render(<App />, '/products')
  expect(screen.getByRole('status')).toHaveTextContent('Comprobando')
  expect(screen.queryByRole('navigation')).not.toBeInTheDocument()
  expect(getProducts).not.toHaveBeenCalled()
  await act(async () => finish(owner))
  await screen.findByRole('heading', { name: 'Productos y servicios' })
})
it.each(['issuer', 'accountant'] as const)('deniega URL administrativa directa a %s', async (role) => {
  vi.mocked(getSession).mockResolvedValue({ ...owner, role })
  render(<App />, '/series')
  await screen.findByRole('heading', { name: 'Acceso restringido' })
  expect(getSeries).not.toHaveBeenCalled()
  expect(screen.queryByRole('link', { name: 'Series' })).not.toBeInTheDocument()
})
it('inicia sesion y conserva solo el destino interno y sus filtros', async () => {
  vi.mocked(getSession).mockRejectedValue(new ApiError(401, {}))
  const { router } = render(<App />, '/products?currency=USD&page=2')
  await screen.findByRole('heading', { name: 'Inicia sesión' })
  expect(router.state.location.pathname).toBe('/acceso')
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('Correo electrónico'), 'demo@example.test')
  await user.type(screen.getByLabelText('Contraseña'), 'test-only')
  await user.click(screen.getByRole('button', { name: 'Mostrar contraseña' }))
  expect(screen.getByLabelText('Contraseña')).toHaveAttribute('type', 'text')
  await user.click(screen.getByRole('button', { name: 'Ingresar' }))
  await screen.findByRole('heading', { name: 'Productos y servicios' })
  expect(router.state.location.search).toBe('?currency=USD&page=2')
})
it.each(['https://example.com', '//example.com', '/\\evil.test', '/api/v1/me', '/acceso', '/%2f%2fevil.test'])('descarta retorno inseguro %s', (value) => {
  expect(safeDestination(value)).toBe('/dashboard')
})
it('mantiene filtros al retroceder y avanzar y distingue 404 de permisos', async () => {
  const { router } = render(<App />, '/customers?q=Ana')
  await screen.findByRole('heading', { name: 'Clientes' })
  await act(async () => { await router.navigate('/products?currency=USD') })
  await screen.findByRole('heading', { name: 'Productos y servicios' })
  await act(async () => { await router.navigate(-1) })
  expect(screen.getByLabelText('Buscar por nombre o documento')).toHaveValue('Ana')
  await act(async () => { await router.navigate(1) })
  expect(screen.getByLabelText('Moneda')).toHaveValue('USD')
  await act(async () => { await router.navigate('/missing') })
  expect(screen.getByRole('heading', { name: 'No encontramos esta página' })).toBeInTheDocument()
})
it('limpia datos privados despues de cerrar sesion', async () => {
  const { router } = render(<App />, '/account')
  await screen.findByRole('heading', { name: 'Hola, Dueño Demo' })
  await userEvent.click(screen.getByText('Dueño Demo'))
  await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }))
  await screen.findByRole('heading', { name: 'Inicia sesión' })
  expect(router.state.location.pathname).toBe('/acceso')
  expect(screen.queryByText('Empresa Demo')).not.toBeInTheDocument()
})
it('redirige una sesion expirada sin conservar la tabla privada', async () => {
  vi.mocked(getProducts).mockRejectedValue(new ApiError(401, {}))
  const { router } = render(<App />, '/products')
  await waitFor(() => expect(router.state.location.pathname).toBe('/acceso'))
  expect(screen.queryByRole('table')).not.toBeInTheDocument()
  expect(await screen.findByRole('alert')).toHaveTextContent('Inicia sesión nuevamente')
})
it('protege cambios locales al intentar salir del formulario', async () => {
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
  const { router } = render(<App />, '/customers/new')
  await userEvent.type(await screen.findByLabelText('Nombre o razón social'), 'Cambio local')
  expect(screen.getByText('Cambios sin guardar')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('link', { name: 'Productos y servicios' }))
  await waitFor(() => expect(confirm).toHaveBeenCalled())
  expect(router.state.location.pathname).toBe('/customers/new')
})
