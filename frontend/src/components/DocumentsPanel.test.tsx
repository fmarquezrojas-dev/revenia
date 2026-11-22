import { Route, Routes } from 'react-router'
import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, expect, it, vi } from 'vitest'
import { render } from '../test/render'
import CreditNotePanel from './CreditNotePanel'
import DocumentEditor from './DocumentEditor'
import DocumentsPanel from './DocumentsPanel'
import { ApiError, getProducts, getCustomers } from '../lib/api'
import { getDocuments, getDocument, getSeriesOptions, previewDocument, saveDocument, deleteDocument, normalizeQuantity, createCreditNote } from '../lib/documents'
import type { DraftDocument, Preview } from '../lib/documents'
vi.mock('../lib/documents',async original=>({...await original<typeof import('../lib/documents')>(),createCreditNote:vi.fn(),getDocuments:vi.fn(),getDocument:vi.fn(),getSeriesOptions:vi.fn(),previewDocument:vi.fn(),saveDocument:vi.fn(),deleteDocument:vi.fn()}))
vi.mock('../lib/api',async original=>({...await original<typeof import('../lib/api')>(),getCustomers:vi.fn(),getProducts:vi.fn()}))
const totals={taxable_cents:3000,exempt_cents:0,unaffected_cents:0,igv_cents:540,total_cents:3540}
const draft:DraftDocument={id:'doc',doc_type:'01',currency:'PEN',issue_date:'2026-01-01',status:'draft',number:null,version:1,customer_id:'customer',series_id:'series',customer:{id:'customer',name:'Cliente ensayo',doc_type:'ruc',doc_number:'20131312955',is_active:true},series:{id:'series',code:'F001',doc_type:'01',is_active:true},items:[{id:'line',product_id:'product',description:'Producto ensayo',unit_price_cents:1000,unit_code:'NIU',tax_type:'10',quantity:'3.000',product_is_active:true,product_currency:'PEN',base_cents:3000,igv_cents:540,total_cents:3540}],totals}
const callbacks=()=>({onSaved:vi.fn(),onCancel:vi.fn(),onReload:vi.fn(),onSessionExpired:vi.fn(),onSavingChange:vi.fn()})
beforeEach(()=>{
 vi.clearAllMocks()
 vi.mocked(getDocument).mockResolvedValue(draft)
 vi.mocked(getDocuments).mockResolvedValue({data:[draft],meta:{current_page:1,last_page:1,per_page:15,total:1}})
 vi.mocked(getSeriesOptions).mockResolvedValue([draft.series])
 vi.mocked(previewDocument).mockResolvedValue({items:draft.items,totals,version:1})
 vi.mocked(deleteDocument).mockResolvedValue(undefined)
 vi.mocked(saveDocument).mockResolvedValue({...draft,version:2})
 vi.mocked(getProducts).mockResolvedValue({data:[],meta:{current_page:1,last_page:1,per_page:15,total:0}})
 vi.mocked(getCustomers).mockResolvedValue({data:[],meta:{current_page:1,last_page:1,per_page:15,total:0}})
 vi.spyOn(window,'confirm').mockReturnValue(true)
})
it('prepara la devolución completa de una línea con vista previa de exceso', async () => {
 const user = userEvent.setup(), saved = vi.fn()
 const doc = {...draft, items:[{...draft.items[0], id:'a', description:'Equipo', total_cents:94400},
   {...draft.items[0], id:'b', description:'Accesorio', total_cents:23600}]}
 const credit = {balance:{net_cents:118000,paid_cents:'100000'},notes:[],states:{a:'reserved' as const},series:[{id:'fc',code:'FC01'}]}
 vi.mocked(createCreditNote).mockResolvedValue({...draft,doc_type:'07'})
 render(<CreditNotePanel doc={doc} credit={credit} canWrite onSaved={saved} onSessionExpired={vi.fn()}/>)
 await user.click(screen.getByRole('button',{name:'Crear nota de crédito'}))
 expect(screen.getByRole('checkbox',{name:/Equipo.*Reservada/})).toBeDisabled()
 await user.click(screen.getByRole('checkbox',{name:/Accesorio.*Disponible/}))
 expect(screen.getByText('Exceso por revisar').nextElementSibling).toHaveTextContent('PEN 56.00')
 expect(screen.getByText('Neto de la venta').nextElementSibling).toHaveTextContent('PEN 944.00')
 await user.type(screen.getByLabelText('Motivo de devolución'),'Devolución')
 await user.click(screen.getByRole('button',{name:'Guardar nota en borrador'}))
 expect(createCreditNote).toHaveBeenCalledWith('doc',{series_id:'fc',reason:'Devolución',item_ids:['b']})
 expect(saved).toHaveBeenCalled()
})
it.each(['owner','issuer','accountant'] as const)('muestra permisos del listado para %s',async role=>{
 render(<DocumentsPanel role={role} onSessionExpired={vi.fn()} onSavingChange={vi.fn()}/>, '/documents')
 await screen.findByText('1 comprobantes encontrados')
 expect(Boolean(screen.queryByRole('link',{name:'Nuevo comprobante'}))).toBe(role!=='accountant')
 expect(Boolean(screen.queryByRole('link',{name:'Editar'}))).toBe(role!=='accountant')
 expect(Boolean(screen.queryByRole('button',{name:'Eliminar'}))).toBe(role!=='accountant')
})
it('deniega el editor al contador sin consultar selectores',()=>{
 render(<DocumentsPanel role="accountant" onSessionExpired={vi.fn()} onSavingChange={vi.fn()}/>, '/documents/new')
 expect(screen.getByRole('alert')).toHaveTextContent('solo permite consultar')
 expect(getSeriesOptions).not.toHaveBeenCalled()
})
it.each(['0','-1','1e3','1.0001','1000000.001'])('rechaza cantidad %s',value=>expect(()=>normalizeQuantity(value)).toThrow())
it('normaliza coma sin aritmetica flotante',()=>expect(normalizeQuantity('0,125')).toBe('0.125'))
it('guarda IDs y cantidad normalizada sin enviar snapshots editables',async()=>{
 const props=callbacks();render(<DocumentEditor document={draft} {...props}/>);const user=userEvent.setup()
 await user.clear(screen.getByLabelText('Cantidad linea 1'));await user.type(screen.getByLabelText('Cantidad linea 1'),'0,500')
 await user.click(screen.getByRole('button',{name:'Guardar borrador'}))
 await waitFor(()=>expect(props.onSaved).toHaveBeenCalled())
 expect(saveDocument).toHaveBeenCalledWith(expect.objectContaining({version:1,items:[{id:'line',product_id:'product',quantity:'0.500'}]}),'doc')
})
it('no guarda cantidades invalidas y muestra el campo',async()=>{
 render(<DocumentEditor document={draft} {...callbacks()}/>);const user=userEvent.setup()
 await user.clear(screen.getByLabelText('Cantidad linea 1'));await user.type(screen.getByLabelText('Cantidad linea 1'),'1e3');await user.click(screen.getByRole('button',{name:'Guardar borrador'}))
 expect(saveDocument).not.toHaveBeenCalled();expect(screen.getByLabelText('Cantidad linea 1')).toHaveAttribute('aria-invalid','true')
})
it('descarta previews antiguos y oculta totales mientras recalcula',async()=>{
 const responses:((v:Preview)=>void)[]=[];vi.mocked(previewDocument).mockImplementation(()=>new Promise(resolve=>responses.push(resolve)))
 render(<DocumentEditor document={draft} {...callbacks()}/>);await waitFor(()=>expect(responses).toHaveLength(1))
 const user=userEvent.setup();await user.clear(screen.getByLabelText('Cantidad linea 1'));await user.type(screen.getByLabelText('Cantidad linea 1'),'4')
 expect(screen.queryByText('35.40 PEN')).not.toBeInTheDocument();await waitFor(()=>expect(responses).toHaveLength(2))
 await act(async()=>responses[1]({items:draft.items,totals:{...totals,total_cents:4720},version:1}))
 await screen.findByText('47.20 PEN');await act(async()=>responses[0]({items:draft.items,totals,version:1}))
 expect(screen.getByText('47.20 PEN')).toBeInTheDocument();expect(screen.queryByText('35.40 PEN')).not.toBeInTheDocument()
})
it('conserva cantidades locales ante conflicto y exige recarga explicita',async()=>{
 vi.mocked(saveDocument).mockRejectedValue(new ApiError(409,{}));const props=callbacks();render(<DocumentEditor document={draft} {...props}/>);const user=userEvent.setup()
 await user.clear(screen.getByLabelText('Cantidad linea 1'));await user.type(screen.getByLabelText('Cantidad linea 1'),'7');await user.click(screen.getByRole('button',{name:'Guardar borrador'}))
 await screen.findByRole('button',{name:'Recargar version actual'});expect(screen.getByLabelText('Cantidad linea 1')).toHaveValue('7');expect(props.onReload).not.toHaveBeenCalled()
 await user.click(screen.getByRole('button',{name:'Recargar version actual'}));expect(props.onReload).toHaveBeenCalledOnce()
})
it('cambiar moneda conserva lineas y exige productos compatibles',async()=>{
 render(<DocumentEditor document={draft} {...callbacks()}/>);await userEvent.selectOptions(screen.getByLabelText('Moneda'),'USD')
 expect(screen.getByText('1. Producto ensayo')).toBeInTheDocument();expect(screen.getByText(/Reemplaza este producto/)).toBeInTheDocument();expect(window.confirm).toHaveBeenCalled()
 await userEvent.click(screen.getByRole('button',{name:'Agregar producto'}));await waitFor(()=>expect(getProducts).toHaveBeenCalledWith(expect.objectContaining({currency:'USD',is_active:'1'}),expect.any(AbortSignal)))
})
it('cambia tipo y permite quitar cliente de boleta sin borrar lineas',async()=>{
 render(<DocumentEditor document={draft} {...callbacks()}/>);await userEvent.selectOptions(screen.getByLabelText('Tipo de comprobante'),'03')
 expect(screen.getByLabelText('Serie')).toHaveValue('');expect(screen.getByLabelText('Cantidad linea 1')).toHaveValue('3.000')
 await userEvent.click(screen.getByRole('button',{name:'Sin cliente identificado'}));expect(screen.queryByText(/Cliente ensayo -/)).not.toBeInTheDocument()
})
it('cancelar conserva la responsabilidad del bloqueo en el shell',async()=>{
 const props=callbacks();render(<DocumentEditor document={draft} {...props}/>);await userEvent.click(screen.getByRole('button',{name:'Cancelar'}));expect(props.onCancel).toHaveBeenCalledOnce();expect(saveDocument).not.toHaveBeenCalled()
})
it('muestra errores por campo del servidor',async()=>{
 vi.mocked(saveDocument).mockRejectedValue(new ApiError(422,{errors:{series_id:['Reemplaza la serie inactiva.']}}));render(<DocumentEditor document={draft} {...callbacks()}/>);await userEvent.click(screen.getByRole('button',{name:'Guardar borrador'}));await waitFor(()=>expect(screen.getByLabelText('Serie')).toHaveAttribute('aria-invalid','true'))
})

