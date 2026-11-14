import { render } from '../test/render'
import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CustomersPanel from './CustomersPanel'
import CustomerForm from './CustomerForm'
import { ApiError, createCustomer, getCustomer, getCustomers, updateCustomer } from '../lib/api'
import type { Customer, CustomersResponse, SessionUser } from '../lib/api'

vi.mock('../lib/api', async (importOriginal) => ({
  ...await importOriginal<typeof import('../lib/api')>(),
  getCustomers: vi.fn(),
  getCustomer: vi.fn(),
  createCustomer: vi.fn(),
  updateCustomer: vi.fn(),
}))

const customer: Customer = {
  id: 'customer-1', doc_type: 'dni', doc_number: '00123456', name: 'Ana Cliente',
  email: null, address: null, is_active: true, created_at: null, updated_at: null,
}
const response = (data = [customer]): CustomersResponse => ({
  data, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length },
})
const callbacks = () => ({ onSessionExpired: vi.fn(), onSavingChange: vi.fn() })

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(getCustomers).mockResolvedValue(response())
  vi.mocked(getCustomer).mockResolvedValue(customer)
  vi.mocked(createCustomer).mockResolvedValue(customer)
  vi.mocked(updateCustomer).mockResolvedValue(customer)
})

describe('permisos de clientes', () => {
  it.each(['owner', 'issuer', 'accountant'] as SessionUser['role'][])('respeta el rol %s', async (role) => {
    const user = userEvent.setup()
    render(<CustomersPanel role={role} {...callbacks()} />)
    await screen.findByText(customer.name)
    expect(Boolean(screen.queryByRole('button', { name: 'Nuevo cliente' }))).toBe(role !== 'accountant')
    await user.click(screen.getByRole('button', { name: 'Ver cliente Ana Cliente' }))
    await screen.findByText('No registrada')
    expect(Boolean(screen.queryByRole('button', { name: 'Editar cliente' }))).toBe(role !== 'accountant')
    expect(Boolean(screen.queryByRole('button', { name: 'Desactivar' }))).toBe(role !== 'accountant')
  })

  it('confirma desactivación y permite cancelar', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const user = userEvent.setup()
    render(<CustomersPanel role="issuer" {...callbacks()} />)
    await user.click(await screen.findByRole('button', { name: 'Ver cliente Ana Cliente' }))
    await user.click(await screen.findByRole('button', { name: 'Desactivar' }))
    expect(updateCustomer).not.toHaveBeenCalled()
    confirm.mockReturnValue(true)
    vi.mocked(updateCustomer).mockResolvedValue({ ...customer, is_active: false })
    await user.click(screen.getByRole('button', { name: 'Desactivar' }))
    await screen.findByRole('button', { name: 'Reactivar' })
    expect(updateCustomer).toHaveBeenCalledWith(customer.id, { is_active: false })
  })
})

describe('formulario de clientes', () => {
  it('muestra errores por campo y conserva los valores', async () => {
    vi.mocked(createCustomer).mockRejectedValue(new ApiError(422, { errors: { doc_number: ['Documento duplicado.'] } }))
    const user = userEvent.setup()
    render(<CustomerForm {...callbacks()} onCancel={vi.fn()} onSaved={vi.fn()} />)
    await user.selectOptions(screen.getByLabelText('Tipo de documento'), 'dni')
    await user.type(screen.getByLabelText('Número de documento'), '00123456')
    await user.type(screen.getByLabelText('Nombre o razón social'), 'Ana')
    await user.click(screen.getByRole('button', { name: 'Guardar cliente' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Documento duplicado.')
    expect(screen.getByLabelText('Número de documento')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Nombre o razón social')).toHaveValue('Ana')
    expect(createCustomer).toHaveBeenCalledWith({
      doc_type: 'dni', doc_number: '00123456', name: 'Ana', address: null, email: null,
    })
  })

  it.each([403, 419, 500])('conserva datos ante error %s', async (status) => {
    vi.mocked(updateCustomer).mockRejectedValue(new ApiError(status, {}))
    const user = userEvent.setup()
    render(<CustomerForm customer={customer} {...callbacks()} onCancel={vi.fn()} onSaved={vi.fn()} />)
    await user.click(screen.getByRole('button', { name: 'Guardar cliente' }))
    await screen.findByRole('alert')
    expect(screen.getByLabelText('Número de documento')).toHaveValue('00123456')
    expect(screen.getByRole('button', { name: 'Guardar cliente' })).toBeEnabled()
  })

  it('notifica una sesión expirada', async () => {
    const props = callbacks()
    vi.mocked(updateCustomer).mockRejectedValue(new ApiError(401, {}))
    render(<CustomerForm customer={customer} {...props} onCancel={vi.fn()} onSaved={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cliente' }))
    await waitFor(() => expect(props.onSessionExpired).toHaveBeenCalledOnce())
  })

  it('bloquea el formulario durante una escritura', async () => {
    let finish!: (value: Customer) => void
    vi.mocked(updateCustomer).mockImplementation(() => new Promise((resolve) => { finish = resolve }))
    const props = callbacks()
    render(<CustomerForm customer={customer} {...props} onCancel={vi.fn()} onSaved={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cliente' }))
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled()
    expect(screen.getByLabelText('Nombre o razón social')).toBeDisabled()
    expect(props.onSavingChange).toHaveBeenCalledWith(true)
    await act(async () => finish(customer))
    expect(props.onSavingChange).toHaveBeenLastCalledWith(false)
  })
})

describe('búsqueda y paginación', () => {
  it('descarta una respuesta antigua aunque el transporte ignore abort', async () => {
    let finishOld!: (value: CustomersResponse) => void
    vi.mocked(getCustomers).mockImplementationOnce(() => new Promise((resolve) => { finishOld = resolve }))
    render(<CustomersPanel role="accountant" {...callbacks()} />)
    await waitFor(() => expect(getCustomers).toHaveBeenCalledOnce())
    const firstSignal = vi.mocked(getCustomers).mock.calls[0][1]
    await userEvent.type(screen.getByLabelText('Buscar por nombre o documento'), 'Ana')
    await screen.findByText('Ana Cliente')
    expect(firstSignal?.aborted).toBe(true)
    await act(async () => finishOld(response([{ ...customer, name: 'Respuesta antigua' }])))
    expect(screen.queryByText('Respuesta antigua')).not.toBeInTheDocument()
    expect(screen.getByText('Ana Cliente')).toBeInTheDocument()
    expect(getCustomers).toHaveBeenLastCalledWith({ q: 'Ana', is_active: '', page: 1 }, expect.any(AbortSignal))
  })

  it('envía página y filtro de estado y muestra lista vacía', async () => {
    vi.mocked(getCustomers).mockResolvedValue({ ...response(), meta: { current_page: 1, last_page: 2, per_page: 15, total: 16 } })
    render(<CustomersPanel role="owner" {...callbacks()} />)
    await userEvent.click(await screen.findByRole('button', { name: 'Siguiente' }))
    await waitFor(() => expect(getCustomers).toHaveBeenLastCalledWith({ q: '', is_active: '', page: 2 }, expect.any(AbortSignal)))
    vi.mocked(getCustomers).mockResolvedValue(response([]))
    await userEvent.selectOptions(screen.getByLabelText('Estado'), '0')
    await screen.findByText('No hay clientes para estos filtros.')
    expect(getCustomers).toHaveBeenLastCalledWith({ q: '', is_active: '0', page: 1 }, expect.any(AbortSignal))
  })
})
