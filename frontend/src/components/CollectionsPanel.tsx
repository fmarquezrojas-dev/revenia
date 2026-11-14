import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../lib/api'
import { centsToDecimal } from '../lib/money'
import { paymentLabels, collectionMoney, collectionMessages, getCollections, requestCollection, safeCheckout } from '../lib/collections'
import type { CollectionState } from '../lib/collections'
interface Props {documentId:string;onSessionExpired:()=>void;onBusy:(busy:boolean)=>void}
const orderLabels:Record<string,string>={pending:'Pendiente de creación',creating:'Creando checkout',open:'Abierta',unknown:'Creación incierta',closed:'Cerrada',expired:'Expirada',stale:'Desactualizada',failed:'Fallida'}
export default function CollectionsPanel({documentId,onSessionExpired,onBusy}:Props){
 const [state,setState]=useState<CollectionState|null>(null),[revision,setRevision]=useState(0),[error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false),[confirmation,setConfirmation]=useState<'create'|'reconcile'|null>(null)
 const mutation=useRef<AbortController|null>(null),submitting=useRef(false),mounted=useRef(true),keys=useRef<Record<string,string>>({})
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;mutation.current?.abort()}},[])
 useEffect(()=>{let active=true,count=0,timer:ReturnType<typeof setTimeout>|undefined;const controller=new AbortController()
  async function load(){try{const data=await getCollections(documentId,AbortSignal.any([controller.signal,AbortSignal.timeout(15000)]));if(!active)return;setState(data);setError('');if(data.pending){if(++count<40)timer=setTimeout(()=>void load(),3000);else setNotice('Seguimiento pausado. Actualiza para continuar.')}}catch(e){if(!active)return;if(e instanceof ApiError&&e.status===401)onSessionExpired();else setError(e instanceof ApiError?e.message:'No se pudo consultar la cobranza. Actualiza para reintentar.')}}
  void load();return()=>{active=false;controller.abort();clearTimeout(timer)}
 },[documentId,revision,onSessionExpired])
 async function submit(){if(submitting.current||!confirmation)return;const operation=confirmation;submitting.current=true;setBusy(true);onBusy(true);setError('');const controller=new AbortController();mutation.current=controller
  const slot='facturly:collection:'+documentId+':'+operation
  try{let key=keys.current[slot];if(!key){try{key=sessionStorage.getItem(slot)??''}catch{/* Optional tab persistence. */}key=key||crypto.randomUUID();keys.current[slot]=key}try{sessionStorage.setItem(slot,key)}catch{/* Retain in memory. */}
   await requestCollection(documentId,operation,key,AbortSignal.any([controller.signal,AbortSignal.timeout(20000)]));delete keys.current[slot];try{sessionStorage.removeItem(slot)}catch{/* Optional persistence. */}if(mounted.current){setConfirmation(null);setNotice('Solicitud registrada. El resultado se confirma desde el backend.');setRevision(v=>v+1)}
  }catch(e){if(mounted.current){if(e instanceof ApiError&&e.status===401)onSessionExpired();else setError(e instanceof ApiError?e.message:'No se pudo confirmar la solicitud. Puedes repetirla con la misma clave.')}}finally{submitting.current=false;if(mounted.current)setBusy(false);onBusy(false)}
 }
 async function copy(url:string){if(!safeCheckout(url))return;try{await navigator.clipboard.writeText(url);setNotice('Enlace de pruebas copiado.')}catch{setError('No se pudo copiar. Puedes abrir el checkout desde el enlace.')}}
 const money=(n:number|string)=>collectionMoney(n)+' '+state?.currency
 return <section className="emission-panel collections-panel" aria-label="Cobranza del comprobante"><h3>Cobranza</h3><p className="collection-demo">Pagos de demostración. No utilizar tarjetas ni dinero reales.</p>
 {new URLSearchParams(window.location.search).has('payment_return')&&<p role="status">Regresaste del checkout. El retorno no confirma un pago; se muestra el estado consultado al backend.</p>}
 {error&&<p role="alert" className="error">{error}</p>}{notice&&<p role="status">{notice}</p>}
 {!state?(!error&&<p>Cargando cobranza...</p>):<>
 <dl className="collection-balances">{[['Importe original',state.original_cents],['Créditos aceptados',state.credits_cents],['Importe neto',state.net_cents],['Importe cobrado',state.paid_cents],['Saldo pendiente',state.outstanding_cents],...(BigInt(state.excess_cents)>0n?[['Exceso pagado',state.excess_cents]]:[])].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{money(value as number|string)}</dd></div>)}</dl>
 <p><strong>Estado financiero: </strong>{{unpaid:'Pendiente de pago',partial:'Saldo parcial',paid:'Pagado',review:'Requiere revisión'}[state.status]}</p>
 {state.needs_review&&<p role="alert">El saldo requiere revisión. No se ha ejecutado ningún reembolso automático.</p>}
 {!state.credit_notes_supported&&<p className="muted">Las notas de crédito aún no están habilitadas en esta instalación.</p>}
 {state.currency==='USD'&&<p>{collectionMessages.currency_unsupported}</p>}
 {state.blocked_reason&&<p>{collectionMessages[state.blocked_reason]??'La cobranza requiere revisión.'}</p>}
 <div className="form-actions">{state.can_create&&<button disabled={busy} onClick={()=>setConfirmation('create')}>Crear enlace de pago</button>}{state.can_reconcile&&state.orders.length>0&&<button className="secondary" disabled={busy} onClick={()=>setConfirmation('reconcile')}>Solicitar conciliación</button>}</div>
 {confirmation&&<div role="group" aria-label="Confirmar cobranza"><p>{confirmation==='create'?'Solicitar un checkout de pruebas por el saldo completo: '+money(state.outstanding_cents)+'.':'Consultar al proveedor las órdenes recientes y conciliar sus pagos. El resultado puede requerir revisión.'}</p><div className="form-actions"><button disabled={busy} onClick={()=>void submit()}>{busy?'Registrando...':'Confirmar solicitud'}</button><button className="secondary" disabled={busy} onClick={()=>setConfirmation(null)}>Cancelar</button></div></div>}
 <h4>Órdenes de pago</h4>{state.orders.length===0?<p className="muted">Todavía no hay órdenes.</p>:<ol className="emission-events">{state.orders.map(o=><li key={o.id}><strong>{orderLabels[o.status]??o.status} - {centsToDecimal(o.amount_cents)} {o.currency}</strong><span>Referencia: {o.id}</span><time>{new Date(o.created_at).toLocaleString('es-PE')}</time>{o.error_code&&<span>{collectionMessages[o.error_code]??'La orden requiere revisión.'}</span>}{o.checkout_url&&safeCheckout(o.checkout_url)&&<div className="form-actions"><a className="button secondary" href={o.checkout_url} target="_blank" rel="noopener noreferrer">Abrir checkout de pruebas</a><button className="secondary" onClick={()=>void copy(o.checkout_url!)}>Copiar enlace</button></div>}</li>)}</ol>}
 <h4>Historial de pagos</h4>{state.payments.length===0?<p className="muted">No hay pagos confirmados por el proveedor.</p>:<ol className="emission-events">{state.payments.map(p=><li key={p.id}><strong>{paymentLabels[p.status]??p.status} - {centsToDecimal(p.amount_cents)} {p.currency}</strong><span>Pago {p.provider_id} - Aplicado: {centsToDecimal(p.applied_cents)} {p.currency}</span><time>{new Date(p.provider_updated_at).toLocaleString('es-PE')}</time>{p.needs_review&&<span>{collectionMessages.external_adjustment_review}</span>}</li>)}</ol>}
 </>}
 <button className="secondary" disabled={busy} onClick={()=>setRevision(v=>v+1)}>Actualizar cobranza</button>
 </section>
}
