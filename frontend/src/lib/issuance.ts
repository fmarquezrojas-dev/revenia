import { prepareCsrf, request } from './api'
export const emissionLabels: Record<string,string> = {draft:'Borrador',issued:'Emitido',queued:'En cola',sending:'Procesando',accepted:'Aceptado por el simulador',rejected:'Rechazado por el simulador',failed:'Fallo técnico'}
export interface EmissionEvent {id:string;type:string;from_status:string;to_status:string;created_at:string;message:string|null}
export async function getEmissionEvents(id:string,signal?:AbortSignal):Promise<EmissionEvent[]> {return (await request<{data:EmissionEvent[]}>('/api/v1/documents/'+encodeURIComponent(id)+'/events',{signal})).data}
export async function requestEmission(id:string,version:number,key:string,operation:'issue'|'retry'):Promise<void> {
 await prepareCsrf()
 await request('/api/v1/documents/'+encodeURIComponent(id)+'/'+operation,{method:'POST',headers:{'Idempotency-Key':key},body:JSON.stringify({version}),signal:AbortSignal.timeout(15000)})
}

export const eventLabels:Record<string,string>={number_assigned:'Número asignado',issuance_requested:'Solicitud registrada',retry_requested:'Reintento solicitado',attempt_started:'Intento iniciado',accepted:'Aceptado',rejected:'Rechazado',failed:'Fallo técnico',attempt_abandoned:'Intento interrumpido',recovered:'Procesamiento recuperado'}
