import { useModuleNavigation, useListFilters } from '../app/useModuleNavigation'
import DataTable from './ui/DataTable'
import { lastSeriesNumber, seriesTypeLabels } from '../lib/series'
import { useEffect, useState } from 'react'
import { ApiError, seriesErrorMessage, getSeries } from '../lib/api'
import type { SeriesResponse, SessionUser } from '../lib/api'
import SeriesForm from './SeriesForm'
import SeriesDetails from './SeriesDetails'

interface Props {
  role: SessionUser['role']
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

type Result = { key: string; data?: SeriesResponse; error?: string }

export default function SeriesPanel({ role, onSessionExpired, onSavingChange }: Props) {
  const [view, setView] = useModuleNavigation('/series')
  const [filters, setFilters] = useListFilters({ doc_type: '', status: '', page: 1 })
  const [revision, setRevision] = useState(0)
  const [result, setResult] = useState<Result | null>(null)
  const [notice, setNotice] = useState('')
  const canWrite = role === 'owner'
  const key = JSON.stringify([filters, revision])
  const current = result?.key === key ? result : null

  useEffect(() => {
    if (!canWrite) return
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      getSeries({ doc_type: filters.doc_type, is_active: filters.status, page: filters.page }, controller.signal)
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
          else setResult({ key, error: seriesErrorMessage(error) })
        })
    }, 300)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [filters, key, onSessionExpired, canWrite, setFilters])

  const changed = () => setRevision((value) => value + 1)

  if (!canWrite) return <p role="alert">Solo el dueño puede administrar series.</p>

  if (view.mode === 'new' && canWrite) return (
    <SeriesForm onCancel={() => setView({ mode: 'list' })}
      onSaved={() => {
        setNotice('Serie creada correctamente.')
        setFilters({ doc_type: '', status: '', page: 1 })
        changed()
        setView({ mode: 'list' })
      }}
      onSessionExpired={onSessionExpired} onSavingChange={onSavingChange} />
  )

  if (view.mode === 'detail') return (
    <SeriesDetails key={view.id} id={view.id} onBack={() => setView({ mode: 'list' })}
      onChanged={changed} onSessionExpired={onSessionExpired} onSavingChange={onSavingChange} />
  )

  return (
    <div className="module-panel">
      {notice && <p className="success-message" role="status">{notice}</p>}
      {canWrite && <div className="module-toolbar"><span className="muted">Configuración de numeración</span><button className="primary-action" type="button" onClick={() => { setNotice(''); setView({ mode: 'new' }) }}>Nueva serie</button></div>}
      <div className="customer-filters">
        <div><label htmlFor="series-type">Tipo de comprobante</label>
          <select id="series-type" value={filters.doc_type} onChange={(event) => setFilters((current) => ({ ...current, doc_type: event.target.value, page: 1 }))}>
            <option value="">Todos</option>{Object.entries(seriesTypeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select></div>
        <div>
          <label htmlFor="series-status">Estado</label>
          <select id="series-status" value={filters.status}
            onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value, page: 1 }))}>
            <option value="">Todos</option><option value="1">Activas</option><option value="0">Inactivas</option>
          </select>
        </div>
      </div>
      {Object.entries(filters).some(([key, value]) => key !== 'page' && value !== '') && <button className="secondary" onClick={() => setFilters({ doc_type: '', status: '', page: 1 })}>Limpiar filtros</button>}
      {!current && <p role="status">Cargando series…</p>}
      {current?.error && <div><p className="error" role="alert">{current.error}</p>
        <button type="button" className="secondary" onClick={changed}>Reintentar</button></div>}
      {current?.data && (
        <>
          <p className="muted" role="status">{current.data.meta.total} series encontradas</p>
          {current.data.data.length === 0 ? <p className="empty-state">No hay series para estos filtros.</p> : (
            <DataTable label="Series" headers={['Serie', 'Tipo de comprobante', 'Último correlativo', 'Estado', 'Acciones']}>
              {current.data.data.map((series) => <tr key={series.id}>
                <td className="primary-cell">{series.code}</td><td>{seriesTypeLabels[series.doc_type]}</td><td className="numeric">{lastSeriesNumber(series)}</td>
                <td><span className={`status-badge ${series.is_active ? 'active' : 'inactive'}`}>{series.is_active ? 'Activa' : 'Inactiva'}</span></td>
                <td><button type="button" className="secondary" aria-label={`Ver serie ${series.code}`} onClick={() => { setNotice(''); setView({ mode: 'detail', id: series.id }) }}>Ver detalle</button></td>
              </tr>)}
            </DataTable>
          )}
          <nav className="customer-pagination" aria-label="Paginación de series">
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
