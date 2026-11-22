import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { ApiError } from '../lib/api'
import { centsToDecimal } from '../lib/money'
import { paymentLabels, collectionMoney, collectionMessages, getCollections, requestCollection, safeCheckout } from '../lib/collections'
import type { CollectionState } from '../lib/collections'
interface Props {documentId:string;onSessionExpired:()=>void;onBusy:(busy:boolean)=>void}
const orderLabels:Record<string,string>={pending:'Pendiente de creación',creating:'Creando checkout',open:'Abierta',unknown:'Creación incierta',closed:'Cerrada',expired:'Expirada',stale:'Desactualizada',failed:'Fallida'}
export default function CollectionsPanel({documentId,onSessionExpired,onBusy}:Props){
 const [state,setState]=useState<CollectionState|null>(null),[revision,setRevision]=useState(0),[error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false),[confirmation,setConfirmation]=useState<'create'|'reconcile'|null>(null)
 const [targetOrder,setTargetOrder]=useState<string>()
 const confirmRef=useRef<HTMLDivElement>(null)
 const locatedOrder = useRef('')
 useEffect(() => {
  const target = window.location.hash.slice(1)
  if (target === locatedOrder.current || !state?.orders.some(order => 'cobro-' + order.id === target)) return
  const el = document.getElementById(target)
  if (el) { el.focus(); locatedOrder.current = target }
 }, [state])
 useEffect(()=>{if(confirmation)confirmRef.current?.focus()},[confirmation,targetOrder])
 const mutation=useRef<AbortController|null>(null),submitting=useRef(false),mounted=useRef(true),keys=useRef<Record<string,string>>({})
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;mutation.current?.abort()}},[])
 useEffect(()=>{let active=true,count=0,timer:ReturnType<typeof setTimeout>|undefined;const controller=new AbortController()
  async function load(){try{const data=await getCollections(documentId,AbortSignal.any([controller.signal,AbortSignal.timeout(15000)]));if(!active)return;setState(data);setError('');if(data.pending){if(++count<40)timer=setTimeout(()=>void load(),3000);else setNotice('Seguimiento pausado. Actualiza para continuar.')}}catch(e){if(!active)return;if(e instanceof ApiError&&e.status===401)onSessionExpired();else setError(e instanceof ApiError?e.message:'No se pudo consultar la cobranza. Actualiza para reintentar.')}}
  void load();return()=>{active=false;controller.abort();clearTimeout(timer)}
 },[documentId,revision,onSessionExpired])
 async function submit(){if(submitting.current||!confirmation)return;const operation=confirmation;submitting.current=true;setBusy(true);onBusy(true);setError('');const controller=new AbortController();mutation.current=controller
  const slot='facturly:collection:'+documentId+':'+operation+(targetOrder?':'+targetOrder:'')
  try{let key=keys.current[slot];if(!key){try{key=sessionStorage.getItem(slot)??''}catch{/* Optional tab persistence. */}key=key||crypto.randomUUID();keys.current[slot]=key}try{sessionStorage.setItem(slot,key)}catch{/* Retain in memory. */}
   await requestCollection(documentId,operation,key,AbortSignal.any([controller.signal,AbortSignal.timeout(20000)]),targetOrder);delete keys.current[slot];try{sessionStorage.removeItem(slot)}catch{/* Optional persistence. */}if(mounted.current){setConfirmation(null);setNotice('Solicitud registrada. El resultado se confirma desde el backend.');setRevision(v=>v+1)}
  }catch(e){if(mounted.current){if(e instanceof ApiError&&e.status===401)onSessionExpired();else setError(e instanceof ApiError?e.message:'No se pudo confirmar la solicitud. Puedes repetirla con la misma clave.')}}finally{submitting.current=false;if(mounted.current)setBusy(false);onBusy(false)}
 }
 async function copy(url:string){if(!safeCheckout(url))return;try{await navigator.clipboard.writeText(url);setNotice('Enlace de pruebas copiado.')}catch{setError('No se pudo copiar. Puedes abrir el checkout desde el enlace.')}}
 const money=(n:number|string)=>collectionMoney(n)+' '+state?.currency
 return <section id="cobranza" className="emission-panel collections-panel" aria-label="Cobranza del comprobante"><h3>Cobranza</h3>{state?.environment === 'sandbox' && <p className="payment-environment">Mercado Pago Perú · Entorno de pruebas</p>}
 {new URLSearchParams(window.location.search).has('payment_return')&&<p role="status">Regresaste del checkout. El retorno no confirma un pago; se muestra el estado consultado al backend.</p>}
 {error&&<p role="alert" className="error">{error}</p>}{notice&&<p role="status">{notice}</p>}
 {!state?(!error&&<p>Cargando cobranza...</p>):<>
 <p>Empresa emisora · <strong>{state.issuer}</strong></p>
 <dl className="collection-balances">{[['Importe original',state.original_cents],['Créditos aceptados',state.credits_cents],['Reserva pendiente',state.reserved_cents],['Importe neto',state.net_cents],['Importe cobrado',state.paid_cents],['Saldo pendiente',state.outstanding_cents],...(BigInt(state.excess_cents)>0n?[['Exceso pagado',state.excess_cents]]:[])].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{money(value as number|string)}</dd></div>)}</dl>
 <p><strong>Estado financiero: </strong>{{unpaid:'Pendiente de pago',partial:'Saldo parcial',paid:'Pagado',review:'Requiere revisión'}[state.status]}</p>
 {state.needs_review&&<p role="alert">El saldo requiere revisión. No se ha ejecutado ningún reembolso automático.</p>}
 {!state.credit_notes_supported&&<p className="muted">Las notas de crédito aún no están habilitadas en esta instalación.</p>}
 {state.currency==='USD'&&<p>{collectionMessages.currency_unsupported}</p>}
 {state.blocked_reason&&<p>{collectionMessages[state.blocked_reason]??'La cobranza requiere revisión.'}</p>}
 <div className="form-actions">{state.can_create&&<button disabled={busy} onClick={()=>{setTargetOrder(undefined);setConfirmation('create')}}>Crear enlace de pago</button>}{state.can_reconcile&&state.orders.length>0&&<button className="secondary" disabled={busy} onClick={()=>{setTargetOrder(undefined);setConfirmation('reconcile')}}>Solicitar conciliación</button>}</div>
 {confirmation&&<div ref={confirmRef} tabIndex={-1} role="group" aria-label="Confirmar cobranza"><p>{confirmation==='create'?'Entorno de pruebas · Solicitar un enlace por '+money(state.outstanding_cents)+'.':targetOrder ? 'Consultar a Mercado Pago la orden '+targetOrder+'.' : 'Consultar al proveedor las órdenes recientes y conciliar sus pagos.'}</p><div className="form-actions"><button disabled={busy} onClick={()=>void submit()}>{busy?'Registrando...':'Confirmar solicitud'}</button><button className="secondary" disabled={busy} onClick={()=>setConfirmation(null)}>Cancelar</button></div></div>}
 {Boolean(state.incidents?.length) && <><h4>Incidencias de conciliación</h4><ul>{state.incidents?.map(i=><li key={i.id}>Orden {i.order_id} · {new Date(i.updated_at).toLocaleString('es-PE')} · {collectionMessages[i.error_code] ?? 'Revisa el resultado con el proveedor'} ({i.error_code})</li>)}</ul></>}
 {state.payments[0] && <p>Último estado conocido del pago · <strong>{paymentLabels[state.payments[0].status] ?? state.payments[0].status}</strong></p>}
 <h4>Órdenes de pago</h4>{state.orders.length===0?<p className="muted">Todavía no hay órdenes.</p>:<ol className="emission-events">{state.orders.map(o=><li key={o.id} id={'cobro-'+o.id} tabIndex={-1}><strong>{orderLabels[o.status]??o.status} - {centsToDecimal(o.amount_cents)} {o.currency}</strong><span>Referencia: {o.id}</span>{o.collector_id && <span>Cuenta receptora · {o.collector_id}</span>}<time>{new Date(o.created_at).toLocaleString('es-PE')}</time>{o.error_code&&<span>{collectionMessages[o.error_code]??'La orden requiere revisión.'}</span>}{state.can_reconcile && o.receiver_kind === 'merchant' && o.error_code !== 'test_receiver_unverified' && <button className="secondary" disabled={busy} onClick={()=>{setTargetOrder(o.id);setConfirmation('reconcile')}}>Conciliar esta orden</button>}{o.checkout_url&&safeCheckout(o.checkout_url)&&<div className="form-actions"><a className="button secondary" href={o.checkout_url} target="_blank" rel="noopener noreferrer">Abrir checkout de pruebas</a><button className="secondary" onClick={()=>void copy(o.checkout_url!)}>Copiar enlace</button></div>}</li>)}</ol>}
 {Boolean(state.bank_applications?.length) && <><h4>Aplicaciones bancarias</h4><ol className="emission-events">{state.bank_applications?.map(a=><li key={a.id}><strong>Banco · {collectionMoney(a.amount_cents)} {state.currency}</strong><span>{a.reversed_at?'Aplicación revertida':'Aplicación confirmada'}</span><Link to={'/bank-receipts/'+a.receipt_id+'?application='+a.id+'#app-'+a.id}>Ver aplicación bancaria</Link></li>)}</ol></>}
 <h4>Historial de pagos del proveedor</h4>{state.payments.length===0?<p className="muted">No hay pagos confirmados por el proveedor.</p>:<ol className="emission-events">{state.payments.map(p=><li key={p.id}><strong>{paymentLabels[p.status]??p.status} - {centsToDecimal(p.amount_cents)} {p.currency}</strong><span>Pago {p.provider_id} - Aplicado: {centsToDecimal(p.applied_cents)} {p.currency}</span><time>{new Date(p.provider_updated_at).toLocaleString('es-PE')}</time>{p.needs_review&&<span>{collectionMessages.external_adjustment_review}</span>}</li>)}</ol>}
 </>}
 <button className="secondary" disabled={busy} onClick={()=>setRevision(v=>v+1)}>Actualizar cobranza</button>
 </section>
}
