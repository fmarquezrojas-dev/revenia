import { useModuleNavigation, useListFilters } from '../app/useModuleNavigation'
import DataTable from './ui/DataTable'
import { centsToDecimal, taxLabels } from '../lib/money'
import { useEffect, useState } from 'react'
import { ApiError, productErrorMessage, getProducts } from '../lib/api'
import type { ProductsResponse, SessionUser } from '../lib/api'
import ProductForm from './ProductForm'
import ProductDetails from './ProductDetails'

interface Props {
  role: SessionUser['role']
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

type Result = { key: string; data?: ProductsResponse; error?: string }

export default function ProductsPanel({ role, onSessionExpired, onSavingChange }: Props) {
  const [view, setView] = useModuleNavigation('/products')
  const [filters, setFilters] = useListFilters({ q: '', status: '', currency: '', tax_type: '', page: 1 })
  const [revision, setRevision] = useState(0)
  const [result, setResult] = useState<Result | null>(null)
  const [notice, setNotice] = useState('')
  const canWrite = role === 'owner' || role === 'issuer'
  const key = JSON.stringify([filters, revision])
  const current = result?.key === key ? result : null

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      getProducts({ q: filters.q, is_active: filters.status, currency: filters.currency, tax_type: filters.tax_type, page: filters.page }, controller.signal)
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
          else setResult({ key, error: productErrorMessage(error) })
        })
    }, 300)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [filters, key, onSessionExpired, setFilters])

  const changed = () => setRevision((value) => value + 1)

  if (view.mode === 'new' && !canWrite) return <p role="alert" className="error">Tu rol solo permite consultar.</p>

  if (view.mode === 'new' && canWrite) return (
    <ProductForm onCancel={() => setView({ mode: 'list' })}
      onSaved={() => {
        setNotice('Producto creado correctamente.')
        setFilters({ q: '', status: '', currency: '', tax_type: '', page: 1 })
        changed()
        setView({ mode: 'list' })
      }}
      onSessionExpired={onSessionExpired} onSavingChange={onSavingChange} />
  )

  if (view.mode === 'detail') return (
    <ProductDetails key={view.id} id={view.id} canWrite={canWrite} onBack={() => setView({ mode: 'list' })}
      onChanged={changed} onSessionExpired={onSessionExpired} onSavingChange={onSavingChange} />
  )

  return (
    <div className="module-panel">
      {notice && <p className="success-message" role="status">{notice}</p>}
      {canWrite && <div className="module-toolbar"><span className="muted">Catálogo de tu empresa</span><button className="primary-action" type="button" onClick={() => { setNotice(''); setView({ mode: 'new' }) }}>Nuevo producto o servicio</button></div>}
      <div className="customer-filters">
        <div>
          <label htmlFor="product-search">Buscar por código o descripción</label>
          <input id="product-search" type="search" maxLength={200} value={filters.q}
            onChange={(event) => setFilters((current) => ({ ...current, q: event.target.value, page: 1 }))} />
        </div>
        <div>
          <label htmlFor="product-status">Estado</label>
          <select id="product-status" value={filters.status}
            onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value, page: 1 }))}>
            <option value="">Todos</option><option value="1">Activos</option><option value="0">Inactivos</option>
          </select>
        </div>
        <div><label htmlFor="product-currency">Moneda</label>
          <select id="product-currency" value={filters.currency} onChange={(event) => setFilters((current) => ({ ...current, currency: event.target.value, page: 1 }))}>
            <option value="">Todas</option><option value="PEN">PEN</option><option value="USD">USD</option>
          </select></div>
        <div><label htmlFor="product-tax">Afectación</label>
          <select id="product-tax" value={filters.tax_type} onChange={(event) => setFilters((current) => ({ ...current, tax_type: event.target.value, page: 1 }))}>
            <option value="">Todas</option>{Object.entries(taxLabels).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
          </select></div>
      </div>
      {Object.entries(filters).some(([key, value]) => key !== 'page' && value !== '') && <button className="secondary" onClick={() => setFilters({ q: '', status: '', currency: '', tax_type: '', page: 1 })}>Limpiar filtros</button>}
      {!current && <p role="status">Cargando productos…</p>}
      {current?.error && <div><p className="error" role="alert">{current.error}</p>
        <button type="button" className="secondary" onClick={changed}>Reintentar</button></div>}
      {current?.data && (
        <>
          <p className="muted" role="status">{current.data.meta.total} productos encontrados</p>
          {current.data.data.length === 0 ? <p className="empty-state">No hay productos para estos filtros.</p> : (
            <DataTable label="Productos y servicios" headers={['Producto o servicio', 'Código', 'Precio sin IGV', 'Afectación', 'Estado', 'Acciones']}>
              {current.data.data.map((product) => <tr key={product.id}>
                <td className="primary-cell">{product.description}<small>{product.unit_code === 'NIU' ? 'Bien' : 'Servicio'}</small></td><td>{product.code}</td>
                <td className="numeric">{product.currency} {centsToDecimal(product.unit_price_cents)}</td><td>{taxLabels[product.tax_type]}</td>
                <td><span className={`status-badge ${product.is_active ? 'active' : 'inactive'}`}>{product.is_active ? 'Activo' : 'Inactivo'}</span></td>
                <td><button type="button" className="secondary" aria-label={`Ver producto ${product.description}`} onClick={() => { setNotice(''); setView({ mode: 'detail', id: product.id }) }}>Ver detalle</button></td>
              </tr>)}
            </DataTable>
          )}
          <nav className="customer-pagination" aria-label="Paginación de productos">
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
