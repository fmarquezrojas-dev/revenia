import { useEffect, useMemo, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError } from '../lib/api'
import type { Customer, Product } from '../lib/api'
import { documentError, getSeriesOptions, limaToday, lineFromProduct, normalizeQuantity, previewDocument, saveDocument } from '../lib/documents'
import type { DraftDocument, DraftInput, DraftLine, Preview, SeriesOption } from '../lib/documents'
import { useFormGuard } from '../app/FormGuard'
import { centsToDecimal, taxLabels } from '../lib/money'
import DocumentLookup from './DocumentLookup'
import DocumentTotals from './DocumentTotals'
interface Props { document?: DraftDocument; onSaved:(doc:DraftDocument)=>void; onCancel:()=>void; onReload:()=>void; onSessionExpired:()=>void; onSavingChange:(value:boolean)=>void }
export default function DocumentEditor({document: draft,onSaved,onCancel,onReload,onSessionExpired,onSavingChange}:Props) {
 const [header,setHeader]=useState({doc_type:draft?.doc_type??'01',currency:draft?.currency??'PEN',issue_date:draft?.issue_date??limaToday(),customer_id:draft?.customer_id??null,series_id:draft?.series_id??''})
 const [customer,setCustomer]=useState(draft?.customer??null)
 const [lines,setLines]=useState<DraftLine[]>(draft?.items??[])
 const [series,setSeries]=useState<{type:string;rows:SeriesOption[]}>({type:'',rows:[]})
 const [lookup,setLookup]=useState<'customer'|'product'|null>(null), [replace,setReplace]=useState<number|null>(null)
 const [saving,setSaving]=useState(false), [message,setMessage]=useState(''), [errors,setErrors]=useState<Record<string,string[]>>({}), [conflict,setConflict]=useState(false)
 const [preview,setPreview]=useState<{key:string;data?:Preview;error?:string;fields?:Record<string,string[]>;conflict?:boolean}>({key:''})
 const submitting=useRef(false), errorRef=useRef<HTMLParagraphElement>(null)
 const signature=JSON.stringify({header,lines})
 const {dirty,markSaved}=useFormGuard(signature,saving)
 const payload=useMemo(()=>{
  const fields:Record<string,string[]>={}
  const items=lines.map((line,index)=>{
   let quantity=line.quantity
   try { quantity=normalizeQuantity(quantity) } catch(e) {fields[`items.${index}.quantity`]=[(e as Error).message]}
   return {...(line.id?{id:line.id}:{}),product_id:line.product_id,quantity}
  })
  if(!items.length)fields.items=['Agrega al menos un producto.']
  if(!header.series_id)fields.series_id=['Selecciona una serie.']
  if(header.doc_type==='01'&&!header.customer_id)fields.customer_id=['Selecciona un cliente con RUC.']
  return {data:{...header,...(draft?{version:draft.version}:{}),items} as DraftInput,fields}
 },[header,lines,draft])
 const key=JSON.stringify(payload.data)
 useEffect(()=>{
  const controller=new AbortController();let active=true
  getSeriesOptions(header.doc_type,controller.signal).then(rows=>{if(active)setSeries({type:header.doc_type,rows})}).catch(error=>{if(!active)return;if(error instanceof ApiError&&error.status===401)onSessionExpired();else setMessage(documentError(error))})
  return ()=>{active=false;controller.abort()}
 },[header.doc_type,onSessionExpired])
 useEffect(()=>{
  if(Object.keys(payload.fields).length)return
  const controller=new AbortController();let active=true
  const timer=setTimeout(()=>{
   previewDocument(payload.data,draft?.id,controller.signal).then(data=>{if(active)setPreview({key,data})}).catch(error=>{
    if(!active)return
    if(error instanceof ApiError&&error.status===401)onSessionExpired()
    else setPreview({key,error:documentError(error),fields:error instanceof ApiError?error.errors:undefined,conflict:error instanceof ApiError&&error.status===409})
   })
  },350)
  return ()=>{active=false;clearTimeout(timer);controller.abort()}
 },[key,payload,draft?.id,onSessionExpired])
 useEffect(()=>{if(message)errorRef.current?.focus()},[message])
 const current=preview.key===key?preview:undefined
 const allErrors={...(current?.fields??{}),...errors,...payload.fields}
 function fieldError(name:string) {return allErrors[name]?.length ? <p id={'error-'+name} className="field-error">{allErrors[name].join(' ')}</p> : null}
 function pickCustomer(value:Customer) {setCustomer(value);setHeader({...header,customer_id:value.id});setLookup(null);setErrors({})}
 function pickProduct(value:Product) {
  const duplicate=lines.findIndex(line=>line.product_id===value.id)
  if(duplicate!==-1) { setMessage('El producto ya tiene una linea. Ajusta su cantidad.');setLookup(null);window.setTimeout(()=>window.document.getElementById('quantity-'+duplicate)?.focus(),0);return }
  if(replace===null&&lines.length>=50) {setMessage('El limite es 50 lineas.');return}
  const old=replace===null?undefined:draft?.items.find(item=>item.id===lines[replace].id&&item.product_id===value.id)
  const line=old?{...old,quantity:'1.000'}:lineFromProduct(value)
  setLines(current=>replace===null?[...current,line]:current.map((old,index)=>index===replace?{...line,id:old.id}:old))
  setLookup(null);setReplace(null);setErrors({});setMessage('')
 }
 async function submit(event:FormEvent) {
  event.preventDefault();if(submitting.current)return
  if(Object.keys(payload.fields).length) {setErrors(payload.fields);setMessage('Revisa los campos indicados.');return}
  submitting.current=true;setSaving(true);onSavingChange(true);setMessage('');setErrors({})
  try {const saved=await saveDocument(payload.data,draft?.id);markSaved();onSaved(saved)}
  catch(error) {if(error instanceof ApiError&&error.status===401)onSessionExpired();else {setMessage(documentError(error));if(error instanceof ApiError){setErrors(error.errors??{});setConflict(error.status===409)}}}
  finally {submitting.current=false;setSaving(false);onSavingChange(false)}
 }
 return <form className="surface document-editor" aria-label={draft?'Editar borrador':'Nuevo comprobante'} onSubmit={submit} aria-busy={saving}>
 <h2>{draft?'Editar borrador':'Nuevo comprobante'}</h2><p className="muted">Factura o boleta en borrador. Guardar no asigna numero ni emite.</p>
 {dirty&&<p role="status" className="dirty-notice">Cambios sin guardar</p>}
 {message&&<p ref={errorRef} tabIndex={-1} role="alert" className="error">{message}</p>}
 {(conflict||current?.conflict)&&<button type="button" className="secondary" disabled={saving} onClick={()=>{if(window.confirm('Recargar descarta tus cambios locales. Antes de continuar puedes revisarlos en el formulario.')){markSaved();onReload()}}}>Recargar version actual</button>}
 <fieldset disabled={saving} className="document-header-fields">
 <div><label htmlFor="document-type">Tipo de comprobante</label><select id="document-type" value={header.doc_type} onChange={e=>{const type=e.target.value as '01'|'03';if(header.series_id&&!window.confirm('Cambiar el tipo requiere seleccionar otra serie. Las lineas se conservaran.'))return;setHeader({...header,doc_type:type,series_id:''});setErrors({})}}><option value="01">Factura</option><option value="03">Boleta</option></select>{fieldError('doc_type')}</div>
 <div><label htmlFor="document-currency">Moneda</label><select id="document-currency" value={header.currency} onChange={e=>{if(lines.length&&!window.confirm('Las lineas se conservaran. Reemplaza los productos que no correspondan a la nueva moneda; no hay conversion automatica.'))return;setHeader({...header,currency:e.target.value as 'PEN'|'USD'});setErrors({})}}><option>PEN</option><option>USD</option></select>{fieldError('currency')}</div>
 <div><label htmlFor="document-date">Fecha (Lima)</label><input id="document-date" type="date" max={limaToday()} value={header.issue_date} onChange={e=>{setHeader({...header,issue_date:e.target.value});setErrors({})}} aria-invalid={Boolean(allErrors.issue_date)} aria-describedby="error-issue_date"/>{fieldError('issue_date')}</div>
 <div><label htmlFor="document-series">Serie</label><select id="document-series" value={header.series_id} onChange={e=>{setHeader({...header,series_id:e.target.value});setErrors({})}} aria-invalid={Boolean(allErrors.series_id)} aria-describedby="error-series_id"><option value="">Seleccionar serie activa</option>{draft?.series_id===header.series_id&&!series.rows.some(s=>s.id===header.series_id)&&<option value={header.series_id}>{draft.series.code} (revisar disponibilidad)</option>}{series.type===header.doc_type&&series.rows.map(s=><option key={s.id} value={s.id}>{s.code}</option>)}</select>{fieldError('series_id')}</div>
 <div className="document-customer"><h3>Cliente</h3><p>{customer?`${customer.name} - ${customer.doc_number}`:'Sin cliente identificado'}</p>{customer&&!customer.is_active&&<p className="error">Cliente inactivo: reemplazalo para guardar.</p>}{header.doc_type==='01'&&customer&&customer.doc_type!=='ruc'&&<p className="error">La factura requiere un cliente con RUC.</p>}
 <button type="button" className="secondary" onClick={()=>setLookup('customer')}>Seleccionar cliente</button>{header.doc_type==='03'&&<button type="button" className="secondary" onClick={()=>{setCustomer(null);setHeader({...header,customer_id:null});setErrors({})}}>Sin cliente identificado</button>}{fieldError('customer_id')}
 <p className="muted">Politica del simulador: una boleta PEN puede ser anonima dentro del umbral configurado. En USD se requiere identificacion. El servidor valida estas condiciones.</p></div>
 </fieldset>
 <fieldset disabled={saving}><legend>Productos y servicios ({lines.length}/50)</legend>
 {lines.map((line,index)=><article className="document-line" key={line.id??line.product_id}><div><h3>{index+1}. {line.description}</h3><p className="muted">{centsToDecimal(line.unit_price_cents)} {line.currency??draft?.currency??header.currency} sin IGV - {line.unit_code} - {taxLabels[line.tax_type as keyof typeof taxLabels]}</p>
 {(line.product_is_active===false||(line.currency??(line.id?draft?.currency:undefined))!==undefined&&(line.currency??draft?.currency)!==header.currency||line.product_currency&&line.product_currency!==header.currency)&&<p className="error">Reemplaza este producto: esta inactivo o tiene otra moneda.</p>}{fieldError(`items.${index}.product_id`)}</div>
 <div><label htmlFor={'quantity-'+index}>Cantidad linea {index+1}</label><input id={'quantity-'+index} inputMode="decimal" value={line.quantity} onChange={e=>{setLines(lines.map((item,i)=>i===index?{...item,quantity:e.target.value}:item));setErrors({})}} aria-invalid={Boolean(allErrors[`items.${index}.quantity`])} aria-describedby={'error-items.'+index+'.quantity'}/>{fieldError(`items.${index}.quantity`)}</div>
 <div className="form-actions"><button type="button" className="secondary" onClick={()=>{setReplace(index);setLookup('product')}}>Reemplazar</button><button type="button" className="secondary" aria-label={'Quitar linea '+(index+1)} onClick={()=>{setLines(lines.filter((_,i)=>i!==index));setErrors({})}}>Quitar</button></div>
 </article>)}
 {fieldError('items')}<button type="button" className="secondary" disabled={lines.length>=50} onClick={()=>{setReplace(null);setLookup('product')}}>Agregar producto</button>
 {lookup&&<><button type="button" className="secondary" onClick={()=>setLookup(null)}>Cerrar busqueda</button><DocumentLookup key={lookup+header.currency} kind={lookup} currency={header.currency} onCustomer={pickCustomer} onProduct={pickProduct} onSessionExpired={onSessionExpired}/></>}
 </fieldset>
 <section aria-live="polite" className="document-preview"><h3>Totales calculados por el servidor</h3>
 {Object.keys(payload.fields).length>0?<p>Completa los datos para calcular los totales.</p>:current?.data?<DocumentTotals totals={current.data.totals} currency={header.currency}/>:current?.error?<p role="alert" className="error">{current.error}</p>:<p role="status">Recalculando importes...</p>}
 </section><div className="form-actions"><button type="submit" disabled={saving||conflict||Boolean(current?.conflict)}>{saving?'Guardando borrador...':'Guardar borrador'}</button><button type="button" className="secondary" disabled={saving} onClick={onCancel}>Cancelar</button></div>
 </form>
}
