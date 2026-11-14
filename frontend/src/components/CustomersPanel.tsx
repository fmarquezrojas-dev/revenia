import { useModuleNavigation, useListFilters } from '../app/useModuleNavigation'
import DataTable from './ui/DataTable'
import { useEffect, useState } from 'react'
import { ApiError, customerErrorMessage, getCustomers } from '../lib/api'
import type { CustomersResponse, SessionUser } from '../lib/api'
import CustomerForm from './CustomerForm'
import CustomerDetails from './CustomerDetails'

interface Props {
  role: SessionUser['role']
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

type Result = { key: string; data?: CustomersResponse; error?: string }

export default function CustomersPanel({ role, onSessionExpired, onSavingChange }: Props) {
  const [view, setView] = useModuleNavigation('/customers')
  const [filters, setFilters] = useListFilters({ q: '', status: '', page: 1 })
  const [revision, setRevision] = useState(0)
  const [result, setResult] = useState<Result | null>(null)
  const [notice, setNotice] = useState('')
  const canWrite = role === 'owner' || role === 'issuer'
  const key = JSON.stringify([filters, revision])
  const current = result?.key === key ? result : null

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      getCustomers({ q: filters.q, is_active: filters.status, page: filters.page }, controller.signal)
        .then((data) => {
          if (controller.signal.aborted) return
          if (filters.page > data.meta.last_page) {
            setFilters((current) => ({ ...current, page: Math.max(1, data.meta.last_page) }))
            return
          }
          setResult({ key, data })
        })
        .catch((error: unknown) => {
          if (controller.signal.aborted) return
          if (error instanceof ApiError && error.status === 401) onSessionExpired()
          else setResult({ key, error: customerErrorMessage(error) })
        })
    }, 300)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [filters, key, onSessionExpired, setFilters])

  const changed = () => setRevision((value) => value + 1)

  if (view.mode === 'new' && !canWrite) return <p role="alert" className="error">Tu rol solo permite consultar.</p>

  if (view.mode === 'new' && canWrite) return (
    <CustomerForm onCancel={() => setView({ mode: 'list' })}
      onSaved={() => {
        setNotice('Cliente creado correctamente.')
        setFilters({ q: '', status: '', page: 1 })
        changed()
        setView({ mode: 'list' })
      }}
      onSessionExpired={onSessionExpired} onSavingChange={onSavingChange} />
  )

  if (view.mode === 'detail') return (
    <CustomerDetails key={view.id} id={view.id} canWrite={canWrite} onBack={() => setView({ mode: 'list' })}
      onChanged={changed} onSessionExpired={onSessionExpired} onSavingChange={onSavingChange} />
  )

  return (
    <div className="module-panel">
      {notice && <p className="success-message" role="status">{notice}</p>}
      {canWrite && <div className="module-toolbar"><span className="muted">Directorio de clientes</span><button className="primary-action" type="button" onClick={() => { setNotice(''); setView({ mode: 'new' }) }}>Nuevo cliente</button></div>}
      <div className="customer-filters">
        <div>
          <label htmlFor="customer-search">Buscar por nombre o documento</label>
          <input id="customer-search" type="search" maxLength={200} value={filters.q}
            onChange={(event) => setFilters((current) => ({ ...current, q: event.target.value, page: 1 }))} />
        </div>
        <div>
          <label htmlFor="customer-status">Estado</label>
          <select id="customer-status" value={filters.status}
            onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value, page: 1 }))}>
            <option value="">Todos</option><option value="1">Activos</option><option value="0">Inactivos</option>
          </select>
        </div>
      </div>
      {Object.entries(filters).some(([key, value]) => key !== 'page' && value !== '') && <button className="secondary" onClick={() => setFilters({ q: '', status: '', page: 1 })}>Limpiar filtros</button>}
      {!current && <p role="status">Cargando clientes…</p>}
      {current?.error && <div><p className="error" role="alert">{current.error}</p>
        <button type="button" className="secondary" onClick={changed}>Reintentar</button></div>}
      {current?.data && (
        <>
          <p className="muted" role="status">{current.data.meta.total} clientes encontrados</p>
          {current.data.data.length === 0 ? <p className="empty-state">No hay clientes para estos filtros.</p> : (
            <DataTable label="Clientes" headers={['Cliente', 'Documento', 'Contacto', 'Estado', 'Acciones']}>
              {current.data.data.map((customer) => <tr key={customer.id}>
                <td className="primary-cell">{customer.name}</td><td>{customer.doc_type.toUpperCase()} {customer.doc_number}</td>
                <td>{customer.email || 'Sin correo'}</td><td><span className={`status-badge ${customer.is_active ? 'active' : 'inactive'}`}>{customer.is_active ? 'Activo' : 'Inactivo'}</span></td>
                <td><button type="button" className="secondary" aria-label={`Ver cliente ${customer.name}`} onClick={() => { setNotice(''); setView({ mode: 'detail', id: customer.id }) }}>Ver detalle</button></td>
              </tr>)}
            </DataTable>
          )}
          <nav className="customer-pagination" aria-label="Paginación de clientes">
            <button type="button" className="secondary" disabled={filters.page <= 1}
              onClick={() => setFilters((current) => ({ ...current, page: current.page - 1 }))}>Anterior</button>
            <span>Página {filters.page} de {current.data.meta.last_page}</span>
            <button type="button" className="secondary" disabled={filters.page >= current.data.meta.last_page}
              onClick={() => setFilters((current) => ({ ...current, page: current.page + 1 }))}>Siguiente</button>
          </nav>
        </>
      )}
    </div>
  )
}
