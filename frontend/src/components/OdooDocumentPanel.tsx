import { Link } from 'react-router'
import { odooStatuses, odooReasons, useOdoo } from '../lib/odoo'

interface Transfer {
  id: string
  status: string
  revision: number
  destination: string
  destination_name: string
  external_id: number | null
  accounting_state: string | null
  error_code: string | null
  updated_at: string
}
interface Detail {
  transfer: Transfer | null
  active: boolean
  can_queue: boolean
  can_rebind: boolean
  current_destination: string | null
  current_name: string | null
}
interface Props {
  documentId: string
  originalId?: string | null
  onSessionExpired: () => void
  onSavingChange: (b: boolean) => void
}
export default function OdooDocumentPanel({
  documentId,
  originalId,
  onSessionExpired,
  onSavingChange,
}: Props) {
  const { data, error, busy, change, refresh } = useOdoo<Detail>(
    '/api/v1/documents/' + documentId + '/odoo',
    onSessionExpired,
    onSavingChange,
  )
  const t = data?.transfer
  const originalDestination = t
    ? `${t.destination_name} (${t.destination.slice(0, 12)})`
    : ''
  const currentDestination = data?.current_destination
    ? `${data.current_name} (${data.current_destination.slice(0, 12)})`
    : ''
  return (
    <section className="emission-panel" aria-label="Transferencia a Odoo">
      <h3>Transferencia a Odoo</h3>
      {!data && !error && <p role="status">Consultando la transferencia…</p>}
      {error && <p role="alert">{error}</p>}
      {data && (
        <>
          <p>{t ? odooStatuses[t.status] : 'Sin transferencia registrada.'}</p>
          {!data.active && <p>Las transferencias están desactivadas.</p>}
          {t && (
            <>
              <p>
                Destino · {originalDestination} · Última actualización{' '}
                {new Date(t.updated_at).toLocaleString('es-PE')}
              </p>
              {t.external_id && (
                <p>
                  Documento Odoo #{t.external_id} · Estado contable al
                  comprobarlo:{' '}
                  {(
                    {
                      draft: 'Borrador',
                      posted: 'Contabilizado',
                      cancel: 'Cancelado',
                    } as Record<string, string>
                  )[t.accounting_state ?? ''] ?? 'Por verificar'}
                </p>
              )}
              {t.error_code && (
                <p>{odooReasons[t.error_code] ?? 'Revisa la transferencia.'}</p>
              )}
              {t.status === 'dependency' && originalId && (
                <Link to={'/documents/' + originalId}>
                  Abrir comprobante original
                </Link>
              )}
              <p>
                <Link to={'/incidents/odoo/' + t.id}>
                  Ver cronología de transferencia
                </Link>
              </p>
            </>
          )}
          <div className="form-actions">
            {data.can_queue && (
              <button disabled={busy} onClick={() => void change('POST', {})}>
                Transferir este comprobante
              </button>
            )}
            {data.can_rebind && (
              <button
                disabled={busy}
                onClick={() => {
                  if (
                    window.confirm(
                      '¿Autorizar la transferencia a ' +
                        currentDestination +
                        ' en lugar de ' +
                        originalDestination +
                        '?',
                    )
                  )
                    void change('POST', {
                      rebind: true,
                      revision: t?.revision,
                      destination: data.current_destination,
                    })
                }}
              >
                Autorizar nuevo destino · {currentDestination}
              </button>
            )}
          </div>
        </>
      )}
      <button className="secondary" disabled={busy} onClick={refresh}>
        Actualizar estado ERP
      </button>
    </section>
  )
}
