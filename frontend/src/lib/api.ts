export interface SessionUser {
  id: string
  name: string
  email: string
  role: 'owner' | 'issuer' | 'accountant'
  role_label: string
  company: {
    id: string
    legal_name: string
    trade_name: string | null
  }
}

interface ErrorPayload {
  message?: string
  errors?: Record<string, string[]>
}

export class ApiError extends Error {
  readonly status: number
  readonly errors?: Record<string, string[]>

  constructor(status: number, payload: ErrorPayload) {
    super(payload.message ?? 'No se pudo completar la solicitud.')
    this.name = 'ApiError'
    this.status = status
    this.errors = payload.errors
  }
}

function readCookie(name: string): string | null {
  const prefix = `${name}=`
  const cookie = document.cookie
    .split('; ')
    .find((item) => item.startsWith(prefix))

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null
}

export async function request<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const method = (options.method ?? 'GET').toUpperCase()
  const headers = new Headers(options.headers)

  headers.set('Accept', 'application/json')

  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }

  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    const csrfToken = readCookie('XSRF-TOKEN')

    if (csrfToken) {
      headers.set('X-XSRF-TOKEN', csrfToken)
    }
  }

  const response = await fetch(path, {
    ...options,
    method,
    headers,
    credentials: 'include',
  })

  if (!response.ok) {
    const payload = (await response.json().catch(() => ({
      message: 'El servidor devolvió una respuesta inesperada.',
    }))) as ErrorPayload

    throw new ApiError(response.status, payload)
  }

  if (response.status === 204) {
    return undefined as T
  }

  return response.json() as Promise<T>
}

export async function prepareCsrf(signal?: AbortSignal): Promise<void> {
  await request<void>('/sanctum/csrf-cookie', { signal })
}

export async function getSession(): Promise<SessionUser> {
  const response = await request<{ data: SessionUser }>('/api/v1/me')
  return response.data
}

