import { beforeEach, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { render } from '../test/render'
import EmissionControls from './EmissionControls'
import { getEmissionEvents, requestEmission } from '../lib/issuance'
import { getUsage } from '../lib/subscriptions'
import { ApiError } from '../lib/api'
import type { DraftDocument } from '../lib/documents'
vi.mock('../lib/issuance',async original=>({...await original<typeof import('../lib/issuance')>(),getEmissionEvents:vi.fn(),requestEmission:vi.fn()}))
vi.mock('../lib/subscriptions',async original=>({...await original<typeof import('../lib/subscriptions')>(),getUsage:vi.fn()}))
const doc:DraftDocument={id:'doc-emission',doc_type:'01',currency:'PEN',issue_date:'2026-11-12',status:'draft',version:3,number:null,customer_id:null,series_id:'series',customer:null,series:{id:'series',code:'F001',doc_type:'01'},items:[],totals:{taxable_cents:1000,exempt_cents:0,unaffected_cents:0,igv_cents:180,total_cents:1180}}
const props=()=>({doc,canWrite:true,disabled:false,onRefresh:vi.fn(),onBusy:vi.fn(),onSessionExpired:vi.fn()})
beforeEach(()=>{vi.clearAllMocks();vi.mocked(getUsage).mockResolvedValue({can_issue:true} as Awaited<ReturnType<typeof getUsage>>);sessionStorage.clear();vi.mocked(getEmissionEvents).mockResolvedValue([]);vi.mocked(requestEmission).mockResolvedValue()})
it('confirma el resumen y envia la version guardada una sola vez',async()=>{
 const callbacks=props();render(<EmissionControls {...callbacks}/>);const user=userEvent.setup()
 await user.click(screen.getByRole('button',{name:'Emitir comprobante'}));expect(screen.getByRole('group')).toHaveTextContent('11.80 PEN');expect(screen.getByRole('group')).toHaveTextContent('versión guardada 3')
 await user.click(screen.getByRole('button',{name:'Confirmar emisión'}));await waitFor(()=>expect(callbacks.onRefresh).toHaveBeenCalledOnce())
 expect(requestEmission).toHaveBeenCalledWith('doc-emission',3,expect.any(String),'issue');expect(requestEmission).toHaveBeenCalledOnce()
})
it('conserva la clave despues de respuesta perdida y remontaje',async()=>{
 vi.mocked(requestEmission).mockRejectedValueOnce(new TypeError('network'))
 const first=render(<EmissionControls {...props()}/>);const user=userEvent.setup();await user.click(screen.getByRole('button',{name:'Emitir comprobante'}));await user.click(screen.getByRole('button',{name:'Confirmar emisión'}));await screen.findByRole('alert')
 const key=vi.mocked(requestEmission).mock.calls[0][2];first.unmount();render(<EmissionControls {...props()}/>);await user.click(screen.getByRole('button',{name:'Emitir comprobante'}));await user.click(screen.getByRole('button',{name:'Confirmar emisión'}))
 await waitFor(()=>expect(requestEmission).toHaveBeenCalledTimes(2));expect(vi.mocked(requestEmission).mock.calls[1][2]).toBe(key)
})
it('bloquea dobles clics mientras la solicitud sigue pendiente',async()=>{
 vi.mocked(requestEmission).mockImplementation(()=>new Promise(()=>{}));render(<EmissionControls {...props()}/>);const user=userEvent.setup();await user.click(screen.getByRole('button',{name:'Emitir comprobante'}));await user.dblClick(screen.getByRole('button',{name:'Confirmar emisión'}));expect(requestEmission).toHaveBeenCalledOnce();expect(screen.getByRole('button',{name:'Registrando...'})).toBeDisabled()
})
it('contabilidad consulta un resultado sin emitir ni reintentar',async()=>{
 render(<EmissionControls {...props()} canWrite={false} doc={{...doc,status:'failed',can_retry:true,result_message:'Resultado desconocido'}}/>);await waitFor(()=>expect(getEmissionEvents).toHaveBeenCalled());expect(screen.queryByRole('button',{name:/Emitir comprobante|Reintentar env/})).not.toBeInTheDocument();expect(screen.getByRole('status')).toHaveTextContent('Fallo técnico')
})
it.each(['accepted','rejected'] as const)('no ofrece reenvio del estado final %s',async status=>{
 render(<EmissionControls {...props()} doc={{...doc,status,can_retry:false}}/>);await waitFor(()=>expect(getEmissionEvents).toHaveBeenCalled());expect(screen.queryByRole('button',{name:/Reintentar env/})).not.toBeInTheDocument()
})
it('reintenta fallos recuperables con la version actual',async()=>{
 render(<EmissionControls {...props()} doc={{...doc,status:'failed',version:6,can_retry:true}}/>);await userEvent.setup().click(screen.getByRole('button',{name:'Reintentar envío'}));expect(requestEmission).toHaveBeenCalledWith(doc.id,6,expect.any(String),'retry')
})

it('descarta el conflicto cuando llega una version mas reciente',async()=>{
 vi.mocked(requestEmission).mockRejectedValueOnce(new ApiError(409,{}));const callbacks=props();const view=render(<EmissionControls {...callbacks}/>);const user=userEvent.setup()
 await user.click(screen.getByRole('button',{name:'Emitir comprobante'}));await user.click(screen.getByRole('button',{name:'Confirmar emisión'}));expect(await screen.findByRole('alert')).toHaveTextContent('comprobante cambió')
 view.rerender(<EmissionControls {...callbacks} doc={{...doc,version:5,status:'accepted'}}/>);await waitFor(()=>expect(getEmissionEvents).toHaveBeenCalled());expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('allows refreshing a draft when its quota is exhausted',async()=>{
 vi.mocked(getUsage).mockResolvedValue({status:'exhausted',remaining:0,limit:2,can_issue:false} as Awaited<ReturnType<typeof getUsage>>)
 const callbacks=props();render(<EmissionControls {...callbacks}/>);
 await waitFor(()=>expect(screen.getByRole('button',{name:'Emitir comprobante'})).toBeDisabled())
 await userEvent.click(screen.getByRole('button',{name:'Actualizar estado'}))
 expect(callbacks.onRefresh).toHaveBeenCalledOnce();expect(requestEmission).not.toHaveBeenCalled()
})
