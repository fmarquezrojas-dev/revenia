import { useEffect, useState, type FormEvent } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router'
import type { Customer } from '../lib/api'
import {
  bankCents,
  bankMoney,
  bankTime,
  bankUrl,
  eventLabels,
  labels,
  origins,
  useBankRead,
  useBankWrite,
  type Candidate,
  type Detail,
  type Page,
  type Receipt,
} from '../lib/bank'
import DocumentLookup from './DocumentLookup'
import DataTable from './ui/DataTable'

function Distribution({
  receipt,
  onSessionExpired,
  onApply,
  busy,
}: {
  receipt: Receipt
  onSessionExpired: () => void
  busy: boolean
  onApply: (lines: { document_id: string; amount_cents: string }[]) => void
}) {
  const [page, setPage] = useState(1)
  const state = useBankRead<Page<Candidate>>(
    bankUrl + '/' + receipt.id + '/candidates?page=' + page,
    receipt.revision,
    onSessionExpired,
  )
  const [entries, setEntries] = useState<Record<string, { number: string; value: string }>>({})
  const [error, setError] = useState('')
  const lines = Object.entries(entries).filter(([, v]) => v.value.trim())
  let total = 0n,
    valid = true
  for (const [, entry] of lines) {
    try {
      total += BigInt(bankCents(entry.value))
    } catch {
      valid = false
    }
  }
  function submit(e: FormEvent) {
    e.preventDefault()
    try {
      if (!lines.length || lines.length > 50) throw new Error('Selecciona entre 1 y 50 comprobantes.')
      if (total > BigInt(receipt.available_cents)) throw new Error('La distribución supera el disponible.')
      const allocations = lines.map(([id, v]) => ({ document_id: id, amount_cents: bankCents(v.value) }))
      setError('')
      onApply(allocations)
    } catch (err) {
      setError((err as Error).message)
    }
  }
  return (
    <section className="surface">
      <h3>Distribuir entre comprobantes</h3>
      <p>
        {receipt.customer} · {receipt.currency}
      </p>
      {!state && <p role="status">Consultando saldos…</p>}
      {state?.error && <p role="alert">{state.error}</p>}
      <form onSubmit={submit}>
        <fieldset disabled={busy} className="bank-distribution">
          {state?.data &&
            (state.data.total === 0 ? (
              <p>El cliente no tiene comprobantes pendientes en esta moneda.</p>
            ) : (
              <DataTable
                label="Distribución del abono"
                headers={['Comprobante', 'Vencimiento', 'Saldo', 'Reserva', 'Aplicación']}
              >
                {state.data.rows.map((d) => (
                  <tr key={d.id}>
                    <td>
                      <Link to={'/documents/' + d.id}>{d.number}</Link>
                    </td>
                    <td>{d.due_date ?? '—'}</td>
                    <td>{bankMoney(d.outstanding_cents, receipt.currency)}</td>
                    <td>{bankMoney(d.reserved_cents, receipt.currency)}</td>
                    <td>
                      {d.needs_review || d.pending_confirmation ? (
                        'Pendiente de revisión o confirmación'
                      ) : (
                        <input
                          aria-label={'Aplicar a ' + d.number}
                          inputMode="decimal"
                          maxLength={18}
                          placeholder="0.00"
                          disabled={d.available_cents === '0'}
                          value={entries[d.id]?.value ?? ''}
                          onChange={(e) =>
                            setEntries({ ...entries, [d.id]: { number: d.number, value: e.target.value } })
                          }
                        />
                      )}
                    </td>
                  </tr>
                ))}
              </DataTable>
            ))}
          {state?.data && state.data.last_page > 1 && (
            <nav className="customer-pagination" aria-label="Comprobantes pendientes">
              <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Anterior
              </button>
              Página {page} de {state.data.last_page}
              <button type="button" disabled={page >= state.data.last_page} onClick={() => setPage(page + 1)}>
                Siguiente
              </button>
            </nav>
          )}
          {lines.length > 0 && <p>{lines.map(([, v]) => v.number + ' · ' + v.value).join(' / ')}</p>}
          <p>
            Distribución propuesta ·{' '}
            <strong>{valid ? bankMoney(total.toString(), receipt.currency) : 'Revisa los importes'}</strong>
          </p>
          {valid && total <= BigInt(receipt.available_cents) && (
            <p>
              Remanente después de aplicar ·{' '}
              {bankMoney((BigInt(receipt.available_cents) - total).toString(), receipt.currency)}
            </p>
          )}
          <button disabled={!lines.length || !valid || total > BigInt(receipt.available_cents)}>
            Confirmar distribución
          </button>
        </fieldset>
      </form>
      {error && <p role="alert">{error}</p>}
    </section>
  )
}

