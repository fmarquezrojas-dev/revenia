import { useEffect, useState, type FormEvent } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { ApiError, request } from '../lib/api'
import { bankTime, type Page } from '../lib/bank'
import { dashboardMoney } from '../lib/dashboard'
import { movementLabels, type CustomerStatementData, type StatementMovement } from '../lib/customerStatement'
import DataTable from './ui/DataTable'
import './customer-statement.css'

type Result = { key: string; data?: CustomerStatementData; error?: string }
type PageKey = 'page' | 'movements_page' | 'receipts_page'

const orderStates: Record<string, string> = {
  open: 'Abierta',
  expired: 'Vencida',
  closed: 'Cerrada',
  stale: 'Desactualizada',
  pending: 'Pendiente',
  creating: 'En creación',
  failed: 'Fallida',
  unknown: 'Por confirmar',
}
const day = (value: string) => value.split('-').reverse().join('/')

function originHref(row: StatementMovement) {
  if (row.receipt_id)
    return '/bank-receipts/' + row.receipt_id + '?application=' + row.source_id + '#app-' + row.source_id
  if (row.kind === 'credit' || row.kind === 'reserve') return '/documents/' + row.source_id
  return '/documents/' + row.document_id + (row.kind === 'sale' ? '' : '#cobranza')
}

