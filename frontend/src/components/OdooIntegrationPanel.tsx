import { useState } from 'react'
import type { FormEvent } from 'react'
import { useOdoo } from '../lib/odoo'
import DocumentLookup from './DocumentLookup'
import type { Customer } from '../lib/api'

const kinds = {
  customer: 'Cliente identificado',
  anonymous: 'Cliente de boletas sin identificar',
  currency: 'Moneda',
  tax: 'Impuesto',
  unit: 'Unidad',
  journal: 'Diario de ventas',
  account: 'Cuenta de ingresos',
}
const sources = {
  anonymous: ['anonymous'],
  currency: ['PEN', 'USD'],
  tax: ['10', '20', '30'],
  unit: ['NIU', 'ZZ'],
  journal: ['sales'],
  account: ['income'],
}
type Kind = keyof typeof kinds
interface Connection {
  endpoint: string
  remote_company: number
  remote_name: string
  login: string
  revision: string
  enabled: boolean
  verified_at: string
}
interface Configuration {
  available: boolean
  endpoints: string[]
  tenant: string
  connection: Connection | null
  mappings: { kind: Kind; source: string; remote_id: number }[]
}
interface Props {
  onSessionExpired: () => void
  onSavingChange: (b: boolean) => void
}
export default function OdooIntegrationPanel({
  onSessionExpired,
  onSavingChange,
}: Props) {
  const { data, error, busy, change, refresh } = useOdoo<Configuration>(
    '/api/v1/integrations/odoo',
    onSessionExpired,
    onSavingChange,
  )
  const [kind, setKind] = useState<Kind>('customer')
  const [customer, setCustomer] = useState<Customer>()
  const [notice, setNotice] = useState('')
  const c = data?.connection
  async function configure(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = e.currentTarget
    const values = new FormData(form)
    const key = form.elements.namedItem('api_key') as HTMLInputElement
    key.value = ''
    setNotice('')
    if (
      await change('PUT', {
        endpoint: values.get('endpoint'),
        login: values.get('login'),
        api_key: values.get('api_key') || null,
        remote_company: Number(values.get('remote_company')),
        revision: c?.revision ?? null,
      })
    )
      setNotice('Identidad y permisos verificados.')
  }
  async function map(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const values = new FormData(e.currentTarget)
    setNotice('')
    if (
      await change(
        'PUT',
        {
          kind,
          source: kind === 'customer' ? customer?.id : values.get('source'),
          remote_id: Number(values.get('remote_id')),
          revision: c?.revision,
        },
        '/mappings',
      )
    )
      setNotice('Correspondencia guardada para esta compañía de Odoo.')
  }
  return (
    <section className="surface">
      <h2>Odoo Community 18.0</h2>
      {error && <p role="alert">{error}</p>}
      {notice && <p role="status">{notice}</p>}
      <button
        className="secondary"
        disabled={busy}
        onClick={() => {
          setNotice('')
          refresh()
        }}
      >
        Actualizar configuración
      </button>
      {data && (
        <>
          <p>
            Empresa de Revenia · <code>{data.tenant}</code>
          </p>
          <p>
            {!data.available
              ? 'El despliegue tiene desactivada la integración.'
              : c?.enabled
                ? 'Transferencias habilitadas.'
                : 'Transferencias desactivadas.'}
          </p>
          {c && (
            <p>
              Compañía verificada · <strong>{c.remote_name}</strong> (ID{' '}
              {c.remote_company}) ·{' '}
              {new Date(c.verified_at).toLocaleString('es-PE')}
            </p>
          )}
          <form
            key={c?.revision ?? 'new'}
            onSubmit={configure}
            autoComplete="off"
          >
            <fieldset
              disabled={busy || !data.available || !data.endpoints.length}
            >
              <legend>Conexión por empresa</legend>
              <label>
                Destino autorizado
                <select name="endpoint" defaultValue={c?.endpoint} required>
                  {data.endpoints.map((e) => (
                    <option key={e}>{e}</option>
                  ))}
                </select>
              </label>
              <label>
                ID de compañía en Odoo
                <input
                  name="remote_company"
                  type="number"
                  min={1}
                  required
                  defaultValue={c?.remote_company}
                />
              </label>
              <label>
                Usuario técnico
                <input
                  name="login"
                  required
                  maxLength={255}
                  defaultValue={c?.login}
                />
              </label>
              <label>
                Clave API
                <input
                  name="api_key"
                  type="password"
                  autoComplete="new-password"
                  required={!c}
                  maxLength={1024}
                />
              </label>
              {c && (
                <p className="muted">
                  Hay una clave configurada. Ingrésala al cambiar de usuario o
                  destino.
                </p>
              )}
              <button>Verificar y guardar</button>
            </fieldset>
          </form>
          {c && (
            <>
              <button
                className="secondary"
                disabled={busy || (!data.available && !c.enabled)}
                onClick={async () => {
                  setNotice('')
                  if (
                    await change('PATCH', {
                      enabled: !c.enabled,
                      revision: c.revision,
                    })
                  )
                    setNotice(
                      c.enabled
                        ? 'Transferencias desactivadas.'
                        : 'Las próximas aceptaciones se transferirán a Odoo.',
                    )
                }}
              >
                {c.enabled
                  ? 'Desactivar transferencias'
                  : 'Habilitar transferencias'}
              </button>
              <h3>Correspondencias</h3>
              <form onSubmit={map}>
                <fieldset disabled={busy}>
                  <legend>Vincular un registro</legend>
                  <label>
                    Tipo
                    <select
                      value={kind}
                      onChange={(e) => setKind(e.target.value as Kind)}
                    >
                      {Object.entries(kinds).map(([k, v]) => (
                        <option key={k} value={k}>
                          {v}
                        </option>
                      ))}
                    </select>
                  </label>
                  {kind === 'customer' ? (
                    <>
                      <DocumentLookup
                        kind="customer"
                        currency=""
                        includeInactive
                        onCustomer={setCustomer}
                        onProduct={() => {}}
                        onSessionExpired={onSessionExpired}
                      />
                      {customer && (
                        <p>
                          Cliente seleccionado · {customer.name} ·{' '}
                          {customer.doc_number}
                        </p>
                      )}
                    </>
                  ) : (
                    <label>
                      Referencia en Revenia
                      <select key={kind} name="source">
                        {sources[kind].map((s) => (
                          <option key={s}>{s}</option>
                        ))}
                      </select>
                    </label>
                  )}
                  <label>
                    ID del registro en Odoo
                    <input type="number" name="remote_id" min={1} required />
                  </label>
                  <button disabled={kind === 'customer' && !customer}>
                    Guardar correspondencia
                  </button>
                </fieldset>
              </form>
              {data.mappings.length > 0 && (
                <div className="dashboard-table">
                  <table>
                    <thead>
                      <tr>
                        <th>Tipo</th>
                        <th>Referencia en Revenia</th>
                        <th>ID en Odoo</th>
                        <th>Acción</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.mappings.map((m) => (
                        <tr key={m.kind + m.source}>
                          <td>{kinds[m.kind]}</td>
                          <td>{m.source}</td>
                          <td>{m.remote_id}</td>
                          <td>
                            <button
                              className="secondary"
                              disabled={busy}
                              onClick={async () => {
                                setNotice('')
                                if (
                                  window.confirm(
                                    '¿Quitar esta correspondencia de la compañía verificada?',
                                  ) &&
                                  (await change(
                                    'DELETE',
                                    { ...m, revision: c.revision },
                                    '/mappings',
                                  ))
                                )
                                  setNotice('Correspondencia retirada.')
                              }}
                            >
                              Quitar
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}
        </>
      )}
    </section>
  )
}
