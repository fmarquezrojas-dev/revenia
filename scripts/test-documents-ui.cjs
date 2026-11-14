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

async function main() {
 const origin='http://localhost:5174'
 await waitFor(async()=>{try{return (await fetch(origin)).ok}catch{return false}},'isolated frontend startup')
 browser=spawn(chrome,['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','about:blank'],{windowsHide:true,stdio:'ignore'})
 const portFile=path.join(profile,'DevToolsActivePort');await waitFor(()=>fs.existsSync(portFile),'Chrome startup')
 const port=fs.readFileSync(portFile,'utf8').split('\n')[0].trim();const targets=await(await fetch('http://127.0.0.1:'+port+'/json')).json()
 socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject})
 socket.onmessage=event=>{const message=JSON.parse(event.data);if(message.method==='Page.javascriptDialogOpening'){void send('Page.handleJavaScriptDialog',{accept:acceptDialog});acceptDialog=false;return}if(!message.id)return;const task=pending.get(message.id);if(!task)return;pending.delete(message.id);message.error?task.reject(new Error(message.error.message)):task.resolve(message.result)}
 await send('Page.enable');await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await send('Page.navigate',{url:origin+'/documents'})
 async function signin(email) {await waitText('Inicia sesi\u00f3n');await fill('email',email);await fill('password','Drafts-Test-Only-123');await click('Ingresar');await waitFor(()=>evaluate('Boolean(document.querySelector(".user-menu"))'),'authenticated shell')}
 async function signout(){await clickSelector('.user-menu summary');await click('Cerrar sesi\u00f3n');await waitText('Inicia sesi\u00f3n')}
 async function api(pathname){return evaluate('(async()=>{const r=await fetch('+JSON.stringify(pathname)+',{headers:{Accept:"application/json"}});return {status:r.status,body:await r.json()}})()')}
 await signin('issuer@drafts.test');await click('Comprobantes');await waitText('0 comprobantes encontrados');await click('Nuevo comprobante')
 const seriesId=(await api('/api/v1/document-series-options?doc_type=01')).body.data[0].id
 await waitFor(()=>evaluate('[...document.querySelectorAll("#document-series option")].some(e=>e.value==='+JSON.stringify(seriesId)+')'),'series option loaded')
 await fill('document-series',seriesId)
 await click('Seleccionar cliente');await waitText('Cliente RUC ensayo');await clickSelector('.lookup-results button')
 await click('Agregar producto');await waitText('Producto gravado ensayo');await clickSelector('.lookup-results button')
 await fill('quantity-0','3.000');await waitText('35.40 PEN');await waitText('30.00 PEN');await waitText('5.40 PEN')
 const evidence=path.join(__dirname,'..','docs','capturas','borradores');fs.mkdirSync(evidence,{recursive:true})
 async function capture(name){const result=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(evidence,name+'.png'),Buffer.from(result.data,'base64'))}
 await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await capture('editor-escritorio')
 await click('Guardar borrador');await waitText('Borrador guardado correctamente.');await waitText('Sin asignar')
 const route=await evaluate('location.pathname');let doc=(await api('/api/v1'+route)).body.data
 assert.equal(doc.totals.total_cents,3540);assert.equal(doc.totals.igv_cents,540);assert.equal(doc.number,null);assert.equal(doc.version,1)
 await send('Page.reload');await waitText('35.40 PEN');assert.equal((await api('/api/v1'+route)).body.data.items[0].quantity,'3.000')
 await click('Editar borrador');await fill('quantity-0','4.000');await waitText('47.20 PEN');await click('Guardar borrador');await waitText('Version 2')
 doc=(await api('/api/v1'+route)).body.data;assert.equal(doc.number,null);assert.equal(doc.version,2);assert.equal(doc.totals.total_cents,4720)
 await click('Volver al listado');await waitText('1 comprobantes encontrados');await capture('listado-escritorio');await click('Editar');await waitText('Editar borrador');await click('Cancelar');await waitText('Version 2')
 await send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:false});await capture('detalle-movil');assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'),true)
 await signout();await signin('accountant@drafts.test');await send('Page.navigate',{url:origin+route});await waitText('47.20 PEN')
 assert.equal(await has('Editar borrador'),false);assert.equal(await has('Eliminar borrador'),false)
 await send('Page.navigate',{url:origin+route+'/edit'});await waitText('Tu rol solo permite consultar comprobantes.')
 await signout();await signin('foreign@drafts.test');assert.equal((await api('/api/v1'+route)).status,404)
 await send('Page.navigate',{url:origin+route});await waitText('El comprobante o una referencia no est\u00e1 disponible.')
 await signout();await signin('issuer@drafts.test');await send('Page.navigate',{url:origin+'/documents'});await waitText('1 comprobantes encontrados')
 await capture('listado-movil');assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'),true)
 acceptDialog=true;await click('Eliminar');await waitText('Borrador eliminado correctamente.');await waitText('0 comprobantes encontrados')
 assert.equal((await api('/api/v1'+route)).status,404)
 console.log('PASS real API: issuer invoice 3000/540/3540, persistence on reload, null number, versioned edit, accountant readonly, foreign company 404, desktop/mobile screenshots, list edit/cancel and confirmed deletion')
}
main().catch(async error=>{console.error(error.stack);console.error(await evaluate('JSON.stringify({path:location.pathname,text:document.body.innerText})').catch(()=>''));process.exitCode=1}).finally(async()=>{if(socket?.readyState===WebSocket.OPEN){await send('Browser.close').catch(()=>{});socket.close()}if(browser&&browser.exitCode===null)browser.kill()})
