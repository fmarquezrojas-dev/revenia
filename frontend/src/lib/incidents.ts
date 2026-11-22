import { odooReasons } from './odoo'
import { collectionMessages, paymentLabels } from './collections'
import { deliveryErrors } from './delivery'
import { eventLabels } from './issuance'

export const processes = { emission: 'Emisión', pdf: 'Generación de PDF', email: 'Entrega de correo', collection: 'Cobranza', odoo: 'Transferencia a Odoo' }
export const states = {
  processing: 'En proceso', retry: 'Reintento automático', delayed: 'Demorada',
  manual: 'Admite recuperación manual', failed: 'Fallo definitivo del intento', confirmation: 'Pendiente de confirmación', resolved: 'Resuelta en origen',
}
export const actions = {
  retry_odoo: 'Recuperar transferencia a Odoo', reviewed: 'Registrar revisión', retry_emission: 'Recuperar emisión', regenerate_pdf: 'Regenerar PDF',
  retry_email: 'Solicitar nuevo envío', reconcile: 'Conciliar esta orden',
}
export type IncidentAction = keyof typeof actions
export interface Incident {
  id: string; document_id: string | null; reference: string; number: string | null; process: keyof typeof processes
  state: keyof typeof states; source_status: string; reason: string | null; action: Exclude<IncidentAction,'reviewed'> | null
  revision: string; can_review: boolean; first_detected_at: string | null; updated_at: string; next_attempt_at: string | null
}
export interface IncidentPage { rows: Incident[]; page: number; last_page: number; total: number }
export interface HistoryEvent { destination?:string|null; id: string; at: string; actor: string | null; event: string; code: string | null }
export interface IncidentDetail extends Incident { history: { events: HistoryEvent[]; page: number; has_more: boolean } }

const reasons: Record<string,string> = {
  ...odooReasons,...paymentLabels,...collectionMessages,...deliveryErrors,
  temporary: 'El simulador está temporalmente ocupado.', timeout: 'El simulador todavía no confirmó el resultado.',
  simulated_acceptance: 'Aceptación registrada por el simulador.', simulated_rejection: 'El simulador confirmó el rechazo del comprobante.',
  identity_conflict: 'La referencia de envío no corresponde al comprobante.', configuration: 'La configuración del emisor requiere revisión técnica.',
  queue_unavailable: 'El trabajo está pendiente de publicación en la cola.', lease_expired: 'Venció el plazo del intento anterior.',
  requested: 'Solicitud registrada para procesamiento.', recorded: 'Revisión registrada.', state_changed: 'La operación cambió. Actualiza el detalle.',
  rate_limited: 'Espera antes de volver a solicitar la acción.', unavailable: 'Las condiciones de la operación impiden completar la solicitud.',
  pending: 'Pendiente', sent: 'Aceptado por el transporte', ready: 'PDF disponible',
  live_payment_blocked: 'El entorno del pago difiere del de la orden.', merchant_order_mismatch: 'La orden del proveedor difiere de la registrada.',
  external_reference_mismatch: 'La referencia del proveedor difiere de la orden.', order_not_found: 'La referencia requiere verificación técnica.',
  technical_review: 'El resultado requiere revisión técnica.',
}
export const historyLabels: Record<string,string> = {
  ...eventLabels, ...actions, odoo_queued:'Transferencia en cola', odoo_rebound:'Nuevo destino autorizado', odoo_retry:'Nuevo intento programado', odoo_dependency:'En espera del original', odoo_uncertain:'Resultado externo incierto', odoo_review:'Transferencia por revisar', odoo_done:'Borrador comprobado en Odoo', reviewed: 'Revisión solicitada', retry_emission: 'Recuperación de emisión solicitada',
  regenerate_pdf: 'Regeneración solicitada', retry_email: 'Nuevo envío solicitado', reconcile: 'Conciliación solicitada',
  email_requested: 'Solicitud de correo registrada', pdf_requested: 'Solicitud de PDF registrada', ready: 'PDF disponible',
  sent: 'Correo aceptado por el transporte', unknown: 'Resultado de entrega incierto', publication_failed: 'Publicación en cola interrumpida', publication_delayed: 'Publicación demorada, programada de nuevo',
  order_requested: 'Orden solicitada', reconciliation_requested: 'Consulta al proveedor solicitada', provider_status_observed: 'Estado informado por el proveedor',
  payment_reconciled: 'Pago cotejado', stale_payment_ignored: 'Se conservó el estado más reciente', work_completed: 'Procesamiento completado',
  work_failed: 'El trabajo encontró una incidencia', work_queued: 'Trabajo en cola', work_started: 'Intento iniciado', payment_mismatch: 'Datos del pago por verificar',
  review: 'Requiere revisión', resolved: 'Trabajo recuperado',
}
export function explanation(code: string | null) { return code ? reasons[code] ?? 'Requiere comprobación técnica.' : '' }
export function guidance(row: Incident): string {
  if (!row.document_id) return 'La notificación está vinculada a tu comercio. Operación técnica debe verificar su correspondencia con una orden.'
  if (row.next_attempt_at) return 'Recuperación automática programada.'
  if (row.state === 'delayed') return 'El proceso superó su plazo de recuperación. Revisa la cronología.'
  if (row.state === 'resolved') return 'El proceso de origen confirmó el resultado.'
  if (row.state === 'processing') return 'Procesamiento en curso.'
  if (row.process === 'email' && row.state === 'confirmation') return 'Operación técnica debe comprobar el transporte antes de otro envío.'
  if (row.reason === 'receiver_unknown') return 'Verifica el receptor histórico con operación técnica.'
  if (row.source_status === 'rejected') return 'Consulta el rechazo en el comprobante.'
  if (row.action) return actions[row.action]
  return 'Consulta el comprobante y el historial de la operación.'
}
export const incidentDate = (value: string | null) => value ? new Intl.DateTimeFormat('es-PE', {
  timeZone:'America/Lima', dateStyle:'short', timeStyle:'medium',
}).format(new Date(value)) : 'Sin detección registrada'
