import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, prepareCsrf, request } from '../lib/api'

interface Connection {
  status: 'unlinked' | 'connected' | 'disabled' | 'invalid'
  collector_id: string | null
  nickname: string | null
  verified_at: string | null
  webhook_url: string | null
  ready: boolean
  environment: 'sandbox'
}

export default function MerchantConnectionPanel({ onSessionExpired, onSavingChange }: { onSessionExpired: () => void; onSavingChange: (value: boolean) => void }) {
  const [data, setData] = useState<Connection>()
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [revision, setRevision] = useState(0)
  const saving = useRef(false)
  const endpoint = '/api/v1/merchant-connection'

  useEffect(() => {
    const controller = new AbortController()
    request<{ data: Connection }>(endpoint, { signal: controller.signal, cache: 'no-store' })
      .then(response => { if (!controller.signal.aborted) { setData(response.data); setError('') } })
      .catch(e => {
        if (controller.signal.aborted) return
        if (e instanceof ApiError && e.status === 401) onSessionExpired()
        else setError('No se pudo consultar la conexión de cobros.')
      })
    return () => controller.abort()
  }, [revision, onSessionExpired])

  async function save(event?: FormEvent<HTMLFormElement>) {
    event?.preventDefault()
    if (saving.current) return
    const form = event?.currentTarget
    if (!form && !window.confirm('¿Desactivar los nuevos enlaces de esta cuenta? Los pagos anteriores conservan su receptor para conciliación.')) return
    saving.current = true
    const fields = form ? new FormData(form) : null
    onSavingChange(true)
    setBusy(true); setError(''); setNotice('')
    try {
      const signal = AbortSignal.timeout(30000)
      await prepareCsrf(signal)
      const response = await request<{ data: Connection }>(endpoint, {
        method: fields ? 'PUT' : 'DELETE', signal,
        body: fields ? JSON.stringify({ access_token: fields.get('token'), webhook_secret: fields.get('secret') }) : undefined,
      })
      setData(response.data)
      setNotice(fields ? 'Cuenta receptora verificada.' : 'Conexión desactivada.')
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) onSessionExpired()
      else setError(e instanceof ApiError ? Object.values(e.errors ?? {}).flat()[0] ?? e.message : 'No se pudo guardar la conexión.')
    } finally {
      form?.reset()
      saving.current = false
      setBusy(false)
      onSavingChange(false)
    }
  }

  return <section className="surface" aria-labelledby="merchant-receiver">
    <h2 id="merchant-receiver">Cuenta receptora de Mercado Pago</h2>
    {error && <p className="error" role="alert">{error}</p>}
    {notice && <p role="status">{notice}</p>}
    {!data ? error ? <button onClick={() => setRevision(v => v + 1)}>Reintentar</button> : <p role="status">Cargando conexión…</p> : <>
      <p className="payment-environment">Entorno de pruebas · Perú</p>
      <dl className="profile">
        <div><dt>Estado</dt><dd>{{ connected: 'Vinculada', disabled: 'Desactivada', invalid: 'Vuelve a verificar las credenciales', unlinked: 'Sin vincular' }[data.status]}</dd></div>
        {data.collector_id && <div><dt>Receptor verificado</dt><dd>{data.nickname} · {data.collector_id}</dd></div>}
        {data.verified_at && <div><dt>Última verificación correcta</dt><dd>{new Date(data.verified_at).toLocaleString('es-PE', { timeZone: 'America/Lima' })} (Lima)</dd></div>}
      </dl>
      {!data.ready && <p>Para habilitar los enlaces, el administrador de Revenia debe configurar el entorno y las URL públicas de notificación y retorno.</p>}
      {data.webhook_url && <p>En la aplicación de Mercado Pago, configura el evento Pagos para esta URL de pruebas. <code className="webhook-url">{data.webhook_url}</code></p>}
      <form onSubmit={save} autoComplete="off" aria-busy={busy}>
        <fieldset disabled={busy}><legend>{data.status === 'connected' ? 'Actualizar credenciales o cambiar de cuenta' : 'Vincular cuenta vendedora'}</legend>
          <label htmlFor="merchant-token">Access Token del vendedor de pruebas</label>
          <input id="merchant-token" name="token" type="password" autoComplete="off" minLength={16} maxLength={2048} required />
          <label htmlFor="merchant-secret">Clave secreta de Webhooks de la aplicación</label>
          <input id="merchant-secret" name="secret" type="password" autoComplete="off" minLength={16} maxLength={512} required />
          <div className="form-actions"><button type="submit">{busy ? 'Guardando…' : 'Verificar y vincular'}</button>
          {data.status === 'connected' && <button className="secondary" type="button" onClick={() => void save()}>Desactivar conexión</button>}</div>
        </fieldset>
      </form>
    </>}
  </section>
}
