import { useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router'
import { ApiError } from '../lib/api'
import { createCreditNote, documentError } from '../lib/documents'
import type { CreditDetail, DraftDocument } from '../lib/documents'
import { dashboardMoney } from '../lib/dashboard'
import { emissionLabels } from '../lib/issuance'

interface Props {
  doc: DraftDocument
  credit: CreditDetail
  canWrite: boolean
  onSaved: (doc: DraftDocument) => void
  onSessionExpired: () => void
}

export default function CreditNotePanel({doc, credit, canWrite, onSaved, onSessionExpired}: Props) {
  const [open, setOpen] = useState(false)
  const [selected, setSelected] = useState<string[]>([])
  const [reason, setReason] = useState('')
  const [series, setSeries] = useState(credit.series[0]?.id ?? '')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const submitting = useRef(false)
  const money = (value: bigint | number) => dashboardMoney(String(value), doc.currency)
  const amount = doc.items.reduce((sum, line) => sum + (line.id && selected.includes(line.id) ? line.total_cents ?? 0 : 0), 0)
  const net = BigInt(credit.balance.net_cents) - BigInt(amount)
  const due = net - BigInt(credit.balance.paid_cents)
  const available = doc.items.some(line => line.id && !credit.states[line.id])

  async function save(event: FormEvent) {
    event.preventDefault()
    if (submitting.current) return
    submitting.current = true
    setBusy(true)
    setError('')
    try {
      const note = await createCreditNote(doc.id, {series_id:series, reason:reason.trim(), item_ids:selected})
      onSaved(note)
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) onSessionExpired()
      else setError(e instanceof ApiError && e.status === 409 ? e.message + ' Actualiza el estado del comprobante.' : documentError(e))
    } finally {
      submitting.current = false
      setBusy(false)
    }
  }
  return <section className="emission-panel" aria-label="Notas de crédito">
    <h3>Notas de crédito</h3>
    {credit.notes.length > 0 && <ul>{credit.notes.map(note => <li key={note.id}>
      <Link to={'/documents/'+note.id}>{note.number ? note.series_code+'-'+String(note.number).padStart(8,'0') : 'Borrador de nota'}</Link>
      {' · '+emissionLabels[note.status]+' · '+money(note.total_cents)}
    </li>)}</ul>}
    {!available && <p>Todas las líneas están reservadas o acreditadas.</p>}
    {canWrite && !open && <button onClick={() => setOpen(true)}>Crear nota de crédito</button>}
    {open && <form onSubmit={save}>
      <fieldset disabled={busy}><legend>Líneas a devolver</legend>
        {doc.items.map(line => {
          const id = line.id!
          const state = credit.states[id]
          return <label key={id} className="credit-line">
            <input type="checkbox" value={id} disabled={Boolean(state)} checked={selected.includes(id)}
              onChange={e => setSelected(e.target.checked ? [...selected,id] : selected.filter(value => value !== id))}/>
            <span>{line.description} · {money(line.total_cents ?? 0)} · {state === 'reserved' ? 'Reservada' : state === 'applied' ? 'Acreditada' : 'Disponible'}</span>
          </label>
        })}
        <label htmlFor="credit-series">Serie de la nota</label>
        <select id="credit-series" required value={series} onChange={e => setSeries(e.target.value)}>
          {credit.series.map(option => <option key={option.id} value={option.id}>{option.code}</option>)}
        </select>
        {!credit.series.length && <p>El titular debe activar una serie 07 con prefijo {doc.doc_type === '01' ? 'F' : 'B'} en Series.</p>}
        <label htmlFor="credit-reason">Motivo de devolución</label>
        <input id="credit-reason" required maxLength={250} value={reason} onChange={e => setReason(e.target.value)}/>
      </fieldset>
      <h4>Si se acepta esta nota</h4>
      <dl className="collection-balances" aria-live="polite">
        <div><dt>Importe de la nota</dt><dd>{money(amount)}</dd></div>
        <div><dt>Neto de la venta</dt><dd>{money(net)}</dd></div>
        <div><dt>Saldo por cobrar</dt><dd>{money(due > 0n ? due : 0n)}</dd></div>
        <div><dt>Exceso por revisar</dt><dd>{money(due < 0n ? -due : 0n)}</dd></div>
      </dl>
      {error && <p className="error" role="alert">{error}</p>}
      <div className="form-actions"><button disabled={busy || !selected.length || !series || !reason.trim()}>Guardar nota en borrador</button>
        <button type="button" className="secondary" disabled={busy} onClick={() => setOpen(false)}>Cancelar</button></div>
    </form>}
  </section>
}
