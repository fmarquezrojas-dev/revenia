import {prepareCsrf,request} from './api'
export interface Usage {status:'active'|'absent'|'expired'|'exhausted'|'review';period_id:string|null;plan_name:string|null;starts_at:string|null;ends_at:string|null;limit:number;consumed:number;remaining:number;can_issue:boolean;expiring_soon:boolean;timezone:string;rule:string}
export interface Plan {code:string;name:string;description:string;price_cents:number;currency:string;emission_limit:number}
export interface Subscription {id:string;plan_name:string;plan_code:string;status:string;phase:string;source:string;price_cents:number;currency:string;emission_limit:number;consumed:number;starts_at:string|null;ends_at:string|null;review_code:string|null;order:{id:string;status:string;checkout_url:string|null;error_code:string|null}|null;payments:{provider_id:string;amount_cents:number;applied_cents:number;status:string;needs_review:boolean}[]}
export interface SubscriptionState {usage:Usage;history:Subscription[];enabled:boolean;pending:boolean}
export async function getUsage(signal:AbortSignal):Promise<Usage>{return (await request<{data:Usage}>('/api/v1/usage',{signal})).data}
export async function getSubscription(signal:AbortSignal):Promise<SubscriptionState>{return (await request<{data:SubscriptionState}>('/api/v1/subscription',{signal})).data}
export async function getPlans(signal:AbortSignal):Promise<Plan[]>{return (await request<{data:Plan[]}>('/api/v1/plans',{signal})).data}
export async function purchasePlan(code:string,key:string,signal:AbortSignal):Promise<void>{await prepareCsrf(signal);await request('/api/v1/subscriptions',{method:'POST',headers:{'Idempotency-Key':key},body:JSON.stringify({plan_code:code}),signal})}
export const usageLabels:Record<string,string>={verification_error:'Error de verificaci\u00f3n',active:'Vigente',absent:'Sin suscripci\u00f3n',expired:'Vencida',exhausted:'Cuota agotada',review:'Requiere revisi\u00f3n',future:'Programado',pending:'Pago pendiente',cancelled:'Cancelada'}
export function limaDate(value:string|null):string{return value?new Date(value).toLocaleString('es-PE',{timeZone:'America/Lima',dateStyle:'medium',timeStyle:'short'}):'Pendiente de confirmaci\u00f3n'}

export async function cancelSubscription(id:string,key:string,signal:AbortSignal):Promise<void>{await prepareCsrf(signal);await request('/api/v1/subscriptions/'+encodeURIComponent(id)+'/cancel',{method:'POST',headers:{'Idempotency-Key':key},body:'{}',signal})}
