import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import type { Customer, SessionUser } from '../lib/api'
import {
  bankCents,
  bankMoney,
  bankUrl,
  labels,
  origins,
  useBankRead,
  useBankWrite,
  type Account,
  type Page,
  type Receipt,
} from '../lib/bank'
import DocumentLookup from './DocumentLookup'
import DataTable from './ui/DataTable'
import BankReceiptDetail from './BankReceiptDetail'

type Props = { role: SessionUser['role']; onSessionExpired: () => void }
type Expiry = Pick<Props, 'onSessionExpired'>

function Accounts({ onSessionExpired }: Expiry) {
  const [version, refresh] = useState(0),
    [selected, edit] = useState<Account | 'new'>()
  const state = useBankRead<Account[]>(bankUrl + '/accounts', version, onSessionExpired)
  const write = useBankWrite(onSessionExpired)
  const a = selected === 'new' ? undefined : selected
  function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const values = new FormData(e.currentTarget)
    void write.send(
      bankUrl + '/accounts' + (a ? '/' + a.id : ''),
      () => ({
        alias: values.get('alias'),
        institution: values.get('institution'),
        currency: a?.currency ?? values.get('currency'),
        active: values.get('active') === 'on',
        revision: a?.revision,
      }),
      () => {
        edit(undefined)
        refresh((i) => i + 1)
      },
      a ? 'PUT' : 'POST',
    )
  }
  return (
    <section className="surface">
      <div className="module-toolbar">
        <h2>Cuentas receptoras</h2>
        <button onClick={() => edit('new')}>Nueva cuenta</button>
      </div>
      {state?.error && <p role="alert">{state.error}</p>}
      {!state && <p role="status">Cargando cuentas…</p>}
      {state?.data?.length === 0 && <p>Aún no hay cuentas receptoras.</p>}
      {state?.data?.map((row) => (
        <div className="module-toolbar" key={row.id}>
          <span>
            <strong>{row.alias}</strong> · {row.institution} · {row.currency} ·{' '}
            {row.active ? 'Activa' : 'Inactiva'}
          </span>
          <button className="secondary" disabled={write.busy} onClick={() => edit(row)}>
            Editar {row.alias}
          </button>
        </div>
      ))}
      {selected && (
        <form className="followup-form" key={a?.id ?? 'new'} onSubmit={submit}>
          <fieldset disabled={write.busy}>
            <label>
              Alias
              <input name="alias" required maxLength={80} defaultValue={a?.alias} />
            </label>
            <label>
              Entidad financiera
              <input name="institution" required maxLength={100} defaultValue={a?.institution} />
            </label>
            <label>
              Moneda
              <select name="currency" disabled={!!a} defaultValue={a?.currency}>
                <option>PEN</option>
                <option>USD</option>
              </select>
            </label>
            <label>
              <input type="checkbox" name="active" defaultChecked={a?.active ?? true} /> Cuenta activa
            </label>
            <div className="form-actions">
              <button>Guardar cuenta</button>
              <button type="button" className="secondary" onClick={() => edit(undefined)}>
                Cancelar
              </button>
            </div>
          </fieldset>
        </form>
      )}
      {write.error && <p role="alert">{write.error}</p>}
    </section>
  )
}