it('contador consulta lineas y totales sin acciones de escritura',async()=>{
 render(<Routes><Route path="/documents/:id" element={<DocumentsPanel role="accountant" onSessionExpired={vi.fn()} onSavingChange={vi.fn()}/>}/></Routes>, '/documents/doc')
 await screen.findByText('35.40 PEN')
 expect(screen.getByText('Producto ensayo')).toBeInTheDocument()
 expect(screen.getByText('Sin asignar')).toBeInTheDocument()
 expect(screen.queryByRole('button',{name:'Editar borrador'})).not.toBeInTheDocument()
 expect(screen.queryByRole('button',{name:'Eliminar borrador'})).not.toBeInTheDocument()
})
it('bloquea doble envio mientras el guardado esta pendiente',async()=>{
 let resolve!:(value:DraftDocument)=>void
 vi.mocked(saveDocument).mockImplementation(()=>new Promise(done=>resolve=done))
 const props=callbacks();render(<DocumentEditor document={draft} {...props}/>);
 const user=userEvent.setup();await user.click(screen.getByRole('button',{name:'Guardar borrador'}));
 expect(screen.getByRole('button',{name:/Guardando borrador/})).toBeDisabled()
 await user.click(screen.getByRole('button',{name:/Guardando borrador/}));expect(saveDocument).toHaveBeenCalledOnce()
 await act(async()=>resolve({...draft,version:2}));expect(props.onSaved).toHaveBeenCalledOnce()
})

