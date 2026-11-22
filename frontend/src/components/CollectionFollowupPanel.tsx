import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { ApiError, prepareCsrf, request } from '../lib/api'
import { dashboardMoney } from '../lib/dashboard'
import { actions, channels, results, day, instant, followupError, useFollowupRead, type Action, type Entry, type Followup } from '../lib/followup'

type Props = { onSessionExpired: () => void }
type Editor = { action: Action; contact?: Entry }
const labels: Record<Action, string> = {assign: 'Asignar responsable', contact: 'Registrar gestión', correct: 'Corregir gestión', promise: 'Registrar compromiso', reschedule: 'Reprogramar', cancel: 'Cancelar compromiso', fulfill: 'Confirmar cumplimiento'}

function EventText({entry, currency}: {entry: Entry; currency: string}) {
  const d = entry.details
  return <>
    {entry.kind === 'assign' && <p>{d.name ?? 'Sin asignar'}</p>}
    {d.channel && d.result && <p>{channels[d.channel]} · {results[d.result]}{d.next_action_on && ' · Próxima acción ' + day(d.next_action_on)}</p>}
    {d.note && <p className="followup-note">{d.note}</p>}
    {d.previous_amount_cents && <p>Acuerdo anterior · {dashboardMoney(d.previous_amount_cents, currency)} · {day(d.previous_due_on)}</p>}
    {d.amount_cents && <p>Importe acordado · {dashboardMoney(d.amount_cents, currency)} · {day(d.due_on)}</p>}
    {d.covered_cents !== undefined && <p>Pagos acreditados al registrar · {dashboardMoney(d.covered_cents, currency)}</p>}
    {d.corrected_revision && <p>Corrección de la gestión #{d.corrected_revision}</p>}
    {d.reason && <p className="followup-note">Motivo · {d.reason}</p>}
    {entry.evidence_review && <p role="status">Cumplimiento en revisión · evidencia vigente {dashboardMoney(entry.confirmed_cents ?? '0', currency)}</p>}
  </>
}

function Fields({editor, data}: {editor: Editor; data: Followup}) {
  const action = editor.action
  const original = editor.contact?.current_details ?? editor.contact?.details
  return <>
    {action === 'assign' && <label>Responsable<select name="assignee_id" defaultValue={data.assignee?.id ?? ''}>
      <option value="">Sin asignar</option>{data.people.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
    </select></label>}
    {(action === 'contact' || action === 'correct') && <>
      <label>Canal<select required name="channel" defaultValue={original?.channel ?? ''}><option value="">Seleccionar canal</option>
        {Object.entries(channels).map(([k,v]) => <option key={k} value={k}>{v}</option>)}</select></label>
      <label>Resultado<select required name="result" defaultValue={original?.result ?? ''}><option value="">Seleccionar resultado</option>
        {Object.entries(results).map(([k,v]) => <option key={k} value={k}>{v}</option>)}</select></label>
      <label>Observación<textarea required maxLength={1500} name="note" defaultValue={original?.note}/></label>
      <label>Próxima acción (opcional)<input type="date" name="next_action_on" defaultValue={original?.next_action_on ?? ''}/></label>
      <p className="muted">Registra el contacto que ya realizaste con el cliente.</p>
    </>}
    {(action === 'promise' || action === 'reschedule') && <>
      <label>{action === 'reschedule' ? 'Nuevo importe pendiente' : 'Importe comprometido'} ({data.currency})
        <input required name="amount" inputMode="decimal" maxLength={20} pattern="[0-9]+([.,][0-9]{1,2})?" placeholder="0.00"/></label>
      {action === 'reschedule' && <p>Los {dashboardMoney(data.promise!.covered_cents, data.currency)} ya acreditados se conservan dentro del total del acuerdo.</p>}
      <label>Fecha acordada<input required name="due_on" type="date" defaultValue={data.promise?.due_on}/></label>
    </>}
    {['reschedule','cancel','correct'].includes(action) && <label>Motivo<textarea required name="reason" maxLength={1500}/></label>}
    {action === 'fulfill' && <p>Se verificará nuevamente la evidencia de los pagos aplicados.</p>}
  </>
}

function File({id, onSessionExpired}: Props & {id: string}) {
  const [params, setParams] = useSearchParams()
  const [refresh, setRefresh] = useState(0)
  const [editor, setEditor] = useState<Editor>()
  const [notice, setNotice] = useState('')
  const [failure, setFailure] = useState('')
  const [busy, setBusy] = useState(false)
  const pending = useRef<AbortController | null>(null)
  useEffect(() => () => { pending.current?.abort() }, [])
  const base = '/api/v1/receivables/' + encodeURIComponent(id) + '/followup'
  const result = useFollowupRead<Followup>(base + '?page=' + encodeURIComponent(params.get('page') ?? '1'), onSessionExpired, refresh)
  const data = result?.data
  function start(action: Action, contact?: Entry) { setFailure(''); setNotice(''); setEditor({action, contact}) }
  function reload() { setEditor(undefined); setFailure(''); setRefresh(v => v + 1) }
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (pending.current || !editor || !data) return
    const form = new FormData(event.currentTarget)
    const body: Record<string,string | number | null> = {revision: data.revision}
    form.forEach((value, key) => { body[key] = String(value).trim() || null })
    if (form.has('amount')) {
      const match = /^(\d+)(?:[.,](\d{1,2}))?$/.exec(String(form.get('amount')))
      if (!match) { setFailure('Introduce un importe con hasta dos decimales.'); return }
      body.amount_cents = (BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0'))).toString()
      body.currency = data.currency
      delete body.amount
    }
    if (editor.contact) body.contact_id = editor.contact.id
    if (['reschedule','cancel','fulfill'].includes(editor.action)) body.promise_id = data.promise!.id
    const controller = new AbortController()
    pending.current = controller
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(20000)])
    setBusy(true); setFailure('')
    try {
      await prepareCsrf(signal)
      await request(base + '/' + editor.action, {method: 'POST', body: JSON.stringify(body), signal})
      if (!controller.signal.aborted) { setNotice('Seguimiento actualizado.'); setParams({}); reload() }
    } catch (error) {
      if (!controller.signal.aborted) {
        if (error instanceof ApiError && error.status === 401) onSessionExpired()
        else setFailure(followupError(error))
      }
    } finally {
      pending.current = null
      if (!controller.signal.aborted) setBusy(false)
    }
  }
  if (!data) return result?.error ? <section className="surface"><p role="alert">{result.error}</p><button onClick={reload}>Actualizar ficha</button></section> : <p role="status">Cargando seguimiento…</p>
  const positive = BigInt(data.outstanding_cents) > 0n
  const p = data.promise
  const button = (action: Action, contact?: Entry) => <button disabled={busy} className="secondary" onClick={() => start(action, contact)}>{labels[action]}</button>
  return <div className="dashboard">
    <section className="surface"><div className="module-toolbar"><div><h2>Seguimiento · {data.number}</h2><p>{data.customer} · <Link to={'/documents/' + id}>Ver comprobante</Link></p></div>
      <button disabled={busy} className="secondary" onClick={reload}>Actualizar ficha</button></div>
      <dl className="collection-balances">
        <div><dt>Saldo vigente</dt><dd>{dashboardMoney(data.outstanding_cents, data.currency)}</dd></div>
        <div><dt>Pagos aplicados</dt><dd>{dashboardMoney(data.paid_cents, data.currency)}</dd></div>
        <div><dt>Créditos aceptados</dt><dd>{dashboardMoney(data.credits_cents, data.currency)}</dd></div>
        <div><dt>Vencimiento original</dt><dd>{day(data.due_date)}</dd></div>
        <div><dt>Responsable</dt><dd>{data.assignee?.name ?? 'Sin asignar'}{data.assignee && (!data.assignee.is_active || data.assignee.role === 'accountant') && ' · requiere reasignación'}</dd></div>
        <div><dt>Próxima acción</dt><dd>{day(data.next_action_on)}</dd></div>
      </dl>
      {data.needs_review && <p>El saldo financiero requiere revisión.</p>}
      <div className="form-actions">{data.can_assign && (positive || data.revision > 0) && button('assign')}{data.can_manage && positive && button('contact')}</div>
    </section>
    <section className="surface"><h3>Última gestión</h3>{data.last_contact ? <><p>{instant(data.last_contact.recorded_at)} · {data.last_contact.author}</p><EventText entry={data.last_contact} currency={data.currency}/></> : <p>Sin gestiones registradas.</p>}</section>
    <section className="surface"><h3>Compromiso activo</h3>{p ? <>
      <p><strong>{dashboardMoney(p.amount_cents, data.currency)}</strong> para el {day(p.due_on)} · Registrado el {instant(p.opened_at)}</p>
      <p>Pagos acreditados {dashboardMoney(p.covered_cents, data.currency)} · Pendiente {dashboardMoney(p.remaining_cents, data.currency)}</p>
      {p.overdue && <p>Compromiso vencido</p>}{p.review && <p role="status">Acuerdo en revisión por cambios en el saldo o en la evidencia de pago.</p>}
      <div className="form-actions">{data.can_manage && <>{positive && !data.needs_review && BigInt(p.remaining_cents) > 0n && button('reschedule')}{button('cancel')}{p.can_fulfill && button('fulfill')}</>}</div>
    </> : <><p>Sin compromiso activo.</p>{data.can_manage && positive && !data.needs_review && button('promise')}</>}</section>
    {failure && <p role="alert" className="error">{failure}</p>}{notice && <p role="status">{notice}</p>}
    {editor && <form className="surface followup-form" key={editor.action + (editor.contact?.id ?? '')} onSubmit={submit}>
      <h3>{labels[editor.action]}{editor.contact && ' #' + editor.contact.revision}</h3>
      <fieldset disabled={busy}><Fields editor={editor} data={data}/><div className="form-actions"><button>{busy ? 'Guardando…' : 'Guardar'}</button>
        <button type="button" className="secondary" onClick={() => setEditor(undefined)}>Volver a la ficha</button></div></fieldset>
    </form>}
    <section className="surface"><h3>Historial de seguimiento</h3>{!data.history.length && <p>Aún no hay movimientos de seguimiento.</p>}
      {data.history.map(e => <article className="followup-entry" key={e.id}><strong>{actions[e.kind]} · #{e.revision}</strong>
        <p className="muted">{instant(e.recorded_at)} · {e.author}</p><EventText entry={e} currency={data.currency}/>
        {e.kind === 'contact' && data.can_manage && button('correct', e)}</article>)}
      <nav className="customer-pagination" aria-label="Paginación del historial"><span>Página {data.page} de {data.last_page} · Hora de Lima</span>
        {data.page > 1 && <button disabled={busy} onClick={() => {setEditor(undefined);setParams({page: String(data.page - 1)})}}>Anterior</button>}
        {data.page < data.last_page && <button disabled={busy} onClick={() => {setEditor(undefined);setParams({page: String(data.page + 1)})}}>Siguiente</button>}
      </nav>
    </section>
  </div>
}
export default function CollectionFollowupPanel(props: Props) {
  const {id = ''} = useParams()
  return <File key={id} id={id} {...props}/>
}
