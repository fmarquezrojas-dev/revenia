import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { ApiError } from '../lib/api'
import { dashboardMoney } from '../lib/dashboard'
import { getReceivables, situationLabels } from '../lib/receivables'
import type { Receivables, Situation } from '../lib/receivables'
import DataTable from './ui/DataTable'
import DocumentLookup from './DocumentLookup'

const situations: Situation[] = ['upcoming', 'today', 'overdue', 'undated']
const bands: Situation[] = ['late_1_30', 'late_31_60', 'late_61_90', 'late_over_90']
type Result = { key: string; data?: Receivables; error?: string }

function dateLabel(value: string) {
  return value.split('-').reverse().join('/')
}

export default function ReceivablesPanel({ onSessionExpired }: { onSessionExpired: () => void }) {
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const [chooseClient, setChooseClient] = useState(false)
  const [revision, setRevision] = useState(0)
  const [result, setResult] = useState<Result>()
  const query = params.toString()
  const key = revision + ':' + query
  const currency = params.get('currency') ?? 'PEN'
  const situation = params.get('situation')
  const filtered = ['customer_id', 'operations', 'q', 'situation', 'due_from', 'due_to'].some(field => Boolean(params.get(field)))

  useEffect(() => {
    const controller = new AbortController()
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(20000)])
    getReceivables(query, signal).then(data => {
      if (!controller.signal.aborted) setResult({ key, data })
    }).catch((error: unknown) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) { onSessionExpired(); return }
      const message = error instanceof ApiError && error.status === 422 ? 'Revisa los filtros y el orden de las fechas de vencimiento.'
        : error instanceof ApiError && error.status === 403 ? 'Tu rol no tiene acceso a la cartera.'
        : 'No se pudo cargar la cartera. Revisa la conexión y vuelve a intentarlo.'
      setResult({ key, error: message })
    })
    return () => controller.abort()
  }, [query, key, onSessionExpired])

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const fields = new FormData(event.currentTarget)
    const next = new URLSearchParams()
    fields.forEach((value, field) => { if (String(value).trim()) next.set(field, String(value).trim()) })
    setParams(next)
    setRevision(value => value + 1)
  }

  function href(field: 'situation' | 'page', value: string) {
    const next = new URLSearchParams(params)
    next.delete('page')
    if (value) next.set(field, value)
    else next.delete(field)
    return '/receivables?' + next.toString()
  }

  const visible = result?.key === key ? result : undefined
  const data = visible?.data
  function cards(keys: Situation[], summary: Receivables) {
    return <div className="dashboard-cards aging-cards">{keys.map(item =>
      <Link key={item} className="dashboard-card" aria-current={situation === item ? 'true' : undefined} to={href('situation', item)}>
        <span>{situationLabels[item]}</span><strong>{dashboardMoney(summary.summary[item], summary.currency)}</strong>
      </Link>
    )}</div>
  }

  return <div className="dashboard receivables">
    <section className="surface" aria-label="Filtros de cartera">
      <button className="secondary" aria-expanded={chooseClient} onClick={() => setChooseClient(!chooseClient)}>Estado de cuenta por cliente</button>
      {chooseClient && <DocumentLookup kind="customer" includeInactive currency={currency} onSessionExpired={onSessionExpired}
        onProduct={() => {}} onCustomer={client => navigate('/customers/'+client.id+'/statement?currency='+currency)}/>}
      {params.has('customer_id') && <p>Cartera del cliente seleccionado · <Link to={'/customers/'+params.get('customer_id')+'/statement?currency='+currency}>Estado de cuenta</Link></p>}
      <form className="dashboard-filters" key={query} onSubmit={submit}
        onReset={() => {
          setParams({ currency: currency === 'USD' ? 'USD' : 'PEN' })
        }}>
        {params.has('customer_id') && <input type="hidden" name="customer_id" value={params.get('customer_id') ?? ''}/>}
        <div><label htmlFor="cartera-q">Cliente o comprobante</label><input type="search" id="cartera-q" name="q" maxLength={120} defaultValue={params.get('q') ?? ''} /></div>
        <div><label htmlFor="cartera-currency">Moneda</label><select id="cartera-currency" name="currency" defaultValue={currency}><option value="PEN">PEN · Soles</option><option value="USD">USD · Dólares</option></select></div>
        <div><label htmlFor="cartera-operations">Vista de cobranza</label><select id="cartera-operations" name="operations" defaultValue={params.get('operations') ?? ''}>
          <option value="">Saldos pendientes</option><option value="pending">Pendientes de confirmación</option><option value="review">Por revisar</option>
        </select></div>
        <div><label htmlFor="cartera-situation">Situación</label><select id="cartera-situation" name="situation" defaultValue={situation ?? ''}>
          <option value="">Todas</option>{situations.map(item => <option key={item} value={item}>{situationLabels[item]}</option>)}
          <optgroup label="Días de atraso">{bands.map(item => <option key={item} value={item}>{situationLabels[item]}</option>)}</optgroup>
        </select></div>
        <div><label htmlFor="cartera-from">Vencimiento desde</label><input id="cartera-from" type="date" name="due_from" defaultValue={params.get('due_from') ?? ''} /></div>
        <div><label htmlFor="cartera-to">Vencimiento hasta</label><input id="cartera-to" type="date" name="due_to" defaultValue={params.get('due_to') ?? ''} /></div>
        <div><label htmlFor="cartera-sort">Orden por vencimiento</label><select id="cartera-sort" name="sort" defaultValue={params.get('sort') ?? 'oldest'}><option value="oldest">Más antiguo primero</option><option value="newest">Más reciente primero</option></select></div>
        <button type="submit">Aplicar filtros</button><button type="reset" className="secondary">Limpiar filtros</button>
      </form>
    </section>
    {!visible && <p role="status">Cargando cartera…</p>}
    {visible?.error && <section className="surface"><p className="error" role="alert">{visible.error}</p><button className="secondary" onClick={() => setRevision(value => value + 1)}>Reintentar</button></section>}
    {data && <>
      <section aria-labelledby="cartera-summary"><div className="module-toolbar"><div>
        <h2 id="cartera-summary">Saldo pendiente de la selección</h2>
        <strong className="receivables-total">{dashboardMoney(data.summary.total, data.currency)}</strong>
        <p className="muted">Cartera actual al <time dateTime={data.cutoff}>{dateLabel(data.cutoff)}</time> · {data.timezone}</p>
      </div><button className="secondary" onClick={() => setRevision(value => value + 1)}>Actualizar</button></div>
      {cards(situations, data)}</section>
      <section aria-labelledby="cartera-bands"><h2 id="cartera-bands">Saldo vencido por días de atraso</h2>{cards(bands, data)}</section>
      <section aria-labelledby="cartera-documents"><h2 id="cartera-documents">{params.has('operations') ? 'Operaciones de cobro' : 'Comprobantes pendientes'}</h2>
        {data.total === 0 ? <p className="surface state-panel" role="status">{filtered ? 'No hay comprobantes que coincidan con estos filtros.' : 'Tu empresa no tiene saldos pendientes en ' + data.currency + '.'}</p> :
          <DataTable label="Detalle de cobranza por comprobante" headers={['Comprobante', 'Cliente', 'Emisión', 'Vencimiento', 'Situación', 'Días de atraso', 'Saldo pendiente', 'Seguimiento']}>
            {data.rows.map(row => <tr key={row.id}>
              <td><Link to={'/documents/' + row.id} state={{ receivablesSearch: query }}>{row.number}</Link>{row.needs_review && <small>Requiere revisión</small>}{row.pending_confirmation && <small>Pendiente de confirmación</small>}</td><td className="primary-cell">{row.customer}{row.customer_id && <small><Link to={'/customers/'+row.customer_id+'/statement?currency='+data.currency}>Estado de cuenta</Link></small>}</td>
              <td>{dateLabel(row.issue_date)}</td><td>{row.due_date ? dateLabel(row.due_date) : 'Sin vencimiento informado'}</td>
              <td>{row.bucket === 'settled' ? 'Saldado' : row.bucket.startsWith('late_') ? 'Vencido' : situationLabels[row.bucket]}</td>
              <td className="numeric">{row.days_late ?? '—'}</td><td className="numeric">{dashboardMoney(row.outstanding_cents, row.currency)}</td>
              <td><Link to={'/receivables/' + row.id + '/followup'}>Seguimiento</Link></td>
            </tr>)}
          </DataTable>}
        {data.total > 0 && <nav className="customer-pagination" aria-label="Paginación de cartera">
          <span>{data.total} comprobantes · Página {data.page} de {data.last_page}</span>
          {data.page > 1 && <Link className="button secondary" to={href('page', String(data.page - 1))}>Anterior</Link>}
          {data.page < data.last_page && <Link className="button secondary" to={href('page', String(data.page + 1))}>Siguiente</Link>}
        </nav>}
      </section>
    </>}
  </div>
}
