import { render } from '../test/render'
import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProductsPanel from './ProductsPanel'
import ProductForm from './ProductForm'
import { ApiError, createProduct, getProduct, getProducts, updateProduct } from '../lib/api'
import type { Product, ProductsResponse, SessionUser } from '../lib/api'

vi.mock('../lib/api', async (importOriginal) => ({
  ...await importOriginal<typeof import('../lib/api')>(),
  getProducts: vi.fn(),
  getProduct: vi.fn(),
  createProduct: vi.fn(),
  updateProduct: vi.fn(),
}))

const product: Product = {
  id: 'product-1', code: '00123456', description: 'Ana Producto',
  currency: 'PEN', unit_code: 'NIU', unit_price_cents: 1050, tax_type: '10', is_active: true, created_at: null, updated_at: null,
}
const response = (data = [product]): ProductsResponse => ({
  data, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length },
})
const callbacks = () => ({ onSessionExpired: vi.fn(), onSavingChange: vi.fn() })

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(getProducts).mockResolvedValue(response())
  vi.mocked(getProduct).mockResolvedValue(product)
  vi.mocked(createProduct).mockResolvedValue(product)
  vi.mocked(updateProduct).mockResolvedValue(product)
})

describe('permisos de productos', () => {
  it.each(['owner', 'issuer', 'accountant'] as SessionUser['role'][])('respeta el rol %s', async (role) => {
    const user = userEvent.setup()
    render(<ProductsPanel role={role} {...callbacks()} />)
    await screen.findByText(product.description)
    expect(Boolean(screen.queryByRole('button', { name: 'Nuevo producto o servicio' }))).toBe(role !== 'accountant')
    await user.click(screen.getByRole('button', { name: 'Ver producto Ana Producto' }))
    await screen.findByText('Unidad de bien (NIU)')
    expect(Boolean(screen.queryByRole('button', { name: 'Editar producto' }))).toBe(role !== 'accountant')
    expect(Boolean(screen.queryByRole('button', { name: 'Desactivar' }))).toBe(role !== 'accountant')
  })

  it('confirma desactivación y permite cancelar', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const user = userEvent.setup()
    render(<ProductsPanel role="issuer" {...callbacks()} />)
    await user.click(await screen.findByRole('button', { name: 'Ver producto Ana Producto' }))
    await user.click(await screen.findByRole('button', { name: 'Desactivar' }))
    expect(updateProduct).not.toHaveBeenCalled()
    confirm.mockReturnValue(true)
    vi.mocked(updateProduct).mockResolvedValue({ ...product, is_active: false })
    await user.click(screen.getByRole('button', { name: 'Desactivar' }))
    await screen.findByRole('button', { name: 'Reactivar' })
    expect(updateProduct).toHaveBeenCalledWith(product.id, { is_active: false })
  })
})

