import {useEffect,useState} from 'react'
import {Link} from 'react-router'
import {ApiError} from '../lib/api'
import {getUsage,usageLabels} from '../lib/subscriptions'
import type {Usage} from '../lib/subscriptions'
interface Props {owner:boolean;version:number;onSessionExpired:()=>void;onChange:(allowed:boolean)=>void}
export default function UsageIndicator({owner,version,onSessionExpired,onChange}:Props){const [usage,setUsage]=useState<Usage|null>(null),[error,setError]=useState('');useEffect(()=>{let active=true;const controller=new AbortController();getUsage(AbortSignal.any([controller.signal,AbortSignal.timeout(15000)])).then(u=>{if(active){setUsage(u);setError('');onChange(u.can_issue)}}).catch(e=>{if(!active)return;if(e instanceof ApiError&&e.status===401)onSessionExpired();else setError('No se pudo consultar el cupo. La emision lo comprobara en el servidor.')});return()=>{active=false;controller.abort()}},[version,onSessionExpired,onChange]);return <div className="usage-indicator">{error?<p role="status">{error}</p>:usage?<><p>Cupo: <strong>{usage.remaining} de {usage.limit}</strong> disponibles - {usageLabels[usage.status]}</p>{!usage.can_issue&&(owner?<Link to="/subscription">Gestionar plan y consumo</Link>:<p>Contacta al titular de la empresa para renovar.</p>)}</>:<p>Consultando cupo...</p>}</div>}
