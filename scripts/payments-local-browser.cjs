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
 const evidence=path.join(__dirname,'..','docs','capturas','cobranza');fs.mkdirSync(evidence,{recursive:true})
 const report={company,pages:[],checks:[],scenarios:[]}
 async function capture(name){const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(evidence,name+'.png'),Buffer.from(screenshot.data,'base64'))}
 await signin(process.env.FACTURLY_DEMO_EMAIL);assert.equal((await api('me')).json.data.company.id,company)

 const acceptedId=docId('aceptado-factura-pen');
 await navigate('/documents/'+acceptedId+'?payment_return=1&status=approved');await waitText('Cobranza');await waitText('deshabilitada');await waitText('El retorno no confirma un pago');
 const balance=(await api('documents/'+acceptedId+'/collections')).json.data;assert.equal(balance.paid_cents,0);assert.equal(balance.outstanding_cents,4065);assert.equal(balance.enabled,false);assert.equal(balance.can_create,false);assert.equal((await api('documents/'+acceptedId)).json.data.status,'accepted');assert.equal(await has('Crear enlace de pago'),false);report.checks.push('Backend balance 40.65 PEN; forged approved return cannot change financial or fiscal state');
 await evaluate('document.querySelector(".collections-panel").scrollIntoView({block:"start"})');await capture('desktop');
 await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await evaluate('document.querySelector(".collections-panel").scrollIntoView({block:"start"})');await capture('mobile');assert.ok(await evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'));report.checks.push('Mobile 390px: no horizontal overflow');
 await navigate('/documents/'+docId('aceptado-factura-usd'));await waitText('No se convierte USD');await capture('usd');
 await signout();await signin('accountant@alfa.example.test');await navigate('/documents/'+acceptedId);await waitText('Cobranza');const readonly=(await api('documents/'+acceptedId+'/collections')).json.data;assert.equal(readonly.can_create,false);assert.equal(readonly.can_reconcile,false);report.checks.push('Accountant can read balance, without mutation controls');report.external_sandbox='NOT RUN: credentials and authorized public URL absent';
 assert.deepEqual(browserErrors,[]);report.result='PASS';fs.writeFileSync(path.join(evidence,'verification.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2))
}
main().catch(async error=>{console.error(error.stack);console.error(await evaluate('JSON.stringify({path:location.pathname+location.search,text:document.body.innerText})').catch(()=>''));process.exitCode=1}).finally(async()=>{if(socket?.readyState===WebSocket.OPEN){await send('Browser.close').catch(()=>{});socket.close()}if(browser&&browser.exitCode===null)browser.kill()})