it('elimina desde el listado con version, confirmacion y bloqueo de doble envio',async()=>{
 let finish!:()=>void
 vi.mocked(deleteDocument).mockImplementation(()=>new Promise(resolve=>finish=resolve))
 const saving=vi.fn()
 render(<DocumentsPanel role="issuer" onSessionExpired={vi.fn()} onSavingChange={saving}/>, '/documents')
 const button=await screen.findByRole('button',{name:'Eliminar'})
 await userEvent.click(button)
 expect(window.confirm).toHaveBeenCalled()
 expect(screen.getByRole('button',{name:'Eliminando...'})).toBeDisabled()
 expect(screen.getByRole('link',{name:'Editar'})).toHaveAttribute('aria-disabled','true')
 await userEvent.click(screen.getByRole('button',{name:'Eliminando...'}))
 expect(deleteDocument).toHaveBeenCalledExactlyOnceWith('doc',1)
 vi.mocked(getDocuments).mockResolvedValue({data:[],meta:{current_page:1,last_page:1,per_page:15,total:0}})
 await act(async()=>finish())
 await screen.findByText('0 comprobantes encontrados')
 expect(screen.getByText('Borrador eliminado correctamente.')).toBeInTheDocument()
 expect(saving).toHaveBeenLastCalledWith(false)
})
it('cancelar la confirmacion no elimina el borrador',async()=>{
 vi.mocked(window.confirm).mockReturnValue(false)
 render(<DocumentsPanel role="owner" onSessionExpired={vi.fn()} onSavingChange={vi.fn()}/>, '/documents')
 await userEvent.click(await screen.findByRole('button',{name:'Eliminar'}))
 expect(deleteDocument).not.toHaveBeenCalled()
 expect(screen.getByText('Cliente ensayo')).toBeInTheDocument()
})
it('un 409 al eliminar conserva el listado y exige recarga explicita',async()=>{
 vi.mocked(deleteDocument).mockRejectedValue(new ApiError(409,{}))
 render(<DocumentsPanel role="issuer" onSessionExpired={vi.fn()} onSavingChange={vi.fn()}/>, '/documents')
 await userEvent.click(await screen.findByRole('button',{name:'Eliminar'}))
 await screen.findByRole('button',{name:'Recargar listado'})
 expect(screen.getByText('Cliente ensayo')).toBeInTheDocument()
 expect(deleteDocument).toHaveBeenCalledExactlyOnceWith('doc',1)
 expect(getDocuments).toHaveBeenCalledOnce()
 await userEvent.click(screen.getByRole('button',{name:'Recargar listado'}))
 await waitFor(()=>expect(getDocuments).toHaveBeenCalledTimes(2))
 expect(deleteDocument).toHaveBeenCalledOnce()
})

it('no duplica paneles al actualizar un comprobante aceptado',async()=>{
 const accepted={...draft,status:'accepted' as const,number:1};vi.mocked(getDocument).mockResolvedValue(accepted)
 render(<Routes><Route path="/documents/:id" element={<DocumentsPanel role="accountant" onSessionExpired={vi.fn()} onSavingChange={vi.fn()}/>}/></Routes>,'/documents/doc')
 await screen.findByRole('region',{name:'PDF privado y correo'});await userEvent.setup().click(screen.getByRole('button',{name:'Actualizar PDF y correo'}));await waitFor(()=>expect(getDocument).toHaveBeenCalledTimes(3));expect(screen.getAllByRole('region',{name:'Emisión simulada'})).toHaveLength(1)
})
