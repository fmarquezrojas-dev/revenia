import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useLocation, useParams, useSearchParams } from 'react-router'
import { ApiError, prepareCsrf, request } from '../lib/api'
import { metricLabels } from '../lib/dashboard'
import { actions, explanation, guidance, historyLabels, incidentDate, processes, states } from '../lib/incidents'
import type { Incident, IncidentAction, IncidentDetail, IncidentPage } from '../lib/incidents'

interface Props { onSessionExpired: () => void; onSavingChange: (busy: boolean) => void; actorId: string }
const api = '/api/v1/incidents'
const alerts = ['queued','failed','pdf_failed','email_failed','collection_review','odoo_review'] as const
const recordPath = (row: Incident) => '/incidents/' + row.process + '/' + row.id

function useRead<T>(url: string, revision: number, expired: () => void) {
  const [result, setResult] = useState<{url: string; revision: number; data?: T; error?: string}>()
  useEffect(() => {
    const controller = new AbortController()
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(20000)])
    request<{data: T}>(url, {signal, cache:'no-store'}).then(r => {
      if (!controller.signal.aborted) setResult({url, revision, data:r.data})
    }).catch(e => {
      if (controller.signal.aborted) return
      if (e instanceof ApiError && e.status === 401) expired()
      else setResult({url, revision, error:e instanceof ApiError && e.status === 404 ? 'No se encontró la operación.' :
        e instanceof ApiError && e.status === 422 ? 'Revisa los filtros y el intervalo de fechas.' : 'No se pudo consultar Incidencias.'})
    })
    return () => controller.abort()
  }, [url, revision, expired])
  return result?.url === url && result.revision === revision ? result : undefined
}

function Options({values}: {values: Record<string,string>}) {
  return Object.entries(values).map(([key,title]) => <option key={key} value={key}>{title}</option>)
}

function Inbox({onSessionExpired}: Pick<Props,'onSessionExpired'>) {
  const [params,setParams] = useSearchParams()
  const [revision,refresh] = useState(0)
  const query = params.toString()
  const result = useRead<IncidentPage>(api + '?' + query, revision, onSessionExpired)
  const data = result?.data
  function filter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const next = new URLSearchParams()
    for (const [key,value] of new FormData(event.currentTarget)) {
      const text = String(value).trim()
      if (text) next.set(key,text)
    }
    setParams(next)
  }
  function page(value: number) {
    const next = new URLSearchParams(params)
    next.set('page',String(value))
    setParams(next)
  }
  return <section className="surface">
    <form key={query} className="dashboard-filters incident-filters" onSubmit={filter}>
      <label>Proceso<select name="process" defaultValue={params.get('process') ?? ''}><option value="">Todos</option><Options values={processes}/></select></label>
      <label>Situación<select name="state" defaultValue={params.get('state') ?? (params.has('alert') ? 'all' : 'attention')}><option value="attention">Requieren atención</option><option value="all">Todas las situaciones</option><Options values={states}/></select></label>
      <label>Desde<input type="date" name="from" defaultValue={params.get('from') ?? ''}/></label>
      <label>Hasta<input type="date" name="to" defaultValue={params.get('to') ?? ''}/></label>
      <label>Moneda<select name="currency" defaultValue={params.get('currency') ?? ''}><option value="">Todas</option><option>PEN</option><option>USD</option></select></label>
      <label>Referencia<input name="q" maxLength={100} placeholder="Número o identificador" defaultValue={params.get('q') ?? ''}/></label>
      {params.has('alert') && <label>Alerta de Resumen<select name="alert" defaultValue={params.get('alert')!}><option value="">Todas</option>{alerts.map(key => <option key={key} value={key}>{metricLabels[key]}</option>)}</select></label>}
      <button>Aplicar filtros</button><button type="button" className="secondary" onClick={() => setParams({})}>Limpiar filtros</button>
    </form>
    <p className="muted">Fechas de la última actualización del proceso, en hora de Lima.</p>
    <button className="secondary" onClick={() => refresh(v => v + 1)}>Actualizar bandeja</button>
    {!result && <p role="status">Cargando operaciones…</p>}
    {result?.error && <p role="alert">{result.error}</p>}
    {data && <><p>{data.total} operaciones · Página {data.page} de {data.last_page}</p>
      {data.rows.length ? <div className="dashboard-table" role="region" aria-label="Incidencias por operación" tabIndex={0}><table>
        <thead><tr>{['Proceso y comprobante','Situación','Primera detección','Última actualización','Acción disponible'].map(t => <th key={t} scope="col">{t}</th>)}</tr></thead>
        <tbody>{data.rows.map(row => <tr key={row.process+row.id}>
          <td><Link to={recordPath(row)+'?'+query}>{processes[row.process]}</Link><small>{row.number ?? 'Sin comprobante confirmado'}</small></td>
          <td>{states[row.state]}</td><td>{incidentDate(row.first_detected_at)}</td><td>{incidentDate(row.updated_at)}</td>
          <td><Link to={recordPath(row)+'?'+query}>{row.action === 'retry_email' ? 'Revisar opciones de envío' : row.action ? actions[row.action] : 'Ver cronología'}</Link></td>
        </tr>)}</tbody></table></div> : <p>No hay operaciones para estos filtros.</p>}
      <nav className="customer-pagination" aria-label="Paginación de incidencias">
        {data.page>1 && <button className="secondary" onClick={() => page(data.page-1)}>Anterior</button>}
        {data.page<data.last_page && <button className="secondary" onClick={() => page(data.page+1)}>Siguiente</button>}
      </nav></>}
  </section>
}

