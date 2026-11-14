// Real API browser test; only run through test-documents-browser.ps1.
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { spawn } = require('node:child_process')
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const chrome = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe'
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'facturly-ui-'))
let browser, socket
let acceptDialog = false
let sequence = 0
const pending = new Map()
const browserErrors=[]
async function waitFor(check, label) {
  for (let i = 0; i < 600; i++) {
    if (await check()) return
    await delay(100)
  }
  throw new Error('Timeout: ' + label)
}
function send(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++sequence
    const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)) }, 60000)
    pending.set(id, { resolve: (v) => { clearTimeout(timer); resolve(v) }, reject })
    socket.send(JSON.stringify({ id, method, params }))
  })
}
async function evaluate(expression) {
  const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text + ': ' + result.exceptionDetails.exception?.description)
  return result.result.value
}
const has = (text) => evaluate('(document.body?.innerText || "").includes(' + JSON.stringify(text) + ')')
const waitText = (text) => waitFor(() => has(text), text)
async function clickSelector(selector) {
  await waitFor(() => evaluate('!![...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(e => e.getClientRects().length && getComputedStyle(e).visibility !== "hidden" && !e.disabled && e.getAttribute("aria-disabled") !== "true")'), selector)
  const point = await evaluate('(() => { const e = [...document.querySelectorAll(' + JSON.stringify(selector) + ')].find(e => e.getClientRects().length && getComputedStyle(e).visibility !== "hidden" && !e.disabled && e.getAttribute("aria-disabled") !== "true"); e.scrollIntoView({block:"center"}); const r=e.getBoundingClientRect(); return {x:r.x+r.width/2,y:r.y+r.height/2} })()')
  await send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 })
  await send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 })
}
async function click(text) {
  const expression = '[...document.querySelectorAll("button,a,summary")].find(e => e.textContent.trim() === ' + JSON.stringify(text) + ' && e.getClientRects().length && !e.disabled && e.getAttribute("aria-disabled") !== "true")'
  let point
  await waitFor(async () => {
    point = await evaluate('(() => { const e=' + expression + '; if(!e)return null; e.scrollIntoView({block:"center"});const r=e.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2} })()')
    return !!point
  }, 'action: ' + text)
  await send('Input.dispatchMouseEvent', { type:'mousePressed', ...point, button:'left', clickCount:1 })
  await send('Input.dispatchMouseEvent', { type:'mouseReleased', ...point, button:'left', clickCount:1 })
}
async function press(key) {
  await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code: key, windowsVirtualKeyCode: key === 'Tab' ? 9 : key === 'Escape' ? 27 : 13 })
  await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code: key })
}
async function fill(id, value) {
  await waitFor(() => evaluate('!!document.getElementById(' + JSON.stringify(id) + ')'), 'field: ' + id)
  await evaluate('(() => { const el = document.getElementById(' + JSON.stringify(id) + '); const proto = el.tagName === "SELECT" ? HTMLSelectElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, "value").set.call(el, ' + JSON.stringify(value) + '); el.dispatchEvent(new Event(el.tagName === "SELECT" ? "change" : "input", {bubbles:true})) })()')
}



