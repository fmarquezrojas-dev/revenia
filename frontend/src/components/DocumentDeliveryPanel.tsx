import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../lib/api'
import type { DraftDocument } from '../lib/documents'
import { getDocument } from '../lib/documents'
import { deliveryErrors, downloadPdf, requestDelivery } from '../lib/delivery'
interface Props {doc:DraftDocument;onSessionExpired:()=>void;onBusy:(busy:boolean)=>void}
const labels={pending:'Pendiente',generating:'Generando PDF',ready:'Disponible',sending:'En proceso',sent:'Aceptado por el transporte',failed:'Fallido',unknown:'Resultado incierto'}
export default function DocumentDeliveryPanel({doc,onSessionExpired,onBusy}:Props) {
 const [state,setState]=useState(doc.delivery),[refresh,setRefresh]=useState(0),[busy,setBusy]=useState(false),[confirm,setConfirm]=useState<'pdf'|'email'|null>(null),[message,setMessage]=useState(''),[pollMessage,setPollMessage]=useState('')
 const submitting=useRef(false),keys=useRef<Record<string,string>>({}),mounted=useRef(true),downloadController=useRef<AbortController|null>(null),submitController=useRef<AbortController|null>(null)
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;downloadController.current?.abort();submitController.current?.abort()}},[])
 useEffect(()=>{
  let active=true,timer:ReturnType<typeof setTimeout>,count=0;const controller=new AbortController()
  async function load(){try{
   const result=await getDocument(doc.id,AbortSignal.any([controller.signal,AbortSignal.timeout(15000)]));if(!active)return;setState(result.delivery);setPollMessage('')
   const delivery=result.delivery;if(delivery&&(delivery.pdf.status==='pending'||delivery.pdf.status==='generating'||delivery.pdf.next_attempt_at||delivery.email.deliveries.some(item=>item.status==='pending'||item.status==='sending'))){if(++count<40)timer=setTimeout(()=>void load(),3000);else setPollMessage('Seguimiento pausado. Actualiza para continuar.')}
  }catch(error){if(!active)return;if(error instanceof ApiError&&error.status===401)onSessionExpired();else setPollMessage('No se pudo actualizar PDF y correo. Revisa la conexión y actualiza.')}}
  void load();return()=>{active=false;controller.abort();clearTimeout(timer)}
 },[doc.id,refresh,onSessionExpired])
 function errorMessage(error:unknown){if(error instanceof ApiError){if(error.status===401){onSessionExpired();return ''}if(error.status===429)return 'Límite de envíos alcanzado. Espera antes de volver a solicitarlo.';return Object.values(error.errors??{}).flat().join(' ')||error.message}return 'No se pudo confirmar la operación. Revisa la conexión; puedes repetir con la misma clave.'}
 async function submit(){if(!state||!confirm||submitting.current)return;submitting.current=true;setBusy(true);onBusy(true);setMessage('')
  const controller=new AbortController();submitController.current=controller
  const operation=confirm,identity=operation==='pdf'?state.pdf.version:state.pdf.file_id,slot='facturly:delivery:'+doc.id+':'+operation+':'+identity
  try{
   if(!keys.current[slot]){let key:string|null=null;try{key=sessionStorage.getItem(slot)}catch{/* Optional persistence. */}keys.current[slot]=key??crypto.randomUUID()}
   try{sessionStorage.setItem(slot,keys.current[slot])}catch{/* Keep the in-memory key. */}
   await requestDelivery(doc.id,operation,keys.current[slot],operation==='pdf'?{version:state.pdf.version}:{file_id:state.pdf.file_id!},controller.signal)
   delete keys.current[slot];try{sessionStorage.removeItem(slot)}catch{/* Optional persistence. */}
   if(mounted.current){setConfirm(null);setMessage('Solicitud registrada para procesamiento.');setRefresh(value=>value+1)}
  }catch(error){if(mounted.current)setMessage(errorMessage(error))}finally{submitting.current=false;if(mounted.current)setBusy(false);onBusy(false)}
 }
 async function download(){if(submitting.current)return;submitting.current=true;setBusy(true);onBusy(true);setMessage('');const controller=new AbortController();downloadController.current=controller
  try{const blob=await downloadPdf(doc.id,AbortSignal.any([controller.signal,AbortSignal.timeout(30000)]));if(!mounted.current)return;const url=URL.createObjectURL(blob);const anchor=document.createElement('a');anchor.href=url;anchor.download=(doc.formatted_number??'comprobante')+'.pdf';anchor.click();setTimeout(()=>URL.revokeObjectURL(url),1000)}catch(error){if(mounted.current)setMessage(errorMessage(error))}finally{submitting.current=false;if(mounted.current)setBusy(false);onBusy(false)}
 }
 return <section className="emission-panel" aria-label="PDF privado y correo"><h3>PDF privado y correo</h3><p className="muted">Documento de demostración — emisión tributaria simulada. Sin validez tributaria.</p>
 {message&&<p role="alert">{message}</p>}{pollMessage&&<p role="status">{pollMessage}</p>}
 {!state?<p>Cargando disponibilidad...</p>:<><p><strong>PDF:</strong> {labels[state.pdf.status]}</p>{state.pdf.error_code&&<p>{deliveryErrors[state.pdf.error_code]??'Requiere revisión técnica.'}</p>}{state.pdf.available&&state.pdf.status!=='ready'&&<p>La versión anterior sigue disponible.</p>}
 <div className="form-actions"><button disabled={busy||!state.pdf.available} onClick={()=>void download()}>Descargar PDF</button>{state.pdf.can_regenerate&&<button className="secondary" disabled={busy} onClick={()=>setConfirm('pdf')}>{state.pdf.status==='failed'?'Reintentar PDF':'Regenerar PDF'}</button>}</div>
 <h4>Envío por correo</h4>{state.email.recipient?<p>Destino de la entrega: <strong>{state.email.recipient}</strong></p>:<p>Destinatario no disponible o no visible para tu rol.</p>}
 {state.email.can_send&&<button disabled={busy} onClick={()=>setConfirm('email')}>Enviar por correo</button>}
 <p className="muted">Aceptado por el transporte significa que el servidor de correo recibió el mensaje; no confirma llegada a la bandeja ni lectura.</p>
 {confirm&&<div className="emission-confirm" role="group" aria-label="Confirmar operación">{confirm==='email'?<p>Enviar {doc.formatted_number} a {state.email.recipient} con el PDF disponible. Un nuevo envío intencional creará otra entrega.</p>:<p>Preparar un nuevo PDF desde los datos históricos. El anterior se conservará hasta que el reemplazo esté listo.</p>}<div className="form-actions"><button disabled={busy} onClick={()=>void submit()}>{busy?'Registrando...':confirm==='email'?'Confirmar envío':'Confirmar generación'}</button><button className="secondary" disabled={busy} onClick={()=>setConfirm(null)}>Cancelar</button></div></div>}
 <ol className="emission-events">{state.email.deliveries.map(item=><li key={item.id}><strong>{labels[item.status]}</strong><time>{new Date(item.created_at).toLocaleString('es-PE')}</time>{item.recipient&&<span>{item.recipient}</span>}{item.error_code&&<span>{deliveryErrors[item.error_code]??'Requiere revisión.'}</span>}{item.message_id&&item.status==='unknown'&&<span>Referencia para revisión: {item.message_id}</span>}</li>)}</ol></>}
 <button className="secondary" disabled={busy} onClick={()=>setRefresh(value=>value+1)}>Actualizar PDF y correo</button></section>
}
