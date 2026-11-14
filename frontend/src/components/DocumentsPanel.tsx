import CollectionsPanel from './CollectionsPanel'
import DocumentDeliveryPanel from './DocumentDeliveryPanel'
import EmissionControls from './EmissionControls'
import { emissionLabels } from '../lib/issuance'
import { FormGuardContext } from '../app/FormGuard'
import DocumentLookup from './DocumentLookup'
import { useContext, useEffect, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router'
import { ApiError } from '../lib/api'
import type { SessionUser } from '../lib/api'
import { deleteDocument, documentError, getDocument, getDocuments } from '../lib/documents'
import type { DraftDocument, DocumentsResult } from '../lib/documents'
import { useListFilters } from '../app/useModuleNavigation'
import { centsToDecimal } from '../lib/money'
import DocumentEditor from './DocumentEditor'
import DocumentTotals from './DocumentTotals'
import DataTable from './ui/DataTable'
interface Props { role:SessionUser['role']; onSessionExpired:()=>void; onSavingChange:(value:boolean)=>void }
export default function DocumentsPanel(props:Props) {
 const {onSessionExpired}=props
 const guard=useContext(FormGuardContext)
 const {id}=useParams();const location=useLocation(); const navigate=useNavigate()
 const creating=location.pathname==='/documents/new', editing=location.pathname.endsWith('/edit')
 const canWrite=props.role!=='accountant'
 const [attempt,setAttempt]=useState(0)
 const [emitting,setEmitting]=useState(false)
 const [pollMessage,setPollMessage]=useState('')
 const [result,setResult]=useState<{key:string;data?:DraftDocument;error?:string}>({key:''})
 const [message,setMessage]=useState(''),[deleting,setDeleting]=useState(false),[conflict,setConflict]=useState(false)
 const deletingRef=useRef(false)
 const key=id+':'+attempt
 useEffect(()=>{
  if(!id||creating)return
  let active=true;const controller=new AbortController();let timer:ReturnType<typeof setTimeout>;let polls=0
  async function load() {
   try {
    const data=await getDocument(id!,AbortSignal.any([controller.signal,AbortSignal.timeout(15000)]))
    if(!active)return
    setResult({key,data});setPollMessage('')
    if(!editing&&(data.status==='queued'||data.status==='sending'||(data.status==='failed'&&data.next_attempt_at))) {
     if(++polls<40)timer=setTimeout(()=>void load(),3000)
     else setPollMessage('Seguimiento pausado. Usa Actualizar estado para continuar.')
    }
   }catch(error){if(!active)return;if(error instanceof ApiError&&error.status===401)onSessionExpired();else if(polls)setPollMessage('Sin conexión. Usa Actualizar estado para consultar el resultado.');else setResult({key,error:documentError(error)})}
  }
  void load()
  return ()=>{active=false;controller.abort();clearTimeout(timer)}
 },[id,creating,editing,key,onSessionExpired])
 function saved(doc:DraftDocument) {setResult({key:doc.id+':'+attempt,data:doc});setMessage('Borrador guardado correctamente.');navigate('/documents/'+doc.id)}
 if((creating||editing)&&!canWrite)return <p role="alert" className="error">Tu rol solo permite consultar comprobantes.</p>
 if(creating)return <DocumentEditor onSaved={saved} onCancel={()=>navigate('/documents')} onReload={()=>{}} onSessionExpired={props.onSessionExpired} onSavingChange={props.onSavingChange}/>
 if(!id)return <DocumentList {...props}/>
 if(result.key!==key)return <p role="status">Cargando comprobante...</p>
 if(result.error||!result.data)return <section className="surface"><p role="alert">{result.error}</p><button onClick={()=>setAttempt(attempt+1)}>Reintentar</button><Link to="/documents">Volver al listado</Link></section>
 const doc=result.data
 if(editing&&doc.status!=='draft')return <p role="alert">Este comprobante ya no es un borrador.</p>
 if(editing)return <DocumentEditor key={doc.id+':'+doc.version+':'+attempt} document={doc} onSaved={saved} onCancel={()=>navigate('/documents/'+id)} onReload={()=>{setConflict(false);setAttempt(attempt+1)}} onSessionExpired={props.onSessionExpired} onSavingChange={props.onSavingChange}/>
 async function remove() {
  if(deletingRef.current||!window.confirm('Eliminar este borrador y sus lineas? Esta accion no se puede deshacer.'))return
  deletingRef.current=true;setDeleting(true);props.onSavingChange(true);setMessage('')
  try {await deleteDocument(doc.id,doc.version);if(guard)guard.state.current={dirty:false,saving:false};props.onSavingChange(false);navigate('/documents')}
  catch(error) {if(error instanceof ApiError&&error.status===401)onSessionExpired();else {setMessage(documentError(error));setConflict(error instanceof ApiError&&error.status===409)}}
  finally {deletingRef.current=false;setDeleting(false);props.onSavingChange(false)}
 }
 return <section className="surface document-detail"><div className="module-toolbar"><h2>{doc.doc_type==='01'?'Factura':'Boleta'} <span className="badge">{emissionLabels[doc.status]}</span></h2><span>Version {doc.version}</span></div>
 {message&&<p role={conflict?'alert':'status'}>{message}</p>}{conflict&&<button disabled={deleting||emitting} onClick={()=>{setConflict(false);setMessage('');setAttempt(attempt+1)}}>Recargar version actual</button>}
 <dl className="profile"><div><dt>Cliente</dt><dd>{doc.customer?.name??'Sin cliente identificado'}{doc.customer&&!doc.customer.is_active?' (inactivo)':''}</dd></div><div><dt>Fecha</dt><dd>{doc.issue_date}</dd></div><div><dt>Serie seleccionada</dt><dd>{doc.series.code}{!doc.series.is_active?' (inactiva)':''}</dd></div><div><dt>Numero</dt><dd>{doc.formatted_number??doc.number??'Sin asignar'}</dd></div></dl>
 <DataTable label="Lineas del comprobante" headers={['Producto','Cantidad','Precio sin IGV','Base','IGV','Total']}>
 {doc.items.map((line,index)=><tr key={line.id??index}><td><strong>{line.description}</strong><small>{line.unit_code} · {line.tax_type}{line.product_is_active===false?' - Inactivo':''}</small></td><td>{line.quantity}</td><td className="numeric">{centsToDecimal(line.unit_price_cents)}</td><td className="numeric">{centsToDecimal(line.base_cents??0)}</td><td className="numeric">{centsToDecimal(line.igv_cents??0)}</td><td className="numeric">{centsToDecimal(line.total_cents??0)}</td></tr>)}
 </DataTable><DocumentTotals totals={doc.totals} currency={doc.currency}/>{pollMessage&&<p role="status">{pollMessage}</p>}<EmissionControls role={props.role} key={doc.id} doc={doc} canWrite={canWrite} disabled={deleting||emitting} onSessionExpired={onSessionExpired} onRefresh={()=>setAttempt(value=>value+1)} onBusy={value=>{setEmitting(value);props.onSavingChange(value)}}/>
 {doc.status==='accepted'&&<CollectionsPanel key={'collections:'+doc.id} documentId={doc.id} onSessionExpired={onSessionExpired} onBusy={value=>{setEmitting(value);props.onSavingChange(value)}}/>}
 {doc.status==='accepted'&&<DocumentDeliveryPanel key={'delivery:'+doc.id} doc={doc} onSessionExpired={onSessionExpired} onBusy={value=>{setEmitting(value);props.onSavingChange(value)}}/>}
 <div className="form-actions">{canWrite&&doc.status==='draft'&&<><button disabled={deleting||emitting} onClick={()=>navigate('/documents/'+id+'/edit')}>Editar borrador</button><button className="secondary" disabled={deleting||emitting} onClick={remove}>{deleting?'Eliminando...':'Eliminar borrador'}</button></>}<button className="secondary" disabled={deleting||emitting} onClick={()=>navigate('/documents')}>Volver al listado</button></div></section>
}
function DocumentList({role,onSessionExpired,onSavingChange}:Props) {
 const [deleting,setDeleting]=useState<string|null>(null)
 const [actionError,setActionError]=useState('')
 const [notice,setNotice]=useState('')
 const [conflict,setConflict]=useState(false)
 const submitting=useRef(false)

 const [selectCustomer,setSelectCustomer]=useState(false)
 const [customerName,setCustomerName]=useState('')
 const [filters,setFilters]=useListFilters({doc_type:'',status:'',customer_id:'',date_from:'',date_to:'',page:1})
 const [attempt,setAttempt]=useState(0),[result,setResult]=useState<{key:string;data?:DocumentsResult;error?:string}>({key:''})
 const key=JSON.stringify([filters,attempt])
 useEffect(()=>{const controller=new AbortController();let active=true;const timer=setTimeout(()=>{
 getDocuments(filters,controller.signal).then(data=>{if(active)setResult({key,data})}).catch(error=>{if(!active)return;if(error instanceof ApiError&&error.status===401)onSessionExpired();else setResult({key,error:documentError(error)})})
 },250);return()=>{active=false;clearTimeout(timer);controller.abort()}},[filters,key,onSessionExpired])
 const current=result.key===key?result:undefined
 async function remove(doc:DraftDocument) {
  if(submitting.current||!window.confirm('Eliminar este borrador y sus lineas? Esta accion no se puede deshacer.'))return
  submitting.current=true;setDeleting(doc.id);onSavingChange(true);setActionError('');setNotice('');setConflict(false)
  try {
   await deleteDocument(doc.id,doc.version)
   setNotice('Borrador eliminado correctamente.')
   if(current?.data?.data.length===1&&filters.page>1)setFilters({...filters,page:filters.page-1})
   else setAttempt(value=>value+1)
  } catch(error) {
   if(error instanceof ApiError&&error.status===401)onSessionExpired()
   else {setActionError(documentError(error));setConflict(error instanceof ApiError&&error.status===409)}
  } finally {submitting.current=false;setDeleting(null);onSavingChange(false)}
 }
 return <div className="module-panel">
 {notice&&<p role="status" className="success-message">{notice}</p>}
 {actionError&&<p role="alert" className="error">{actionError}</p>}
 {conflict&&<button type="button" className="secondary" disabled={Boolean(deleting)} onClick={()=>{
  if(window.confirm('Recargar el listado para consultar las versiones actuales?')){setActionError('');setConflict(false);setAttempt(value=>value+1)}
 }}>Recargar listado</button>}
<div className="module-toolbar"><span className="muted">Facturas y boletas de tu empresa</span>{role!=='accountant'&&<Link className="button" to="/documents/new">Nuevo comprobante</Link>}</div>
 <div className="customer-filters"><div><label htmlFor="filter-doc-type">Tipo</label><select id="filter-doc-type" value={filters.doc_type} onChange={e=>setFilters({...filters,doc_type:e.target.value,page:1})}><option value="">Todos</option><option value="01">Factura</option><option value="03">Boleta</option></select></div><div><label htmlFor="filter-doc-status">Estado</label><select id="filter-doc-status" value={filters.status} onChange={e=>setFilters({...filters,status:e.target.value,page:1})}><option value="">Todos</option>{Object.entries(emissionLabels).map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></div>
 {(['date_from','date_to'] as const).map(field=><div key={field}><label htmlFor={field}>{field==='date_from'?'Desde':'Hasta'}</label><input id={field} type="date" value={filters[field]} onChange={e=>setFilters({...filters,[field]:e.target.value,page:1})}/></div>)}
 <div><span>Cliente</span><button className="secondary" onClick={()=>setSelectCustomer(!selectCustomer)}>{filters.customer_id?(customerName||'Cliente seleccionado'):'Filtrar por cliente'}</button></div>
 <button className="secondary" onClick={()=>{setCustomerName('');setFilters({doc_type:'',status:'',customer_id:'',date_from:'',date_to:'',page:1})}}>Limpiar filtros</button></div>
 {selectCustomer&&<DocumentLookup kind="customer" currency="PEN" includeInactive onCustomer={customer=>{setFilters({...filters,customer_id:customer.id,page:1});setCustomerName(customer.name);setSelectCustomer(false)}} onProduct={()=>{}} onSessionExpired={onSessionExpired}/>}
 {!current&&<p role="status">Cargando comprobantes...</p>}{current?.error&&<div><p role="alert">{current.error}</p><button onClick={()=>setAttempt(attempt+1)}>Reintentar</button></div>}
 {current?.data&&<><p role="status">{current.data.meta.total} comprobantes encontrados</p>{current.data.data.length===0?<p className="surface state-panel">No hay comprobantes para estos filtros.</p>:<DataTable label="Comprobantes" headers={['Tipo / serie','Cliente','Fecha','Estado','Total','Acciones']}>{current.data.data.map(doc=><tr key={doc.id}><td><strong>{doc.doc_type==='01'?'Factura':'Boleta'} · {doc.series.code}</strong><small>{doc.number??'Sin numero asignado'}</small></td><td>{doc.customer?.name??'Sin cliente identificado'}</td><td>{doc.issue_date}</td><td><span className="badge">{emissionLabels[doc.status]}</span></td><td className="numeric">{centsToDecimal(doc.totals.total_cents)} {doc.currency}</td><td><div className="document-row-actions"><Link className="button secondary" aria-disabled={Boolean(deleting)||undefined} onClick={event=>{if(deleting)event.preventDefault()}} to={'/documents/'+doc.id}>Ver detalle</Link>{role!=='accountant'&&doc.status==='draft'&&<><Link className="button secondary" aria-disabled={Boolean(deleting)||undefined} onClick={event=>{if(deleting)event.preventDefault()}} to={'/documents/'+doc.id+'/edit'}>Editar</Link><button type="button" className="secondary" disabled={Boolean(deleting)} onClick={()=>void remove(doc)}>{deleting===doc.id?'Eliminando...':'Eliminar'}</button></>}</div></td></tr>)}</DataTable>}
 <div className="pagination"><button className="secondary" disabled={filters.page===1} onClick={()=>setFilters({...filters,page:filters.page-1})}>Anterior</button><span>Pagina {current.data.meta.current_page} de {current.data.meta.last_page}</span><button className="secondary" disabled={filters.page>=current.data.meta.last_page} onClick={()=>setFilters({...filters,page:filters.page+1})}>Siguiente</button></div></>}
 </div>
}
