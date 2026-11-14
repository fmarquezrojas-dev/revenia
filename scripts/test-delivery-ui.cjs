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

async function main() {
 const origin='http://localhost:5174'
 await waitFor(async()=>{try{return (await fetch(origin)).ok}catch{return false}},'isolated frontend startup')
 browser=spawn(chrome,['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','about:blank'],{windowsHide:true,stdio:'ignore'})
 const portFile=path.join(profile,'DevToolsActivePort');await waitFor(()=>fs.existsSync(portFile),'Chrome startup')
 const port=fs.readFileSync(portFile,'utf8').split('\n')[0].trim();const targets=await(await fetch('http://127.0.0.1:'+port+'/json')).json()
 socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject})
 socket.onmessage=event=>{const message=JSON.parse(event.data);if(message.method==='Runtime.exceptionThrown')browserErrors.push(message.params.exceptionDetails);if(message.method==='Page.javascriptDialogOpening'){void send('Page.handleJavaScriptDialog',{accept:acceptDialog});acceptDialog=false;return}if(!message.id)return;const task=pending.get(message.id);if(!task)return;pending.delete(message.id);message.error?task.reject(new Error(message.error.message)):task.resolve(message.result)}
 await send('Runtime.enable');await send('Page.enable');await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await send('Page.navigate',{url:origin+'/documents'})
 async function signin(email) {await waitText('Inicia sesi\u00f3n');await fill('email',email);await fill('password','Delivery-Test-Only-123');await click('Ingresar');await waitFor(()=>evaluate('Boolean(document.querySelector(".user-menu"))'),'authenticated shell')}
 async function signout(){await clickSelector('.user-menu summary');await click('Cerrar sesi\u00f3n');await waitText('Inicia sesi\u00f3n')}
 async function api(pathname){return evaluate('(async()=>{const r=await fetch('+JSON.stringify(pathname)+',{headers:{Accept:"application/json"}});return {status:r.status,body:await r.json()}})()')}

 const manifest=JSON.parse(fs.readFileSync(path.join(__dirname,'..','docs','examples','pdf','manifest.json'),'utf8'));const id=manifest.documents['factura-pen'],route='/documents/'+id
 await signin('owner@delivery.test');await send('Page.navigate',{url:origin+route});await waitText('PDF privado y correo');await waitText(manifest.recipient)
 await waitFor(()=>evaluate('!!document.querySelector('+JSON.stringify('[aria-label="PDF privado y correo"]')+')'),'delivery panel')
 const download=await evaluate('(async()=>{const r=await fetch('+JSON.stringify('/api/v1'+route+'/pdf')+');const b=await r.arrayBuffer();return {status:r.status,type:r.headers.get("content-type"),cache:r.headers.get("cache-control"),prefix:new TextDecoder().decode(b.slice(0,5)),size:b.byteLength}})()');assert.equal(download.status,200);assert.equal(download.type,'application/pdf');assert.equal(download.prefix,'%PDF-');assert.ok(download.cache.includes('no-store'));assert.ok(download.size>1000)
 const {execFile}=require('node:child_process');const run=require('node:util').promisify(execFile)
 async function artisan(...args){const result=await run('docker',['exec',process.env.FACTURLY_TEST_API,'php','artisan',...args],{windowsHide:true,timeout:180000});console.log(result.stdout.trim())}
 await click('Enviar por correo');await waitText('Confirmar env\u00edo');await click('Confirmar env\u00edo');await waitText('Solicitud registrada para procesamiento.')
 await artisan('issuance:tick');await artisan('queue:work','database','--queue=documents','--stop-when-empty','--tries=1','--timeout=45');await click('Actualizar PDF y correo');await waitFor(async()=>{const d=(await api('/api/v1'+route)).body.data;return d.delivery.email.deliveries[0]?.status==='sent'},'delivery sent')
 assert.equal(await evaluate('document.querySelectorAll('+JSON.stringify('[aria-label="Emisi\u00f3n simulada"]')+').length'),1)
 const detail=(await api('/api/v1'+route)).body.data;assert.equal(detail.status,'accepted');assert.equal(detail.number,1);assert.equal(detail.delivery.email.deliveries.length,1);const delivery=detail.delivery.email.deliveries[0];assert.equal(delivery.status,'sent')
 let mail;await waitFor(async()=>{const response=await fetch('http://localhost:8025/api/v1/search?query='+encodeURIComponent('to:'+manifest.recipient));const result=await response.json();mail=result.messages?.find(m=>m.To?.some(to=>to.Address===manifest.recipient));return !!mail},'Mailpit receipt')
 const message=await(await fetch('http://localhost:8025/api/v1/message/'+mail.ID)).json();assert.ok(message.Text.includes('Sin validez tributaria'));assert.ok(message.HTML.includes('Sin validez tributaria'));assert.ok(message.Attachments.some(a=>a.ContentType==='application/pdf'));assert.ok(message.Subject.includes('F001-00000001'))
 const evidence=path.join(__dirname,'..','docs','capturas','pdf-correo');fs.mkdirSync(evidence,{recursive:true});await send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:false});await evaluate('document.querySelector('+JSON.stringify('[aria-label="PDF privado y correo"]')+').scrollIntoView()');assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'),true);const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(evidence,'entrega-movil.png'),Buffer.from(screenshot.data,'base64'))
 await send('Page.reload');await waitText('Aceptado por el transporte');await signout();await signin('accountant@delivery.test');await send('Page.navigate',{url:origin+route});await waitText('PDF privado y correo');assert.equal(await has('Enviar por correo'),false);assert.equal(await has(manifest.recipient),false);assert.equal((await api('/api/v1'+route)).body.data.delivery.pdf.available,true)
 await signout();await signin('foreign@delivery.test');assert.equal((await api('/api/v1'+route+'/pdf')).status,404)
 assert.deepEqual(browserErrors,[]);console.log('PASS delivery browser: private PDF download, captured recipient, queued email, Mailpit observable with PDF and plain text, readonly accountant, tenant 404, mobile, no JS exceptions')
 fs.writeFileSync(path.join(evidence,'mailpit-evidence.json'),JSON.stringify({deliveryId:delivery.id,messageId:delivery.message_id,mailpitId:mail.ID,subject:message.Subject,attachments:message.Attachments.map(a=>({name:a.FileName,type:a.ContentType,size:a.Size})),result:'PASS'},null,2))

}
main().catch(async error=>{console.error(error.stack);console.error(await evaluate('JSON.stringify({path:location.pathname,text:document.body.innerText})').catch(()=>''));process.exitCode=1}).finally(async()=>{if(socket?.readyState===WebSocket.OPEN){await send('Browser.close').catch(()=>{});socket.close()}if(browser&&browser.exitCode===null)browser.kill()})