export async function login(
  email: string,
  password: string,
): Promise<SessionUser> {
  await prepareCsrf()

  await request<void>('/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })

  return getSession()
}

export async function logout(): Promise<void> {
  await prepareCsrf()

  await request<void>('/logout', {
    method: 'POST',
  })
 
}

export interface Company {
  id: string
  ruc: string
  legal_name: string
  trade_name: string | null
  address: string
  email: string
  is_active: boolean
}

export async function getCompany(
  signal?: AbortSignal,
): Promise<Company> {
  const response = await request<{ data: Company }>('/api/v1/company', {
    signal,
  })

  return response.data
}

export interface CompanyUpdate {
  legal_name: string
  trade_name: string | null
  address: string
  email: string
}

export async function updateCompany(
  data: CompanyUpdate,
): Promise<Company> {
  await prepareCsrf()

  const response = await request<{ data: Company }>('/api/v1/company', {
    method: 'PATCH',
    body: JSON.stringify(data),
  })

  return response.data
}

export interface ManagedUser {
  id: string
  name: string
  email: string
  role: SessionUser['role']
  role_label: string
  is_active: boolean
  created_at: string | null
}

export interface UsersResponse {
  data: ManagedUser[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export async function getUsers(
  signal?: AbortSignal,
): Promise<UsersResponse> {
  return request<UsersResponse>('/api/v1/users?per_page=100', {
    signal,
  })
}
export interface CreateUserInput {
  name: string
  email: string
  role: SessionUser['role']
  password: string
  password_confirmation: string
}

export interface UpdateUserInput {
  name: string
  role: SessionUser['role']
  is_active: boolean
}

export async function createUser(data: CreateUserInput): Promise<ManagedUser> {
  await prepareCsrf()
  const response = await request<{ data: ManagedUser }>('/api/v1/users', {
    method: 'POST',
    body: JSON.stringify({
      name: data.name, email: data.email, role: data.role,
      password: data.password, password_confirmation: data.password_confirmation,
    }),
  })
  return response.data
}

export async function updateUser(id: string, data: UpdateUserInput): Promise<ManagedUser> {
  await prepareCsrf()
  const response = await request<{ data: ManagedUser }>(`/api/v1/users/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: JSON.stringify({ name: data.name, role: data.role, is_active: data.is_active }),
  })
  return response.data
}

export interface Customer {
  id: string
  doc_type: 'ruc' | 'dni' | 'other'
  doc_number: string
  name: string
  address: string | null
  email: string | null
  is_active: boolean
  created_at: string | null
  updated_at: string | null
}

export type CustomerInput = Pick<Customer, 'doc_type' | 'doc_number' | 'name' | 'address' | 'email'>
export type CustomerUpdate = Partial<CustomerInput & Pick<Customer, 'is_active'>>
export interface CustomersResponse {
  data: Customer[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export async function getCustomers(
  filters: { q: string; is_active: string; page: number },
  signal?: AbortSignal,
): Promise<CustomersResponse> {
  const params = new URLSearchParams({ page: String(filters.page), per_page: '15' })
  if (filters.q.trim()) params.set('q', filters.q.trim())
  if (filters.is_active !== '') params.set('is_active', filters.is_active)
  return request<CustomersResponse>(`/api/v1/customers?${params}`, { signal })
}

export async function getCustomer(id: string, signal?: AbortSignal): Promise<Customer> {
  const response = await request<{ data: Customer }>(`/api/v1/customers/${encodeURIComponent(id)}`, { signal })
  return response.data
}

function customerPayload(data: CustomerUpdate): CustomerUpdate {
  const result: CustomerUpdate = {}
  if (data.doc_type !== undefined) result.doc_type = data.doc_type
  if (data.doc_number !== undefined) result.doc_number = data.doc_number
  if (data.name !== undefined) result.name = data.name
  if (data.address !== undefined) result.address = data.address
  if (data.email !== undefined) result.email = data.email
  if (data.is_active !== undefined) result.is_active = data.is_active
  return result
}

export async function createCustomer(data: CustomerInput): Promise<Customer> {
  await prepareCsrf()
  const payload = customerPayload(data)
  delete payload.is_active
  const response = await request<{ data: Customer }>('/api/v1/customers', {
    method: 'POST', body: JSON.stringify(payload),
  })
  return response.data
}

export async function updateCustomer(id: string, data: CustomerUpdate): Promise<Customer> {
  await prepareCsrf()
  const response = await request<{ data: Customer }>(`/api/v1/customers/${encodeURIComponent(id)}`, {
    method: 'PATCH', body: JSON.stringify(customerPayload(data)),
  })
  return response.data
}

export function customerErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 403) return 'Tu cuenta no tiene permiso para realizar esta operación.'
    if (error.status === 404) return 'Este cliente no está disponible.'
    if (error.status === 419) return 'La sesión de seguridad venció. Intenta nuevamente.'
    if (error.status === 422) return Object.values(error.errors ?? {}).flat().join(' ') || 'Revisa los datos ingresados.'
  }
  return 'No pudimos completar la operación. Revisa la conexión e intenta nuevamente. Los datos del formulario se conservan.'
}

export interface Product {
  id: string
  code: string
  description: string
  unit_code: 'NIU' | 'ZZ'
  currency: 'PEN' | 'USD'
  unit_price_cents: number
  tax_type: '10' | '20' | '30'
  is_active: boolean
  created_at: string | null
  updated_at: string | null
}
export type ProductInput = Pick<Product, 'code' | 'description' | 'unit_code' | 'currency' | 'unit_price_cents' | 'tax_type'>
export type ProductUpdate = Partial<ProductInput & Pick<Product, 'is_active'>>
export interface ProductsResponse {
  data: Product[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export async function getProducts(
  filters: { q: string; is_active: string; currency: string; tax_type: string; page: number },
  signal?: AbortSignal,
): Promise<ProductsResponse> {
  const params = new URLSearchParams({ page: String(filters.page), per_page: '15' })
  if (filters.q.trim()) params.set('q', filters.q.trim())
  if (filters.is_active !== '') params.set('is_active', filters.is_active)
  if (filters.currency) params.set('currency', filters.currency)
  if (filters.tax_type) params.set('tax_type', filters.tax_type)
  return request<ProductsResponse>(`/api/v1/products?${params}`, { signal })
}

export async function getProduct(id: string, signal?: AbortSignal): Promise<Product> {
  const response = await request<{ data: Product }>(`/api/v1/products/${encodeURIComponent(id)}`, { signal })
  return response.data
}

function productPayload(data: ProductUpdate): ProductUpdate {
  const result: ProductUpdate = {}
  if (data.code !== undefined) result.code = data.code
  if (data.description !== undefined) result.description = data.description
  if (data.unit_code !== undefined) result.unit_code = data.unit_code
  if (data.currency !== undefined) result.currency = data.currency
  if (data.unit_price_cents !== undefined) result.unit_price_cents = data.unit_price_cents
  if (data.tax_type !== undefined) result.tax_type = data.tax_type
  if (data.is_active !== undefined) result.is_active = data.is_active
  return result
}

export async function createProduct(data: ProductInput): Promise<Product> {
  await prepareCsrf()
  const payload = productPayload(data)
  delete payload.is_active
  const response = await request<{ data: Product }>('/api/v1/products', {
    method: 'POST', body: JSON.stringify(payload),
  })
  return response.data
}

export async function updateProduct(id: string, data: ProductUpdate): Promise<Product> {
  await prepareCsrf()
  const response = await request<{ data: Product }>(`/api/v1/products/${encodeURIComponent(id)}`, {
    method: 'PATCH', body: JSON.stringify(productPayload(data)),
  })
  return response.data
}

export function productErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 403) return 'Tu cuenta no tiene permiso para realizar esta operación.'
    if (error.status === 404) return 'Este producto no está disponible.'
    if (error.status === 419) return 'La sesión de seguridad venció. Intenta nuevamente.'
    if (error.status === 422) return Object.values(error.errors ?? {}).flat().join(' ') || 'Revisa los datos ingresados.'
  }
  return 'No pudimos completar la operación. Revisa la conexión e intenta nuevamente. Los datos del formulario se conservan.'
}

export interface DocumentSeries {
  id: string
  doc_type: '01' | '03' | '07'
  code: string
  current_number: number
  is_active: boolean
  created_at: string | null
  updated_at: string | null
}
export type SeriesInput = Pick<DocumentSeries, 'doc_type' | 'code'>
export interface SeriesResponse {
  data: DocumentSeries[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}
export async function getSeries(filters: { doc_type: string; is_active: string; page: number }, signal?: AbortSignal): Promise<SeriesResponse> {
  const params = new URLSearchParams({ page: String(filters.page), per_page: '15' })
  if (filters.doc_type) params.set('doc_type', filters.doc_type)
  if (filters.is_active !== '') params.set('is_active', filters.is_active)
  return request<SeriesResponse>(`/api/v1/series?${params}`, { signal })
}
export async function getSeriesDetail(id: string, signal?: AbortSignal): Promise<DocumentSeries> {
  const response = await request<{ data: DocumentSeries }>(`/api/v1/series/${encodeURIComponent(id)}`, { signal })
  return response.data
}
export async function createSeries(data: SeriesInput): Promise<DocumentSeries> {
  await prepareCsrf()
  const response = await request<{ data: DocumentSeries }>('/api/v1/series', {
    method: 'POST', body: JSON.stringify({ doc_type: data.doc_type, code: data.code }),
  })
  return response.data
}
export async function updateSeries(id: string, data: Pick<DocumentSeries, 'is_active'>): Promise<DocumentSeries> {
  await prepareCsrf()
  const response = await request<{ data: DocumentSeries }>(`/api/v1/series/${encodeURIComponent(id)}`, {
    method: 'PATCH', body: JSON.stringify({ is_active: data.is_active }),
  })
  return response.data
}
export function seriesErrorMessage(error: unknown): string {
  if (error instanceof ApiError && error.status === 404) return 'Esta serie no está disponible.'
  return productErrorMessage(error)
}
