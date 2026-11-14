import { useEffect, useState } from 'react'
import { ApiError, getCustomers, getProducts } from '../lib/api'
import type { Customer, Product } from '../lib/api'
import { documentError } from '../lib/documents'
import { centsToDecimal } from '../lib/money'
interface Props { includeInactive?: boolean; kind: 'customer'|'product'; currency: string; onCustomer: (value: Customer)=>void; onProduct: (value: Product)=>void; onSessionExpired: ()=>void }
export default function DocumentLookup({includeInactive=false,kind,currency,onCustomer,onProduct,onSessionExpired}: Props) {
 const [q,setQ]=useState(''), [page,setPage]=useState(1)
 const key=JSON.stringify([kind,currency,q,page])
 const [result,setResult]=useState<{key:string; rows?: (Customer|Product)[]; last?: number; error?: string}>({key:''})
 useEffect(()=>{
  const controller=new AbortController(); let active=true
  const timer=setTimeout(()=>{
   const promise=kind==='customer' ? getCustomers({q,is_active:includeInactive?'':'1',page},controller.signal) : getProducts({q,is_active:'1',currency,tax_type:'',page},controller.signal)
   promise.then(data=>{if(active)setResult({key,rows:data.data,last:data.meta.last_page})}).catch(error=>{if(!active)return;if(error instanceof ApiError&&error.status===401)onSessionExpired();else setResult({key,error:documentError(error)})})
  },250)
  return ()=>{active=false;clearTimeout(timer);controller.abort()}
 },[kind,currency,q,page,key,onSessionExpired,includeInactive])
 const current=result.key===key?result:undefined
 return <section className="document-lookup"><label htmlFor={'lookup-'+kind}>{kind==='customer'?'Buscar cliente activo':'Buscar producto activo de la moneda seleccionada'}</label><input id={'lookup-'+kind} type="search" value={q} onChange={e=>{setQ(e.target.value);setPage(1)}} />
 {!current&&<p role="status">Buscando...</p>}{current?.error&&<p role="alert">{current.error}</p>}
 {current?.rows?.length===0&&<p>No hay resultados.</p>}
 <ul className="lookup-results">{current?.rows?.map(row=><li key={row.id}>{'name' in row ? <><span>{row.name}<small>{row.doc_number}</small></span><button type="button" className="secondary" onClick={()=>onCustomer(row)}>Seleccionar cliente</button></> : <><span>{row.description}<small>{row.code} - {centsToDecimal(row.unit_price_cents)} {row.currency} sin IGV</small></span><button type="button" className="secondary" onClick={()=>onProduct(row)}>Seleccionar producto</button></>}</li>)}</ul>
 <div className="pagination"><button type="button" className="secondary" disabled={page===1} onClick={()=>setPage(page-1)}>Anterior</button><span>P?gina {page}</span><button type="button" className="secondary" disabled={!current?.last||page>=current.last} onClick={()=>setPage(page+1)}>Siguiente</button></div>
 </section>
}