async function main(){
 const origin='http://localhost:5173'
 const {execFileSync}=require('node:child_process')
 const manifest=JSON.parse(execFileSync('docker',['compose','exec','-T','app','php'],{windowsHide:true,encoding:'utf8',input:`<?php require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo json_encode(Illuminate\\Support\\Facades\\DB::table('local_demo_records')->where('dataset','local-demo-2026-v1')->get(['company_id','kind','scenario','record_id']));`}))
 const company='01a1c200-c32a-73cd-939c-6b7535d596f3';const records=manifest.filter(r=>r.company_id===company);const docId=scenario=>records.find(r=>r.kind==='documents'&&r.scenario===scenario).record_id
 browser=spawn(chrome,['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','about:blank'],{windowsHide:true,stdio:'ignore'})
 const portFile=path.join(profile,'DevToolsActivePort');await waitFor(()=>fs.existsSync(portFile),'Chrome startup')
 const port=fs.readFileSync(portFile,'utf8').split('\n')[0].trim();const targets=await(await fetch('http://127.0.0.1:'+port+'/json')).json()
 socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject})
 socket.onmessage=event=>{const message=JSON.parse(event.data);if(message.method==='Runtime.exceptionThrown')browserErrors.push(message.params.exceptionDetails);if(message.method==='Page.javascriptDialogOpening'){void send('Page.handleJavaScriptDialog',{accept:acceptDialog});acceptDialog=false;return}if(!message.id)return;const task=pending.get(message.id);if(!task)return;pending.delete(message.id);message.error?task.reject(new Error(message.error.message)):task.resolve(message.result)}
 await send('Runtime.enable');await send('Page.enable');await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await send('Page.navigate',{url:origin+'/documents'})


 async function signin(email){await waitText('Inicia sesi\u00f3n');await fill('email',email);await fill('password',process.env.FACTURLY_DEMO_PASSWORD);await click('Ingresar');await waitFor(()=>evaluate('Boolean(document.querySelector(".user-menu"))'),'authenticated shell')}
 async function signout(){await clickSelector('.user-menu summary');await click('Cerrar sesi\u00f3n');await waitText('Inicia sesi\u00f3n')}
 async function api(route){return evaluate('(async()=>{const r=await fetch('+JSON.stringify('/api/v1/'+route)+',{headers:{Accept:"application/json"}});return {status:r.status,json:await r.json()}})()')}
 async function navigate(route){await send('Page.navigate',{url:origin+route});await waitFor(()=>evaluate('!!document.querySelector(".user-menu")&&!document.body.innerText.includes("Cargando")'),'loaded '+route)}
 const evidence=path.join(__dirname,'..','docs','capturas','datos-locales');fs.mkdirSync(evidence,{recursive:true})
 const report={company,pages:[],checks:[],scenarios:[]}
 async function capture(name){const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(evidence,name+'.png'),Buffer.from(screenshot.data,'base64'))}
 await signin(process.env.FACTURLY_DEMO_EMAIL);assert.equal((await api('me')).json.data.company.id,company)
 for(const [module,total] of [['users',4],['customers',20],['products',24],['series',3],['documents',12]]){
   await navigate('/'+module);await waitFor(()=>evaluate('document.querySelectorAll("tbody tr").length>0'),'rows '+module)
   const result=await api(module);assert.equal(result.status,200);assert.equal(result.json.meta.total,total);assert.ok(Array.isArray(result.json.data));const rows=await evaluate('document.querySelectorAll("tbody tr").length');assert.equal(rows,result.json.data.length)
   console.log('Verified list: '+module);report.pages.push({module,status:result.status,total,visibleRows:rows,api:true,ui:true});await capture(module+'-after')
 }
 await navigate('/customers');await waitText('20 clientes encontrados');await click('Siguiente');await waitText('P\u00e1gina 2 de 2');assert.equal(await evaluate('document.querySelectorAll("tbody tr").length'),5)
 await fill('customer-search','DEMO C01');await waitText('1 clientes encontrados');assert.equal((await api('customers?q=DEMO%20C01')).json.meta.total,1)
 await fill('customer-search','');await waitText('20 clientes encontrados');await fill('customer-status','0');await waitText('3 clientes encontrados');assert.equal((await api('customers?is_active=0')).json.meta.total,3);console.log('Completed check');report.checks.push('Clientes: busqueda, inactivos y segunda pagina')
 await navigate('/products');await fill('product-currency','USD');await waitText('4 productos encontrados');assert.equal((await api('products?currency=USD')).json.meta.total,4);await fill('product-currency','');await waitText('24 productos encontrados');await fill('product-search','DEMO-S001');await waitText('1 productos encontrados');await click('Ver detalle');await waitText('Servicio de soporte');console.log('Completed check');report.checks.push('Productos: filtro USD, busqueda y detalle')
 await navigate('/documents?status=draft');await waitText('6 comprobantes encontrados');assert.equal((await api('documents?status=draft')).json.meta.total,6)
 const editId=docId('borrador-fraccionario');await navigate('/documents/'+editId);await waitText('Editar borrador');const before=(await api('documents/'+editId)).json.data;await click('Editar borrador');await fill('quantity-0','1.375');await click('Guardar borrador');await waitText('Editar borrador');await waitFor(async()=>{const d=(await api('documents/'+editId)).json.data;return d.version>before.version},'saved draft');const edited=(await api('documents/'+editId)).json.data;assert.equal(edited.items[0].quantity,'1.375');assert.equal(edited.status,'draft');assert.equal(edited.number,null);assert.equal(edited.totals.total_cents,edited.items.reduce((n,x)=>n+x.total_cents,0));assert.equal(edited.totals.total_cents,2545);await capture('borrador-editado');await send('Page.reload');await waitText('Editar borrador');assert.equal((await api('documents/'+editId)).json.data.items[0].quantity,'1.375');console.log('Completed check');report.checks.push('Borrador fraccionario editado y persistente: 25.45 PEN, sin numero')
 for(const record of records.filter(r=>r.kind==='documents')){const d=(await api('documents/'+record.record_id)).json.data;report.scenarios.push({scenario:record.scenario,id:d.id,status:d.status,number:d.formatted_number,total_cents:d.totals.total_cents,currency:d.currency});if(d.status==='accepted'){assert.equal(d.delivery.pdf.available,true)}}
 const acceptedId=docId('aceptado-factura-pen');await navigate('/documents/'+acceptedId);await waitText('PDF privado y correo');await waitText('Disponible');const download=await evaluate('(async()=>{const r=await fetch("/api/v1/documents/'+acceptedId+'/pdf");const b=await r.arrayBuffer();return {status:r.status,type:r.headers.get("content-type"),cache:r.headers.get("cache-control"),signature:new TextDecoder().decode(b.slice(0,5)),size:b.byteLength}})()');assert.equal(download.status,200);assert.equal(download.signature,'%PDF-');assert.equal(download.type,'application/pdf');assert.ok(download.cache.includes('no-store'));report.pdf=download
 let delivery=(await api('documents/'+acceptedId)).json.data.delivery.email.deliveries[0]
 if(!delivery){await click('Enviar por correo');await waitText('Confirmar env\u00edo');await click('Confirmar env\u00edo')}
 await waitFor(async()=>{delivery=(await api('documents/'+acceptedId)).json.data.delivery.email.deliveries[0];return delivery?.status==='sent'},'Mailpit queued delivery');assert.ok(delivery.recipient.endsWith('@example.test'))
 const messages=await(await fetch('http://localhost:8025/api/v1/search?query='+encodeURIComponent('to:'+delivery.recipient))).json();let observed
 for(const item of messages.messages??[]){const full=await(await fetch('http://localhost:8025/api/v1/message/'+item.ID)).json();if(full.Subject.includes('F001-00000001')&&full.Attachments.some(a=>a.ContentType==='application/pdf')){observed=full;break}}
 assert.ok(observed);assert.ok(observed.Text.includes('Sin validez tributaria'));assert.ok(observed.HTML.includes('Sin validez tributaria'));report.mailpit={deliveryId:delivery.id,messageId:delivery.message_id,mailpitId:observed.ID,status:delivery.status,attachment:true};await capture('pdf-y-correo')
 const foreign=manifest.find(r=>r.company_id!==company&&r.kind==='documents');assert.equal((await api('documents/'+foreign.record_id)).status,404);for(const kind of ['customers','products']){const other=manifest.find(r=>r.company_id!==company&&r.kind===kind);assert.equal((await api(kind+'/'+other.record_id)).status,404)}console.log('Completed check');report.checks.push('Aislamiento: documento, cliente y producto de Demo Beta devuelven 404')
 await signout();await signin('issuer@alfa.example.test');await navigate('/documents/'+acceptedId);await waitText('PDF privado y correo');assert.equal(await has('Regenerar PDF'),false);assert.equal(await has('Enviar por correo'),true);assert.equal((await api('users')).status,403);console.log('Completed check');report.checks.push('Issuer: consulta/envio, sin usuarios ni regeneracion')
 await signout();await signin('accountant@alfa.example.test');await navigate('/documents/'+acceptedId);await waitText('PDF privado y correo');assert.equal(await has('Enviar por correo'),false);assert.equal(await has('Regenerar PDF'),false);await waitText('Descargar PDF');assert.equal((await api('series')).status,403);await navigate('/documents/'+editId);await waitText('Volver al listado');assert.equal(await has('Editar borrador'),false);console.log('Completed check');report.checks.push('Accountant: consulta/descarga, sin editar ni enviar ni administrar series')
 assert.deepEqual(browserErrors,[]);report.result='PASS';fs.writeFileSync(path.join(evidence,'verification.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2))
}
main().catch(async error=>{console.error(error.stack);console.error(await evaluate('JSON.stringify({path:location.pathname+location.search,text:document.body.innerText})').catch(()=>''));process.exitCode=1}).finally(async()=>{if(socket?.readyState===WebSocket.OPEN){await send('Browser.close').catch(()=>{});socket.close()}if(browser&&browser.exitCode===null)browser.kill()})
