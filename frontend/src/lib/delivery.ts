import { ApiError, prepareCsrf, request } from './api'
export interface DeliveryState {
 pdf:{status:'pending'|'generating'|'ready'|'failed';version:number;available:boolean;file_id:string|null;generated_at:string|null;error_code:string|null;can_regenerate:boolean;next_attempt_at:string|null}
 email:{recipient:string|null;can_send:boolean;deliveries:{id:string;status:'pending'|'sending'|'sent'|'failed'|'unknown';recipient:string|null;message_id:string|null;error_code:string|null;created_at:string;sent_at:string|null}[]}
}
export async function requestDelivery(id:string,operation:'pdf'|'email',key:string,payload:{version:number}|{file_id:string},signal?:AbortSignal):Promise<void> {
 const deadline=AbortSignal.timeout(15000);const operationSignal=signal?AbortSignal.any([signal,deadline]):deadline;await prepareCsrf(operationSignal);await request('/api/v1/documents/'+encodeURIComponent(id)+(operation==='pdf'?'/pdf/regenerate':'/email'),{method:'POST',headers:{'Idempotency-Key':key},body:JSON.stringify(payload),signal:operationSignal})
}
export async function downloadPdf(id:string,signal?:AbortSignal):Promise<Blob> {
 const response=await fetch('/api/v1/documents/'+encodeURIComponent(id)+'/pdf',{credentials:'include',headers:{Accept:'application/pdf, application/json'},signal})
 if(!response.ok)throw new ApiError(response.status,await response.json().catch(()=>({message:'No se pudo descargar el PDF.'})))
 if(!response.headers.get('Content-Type')?.toLowerCase().startsWith('application/pdf'))throw new Error('La respuesta no es un PDF.')
 const blob=await response.blob();if(!(await blob.slice(0,5).text()).startsWith('%PDF-'))throw new Error('El archivo recibido no es un PDF valido.')
 return blob
}
export const deliveryErrors:Record<string,string>={snapshot_incomplete:'Faltan datos históricos. Requiere revisión del flujo que emitió el comprobante.',pdf_generation_failed:'No se pudo generar el PDF. Se conserva cualquier versión anterior disponible.',lease_expired:'La generación fue interrumpida.',file_missing:'El archivo no está disponible. Solicita regeneración al propietario.',pdf_unavailable:'No se encontró el PDF de esta entrega.',delivery_unknown:'Resultado incierto. Requiere revisión del transporte; no se reenviará automáticamente.',demo_transport_blocked:'El transporte o destino de pruebas no está autorizado.',email_render_failed:'No se pudo preparar el mensaje.',transport_reviewed:'Resultado verificado por operación técnica.'}