function Register({ onSessionExpired }: Expiry) {
  const state = useBankRead<Account[]>(bankUrl + '/accounts', 0, onSessionExpired)
  const [accountId, account] = useState(''),
    [origin, setOrigin] = useState<keyof typeof origins>('customer')
  const [customer, identify] = useState<Customer>(),
    [lookup, choose] = useState(false)
  const selected = state?.data?.find((a) => a.id === accountId)
  const write = useBankWrite(onSessionExpired),
    navigate = useNavigate()
  function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    void write.send(
      bankUrl,
      () => ({
        account_id: accountId,
        currency: selected?.currency,
        origin,
        method: f.get('method'),
        bank_date: f.get('bank_date'),
        reference: f.get('reference'),
        amount_cents: bankCents(String(f.get('amount'))),
        note: f.get('note') || null,
        customer_id: origin === 'customer' ? (customer?.id ?? null) : null,
      }),
      (result) =>
        navigate('/bank-receipts/' + result.id, {
          replace: true,
          state: {
            notice: result.repeated
              ? 'Esta operación ya estaba registrada.'
              : 'Abono registrado. Pendiente de verificación bancaria.',
          },
        }),
    )
  }
  return (
    <section className="surface">
      <h2>Registrar abono</h2>
      {state?.error && <p role="alert">{state.error}</p>}
      {state?.data && !state.data.some((a) => a.active) && (
        <p>El dueño debe habilitar una cuenta receptora antes de registrar un abono.</p>
      )}
      <form className="followup-form" onSubmit={submit}>
        <fieldset disabled={write.busy}>
          <label>
            Cuenta receptora
            <select required value={accountId} onChange={(e) => account(e.target.value)}>
              <option value="">Seleccionar cuenta</option>
              {state?.data
                ?.filter((a) => a.active)
                .map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.alias} · {a.institution} · {a.currency}
                  </option>
                ))}
            </select>
          </label>
          <label>
            Origen
            <select value={origin} onChange={(e) => setOrigin(e.target.value as keyof typeof origins)}>
              {Object.entries(origins).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </label>
          <label>
            Medio de pago
            <select name="method">
              <option value="transfer">Transferencia</option>
              <option value="deposit">Depósito</option>
            </select>
          </label>
          <label>
            Fecha efectiva del ingreso
            <input type="date" name="bank_date" required />
          </label>
          <label>
            Referencia bancaria
            <input name="reference" required maxLength={100} />
          </label>
          <label>
            Importe recibido {selected?.currency}
            <input inputMode="decimal" name="amount" required maxLength={18} placeholder="0.00" />
          </label>
          {origin === 'customer' && (
            <div>
              <p>Cliente · {customer?.name ?? 'Pendiente de identificación'}</p>
              <button type="button" className="secondary" onClick={() => choose(!lookup)}>
                {lookup ? 'Cerrar búsqueda' : 'Elegir cliente'}
              </button>
              {customer && (
                <button type="button" className="secondary" onClick={() => identify(undefined)}>
                  Quitar cliente
                </button>
              )}
              {lookup && (
                <DocumentLookup
                  kind="customer"
                  includeInactive
                  currency={selected?.currency ?? 'PEN'}
                  onProduct={() => {}}
                  onCustomer={(v) => {
                    identify(v)
                    choose(false)
                  }}
                  onSessionExpired={onSessionExpired}
                />
              )}
            </div>
          )}
          <label>
            Observación (opcional)
            <textarea name="note" maxLength={600} />
          </label>
          <button disabled={!selected}>Registrar abono pendiente</button>
        </fieldset>
      </form>
      {write.error && (
        <p role="alert">
          {write.error}{' '}
          {write.related && <Link to={'/bank-receipts/' + write.related}>Revisar abono existente</Link>}
        </p>
      )}
    </section>
  )
}