describe('formulario de productos', () => {
  it('muestra errores por campo y conserva los valores', async () => {
    vi.mocked(createProduct).mockRejectedValue(new ApiError(422, { errors: { code: ['Codigo duplicado.'] } }))
    const user = userEvent.setup()
    render(<ProductForm {...callbacks()} onCancel={vi.fn()} onSaved={vi.fn()} />)
    await user.type(screen.getByLabelText('Precio unitario sin IGV'), '10,50')
    await user.type(screen.getByLabelText('Código'), '00123456')
    await user.type(screen.getByLabelText('Descripción'), 'Ana')
    await user.click(screen.getByRole('button', { name: 'Guardar producto' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Codigo duplicado.')
    expect(screen.getByLabelText('Código')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Descripción')).toHaveValue('Ana')
    expect(createProduct).toHaveBeenCalledWith({
      code: '00123456', description: 'Ana', currency: 'PEN', unit_code: 'NIU', tax_type: '10', unit_price_cents: 1050,
    })
  })

  it.each([403, 419, 500])('conserva datos ante error %s', async (status) => {
    vi.mocked(updateProduct).mockRejectedValue(new ApiError(status, {}))
    const user = userEvent.setup()
    render(<ProductForm product={product} {...callbacks()} onCancel={vi.fn()} onSaved={vi.fn()} />)
    await user.click(screen.getByRole('button', { name: 'Guardar producto' }))
    await screen.findByRole('alert')
    expect(screen.getByLabelText('Código')).toHaveValue('00123456')
    expect(screen.getByRole('button', { name: 'Guardar producto' })).toBeEnabled()
  })

  it('notifica una sesión expirada', async () => {
    const props = callbacks()
    vi.mocked(updateProduct).mockRejectedValue(new ApiError(401, {}))
    render(<ProductForm product={product} {...props} onCancel={vi.fn()} onSaved={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: 'Guardar producto' }))
    await waitFor(() => expect(props.onSessionExpired).toHaveBeenCalledOnce())
  })

  it('bloquea el formulario durante una escritura', async () => {
    let finish!: (value: Product) => void
    vi.mocked(updateProduct).mockImplementation(() => new Promise((resolve) => { finish = resolve }))
    const props = callbacks()
    render(<ProductForm product={product} {...props} onCancel={vi.fn()} onSaved={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: 'Guardar producto' }))
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled()
    expect(screen.getByLabelText('Descripción')).toBeDisabled()
    expect(props.onSavingChange).toHaveBeenCalledWith(true)
    await act(async () => finish(product))
    expect(props.onSavingChange).toHaveBeenLastCalledWith(false)
  })
})

describe('búsqueda y paginación', () => {
  it('descarta una respuesta antigua aunque el transporte ignore abort', async () => {
    let finishOld!: (value: ProductsResponse) => void
    vi.mocked(getProducts).mockImplementationOnce(() => new Promise((resolve) => { finishOld = resolve }))
    render(<ProductsPanel role="accountant" {...callbacks()} />)
    await waitFor(() => expect(getProducts).toHaveBeenCalledOnce())
    const firstSignal = vi.mocked(getProducts).mock.calls[0][1]
    await userEvent.type(screen.getByLabelText('Buscar por código o descripción'), 'Ana')
    await screen.findByText('Ana Producto')
    expect(firstSignal?.aborted).toBe(true)
    await act(async () => finishOld(response([{ ...product, description: 'Respuesta antigua' }])))
    expect(screen.queryByText('Respuesta antigua')).not.toBeInTheDocument()
    expect(screen.getByText('Ana Producto')).toBeInTheDocument()
    expect(getProducts).toHaveBeenLastCalledWith({ q: 'Ana', is_active: '', currency: '', tax_type: '', page: 1 }, expect.any(AbortSignal))
  })

  it('envía página y filtro de estado y muestra lista vacía', async () => {
    vi.mocked(getProducts).mockResolvedValue({ ...response(), meta: { current_page: 1, last_page: 2, per_page: 15, total: 16 } })
    render(<ProductsPanel role="owner" {...callbacks()} />)
    await userEvent.click(await screen.findByRole('button', { name: 'Siguiente' }))
    await waitFor(() => expect(getProducts).toHaveBeenLastCalledWith({ q: '', is_active: '', currency: '', tax_type: '', page: 2 }, expect.any(AbortSignal)))
    vi.mocked(getProducts).mockResolvedValue(response([]))
    await userEvent.selectOptions(screen.getByLabelText('Estado'), '0')
    await screen.findByText('No hay productos para estos filtros.')
    expect(getProducts).toHaveBeenLastCalledWith({ q: '', is_active: '0', currency: '', tax_type: '', page: 1 }, expect.any(AbortSignal))
  })
})

it('guarda la respuesta del servidor y cancelar descarta cambios locales', async () => {
  const user = userEvent.setup()
  render(<ProductsPanel role="owner" {...callbacks()} />)
  await user.click(await screen.findByRole('button', { name: 'Ver producto Ana Producto' }))
  await user.click(await screen.findByRole('button', { name: 'Editar producto' }))
  await user.clear(screen.getByLabelText('Descripción'))
  await user.type(screen.getByLabelText('Descripción'), 'Temporal')
  await user.click(screen.getByRole('button', { name: 'Cancelar' }))
  expect(updateProduct).not.toHaveBeenCalled()
  expect(screen.queryByText('Temporal')).not.toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Editar producto' }))
  expect(screen.getByLabelText('Descripción')).toHaveValue(product.description)
  vi.mocked(updateProduct).mockResolvedValue({ ...product, description: 'Respuesta servidor' })
  await user.click(screen.getByRole('button', { name: 'Guardar producto' }))
  await screen.findByText('Respuesta servidor')
})
