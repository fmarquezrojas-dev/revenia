import { useEffect, useState } from 'react'
import { ApiError, request, type SessionUser } from './api'

export const channels = { phone: 'Llamada', email: 'Correo', message: 'Mensajería', meeting: 'Reunión', other: 'Otro' }
export const results = { contacted: 'Contacto realizado', no_answer: 'Sin respuesta', dispute: 'Disconformidad', callback: 'Volver a contactar' }
export const tasks = { action_today: 'Acciones para hoy', action_late: 'Acciones atrasadas', promise_late: 'Compromisos vencidos', unassigned: 'Requieren responsable' }
export const actions = { assign: 'Asignación', contact: 'Gestión de contacto', correct: 'Corrección de gestión', promise: 'Compromiso registrado', reschedule: 'Reprogramación', cancel: 'Cancelación', fulfill: 'Cumplimiento confirmado' }
export type Action = keyof typeof actions
export type Person = { id: string; name: string; role: SessionUser['role']; is_active: boolean }
export interface Entry {
  id: string; kind: Action; author: string; recorded_at: string; revision: number; corrects_id: string | null; promise_id: string | null
  details: { corrected_revision?: number; name?: string | null; channel?: keyof typeof channels; result?: keyof typeof results; note?: string; next_action_on?: string | null; amount_cents?: string; due_on?: string; previous_amount_cents?: string; previous_due_on?: string; reason?: string; covered_cents?: string }
  current_details?: Entry['details']; evidence_review?: boolean; confirmed_cents?: string
}
export interface Followup {
  id: string; number: string; customer: string; currency: string; due_date: string | null; today: string
  outstanding_cents: string; paid_cents: string; credits_cents: string; needs_review: boolean
  assignee: Person | null; revision: number; next_action_on: string | null; last_contact: Entry | null
  promise: { id: string; amount_cents: string; due_on: string; opened_at: string; covered_cents: string; remaining_cents: string; overdue: boolean; review: boolean; can_fulfill: boolean } | null
  can_assign: boolean; can_manage: boolean; people: Person[]; history: Entry[]; page: number; last_page: number
}
export interface Agenda {
  rows: ({ id: string; number: string; customer: string; assignee: string | null; next_action_on: string | null; promise_due: string | null; outstanding_cents: string } & Record<keyof typeof tasks, boolean | null>)[]
  counts: Record<keyof typeof tasks | 'total', number>; total: number; today: string; currency: string; page: number; last_page: number; people: Person[]
}
export function day(value: string | null | undefined) { return value ? value.split('-').reverse().join('/') : '—' }
export function instant(value: string) {
  return new Intl.DateTimeFormat('es-PE', {dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Lima'}).format(new Date(value.replace(' ', 'T')))
}
export function followupError(error: unknown) {
  if (error instanceof ApiError) {
    if (error.status === 404) return 'No se encontró el registro en tu empresa.'
    if (error.status === 403) return 'Tu cuenta no tiene permiso para esta operación.'
    if (error.status === 422) return Object.values(error.errors ?? {}).flat().join(' ') || error.message
    if (error.status === 409) return error.message
  }
  return 'No se pudo completar la solicitud. Actualiza la vista antes de volver a intentarlo.'
}
export function useFollowupRead<T>(path: string, onExpired: () => void, version = 0) {
  const key = path + ':' + version
  const [result, setResult] = useState<{key: string; data?: T; error?: string}>()
  useEffect(() => {
    const controller = new AbortController()
    request<{data: T}>(path, { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(20000)]), cache: 'no-store' })
      .then(({data}) => { if (!controller.signal.aborted) setResult({key, data}) })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return
        if (error instanceof ApiError && error.status === 401) onExpired()
        else setResult({key, error: followupError(error)})
      })
    return () => controller.abort()
  }, [path, key, onExpired])
  return result?.key === key ? result : undefined
}
