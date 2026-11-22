import type { DeliveryState } from './delivery'
import { request, prepareCsrf, ApiError } from './api'
import type { Customer, Product, ProductsResponse } from './api'
export interface Totals { taxable_cents: number; exempt_cents: number; unaffected_cents: number; igv_cents: number; total_cents: number }
export interface DraftLine { currency?: string; id?: string; product_id: string; quantity: string; description: string; unit_code: string; unit_price_cents: number; tax_type: string; base_cents?: number; igv_cents?: number; total_cents?: number; product_is_active?: boolean; product_currency?: string }
export interface SeriesOption { id: string; code: string; doc_type: '01' | '03' | '07'; is_active?: boolean }
export interface CreditDetail {
 notes: {id:string; series_code:string|null; number:number|null; status:DraftDocument['status']; total_cents:number}[]
 states: Record<string,'applied'|'reserved'>
 series: {id:string; code:string}[]
 balance: {net_cents:number; paid_cents:string}
}
export type PaymentTerms = 'cash' | 'credit'
export interface DraftDocument { payment_terms?: PaymentTerms | null; due_date?: string | null; credit?:CreditDetail|null; source_document_id?:string|null; source_number?:string|null; credit_reason?:string|null; delivery?: DeliveryState | null; id: string; doc_type: '01' | '03' | '07'; currency: 'PEN' | 'USD'; issue_date: string; status: 'draft' | 'issued' | 'queued' | 'sending' | 'accepted' | 'rejected' | 'failed'; formatted_number?: string | null; can_retry?: boolean; next_attempt_at?: string | null; result_message?: string | null; failure_kind?: string | null; attempt_count?: number; version: number; number: number | null; customer_id: string | null; series_id: string; customer: Pick<Customer,'id'|'name'|'doc_type'|'doc_number'|'is_active'> | null; series: SeriesOption; items: DraftLine[]; totals: Totals }
export interface DraftInput { payment_terms?: PaymentTerms; due_date?: string; doc_type: '01' | '03'; currency: 'PEN' | 'USD'; issue_date: string; customer_id: string | null; series_id: string; version?: number; items: { id?: string; product_id: string; quantity: string }[] }
export interface Preview { items: DraftLine[]; totals: Totals; version: number | null }
export interface DocumentFilters { doc_type: string; status: string; customer_id: string; date_from: string; date_to: string; page: number }
export interface DocumentsResult { data: DraftDocument[]; meta: ProductsResponse['meta'] }
export async function getDocuments(filters: DocumentFilters, signal?: AbortSignal): Promise<DocumentsResult> {
 const params = new URLSearchParams({ per_page: '15' }); Object.entries(filters).forEach(([k,v]) => { if(v !== '') params.set(k,String(v)) })
 return request('/api/v1/documents?' + params, { signal })
}
export async function getDocument(id: string, signal?: AbortSignal): Promise<DraftDocument> { return (await request<{data: DraftDocument}>('/api/v1/documents/' + encodeURIComponent(id),{signal})).data }
export async function getSeriesOptions(type: string, signal?: AbortSignal): Promise<SeriesOption[]> { return (await request<{data: SeriesOption[]}>('/api/v1/document-series-options?doc_type=' + type,{signal})).data }
export async function previewDocument(data: DraftInput, id?: string, signal?: AbortSignal): Promise<Preview> {
 await prepareCsrf(); return (await request<{data: Preview}>('/api/v1/documents/preview',{method:'POST',signal,body:JSON.stringify({...data,...(id ? {document_id:id} : {})})})).data
}
export async function saveDocument(data: DraftInput, id?: string): Promise<DraftDocument> {
 await prepareCsrf(); return (await request<{data: DraftDocument}>('/api/v1/documents' + (id ? '/'+encodeURIComponent(id) : ''),{method:id?'PATCH':'POST',body:JSON.stringify(data)})).data
}
export async function createCreditNote(id:string, data:{series_id:string; reason:string; item_ids:string[]}):Promise<DraftDocument> {
 await prepareCsrf()
 return (await request<{data:DraftDocument}>('/api/v1/documents/'+encodeURIComponent(id)+'/credit-notes',{method:'POST',body:JSON.stringify(data)})).data
}
export async function deleteDocument(id: string, version: number): Promise<void> { await prepareCsrf(); return request('/api/v1/documents/'+encodeURIComponent(id),{method:'DELETE',body:JSON.stringify({version})}) }
export function documentError(error: unknown): string {
 if(error instanceof ApiError) {
  if(error.status===409) return 'El comprobante cambi\u00f3 o ya no es un borrador. Tus cambios locales se conservan. Recarga la versi\u00f3n actual para revisarla.'
  if(error.status===404) return 'El comprobante o una referencia no est\u00e1 disponible.'
  if(error.status===403) return 'Tu rol no permite realizar esta operaci\u00f3n.'
  if(error.status===422) return Object.values(error.errors ?? {}).flat().join(' ') || 'Revisa los datos.'
 }
 return 'No se pudo completar la operaci\u00f3n. Revisa la conexi\u00f3n e intenta nuevamente.'
}
export function normalizeQuantity(value: string): string {
 const normalized=value.trim().replace(',','.')
 if(!/^\d{1,7}(\.\d{1,3})?$/.test(normalized)) throw new Error('Usa una cantidad positiva con hasta tres decimales.')
 const [whole,fraction='']=normalized.split('.'); const millis=BigInt(whole)*1000n+BigInt(fraction.padEnd(3,'0'))
 if(millis<1n || millis>1000000000n) throw new Error('Cantidad entre 0.001 y 1000000.000.')
 return (millis/1000n).toString()+'.'+(millis%1000n).toString().padStart(3,'0')
}
export function lineFromProduct(product: Product): DraftLine { return {currency:product.currency,product_id:product.id,description:product.description,unit_code:product.unit_code,unit_price_cents:product.unit_price_cents,tax_type:product.tax_type,quantity:'1.000',product_is_active:true,product_currency:product.currency} }
export function limaToday(): string { return new Intl.DateTimeFormat('en-CA',{timeZone:'America/Lima',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()) }
