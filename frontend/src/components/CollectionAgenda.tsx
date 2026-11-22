import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { type SessionUser } from '../lib/api'
import { dashboardMoney } from '../lib/dashboard'
import { day, tasks, useFollowupRead, type Agenda } from '../lib/followup'
import DataTable from './ui/DataTable'

export default function CollectionAgenda({role, onSessionExpired}: {role: SessionUser['role']; onSessionExpired: () => void}) {
  const [params, setParams] = useSearchParams()
  const [version, setVersion] = useState(0)
  const scope = params.get('scope') ?? (role === 'accountant' ? 'team' : 'mine')
  const query = new URLSearchParams(params)
  query.set('scope', scope)
  const result = useFollowupRead<Agenda>('/api/v1/receivables/agenda?' + query, onSessionExpired, version)
  const data = result?.data
  function change(field: string, value: string) {
    const next = new URLSearchParams(query)
    next.delete('page')
    if (value) next.set(field, value)
    else next.delete(field)
    return '/receivables/agenda?' + next
  }
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const next = new URLSearchParams({scope})
    new FormData(event.currentTarget).forEach((value, field) => { if (String(value).trim()) next.set(field, String(value).trim()) })
    if (params.get('task')) next.set('task', params.get('task')!)
    setParams(next)
  }
  return <div className="dashboard receivables">
    <h2>{scope === 'team' ? 'Agenda del equipo' : 'Mi agenda'}</h2>
    {!result && <p role="status">Cargando agenda…</p>}
    {result?.error && <div className="surface"><p role="alert">{result.error}</p><button onClick={() => setVersion(v => v + 1)}>Actualizar</button> <Link to={'/receivables/agenda?scope=' + scope}>Limpiar filtros</Link></div>}
    {data && <>
      <form key={query.toString()} className="surface dashboard-filters" onSubmit={submit}>
        <label>Cliente<input name="q" type="search" maxLength={120} defaultValue={params.get('q') ?? ''}/></label>
        {scope === 'team' && <label>Responsable<select name="assignee" defaultValue={params.get('assignee') ?? ''}>
          <option value="">Todos</option>{data.people.map(u => <option key={u.id} value={u.id}>{u.name}{u.is_active ? '' : ' · inactivo'}</option>)}
        </select></label>}
        <label>Moneda<select name="currency" defaultValue={data.currency}><option>PEN</option><option>USD</option></select></label>
        <label>Desde<input type="date" name="from" defaultValue={params.get('from') ?? ''}/></label>
        <label>Hasta<input type="date" name="to" defaultValue={params.get('to') ?? ''}/></label>
        <button>Aplicar filtros</button><Link to={'/receivables/agenda?scope=' + scope}>Limpiar</Link>
      </form>
      <div className="module-toolbar"><p>Al {day(data.today)} · America/Lima</p><button className="secondary" onClick={() => setVersion(v => v + 1)}>Actualizar</button></div>
      <div className="dashboard-cards aging-cards">{(Object.keys(tasks) as (keyof typeof tasks)[]).map(task =>
        <Link className="dashboard-card" key={task} aria-current={params.get('task') === task ? 'true' : undefined} to={change('task', task)}>
          <span>{tasks[task]}</span><strong>{data.counts[task]}</strong>
        </Link>)}</div>
      <div className="module-toolbar"><h3>{tasks[params.get('task') as keyof typeof tasks] ?? 'Documentos con saldo'}</h3><Link to={change('task', '')}>Ver todos ({data.counts.total})</Link></div>
      {data.total === 0 ? <p className="surface" role="status">No hay tareas que coincidan con la selección.</p> :
        <DataTable label="Agenda de cobranza" headers={['Comprobante / cliente','Responsable','Próxima acción','Compromiso pendiente','Saldo','Seguimiento']}>
          {data.rows.map(row => <tr key={row.id}>
            <td>{row.number}<small>{row.customer}</small></td><td>{row.assignee ?? 'Sin asignar'}{row.unassigned && row.assignee && <small>Requiere reasignación</small>}</td>
            <td>{day(row.next_action_on)}{row.action_late ? <small>Atrasada</small> : row.action_today && <small>Hoy</small>}</td>
            <td>{day(row.promise_due)}{row.promise_late && <small>Vencido</small>}</td><td>{dashboardMoney(row.outstanding_cents, data.currency)}</td>
            <td><Link to={'/receivables/' + row.id + '/followup'}>Seguimiento</Link></td>
          </tr>)}
        </DataTable>}
      <nav className="customer-pagination" aria-label="Paginación de agenda"><span>{data.total} documentos · Página {data.page} de {data.last_page}</span>
        {data.page > 1 && <Link to={change('page', String(data.page - 1))}>Anterior</Link>}
        {data.page < data.last_page && <Link to={change('page', String(data.page + 1))}>Siguiente</Link>}
      </nav>
    </>}
  </div>
}