function Inbox({ onSessionExpired }: Expiry) {
  const [params, setParams] = useSearchParams(),
    [version, refresh] = useState(0)
  const state = useBankRead<Page<Receipt> & { accounts: Account[] }>(
    bankUrl + '?' + params,
    version,
    onSessionExpired,
  )
  const data = state?.data
  function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const next = new URLSearchParams()
    new FormData(e.currentTarget).forEach((v, k) => {
      if (String(v).trim()) next.set(k, String(v).trim())
    })
    if (params.has('customer_id')) next.set('customer_id', params.get('customer_id')!)
    setParams(next)
  }
  function page(n: number) {
    const next = new URLSearchParams(params)
    next.set('page', String(n))
    setParams(next)
  }
  return (
    <>
      <section className="surface">
        <h2>Bandeja de abonos</h2>
        {params.has('customer_id') && (
          <p>
            Abonos del cliente seleccionado · <Link to="/bank-receipts">Ver toda la empresa</Link>
          </p>
        )}
        <form key={params.toString()} className="dashboard-filters" onSubmit={submit}>
          <label>
            Cuenta
            <select
              name="account_id"
              defaultValue={params.get('account_id') ?? ''}
              key={data?.accounts.length ?? -1}
            >
              <option value="">Todas</option>
              {data?.accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.alias} · {a.currency}
                </option>
              ))}
            </select>
          </label>
          <label>
            Cliente
            <input type="search" name="q" maxLength={120} defaultValue={params.get('q') ?? ''} />
          </label>
          <label>
            Moneda
            <select name="currency" defaultValue={params.get('currency') ?? ''}>
              <option value="">Todas</option>
              <option>PEN</option>
              <option>USD</option>
            </select>
          </label>
          <label>
            Situación
            <select name="situation" defaultValue={params.get('situation') ?? ''}>
              <option value="">Todas</option>
              {Object.entries(labels).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </label>
          <label>
            Fecha bancaria desde
            <input type="date" name="from" defaultValue={params.get('from') ?? ''} />
          </label>
          <label>
            Hasta
            <input type="date" name="to" defaultValue={params.get('to') ?? ''} />
          </label>
          <button>Aplicar filtros</button>
          <Link to="/bank-receipts">Limpiar</Link>
        </form>
      </section>
      {!state && <p role="status">Cargando abonos…</p>}
      {state?.error && (
        <p role="alert">
          {state.error} <button onClick={() => refresh((v) => v + 1)}>Actualizar</button>
        </p>
      )}
      {data && (
        <>
          {data.total === 0 ? (
            <p className="surface">No hay abonos para esta selección.</p>
          ) : (
            <DataTable
              label="Abonos bancarios"
              headers={[
                'Referencia / cuenta',
                'Fecha bancaria',
                'Cliente',
                'Situación',
                'Recibido',
                'Aplicado',
                'Disponible',
              ]}
            >
              {data.rows.map((r) => (
                <tr key={r.id}>
                  <td>
                    <Link to={'/bank-receipts/' + r.id}>{r.reference}</Link>
                    <small>{r.alias}</small>
                  </td>
                  <td>{r.bank_date}</td>
                  <td>{r.customer ?? (r.origin === 'customer' ? 'Por identificar' : 'Mercado Pago')}</td>
                  <td>{labels[r.situation]}</td>
                  {[r.amount_cents, r.applied_cents, r.available_cents].map((v, i) => (
                    <td className="numeric" key={i}>
                      {bankMoney(v, r.currency)}
                    </td>
                  ))}
                </tr>
              ))}
            </DataTable>
          )}
          <nav className="customer-pagination" aria-label="Paginación de abonos">
            {data.total} abonos · Página {data.page} de {data.last_page}
            {data.page > 1 && <button onClick={() => page(data.page - 1)}>Anterior</button>}
            {data.page < data.last_page && <button onClick={() => page(data.page + 1)}>Siguiente</button>}
          </nav>
        </>
      )}
    </>
  )
}

export default function BankReceiptsPanel({ role, onSessionExpired }: Props) {
  const { id } = useParams()
  const denied = (
    <p className="surface" role="alert">
      Tu rol no permite realizar esta operación.
    </p>
  )
  return (
    <div className="dashboard">
      <nav className="form-actions" aria-label="Tesorería">
        <Link className="button secondary" to="/bank-receipts">
          Bandeja de abonos
        </Link>
        {role !== 'accountant' && (
          <Link className="button" to="/bank-receipts/new">
            Registrar abono
          </Link>
        )}
        {role === 'owner' && (
          <Link className="button secondary" to="/bank-receipts/accounts">
            Cuentas receptoras
          </Link>
        )}
      </nav>
      {id === 'accounts' ? (
        role === 'owner' ? (
          <Accounts onSessionExpired={onSessionExpired} />
        ) : (
          denied
        )
      ) : id === 'new' ? (
        role !== 'accountant' ? (
          <Register onSessionExpired={onSessionExpired} />
        ) : (
          denied
        )
      ) : id ? (
        <BankReceiptDetail key={id} id={id} owner={role === 'owner'} onSessionExpired={onSessionExpired} />
      ) : (
        <Inbox onSessionExpired={onSessionExpired} />
      )}
    </div>
  )
}
