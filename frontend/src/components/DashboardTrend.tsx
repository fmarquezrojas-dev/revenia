import { dashboardMoney } from '../lib/dashboard'
import type { DashboardDay } from '../lib/dashboard'

export default function DashboardTrend({ days, currency }: { days: DashboardDay[]; currency: string }) {
  const maximum = days.reduce((max, day) => [BigInt(day.credit_notes_cents), BigInt(day.accepted_cents), BigInt(day.collected_cents), max].reduce((a, b) => a > b ? a : b), 0n)
  const scale = maximum || 1n
  const points = (key: 'credit_notes_cents' | 'accepted_cents' | 'collected_cents') => days.map((day, index) => `${60 + index * 670 / Math.max(days.length - 1, 1)},${185 - Number(BigInt(day[key]) * 14000n / scale) / 100}`).join(' ')
  return <section className="surface dashboard-trend" aria-labelledby="trend-title">
    <h2 id="trend-title">Actividad diaria</h2>
    <p className="muted">Ventas y notas por fecha de aceptación. Pagos por fecha de confirmación. Importes en {currency}.</p>
    {maximum === 0n ? <p>No hay actividad en este periodo.</p> : <>
      <p className="dashboard-legend"><span>━ Emisión aceptada</span><span>┄ Pagos confirmados</span><span>··· Notas aceptadas</span></p>
      <svg viewBox="0 0 780 230" role="img" aria-label={`Actividad diaria en ${currency}. Importes exactos en la tabla de datos.`}>
        <title>Ventas, notas de crédito y pagos confirmados</title>
        <line x1="60" y1="185" x2="730" y2="185" stroke="#b8c4d6" />
        <line x1="60" y1="45" x2="730" y2="45" stroke="#e0e7f0" />
        <text x="60" y="25">Máximo: {dashboardMoney(maximum.toString(), currency)}</text>
        <text x="45" y="189">0</text>
        <polyline points={points('accepted_cents')} fill="none" stroke="#2851cf" strokeWidth="3" />
        <polyline points={points('credit_notes_cents')} fill="none" stroke="#954327" strokeWidth="3" strokeDasharray="2 4" />
        <polyline points={points('collected_cents')} fill="none" stroke="#14715c" strokeWidth="3" strokeDasharray="7 5" />
        {days.length === 1 && <><circle cx="60" cy={185 - Number(BigInt(days[0].credit_notes_cents) * 14000n / scale) / 100} r="4" fill="#954327" /><circle cx="60" cy={185 - Number(BigInt(days[0].accepted_cents) * 14000n / scale) / 100} r="4" fill="#2851cf" /><circle cx="60" cy={185 - Number(BigInt(days[0].collected_cents) * 14000n / scale) / 100} r="4" fill="#14715c" /></>}
        <text x="60" y="215">{days[0]?.date}</text><text x="730" y="215" textAnchor="end">{days.at(-1)?.date}</text>
      </svg>
    </>}
    <details><summary>Ver tabla de datos diarios</summary><div className="dashboard-table" role="region" aria-label="Datos diarios" tabIndex={0}><table><caption>Actividad diaria en {currency}, fecha de America/Lima</caption><thead><tr><th scope="col">Fecha</th><th scope="col">Emisión aceptada</th><th scope="col">Notas aceptadas</th><th scope="col">Pagos confirmados</th></tr></thead><tbody>{days.map(day => <tr key={day.date}><th scope="row">{day.date}</th><td>{dashboardMoney(day.accepted_cents, currency)}</td><td>{dashboardMoney(day.credit_notes_cents, currency)}</td><td>{dashboardMoney(day.collected_cents, currency)}</td></tr>)}</tbody></table></div></details>
  </section>
}
