import { useEffect, useRef, useState } from 'react'
import { ApiError, prepareCsrf, request } from './api'
import { dashboardMoney } from './dashboard'
import { decimalToCents } from './money'

export const bankUrl = '/api/v1/bank-receipts'
export const labels = {
  confirmed: 'Todos los confirmados',
  pending: 'Pendiente de confirmación',
  settlement: 'Liquidación de Mercado Pago',
  unidentified: 'Cliente por identificar',
  available: 'Por aplicar',
  partial: 'Parcialmente aplicado',
  applied: 'Aplicado',
}
export const origins = { customer: 'Pago directo de cliente', mp_settlement: 'Liquidación de Mercado Pago' }
export const eventLabels = {
  match: 'Registro repetido identificado',
  register: 'Registro del abono',
  confirm: 'Confirmación bancaria',
  identify: 'Identificación del cliente',
  apply: 'Distribución confirmada',
  reverse: 'Reversión interna',
}
export type Account = {
  id: string
  alias: string
  institution: string
  currency: string
  active: boolean
  revision: number
}
export type Receipt = {
  id: string
  revision: number
  account_id: string
  alias: string
  customer_id: string | null
  customer: string | null
  currency: string
  bank_date: string
  method: 'deposit' | 'transfer'
  origin: keyof typeof origins
  reference: string
  situation: keyof typeof labels
  recorded_at: string
  confirmed_at: string | null
  amount_cents: string
  applied_cents: string
  available_cents: string
}
export type Page<T> = { rows: T[]; total: number; page: number; last_page: number }
export type Application = {
  id: string
  document_id: string
  number: string
  amount_cents: string
  actor: string
  applied_at: string
  reversed_at: string | null
  reversed_by: string | null
  reason: string | null
  batch_id: string
}
export type BankEvent = {
  request_id: string
  action: keyof typeof eventLabels
  actor: string
  recorded_at: string
  details: {
    origin?: keyof typeof origins
    name?: string
    reason?: string
    documents?: number
    amount_cents?: string
    application_id?: string
  }
}
export type Detail = {
  receipt: Receipt
  note: string | null
  applications: Page<Application>
  history: Page<BankEvent>
}
export type Candidate = {
  id: string
  number: string
  outstanding_cents: string
  reserved_cents: string
  available_cents: string
  due_date: string | null
  needs_review: boolean
  pending_confirmation: boolean
}
export const bankMoney = (amount: string | number, currency: string) =>
  dashboardMoney(String(amount), currency)
export const bankTime = (s: string) =>
  new Intl.DateTimeFormat('es-PE', {
    dateStyle: 'short',
    timeStyle: 'short',
    timeZone: 'America/Lima',
  }).format(new Date(s.replace(' ', 'T').replace(/([+-]\d\d)$/, '$1:00')))
export function bankCents(value: string) {
  const result = decimalToCents(value)
  if (result === 0) throw new Error('Introduce un importe mayor que cero.')
  return result.toString()
}
function message(e: unknown) {
  if (e instanceof ApiError)
    return (
      Object.values(e.errors ?? {})
        .flat()
        .join(' ') || e.message
    )
  return e instanceof Error && e.name === 'Error'
    ? e.message
    : 'No se pudo completar la solicitud. Revisa la conexión.'
}
export function useBankRead<T>(path: string, version: number, expired: () => void) {
  const key = path + ':' + version
  const [state, setState] = useState<{ key: string; data?: T; error?: string }>()
  useEffect(() => {
    const controller = new AbortController()
    request<{ data: T }>(path, {
      signal: AbortSignal.any([controller.signal, AbortSignal.timeout(20000)]),
      cache: 'no-store',
    })
      .then((v) => {
        if (!controller.signal.aborted) setState({ key, data: v.data })
      })
      .catch((e) => {
        if (controller.signal.aborted) return
        if (e instanceof ApiError && e.status === 401) expired()
        else setState({ key, error: message(e) })
      })
    return () => controller.abort()
  }, [path, key, expired])
  return state?.key === key ? state : undefined
}
export function useBankWrite(expired: () => void) {
  const lock = useRef(false),
    previous = useRef({ signature: '', key: '' })
  const alive = useRef(true)
  useEffect(() => {
    alive.current = true
    return () => {
      alive.current = false
    }
  }, [])
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(''),
    [related, setRelated] = useState<string>()
  async function send(
    path: string,
    make: () => Record<string, unknown>,
    done: (result: { id?: string; repeated?: boolean }) => void,
    method = 'POST',
  ) {
    if (lock.current) return
    lock.current = true
    setBusy(true)
    setError('')
    setRelated(undefined)
    try {
      const body = make(),
        signature = JSON.stringify([path, method, body])
      if (previous.current.signature !== signature) previous.current = { signature, key: crypto.randomUUID() }
      const signal = AbortSignal.timeout(25000)
      await prepareCsrf(signal)
      const result = await request<{ data?: { id: string; repeated: boolean } }>(path, {
        method,
        signal,
        body: JSON.stringify({ ...body, request_id: previous.current.key }),
      })
      previous.current = { signature: '', key: '' }
      if (alive.current) done(result.data ?? {})
    } catch (e) {
      if (alive.current) {
        if (e instanceof ApiError && e.status === 401) expired()
        else {
          setError(message(e))
          if (e instanceof ApiError) setRelated(e.existingId)
        }
      }
    } finally {
      lock.current = false
      if (alive.current) setBusy(false)
    }
  }
  return { busy, error, related, send }
}
