import { beforeEach, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { render } from '../test/render'
import DocumentDeliveryPanel from './DocumentDeliveryPanel'
import { getDocument } from '../lib/documents'
import { requestDelivery, downloadPdf } from '../lib/delivery'
import { ApiError } from '../lib/api'
import type { DraftDocument } from '../lib/documents'
vi.mock('../lib/documents',async original=>({...await original<typeof import('../lib/documents')>(),getDocument:vi.fn()}))
vi.mock('../lib/delivery',async original=>({...await original<typeof import('../lib/delivery')>(),requestDelivery:vi.fn(),downloadPdf:vi.fn()}))
const doc:DraftDocument={id:'accepted-document',doc_type:'01',currency:'PEN',issue_date:'2026-11-12',status:'accepted',version:4,number:1,formatted_number:'F001-00000001',customer_id:null,series_id:'s',customer:null,series:{id:'s',code:'F001',doc_type:'01'},items:[],totals:{taxable_cents:1000,exempt_cents:0,unaffected_cents:0,igv_cents:180,total_cents:1180},delivery:{pdf:{status:'ready',version:1,available:true,file_id:'file-1',generated_at:null,error_code:null,can_regenerate:true,next_attempt_at:null},email:{recipient:'captured@example.test',can_send:true,deliveries:[]}}}
const callbacks=()=>({onSessionExpired:vi.fn(),onBusy:vi.fn()})
beforeEach(()=>{vi.clearAllMocks();sessionStorage.clear();vi.mocked(getDocument).mockResolvedValue(doc);vi.mocked(requestDelivery).mockResolvedValue()})
it('confirma destinatario y vincula el envio al archivo visible',async()=>{
 render(<DocumentDeliveryPanel doc={doc} {...callbacks()}/>);const user=userEvent.setup();await user.click(await screen.findByRole('button',{name:'Enviar por correo'}));expect(screen.getByRole('group')).toHaveTextContent('captured@example.test');await user.click(screen.getByRole('button',{name:'Confirmar envío'}));await waitFor(()=>expect(requestDelivery).toHaveBeenCalledWith(doc.id,'email',expect.any(String),{file_id:'file-1'},expect.any(AbortSignal)))
})
it('conserva clave ante respuesta perdida incluso al remontar',async()=>{
 vi.mocked(requestDelivery).mockRejectedValueOnce(new TypeError('network'));const first=render(<DocumentDeliveryPanel doc={doc} {...callbacks()}/>);const user=userEvent.setup();await user.click(await screen.findByRole('button',{name:'Enviar por correo'}));await user.click(screen.getByRole('button',{name:'Confirmar envío'}));await screen.findByRole('alert');const key=vi.mocked(requestDelivery).mock.calls[0][2];first.unmount();render(<DocumentDeliveryPanel doc={doc} {...callbacks()}/>);await user.click(await screen.findByRole('button',{name:'Enviar por correo'}));await user.click(screen.getByRole('button',{name:'Confirmar envío'}));await waitFor(()=>expect(requestDelivery).toHaveBeenCalledTimes(2));expect(vi.mocked(requestDelivery).mock.calls[1][2]).toBe(key)
})
it('bloquea doble solicitud mientras espera',async()=>{
 vi.mocked(requestDelivery).mockImplementation(()=>new Promise(()=>{}));render(<DocumentDeliveryPanel doc={doc} {...callbacks()}/>);const user=userEvent.setup();await user.click(await screen.findByRole('button',{name:'Enviar por correo'}));await user.dblClick(screen.getByRole('button',{name:'Confirmar envío'}));expect(requestDelivery).toHaveBeenCalledOnce();expect(screen.getByRole('button',{name:'Registrando...'})).toBeDisabled()
})
it('permite descargar pero no muestra envio ni regeneracion sin permiso',async()=>{
 const readonly={...doc,delivery:{pdf:{...doc.delivery!.pdf,can_regenerate:false},email:{recipient:null,can_send:false,deliveries:[]}}};vi.mocked(getDocument).mockResolvedValue(readonly);render(<DocumentDeliveryPanel doc={readonly} {...callbacks()}/>);await screen.findByRole('button',{name:'Descargar PDF'});expect(screen.queryByRole('button',{name:'Enviar por correo'})).not.toBeInTheDocument();expect(screen.queryByRole('button',{name:'Regenerar PDF'})).not.toBeInTheDocument()
})
it('muestra error controlado antes de crear una descarga',async()=>{
 vi.mocked(downloadPdf).mockRejectedValue(new ApiError(404,{message:'El archivo no esta disponible.'}));render(<DocumentDeliveryPanel doc={doc} {...callbacks()}/>);await userEvent.setup().click(await screen.findByRole('button',{name:'Descargar PDF'}));expect(await screen.findByRole('alert')).toHaveTextContent('archivo no esta disponible')
})
it.each([401,403,409,422,429])('gestiona respuesta HTTP %s',async status=>{
 const props=callbacks();vi.mocked(requestDelivery).mockRejectedValue(new ApiError(status,{message:'Error controlado'}));render(<DocumentDeliveryPanel doc={doc} {...props}/>);const user=userEvent.setup();await user.click(await screen.findByRole('button',{name:'Enviar por correo'}));await user.click(screen.getByRole('button',{name:'Confirmar envío'}));if(status===401)await waitFor(()=>expect(props.onSessionExpired).toHaveBeenCalled());else expect(await screen.findByRole('alert')).toHaveTextContent(status===429?'Límite':'Error controlado')
})
it('cancela la consulta al desmontar y no consulta mas un estado final',async()=>{
 const view=render(<DocumentDeliveryPanel doc={doc} {...callbacks()}/>);await waitFor(()=>expect(getDocument).toHaveBeenCalledOnce());const signal=vi.mocked(getDocument).mock.calls[0][1];view.unmount();expect(signal?.aborted).toBe(true)
})

it('cancela el envio al desmontar y conserva su clave para reconciliar',async()=>{
 vi.mocked(requestDelivery).mockImplementation(()=>new Promise(()=>{}));const view=render(<DocumentDeliveryPanel doc={doc} {...callbacks()}/>);const user=userEvent.setup();await user.click(await screen.findByRole('button',{name:'Enviar por correo'}));await user.click(screen.getByRole('button',{name:'Confirmar env\u00edo'}));const call=vi.mocked(requestDelivery).mock.calls[0];view.unmount();expect(call[4]?.aborted).toBe(true);expect(sessionStorage.getItem('facturly:delivery:'+doc.id+':email:file-1')).toBe(call[2])
})