export default function CustomerStatement({ onSessionExpired }: { onSessionExpired: () => void }) {
  const { id } = useParams()
  const [params, setParams] = useSearchParams()
  const [attempt, retry] = useState(0)
  const [result, setResult] = useState<Result>()
  const query = params.toString()
  const print = params.get('view') === 'print'
  const key = id + '?' + query + ':' + attempt

  useEffect(() => {
    const controller = new AbortController()
    request<{ data: CustomerStatementData }>('/api/v1/customers/' + id + '/statement?' + query, {
      cache: 'no-store',
      signal: AbortSignal.any([controller.signal, AbortSignal.timeout(print ? 120000 : 30000)]),
    })
      .then((response) => {
        if (!controller.signal.aborted) setResult({ key, data: response.data })
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return
        if (error instanceof ApiError && error.status === 401) {
          onSessionExpired()
          return
        }
        const message =
          error instanceof ApiError && error.status === 422
            ? 'Revisa la moneda, el período y los filtros.'
            : error instanceof ApiError && error.status === 404
              ? 'El cliente o comprobante no está disponible.'
              : 'No se pudo consultar el estado de cuenta.'
        setResult({ key, error: message })
      })
    return () => controller.abort()
  }, [id, query, key, print, onSessionExpired])

  const current = result?.key === key ? result : undefined
  if (!current) return <p role="status">Cargando estado de cuenta…</p>
  if (!current.data)
    return (
      <section className="surface">
        <p role="alert">{current.error}</p>
        <Link to={'/customers/' + id + '/statement'}>Restablecer filtros</Link>{' '}
        <button onClick={() => retry((value) => value + 1)}>Reintentar</button>
      </section>
    )

  const data = current.data
  const { company, customer, currency, period, summary } = data
  const money = (value: string) => dashboardMoney(value, currency)
  const changes = (values: Record<string, string | null>) => {
    const next = new URLSearchParams(params)
    for (const [name, value] of Object.entries(values)) {
      if (value === null) next.delete(name)
      else next.set(name, value)
    }
    return '?' + next.toString()
  }
  function applyFilters(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const next = new URLSearchParams({
      currency: String(form.get('currency')),
      from: String(form.get('from')),
      to: String(form.get('to')),
    })
    const selected = params.get('document')
    if (selected && next.get('currency') === currency) next.set('document', selected)
    setParams(next)
  }
  function pages(page: Page<unknown>, param: PageKey, label: string) {
    if (print || page.last_page <= 1) return null
    return (
      <nav className="customer-pagination" aria-label={label}>
        {page.page > 1 && <Link to={changes({ [param]: String(page.page - 1) })}>Anterior</Link>}
        Página {page.page} de {page.last_page} · {page.total} registros
        {page.page < page.last_page && (
          <Link to={changes({ [param]: String(page.page + 1) })}>Siguiente</Link>
        )}
      </nav>
    )
  }
  const customerFilter = '?customer_id=' + customer.id + '&currency=' + currency
  const printLink = changes({
    view: 'print',
    page: null,
    movements_page: null,
    receipts_page: null,
    from: period.from,
    to: period.to,
    currency,
  })

  return (
    <article className={'dashboard customer-statement' + (print ? ' statement-print' : '')}>
      <header className="surface">
        <h2>Estado de cuenta</h2>
        <p>
          {company.legal_name} · RUC {company.ruc}
        </p>
        <h3>{customer.name}</h3>
        <p>
          {customer.doc_type.toUpperCase()} {customer.doc_number} · {currency}
        </p>
        <p>Emisión tributaria simulada</p>
        <p>
          Saldo actual calculado el <time dateTime={data.calculated_at}>{bankTime(data.calculated_at)}</time>{' '}
          · {data.timezone}
        </p>
        <nav className="form-actions statement-controls" aria-label="Opciones del estado de cuenta">
          {print ? (
            <>
              <button onClick={() => window.print()}>Imprimir</button>
              <Link to={changes({ view: null })}>Volver a la consulta</Link>
            </>
          ) : (
            <>
              <Link className="button" to={printLink}>
                Vista imprimible completa
              </Link>
              <Link to={'/customers/' + customer.id}>Ficha del cliente</Link>
              <Link to={'/receivables' + customerFilter}>Cartera de este cliente</Link>
              <Link to={'/bank-receipts' + customerFilter}>Cobros bancarios del cliente</Link>
            </>
          )}
        </nav>
      </header>
      {!print && (
        <form className="surface dashboard-filters statement-controls" onSubmit={applyFilters} key={query}>
          <label>
            Moneda
            <select name="currency" defaultValue={currency}>
              <option>PEN</option>
              <option>USD</option>
            </select>
          </label>
          <label>
            Movimientos desde
            <input name="from" type="date" required defaultValue={period.from} />
          </label>
          <label>
            Hasta
            <input name="to" type="date" required defaultValue={period.to} />
          </label>
          <button>Aplicar filtros</button>
        </form>
      )}
      <section aria-label="Posición actual del cliente">
        <p>
          Los saldos incluyen todos los comprobantes de la moneda seleccionada. El período filtra únicamente
          los movimientos.
        </p>
        <dl className="collection-balances">
          <div>
            <dt>Saldo pendiente actual</dt>
            <dd>{money(summary.outstanding_cents)}</dd>
          </div>
          <div>
            <dt>Parte vencida del saldo</dt>
            <dd>{money(summary.overdue_cents)}</dd>
          </div>
          <div>
            <dt>Comprobantes en revisión</dt>
            <dd>{summary.review}</dd>
          </div>
          <div>
            <dt>Excesos en comprobantes</dt>
            <dd>{money(summary.excess_cents)}</dd>
          </div>
          <div>
            <dt>Abonos disponibles por aplicar</dt>
            <dd>
              {summary.available_cents === null
                ? 'Tesorería pendiente de habilitación'
                : money(summary.available_cents)}
            </dd>
          </div>
        </dl>
      </section>
      <section>
        <h3>Comprobantes aceptados · {data.documents.total}</h3>
        {data.documents.total ? (
          <DataTable
            label="Saldos actuales por comprobante"
            headers={[
              'Comprobante',
              'Emisión / vencimiento',
              'Original',
              'Créditos aceptados',
              'Pagos aplicados',
              'Saldo actual',
              'Exceso',
            ]}
          >
            {data.documents.rows.map((doc) => (
              <tr key={doc.id}>
                <td>
                  <Link to={'/documents/' + doc.id}>{doc.number}</Link>
                  {doc.needs_review && <small>Requiere revisión</small>}
                  {doc.pending_confirmation && <small>Cobro pendiente de confirmación</small>}
                  {!print && (
                    <small>
                      <Link to={changes({ document: doc.id, movements_page: null })}>Ver movimientos</Link>
                      {' · '}
                      <Link to={'/receivables/' + doc.id + '/followup'}>Seguimiento</Link>
                    </small>
                  )}
                </td>
                <td>
                  {day(doc.issue_date)}
                  <small>{doc.due_date ? day(doc.due_date) : 'Sin vencimiento informado'}</small>
                </td>
                <td>{money(doc.total_cents)}</td>
                <td>
                  {money(doc.credits_cents)}
                  {doc.reserved_cents !== '0' && <small>En reserva · {money(doc.reserved_cents)}</small>}
                </td>
                <td>{money(doc.paid_cents)}</td>
                <td>{money(doc.outstanding_cents)}</td>
                <td>{money(doc.excess_cents)}</td>
              </tr>
            ))}
          </DataTable>
        ) : (
          <p>Sin comprobantes aceptados en {currency}.</p>
        )}
        {pages(data.documents, 'page', 'Páginas de comprobantes')}
      </section>
      <section>
        <h3>Abonos identificados con remanente actual</h3>
        {data.receipts === null ? (
          <p>Consulta disponible al aplicar la migración de Cobros bancarios.</p>
        ) : data.receipts.total ? (
          <>
            <DataTable
              label="Abonos disponibles"
              headers={['Referencia bancaria', 'Fecha bancaria', 'Recibido', 'Aplicado', 'Disponible']}
            >
              {data.receipts.rows.map((r) => (
                <tr key={r.id}>
                  <td>
                    <Link to={'/bank-receipts/' + r.id}>{r.reference}</Link>
                  </td>
                  <td>{day(r.bank_date)}</td>
                  <td>{money(r.amount_cents)}</td>
                  <td>{money(r.applied_cents)}</td>
                  <td>{money(r.available_cents)}</td>
                </tr>
              ))}
            </DataTable>
            {pages(data.receipts, 'receipts_page', 'Páginas de abonos')}
          </>
        ) : (
          <p>Sin abonos identificados disponibles en {currency}.</p>
        )}
      </section>
      <section>
        <h3>Movimientos del período · {data.movements.total}</h3>
        <p>
          Del {day(period.from)} al {day(period.to)} · {data.timezone} ·{' '}
          {data.movement_document ?? 'Todos los comprobantes'}
        </p>
        {data.movement_document && !print && (
          <Link to={changes({ document: null, movements_page: null })}>
            Ver movimientos de todos los comprobantes
          </Link>
        )}
        {data.movements.total ? (
          <DataTable
            label="Movimientos del período"
            headers={['Fecha local', 'Origen', 'Comprobante', 'Referencia', 'Importe']}
          >
            {data.movements.rows.map((row) => (
              <tr key={row.kind + row.source_id}>
                <td>{bankTime(row.occurred_at)}</td>
                <td>
                  {movementLabels[row.kind]}
                  {row.order_state && (
                    <small>Estado actual · {orderStates[row.order_state] ?? 'Por revisar'}</small>
                  )}
                  {row.sandbox && <small>Mercado Pago · entorno de pruebas</small>}
                  {(row.kind === 'reserve' || row.kind === 'order') && <small>Sin efecto en el saldo</small>}
                </td>
                <td>
                  <Link to={'/documents/' + row.document_id}>{row.number}</Link>
                </td>
                <td>
                  <Link to={originHref(row)}>{row.reference}</Link>
                </td>
                <td>
                  {money(row.amount_cents)}
                  {row.kind === 'bank_reversal' && <small>Restituye lo aplicado</small>}
                </td>
              </tr>
            ))}
          </DataTable>
        ) : (
          <p>Sin movimientos en el período seleccionado.</p>
        )}
        {pages(data.movements, 'movements_page', 'Páginas de movimientos')}
      </section>
    </article>
  )
}
