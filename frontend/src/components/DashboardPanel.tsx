import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { ApiError } from '../lib/api'
import { emissionLabels } from '../lib/issuance'
import { paymentLabels } from '../lib/collections'
import { calendarMonth, dashboardMoney, dashboardQuery, getDashboard, getDashboardDetail, metricLabels } from '../lib/dashboard'
import type { DashboardData, DashboardDetail, DashboardFilters, DashboardMetric, DashboardRow } from '../lib/dashboard'
import { limaDate, usageLabels } from '../lib/subscriptions'
import DashboardTrend from './DashboardTrend'

function Rows({ rows }: { rows: DashboardRow[] }) {
  return rows.length === 0 ? <p>No hay registros para esta selección.</p> : <div className="dashboard-table" role="region" aria-label="Documentos que explican el indicador" tabIndex={0}><table><thead><tr><th scope="col">Documento / cliente</th><th scope="col">Fecha relevante</th><th scope="col">Importe</th><th scope="col">Estado tributario</th></tr></thead><tbody>{rows.map(row => <tr key={row.id}><td><Link to={row.href}>{row.number}</Link><small>{row.customer}</small>{row.payment_status && <small>Pago: {paymentLabels[row.payment_status] ?? 'Estado por revisar'}{row.needs_review ? ' · Requiere revisión' : ''}</small>}</td><td>{limaDate(row.relevant_at)}</td><td>{dashboardMoney(row.amount_cents, row.currency)}</td><td><span className="badge">{emissionLabels[row.status] ?? row.status}</span></td></tr>)}</tbody></table></div>
}
interface Props { companyName: string; onSessionExpired: () => void }
export default function DashboardPanel({ companyName, onSessionExpired }: Props) {
  const [params, setParams] = useSearchParams()
  const defaults = calendarMonth()
  const filters = { from: params.get('from') ?? defaults.from, to: params.get('to') ?? defaults.to, currency: params.get('currency') ?? 'PEN' }
  const metricValue = params.get('metric')
  const metric = metricValue && Object.hasOwn(metricLabels, metricValue) ? metricValue as DashboardMetric : undefined
  const page = Number(params.get('page') ?? 1)
  const query = dashboardQuery(filters, metric, page)
  const [form, setForm] = useState<DashboardFilters>(filters)
  const [data, setData] = useState<{ query: string; summary?: DashboardData; detail?: DashboardDetail } | null>(null)
  const [loading, setLoading] = useState(true), [error, setError] = useState(''), [revision, setRevision] = useState(0)
  useEffect(() => { setForm({ from: filters.from, to: filters.to, currency: filters.currency }) }, [filters.from, filters.to, filters.currency])
  useEffect(() => {
    let active = true
    const controller = new AbortController()
    setLoading(true); setError('')
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(20000)])
    const load = metric ? getDashboardDetail(query, signal).then(detail => ({ query, detail })) : getDashboard(query, signal).then(summary => ({ query, summary }))
    void load.then(result => { if (active) setData(result) }).catch((e: unknown) => {
      if (!active) return
      if (e instanceof ApiError && e.status === 401) { onSessionExpired(); return }
      setError(e instanceof ApiError && e.status === 403 ? 'Tu rol no tiene acceso a este resumen.' : e instanceof ApiError && e.status === 422 ? 'Revisa las fechas (máximo 366 días), su orden y la moneda.' : e instanceof ApiError && e.status === 429 ? 'Hay demasiadas solicitudes. Espera un momento y vuelve a actualizar.' : 'No se pudo actualizar el resumen. Revisa la conexión y reintenta.')
    }).finally(() => { if (active) setLoading(false) })
    return () => { active = false; controller.abort() }
  }, [query, metric, revision, onSessionExpired])
  const visible = data?.query === query ? data : null
  const summary = visible?.summary, detail = visible?.detail
  const href = (key: DashboardMetric, targetPage = 1) => '/dashboard?' + dashboardQuery(filters, key, targetPage)
  function submit(event: FormEvent) { event.preventDefault(); setParams(dashboardQuery(form, metric)) }
  function preset(previous: boolean) { setParams(dashboardQuery({ ...calendarMonth(previous), currency: filters.currency }, metric)) }
  function card(key: DashboardMetric, amount: string, explanation: string) { return <Link className="dashboard-card" to={key === 'outstanding' ? '/receivables?currency=' + summary!.currency : href(key)}><span>{metricLabels[key]}</span><strong>{dashboardMoney(amount, summary!.currency)}</strong><small>{explanation}</small><span className="dashboard-card-link">Ver detalle →</span></Link> }
  return <div className="dashboard">
    <section className="surface dashboard-toolbar" aria-label="Filtros del resumen">
      <p><strong>{companyName}</strong></p>
      <div className="dashboard-presets"><button className="secondary" onClick={() => preset(false)}>Mes actual</button><button className="secondary" onClick={() => preset(true)}>Mes anterior</button></div>
      <form onSubmit={submit} className="dashboard-filters"><div><label htmlFor="dashboard-from">Desde</label><input id="dashboard-from" type="date" required value={form.from} onChange={e => setForm({ ...form, from: e.target.value })} /></div><div><label htmlFor="dashboard-to">Hasta (incluido)</label><input id="dashboard-to" type="date" required value={form.to} onChange={e => setForm({ ...form, to: e.target.value })} /></div><div><label htmlFor="dashboard-currency">Moneda</label><select id="dashboard-currency" value={form.currency} onChange={e => setForm({ ...form, currency: e.target.value })}><option value="PEN">PEN · Soles</option><option value="USD">USD · Dólares</option></select></div><button type="submit">Aplicar filtros</button><button type="button" className="secondary" disabled={loading} onClick={() => setRevision(v => v + 1)}>Actualizar</button></form>
      <p className="muted">Actividad: {filters.from} a {filters.to} · America/Lima · {filters.currency}. Máximo 366 días.</p>
      {visible && <p className="muted">Calculado: {limaDate((summary ?? detail)!.calculated_at)}</p>}
    </section>
    {error && <p className="error" role="alert">{error}{visible ? ' Se conservan los últimos datos; pueden estar desactualizados.' : ''}</p>}
    {loading && <p role="status">{visible ? 'Actualizando resumen…' : 'Cargando resumen…'}</p>}
    {metricValue && !metric && <p role="alert">El indicador solicitado no existe. Se muestra el resumen.</p>}
    {detail && <section className="surface dashboard-detail"><Link to={'/dashboard?' + dashboardQuery(filters)}>← Volver al resumen</Link><h2>{metricLabels[detail.detail.metric]}</h2><p className="muted">{['accepted', 'credits', 'rejected'].includes(detail.detail.metric) ? 'Primera fecha de resultado del simulador dentro del periodo, sin filtrar por fecha de emisión.' : detail.detail.metric === 'payments' ? 'Una fila por pago confirmado. Un documento puede tener varios pagos. Los ajustes observados requieren revisión.' : 'Situación actual, sin filtro de fechas. Se conserva la moneda seleccionada; la fecha de la fila es la emisión original.'}</p>{detail.detail.metric === 'review' && <p>El importe de cada fila es el total original del documento, no un ajuste ni un saldo.</p>}<Rows rows={detail.detail.rows} /><nav className="customer-pagination" aria-label="Paginación del indicador"><span>{detail.detail.total} registros · Página {detail.detail.page} de {detail.detail.last_page}</span>{detail.detail.page > 1 && <Link className="button secondary" to={href(metric!, detail.detail.page - 1)}>Anterior</Link>}{detail.detail.page < detail.detail.last_page && <Link className="button secondary" to={href(metric!, detail.detail.page + 1)}>Siguiente</Link>}</nav></section>}
    {summary && <>
      {summary.actions.create_document && <div className="dashboard-actions"><Link className="button" to="/documents/new">Crear comprobante</Link><Link className="button secondary" to="/customers">Ver clientes</Link></div>}
      <section aria-labelledby="activity-title"><h2 id="activity-title">Actividad del periodo</h2><div className="dashboard-cards">{card('accepted', summary.activity.accepted_cents, `${summary.activity.accepted_count} facturas y boletas aceptadas`)}{card('credits', summary.activity.credit_notes_cents, 'Devoluciones aceptadas en el periodo')}<article className="dashboard-card"><span>Emisión neta del periodo</span><strong>{dashboardMoney(String(BigInt(summary.activity.accepted_cents) - BigInt(summary.activity.credit_notes_cents)), summary.currency)}</strong></article>{card('payments', summary.activity.collected_cents, `${summary.activity.payments_count} pagos de clientes confirmados; excluye suscripciones`)}</div><p><Link to={href('rejected')}>{summary.activity.rejected_count} documentos rechazados en el periodo</Link></p></section>
      {summary.bank && <section aria-labelledby="bank-title"><h2 id="bank-title">Cobros bancarios</h2><div className="dashboard-cards">
        <Link className="dashboard-card" to={'/bank-receipts?situation=confirmed&currency='+summary.currency+'&from='+filters.from+'&to='+filters.to}><span>Ingresos bancarios confirmados</span><strong>{dashboardMoney(summary.bank.received_cents,summary.currency)}</strong><small>{summary.bank.receipts} abonos por fecha bancaria · incluye {dashboardMoney(summary.bank.settlement_cents,summary.currency)} de liquidaciones de Mercado Pago</small></Link>
        <article className="dashboard-card"><span>Aplicado a cartera desde banco</span><strong>{dashboardMoney(summary.bank.applied_cents,summary.currency)}</strong><small>Aplicaciones vigentes por fecha de aplicación en el período</small></article>
        <article className="dashboard-card"><span>Disponible bancario por aplicar</span><strong>{dashboardMoney(summary.bank.available_cents,summary.currency)}</strong><small>Abonos directos confirmados con remanente actual, de cualquier fecha</small></article>
      </div></section>}
      <section aria-labelledby="portfolio-title"><h2 id="portfolio-title">Cartera actual</h2><p className="muted">Todos los comprobantes aceptados en {summary.currency}, sin filtro de fechas. Los ajustes de pagos observados requieren conciliación; no se descuentan automáticamente.</p><div className="dashboard-cards">{card('outstanding', summary.portfolio.outstanding_cents, `${summary.portfolio.outstanding_count} documentos con saldo pendiente`)}{card('excess', summary.portfolio.excess_cents, `${summary.portfolio.excess_count} documentos con exceso; nunca se muestra como saldo negativo`)}<Link className="dashboard-card" to={href('review')}><span>Pagos por revisar</span><strong>{summary.portfolio.review_count} documentos</strong><small>Excesos, ajustes o fallos de conciliación.</small><span className="dashboard-card-link">Ver detalle →</span></Link></div></section>
      <DashboardTrend days={summary.series} currency={summary.currency} />
      <div className="dashboard-secondary"><section className="surface"><h2>Atención operativa</h2><p className="muted">Operaciones por última actualización en el periodo seleccionado · {summary.currency}.</p><ul className="dashboard-alerts">{Object.entries(summary.alerts).map(([key, count]) => <li key={key}><Link to={'/incidents?' + new URLSearchParams({ ...filters, alert: key })}>{metricLabels[key as DashboardMetric]}<strong>{count}</strong></Link></li>)}</ul></section><section className="surface"><h2>Plan y consumo</h2><p><strong>{summary.usage.plan_name ?? 'Sin plan vigente'}</strong> · {usageLabels[summary.usage.status]}</p><p className="dashboard-quota"><strong>{summary.usage.remaining}</strong> disponibles de {summary.usage.limit}</p><p>{summary.usage.consumed} emisiones consumidas · Todas las monedas.</p><p className="muted">{limaDate(summary.usage.starts_at)} a {limaDate(summary.usage.ends_at)} (fin excluido).</p>{summary.usage.expiring_soon && <p>El periodo vence en menos de tres días.</p>}{summary.actions.manage_subscription ? <Link to="/subscription">Gestionar plan y consumo →</Link> : <p>Para gestionar el plan, contacta al dueño.</p>}</section></div>
      <section className="surface"><h2>Aceptaciones recientes del periodo</h2><p className="muted">Hasta cinco documentos, ordenados por aceptación del simulador.</p><Rows rows={summary.recent} /><Link to={href('accepted')}>Ver todas las aceptaciones →</Link></section>
    </>}
  </div>
}