const confirmations: Record<IncidentAction,string> = {
  retry_odoo:'Consultar la clave de origen en Odoo y recuperar la transferencia a la compañía verificada.',
  reviewed:'Registrar que revisaste esta operación. Su estado seguirá el resultado del proceso.',
  retry_emission:'Solicitar la recuperación de la emisión. El proceso consultará la referencia original en el simulador antes de enviarla de nuevo.',
  regenerate_pdf:'Solicitar otra generación del PDF a partir del comprobante emitido.',
  retry_email:'Solicitar un nuevo envío al destinatario registrado en el comprobante con el PDF vigente.',
  reconcile:'Solicitar una consulta de esta orden a Mercado Pago en el entorno de pruebas.',
}
function Detail({onSessionExpired,onSavingChange,actorId,process,id}: Props & {process:string; id:string}) {
  const {search} = useLocation()
  const [revision,refresh] = useState(0)
  const [historyPage,setHistoryPage] = useState(1)
  const url = api + '/' + encodeURIComponent(process) + '/' + encodeURIComponent(id)
  const result = useRead<IncidentDetail>(url+'?history_page='+historyPage,revision,onSessionExpired)
  const item = result?.data
  const [confirm,setConfirm] = useState<IncidentAction|null>(null)
  const [busy,setBusy] = useState(false)
  const [notice,setNotice] = useState('')
  const sending = useRef(false)
  const keys = useRef<Record<string,string>>({})
  async function submit() {
    if (!item || !confirm || sending.current) return
    sending.current = true
    setBusy(true); onSavingChange(true); setNotice('')
    const slot = ['revenia:incidents',actorId,process,id,confirm,item.revision].join(':')
    try {
      let key = keys.current[slot]
      if (!key) {
        try { key = sessionStorage.getItem(slot) ?? '' } catch { /* Optional browser storage. */ }
        keys.current[slot] = key ||= crypto.randomUUID()
        try { sessionStorage.setItem(slot,key) } catch { /* Keep the in-memory key. */ }
      }
      const signal = AbortSignal.timeout(20000)
      await prepareCsrf(signal)
      await request(url+'/actions',{method:'POST', signal,headers:{'Idempotency-Key':key},body:JSON.stringify({action:confirm,revision:item.revision})})
      setNotice(confirm === 'reviewed' ? 'Revisión registrada en la cronología.' : 'Solicitud registrada. Consulta la cronología para seguir el resultado.')
      clearKey()
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) onSessionExpired()
      else if (e instanceof ApiError && [403,404,409,422,429].includes(e.status)) {
        clearKey()
        setNotice(e.status === 429 ? 'Se alcanzó el límite de solicitudes. Espera antes de volver a intentarlo.' :
          'La acción ya no está disponible con este estado o permiso. Revisa la operación actualizada.')
      } else setNotice('No se pudo confirmar la solicitud. Actualiza el historial antes de repetirla.')
    } finally {
      setConfirm(null); setBusy(false); onSavingChange(false); sending.current=false
      setHistoryPage(1); refresh(v => v+1)
    }
    function clearKey() {
      delete keys.current[slot]
      try { sessionStorage.removeItem(slot) } catch { /* Optional browser storage. */ }
    }
  }
  return <section className="surface">
    <Link to={'/incidents'+search}>← Volver a Incidencias</Link>
    {!result && <p role="status">Cargando detalle…</p>}{result?.error && <p role="alert">{result.error}</p>}
    {notice && <p role="status">{notice}</p>}
    {item && <><h2>{processes[item.process]} · {item.number ?? 'Sin comprobante confirmado'}</h2>
      <dl className="profile"><div><dt>Situación</dt><dd>{states[item.state]}</dd></div>
        {item.process === 'email' && <div><dt>Referencia de seguimiento</dt><dd className="webhook-url">{item.id}</dd></div>}
        <div><dt>{item.process === 'email' ? 'Última entrega' : 'Referencia de origen'}</dt><dd className="webhook-url">{item.reference}</dd></div>
        <div><dt>Primera detección</dt><dd>{incidentDate(item.first_detected_at)}</dd></div>
        <div><dt>Última actualización</dt><dd>{incidentDate(item.updated_at)}</dd></div>
        {item.next_attempt_at && <div><dt>Próximo intento programado</dt><dd>{incidentDate(item.next_attempt_at)}</dd></div>}
      </dl>
      {item.reason && <p>{explanation(item.reason)}</p>}<p>{guidance(item)}</p>
      {item.process === 'emission' && <p className="muted">Emisión tributaria simulada.</p>}
      {item.document_id && <Link to={'/documents/'+item.document_id+(item.process==='collection'?'#cobro-'+item.id:'')}>{item.process === 'collection' ? 'Abrir cobranza del comprobante' : 'Abrir comprobante'}</Link>}
      <div className="form-actions">{item.action && <button disabled={busy} onClick={() => setConfirm(item.action)}>{actions[item.action]}</button>}
        {item.can_review && <button className="secondary" disabled={busy} onClick={() => setConfirm('reviewed')}>Registrar revisión</button>}
      </div>
      {confirm && <div className="emission-confirm" role="group" aria-label="Confirmar acción"><p>{confirmations[confirm]}</p>
        <button disabled={busy} onClick={() => void submit()}>Confirmar</button> <button className="secondary" disabled={busy} onClick={() => setConfirm(null)}>Cancelar</button></div>}
      <h3>Cronología</h3>
      {item.history.events.length ? <ol className="emission-events">{item.history.events.map(e => <li key={e.id}>
        <strong>{historyLabels[e.event] ?? 'Actualización del proceso'}</strong><time dateTime={e.at}>{incidentDate(e.at)} · Lima</time>
        {e.actor && <span>{e.actor}</span>}{e.destination && <span>Compañía Odoo · {e.destination}</span>}{e.code && <span>{explanation(e.code)}</span>}</li>)}</ol> : <p>Sin eventos registrados para esta página.</p>}
      <nav className="customer-pagination" aria-label="Paginación de la cronología">
        {historyPage>1 && <button className="secondary" disabled={busy} onClick={() => setHistoryPage(v=>v-1)}>Más recientes</button>}
        {item.history.has_more && <button className="secondary" disabled={busy} onClick={() => setHistoryPage(v=>v+1)}>Anteriores</button>}
      </nav></>}
    <button className="secondary" disabled={busy} onClick={() => { setConfirm(null); refresh(v=>v+1) }}>Actualizar detalle</button>
  </section>
}
export default function IncidentsPanel(props: Props) {
  const {process,id} = useParams()
  return process && id ? <Detail key={process+id} process={process} id={id} {...props}/> : <Inbox onSessionExpired={props.onSessionExpired}/>
}
