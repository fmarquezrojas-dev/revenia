import { useEffect, useRef, useState } from 'react'
import UsageIndicator from './UsageIndicator'
import { ApiError } from '../lib/api'
import type { DraftDocument } from '../lib/documents'
import { documentError } from '../lib/documents'
import { emissionLabels, eventLabels, getEmissionEvents, requestEmission } from '../lib/issuance'
import type { EmissionEvent } from '../lib/issuance'
import { centsToDecimal } from '../lib/money'
interface Props {role?:string;doc:DraftDocument;canWrite:boolean;disabled:boolean;onRefresh:()=>void;onBusy:(busy:boolean)=>void;onSessionExpired:()=>void}
export default function EmissionControls({role,doc,canWrite,disabled,onRefresh,onBusy,onSessionExpired}:Props) {
 const [quotaAllowed,setQuotaAllowed]=useState(true)
 const [confirm,setConfirm]=useState(false),[busy,setBusy]=useState(false),[outcome,setOutcome]=useState({version:doc.version,text:''})
 const [events,setEvents]=useState<EmissionEvent[]>([]),[eventError,setEventError]=useState('')
 const message=outcome.version===doc.version?outcome.text:''
 function setMessage(text:string){setOutcome({version:doc.version,text})}
 const submitting=useRef(false),pending=useRef<{slot:string;key:string}|null>(null)
 useEffect(()=>{
  if(doc.status==='draft')return
  let active=true;const controller=new AbortController()
  getEmissionEvents(doc.id,controller.signal).then(rows=>{if(active){setEvents(rows);setEventError('')}}).catch(error=>{if(!active)return;if(error instanceof ApiError&&error.status===401)onSessionExpired();else setEventError('No se pudo actualizar el historial.')})
  return()=>{active=false;controller.abort()}
 },[doc.id,doc.status,doc.version,onSessionExpired])
 async function send() {
  if(submitting.current)return
  submitting.current=true;setBusy(true);onBusy(true);setMessage('')
  const operation=doc.status==='draft'?'issue':'retry'
  const slot='facturly:emission:'+doc.id+':'+operation+':'+doc.version
  try {
   if(pending.current?.slot!==slot){let key:string|null=null;try{key=sessionStorage.getItem(slot)}catch{/* Storage may be disabled. */}pending.current={slot,key:key??crypto.randomUUID()}}
   const key=pending.current.key
   try{sessionStorage.setItem(slot,key)}catch{/* The ref still preserves the key for retries. */}
   await requestEmission(doc.id,doc.version,key,operation)
   try{sessionStorage.removeItem(slot)}catch{/* No sensitive data is stored. */}
   setConfirm(false);setMessage('Solicitud registrada. Esto no significa aceptación tributaria.');onRefresh()
  } catch(error) {
   if(error instanceof ApiError&&error.status===401)onSessionExpired()
   else setMessage(error instanceof ApiError?(error.status===409?'El comprobante cambió. Actualiza su estado y revisa antes de continuar.':documentError(error)):'No se pudo confirmar la recepción. Puedes repetir la solicitud con la misma clave o actualizar el estado. No es un rechazo tributario.')
  } finally {submitting.current=false;setBusy(false);onBusy(false)}
 }
 return <section className="emission-panel" aria-label="Emisión simulada">
  <h3>Emisión tributaria simulada</h3><p className="muted">No se envía a SUNAT ni tiene validez tributaria.</p>
  {doc.status!=='draft'&&<><p role="status"><strong>{emissionLabels[doc.status]}</strong></p>{doc.result_message&&<p>{doc.result_message}</p>}{doc.next_attempt_at&&<p>Recuperación automática pendiente. Se consultará el resultado antes de reenviar.</p>}</>}
  {doc.status==='draft'&&<UsageIndicator owner={role==='owner'} version={doc.version} onSessionExpired={onSessionExpired} onChange={setQuotaAllowed}/>}
  {message&&<p role="alert">{message}</p>}
  {canWrite&&doc.status==='draft'&&!confirm&&<button disabled={disabled||busy||(doc.status==='draft'&&!quotaAllowed)} onClick={()=>setConfirm(true)}>Emitir comprobante</button>}
  {confirm&&doc.status==='draft'&&<div className="emission-confirm" role="group" aria-label="Confirmar emisión">
   <p><strong>{doc.customer?.name??'Sin cliente identificado'}</strong> · {doc.series.code} · {centsToDecimal(doc.totals.total_cents)} {doc.currency}</p>
   <p>Se asignará un número y el comprobante dejará de ser editable. Se emitirá la versión guardada {doc.version}.</p>
   <div className="form-actions"><button disabled={disabled||busy||(doc.status==='draft'&&!quotaAllowed)} onClick={()=>void send()}>{busy?'Registrando...':'Confirmar emisión'}</button><button className="secondary" disabled={busy} onClick={()=>setConfirm(false)}>Cancelar emisión</button></div>
  </div>}
  {canWrite&&doc.status==='failed'&&doc.can_retry&&<button disabled={disabled||busy} onClick={()=>void send()}>{busy?'Registrando...':'Reintentar envío'}</button>}
  <button className="secondary" disabled={disabled||busy} onClick={onRefresh}>Actualizar estado</button>
  {doc.status!=='draft'&&<><h4>Historial de emisión</h4>{eventError&&<p role="alert">{eventError}</p>}<ol className="emission-events">{events.map(event=><li key={event.id}><time dateTime={event.created_at}>{new Date(event.created_at).toLocaleString('es-PE')}</time><strong>{emissionLabels[event.to_status]??event.to_status}</strong><span>{event.message??eventLabels[event.type]??'Cambio de estado'}</span></li>)}</ol></>}
 </section>
}
