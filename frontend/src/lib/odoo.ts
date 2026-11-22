import { useEffect, useRef, useState } from 'react'
import { ApiError, prepareCsrf, request } from './api'

export const odooStatuses: Record<string, string> = {
  queued: 'En cola',
  processing: 'Transfiriendo',
  retry: 'Reintento programado',
  dependency: 'Espera el comprobante original',
  review: 'Requiere revisión',
  uncertain: 'Resultado externo por confirmar',
  done: 'Transferencia comprobada',
}
export const odooReasons: Record<string, string> = {
  odoo_destination:
    'El destino cambió. Revisa la compañía vinculada y el destino de esta transferencia.',
  odoo_credentials:
    'Verifica las credenciales del usuario técnico en Integraciones.',
  odoo_unavailable: 'No se pudo obtener respuesta de Odoo.',
  odoo_original: 'Transfiere primero el comprobante original al mismo destino.',
  odoo_mapping:
    'Comprueba que los registros de Odoo estén activos, correspondan a la compañía y tengan la configuración contable esperada.',
  odoo_amounts:
    'Las líneas o los importes de Odoo difieren del comprobante aceptado. Revisa impuestos, unidades y redondeo.',
  odoo_receipt:
    'La referencia, los datos o el estado contable en Odoo requieren comprobación.',
  odoo_endpoint:
    'El destino está deshabilitado o su dirección autorizada cambió.',
  odoo_paused: 'La conexión fue desactivada o modificada.',
  odoo_rejected:
    'Odoo rechazó la llamada. Revisa el módulo, los permisos y el registro del servidor.',
  odoo_document: 'El documento debe estar aceptado en Revenia.',
  odoo_map_anonymous:
    'Vincula el contacto de Odoo para las boletas sin cliente identificado.',
  odoo_map_customer: 'Falta vincular el cliente histórico.',
  odoo_map_tax: 'Falta vincular el impuesto de una línea.',
  odoo_map_unit: 'Falta vincular la unidad de una línea.',
  odoo_map_currency: 'Falta vincular la moneda.',
  odoo_map_account: 'Falta configurar la cuenta de ingresos.',
  odoo_map_journal: 'Falta configurar el diario de ventas.',
}

export function useOdoo<T>(
  url: string,
  expired: () => void,
  saving: (busy: boolean) => void,
) {
  const [data, setData] = useState<T>()
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [version, refresh] = useState(0)
  const locked = useRef(false)
  const generation = useRef(0)
  function describe(e: unknown) {
    if (e instanceof ApiError)
      return Object.values(e.errors ?? {}).flat()[0] ?? e.message
    return 'No se pudo confirmar la operación. Actualiza el estado.'
  }
  useEffect(() => {
    const controller = new AbortController()
    const reading = ++generation.current
    request<{ data: T }>(url, { signal: controller.signal, cache: 'no-store' })
      .then((r) => {
        if (!controller.signal.aborted && reading === generation.current) {
          setData(r.data)
          setError('')
        }
      })
      .catch((e) => {
        if (!controller.signal.aborted && reading === generation.current) {
          if (e instanceof ApiError && e.status === 401) expired()
          else setError(describe(e))
        }
      })
    return () => controller.abort()
  }, [url, version, expired])
  async function change(method: string, body: object, suffix = '') {
    if (locked.current) return false
    generation.current++
    locked.current = true
    setBusy(true)
    saving(true)
    setError('')
    try {
      const signal = AbortSignal.timeout(40000)
      await prepareCsrf(signal)
      const result = await request<{ data: T }>(url + suffix, {
        method,
        body: JSON.stringify(body),
        signal,
      })
      setData(result.data)
      return true
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) expired()
      else setError(describe(e))
      return false
    } finally {
      locked.current = false
      setBusy(false)
      saving(false)
    }
  }
  return { data, error, busy, change, refresh: () => refresh((v) => v + 1) }
}