export default function BankReceiptDetail({
  id,
  owner,
  onSessionExpired,
}: {
  id: string
  owner: boolean
  onSessionExpired: () => void
}) {
  const [params, setParams] = useSearchParams(),
    location = useLocation()
  const [version, refresh] = useState(0),
    [notice, setNotice] = useState<string>(location.state?.notice ?? '')
  const [mode, setMode] = useState<'identify' | 'distribute' | 'reverse' | null>(null),
    [target, setTarget] = useState(''),
    [customer, setCustomer] = useState<Customer>()
  const state = useBankRead<Detail>(bankUrl + '/' + id + '?' + params, version, onSessionExpired)
  const w = useBankWrite(onSessionExpired),
    data = state?.data,
    r = data?.receipt
  const selectedApplication = params.get('application')
  useEffect(() => {
    if (data && selectedApplication)
      document.getElementById('app-' + selectedApplication)?.scrollIntoView?.({ block: 'center' })
  }, [data, selectedApplication])
  function save(action: string, body: Record<string, unknown>, success: string) {
    if (!r) return
    void w.send(
      bankUrl + '/' + id + '/' + action,
      () => ({ ...body, revision: r.revision }),
      (response) => {
        setMode(null)
        setCustomer(undefined)
        setNotice(response.repeated ? 'Esta solicitud ya se había registrado.' : success)
        setParams({})
        refresh((v) => v + 1)
      },
    )
  }
  function page(key: string, n: number) {
    const next = new URLSearchParams(params)
    next.delete('application')
    next.set(key, String(n))
    setParams(next)
  }
  const pages = (p: Page<unknown>, key: string, title: string) => (
    <nav className="customer-pagination" aria-label={title}>
      {p.page > 1 && (
        <button disabled={w.busy} onClick={() => page(key, p.page - 1)}>
          Anterior
        </button>
      )}
      Página {p.page} de {p.last_page}
      {p.page < p.last_page && (
        <button disabled={w.busy} onClick={() => page(key, p.page + 1)}>
          Siguiente
        </button>
      )}
    </nav>
  )
  return (
    <>
      {notice && <p role="status">{notice}</p>}
      {(w.error || state?.error) && (
        <p role="alert" className="error">
          {w.error || state?.error}
        </p>
      )}
      <div className="module-toolbar">
        <h2>Detalle del abono</h2>
        <button
          className="secondary"
          disabled={w.busy}
          onClick={() => {
            setMode(null)
            refresh((v) => v + 1)
          }}
        >
          Actualizar
        </button>
      </div>
      {!state && <p role="status">Cargando abono…</p>}
      {data && r && (
        <>
          <section className="surface">
            <h3>
              {r.reference} · {r.alias}
            </h3>
            <p>
              {r.method === 'deposit' ? 'Depósito' : 'Transferencia'} · {origins[r.origin]} ·{' '}
              {labels[r.situation]}
            </p>
            <p>
              Fecha bancaria {r.bank_date} · Registro {bankTime(r.recorded_at)} · Confirmación{' '}
              {r.confirmed_at ? bankTime(r.confirmed_at) : 'Pendiente'}
            </p>
            <dl className="collection-balances">
              {[
                ['Recibido', r.amount_cents],
                ['Aplicado', r.applied_cents],
                ['Disponible por aplicar', r.available_cents],
              ].map(([label, amount]) => (
                <div key={label}>
                  <dt>{label}</dt>
                  <dd>{bankMoney(amount, r.currency)}</dd>
                </div>
              ))}
            </dl>
            {r.origin === 'customer' && (
              <p>
                Cliente ·{' '}
                {r.customer_id ? (
                  <Link to={'/customers/' + r.customer_id}>{r.customer}</Link>
                ) : (
                  'Pendiente de identificación'
                )}
              </p>
            )}
            {data.note && <p className="followup-note">{data.note}</p>}
            {owner && !r.confirmed_at && (
              <form
                className="followup-form"
                onSubmit={(e) => {
                  e.preventDefault()
                  const f = new FormData(e.currentTarget)
                  save('confirm', { origin: f.get('origin'), verified: true }, 'Ingreso bancario confirmado.')
                }}
              >
                <fieldset disabled={w.busy}>
                  <label>
                    Origen verificado
                    <select name="origin" defaultValue={r.origin}>
                      {r.origin === 'customer' && <option value="customer">{origins.customer}</option>}
                      <option value="mp_settlement">{origins.mp_settlement}</option>
                    </select>
                  </label>
                  <label>
                    <input type="checkbox" required /> Contrasté la cuenta, fecha, referencia, moneda, importe
                    y origen con el movimiento bancario.
                  </label>
                  <button>Confirmar ingreso bancario</button>
                </fieldset>
              </form>
            )}
            {owner && r.confirmed_at && r.origin === 'customer' && (
              <div className="form-actions">
                {r.applied_cents === '0' && (
                  <button disabled={w.busy} onClick={() => setMode('identify')}>
                    {r.customer_id ? 'Cambiar cliente' : 'Identificar cliente'}
                  </button>
                )}
                {r.customer_id && BigInt(r.available_cents) > 0n && (
                  <button disabled={w.busy} onClick={() => setMode('distribute')}>
                    Distribuir abono
                  </button>
                )}
              </div>
            )}
          </section>
          {mode === 'identify' && (
            <section className="surface">
              <h3>Identificar cliente</h3>
              <DocumentLookup
                kind="customer"
                includeInactive
                currency={r.currency}
                onCustomer={setCustomer}
                onProduct={() => {}}
                onSessionExpired={onSessionExpired}
              />
              {customer && (
                <button
                  disabled={w.busy}
                  onClick={() => save('identify', { customer_id: customer.id }, 'Cliente identificado.')}
                >
                  Confirmar cliente · {customer.name}
                </button>
              )}
            </section>
          )}
          {mode === 'distribute' && (
            <Distribution
              receipt={r}
              onSessionExpired={onSessionExpired}
              busy={w.busy}
              onApply={(lines) => save('apply', { lines }, 'Distribución aplicada a cartera.')}
            />
          )}
          <section className="surface">
            <h3>Aplicaciones a comprobantes</h3>
            {!data.applications.total && <p>Sin aplicaciones registradas.</p>}
            {data.applications.rows.map((a) => (
              <article key={a.id} className="followup-entry" id={'app-' + a.id}>
                <Link to={'/documents/' + a.document_id}>{a.number}</Link> ·{' '}
                <strong>{bankMoney(a.amount_cents, r.currency)}</strong>
                <p>
                  {selectedApplication === a.id && <strong>Aplicación seleccionada · </strong>}Aplicada por{' '}
                  {a.actor} · {bankTime(a.applied_at)}
                </p>
                {a.reversed_at ? (
                  <p>
                    Revertida por {a.reversed_by} · {bankTime(a.reversed_at)} · {a.reason}
                  </p>
                ) : (
                  owner && (
                    <button
                      disabled={w.busy}
                      className="secondary"
                      onClick={() => {
                        setMode('reverse')
                        setTarget(a.id)
                      }}
                    >
                      Revertir aplicación
                    </button>
                  )
                )}
                {mode === 'reverse' && target === a.id && (
                  <form
                    className="followup-form"
                    onSubmit={(e) => {
                      e.preventDefault()
                      save(
                        'reverse',
                        { application_id: a.id, reason: new FormData(e.currentTarget).get('reason') },
                        'Aplicación revertida. Se actualizó el disponible del abono.',
                      )
                    }}
                  >
                    <label>
                      Motivo de la reversión interna
                      <textarea required name="reason" maxLength={600} />
                    </label>
                    <button disabled={w.busy}>Confirmar reversión interna</button>
                  </form>
                )}
              </article>
            ))}
            {data.applications.total > 20 && pages(data.applications, 'page', 'Páginas de aplicaciones')}
          </section>
          <section className="surface">
            <h3>Historial de tesorería</h3>
            {data.history.rows.map((e) => (
              <article className="followup-entry" key={e.request_id}>
                <strong>{eventLabels[e.action]}</strong> · {e.actor} · {bankTime(e.recorded_at)}
                {e.details.origin && <p>{origins[e.details.origin]}</p>}
                {e.details.name && <p>{e.details.name}</p>}
                {e.details.amount_cents && (
                  <p>
                    {bankMoney(e.details.amount_cents, r.currency)} · {e.details.documents} comprobantes
                  </p>
                )}
                {e.details.reason && <p>{e.details.reason}</p>}
                {e.details.application_id && (
                  <Link
                    to={
                      '/bank-receipts/' +
                      id +
                      '?application=' +
                      e.details.application_id +
                      '#app-' +
                      e.details.application_id
                    }
                  >
                    Ver aplicación original
                  </Link>
                )}
              </article>
            ))}
            {data.history.total > 20 && pages(data.history, 'history_page', 'Páginas del historial')}
          </section>
        </>
      )}
    </>
  )
}
