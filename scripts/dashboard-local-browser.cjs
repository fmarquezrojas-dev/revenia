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
 const company='01a1c200-c32a-73cd-939c-6b7535d596f3'
 browser=spawn(chrome,['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','about:blank'],{windowsHide:true,stdio:'ignore'})
 const portFile=path.join(profile,'DevToolsActivePort');await waitFor(()=>fs.existsSync(portFile),'Chrome startup')
 const port=fs.readFileSync(portFile,'utf8').split('\n')[0].trim();const targets=await(await fetch('http://127.0.0.1:'+port+'/json')).json()
 socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject})
 socket.onmessage=event=>{const message=JSON.parse(event.data);if(message.method==='Runtime.exceptionThrown')browserErrors.push(message.params.exceptionDetails);if(message.method==='Page.javascriptDialogOpening'){void send('Page.handleJavaScriptDialog',{accept:acceptDialog});acceptDialog=false;return}if(!message.id)return;const task=pending.get(message.id);if(!task)return;pending.delete(message.id);message.error?task.reject(new Error(message.error.message)):task.resolve(message.result)}
 await send('Runtime.enable');await send('Page.enable');await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await send('Page.navigate',{url:origin+'/'})


 async function signin(email){await waitText('Inicia sesi\u00f3n');await fill('email',email);await fill('password',process.env.FACTURLY_DEMO_PASSWORD);await click('Ingresar');await waitFor(()=>evaluate('Boolean(document.querySelector(".user-menu"))'),'authenticated shell')}
 async function signout(){await clickSelector('.user-menu summary');await click('Cerrar sesi\u00f3n');await waitText('Inicia sesi\u00f3n')}
 async function api(route){return evaluate('(async()=>{const r=await fetch('+JSON.stringify('/api/v1/'+route)+',{headers:{Accept:"application/json"}});return {status:r.status,json:await r.json()}})()')}
 async function navigate(route){await send('Page.navigate',{url:origin+route});await waitFor(()=>evaluate('!!document.querySelector(".user-menu")&&!document.body.innerText.includes("Cargando")'),'loaded '+route)}
 const evidence=path.join(__dirname,'..','docs','capturas','dashboard');fs.mkdirSync(evidence,{recursive:true})
 const report={company,pages:[],checks:[],scenarios:[]}
 async function capture(name){const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(evidence,name+'.png'),Buffer.from(screenshot.data,'base64'))}
 await signin(process.env.FACTURLY_DEMO_EMAIL);assert.equal((await api('me')).json.data.company.id,company)



 await waitText('Cartera actual');assert.equal(await evaluate('location.pathname'),'/dashboard');
 const dashboard=(await api('dashboard')).json.data;
 assert.equal(dashboard.activity.collected_cents,'0');assert.equal(dashboard.activity.credit_notes_supported,true);assert.equal(dashboard.usage.consumed,6);
 const details=(await api('dashboard?metric=accepted')).json.data.detail;
 assert.equal(details.rows.reduce((sum,row)=>sum+BigInt(row.amount_cents),0n).toString(),dashboard.activity.accepted_cents);
 report.metrics={currency:dashboard.currency,period:dashboard.period,activity:dashboard.activity,portfolio:dashboard.portfolio};
 report.checks.push('Initial route is dashboard; accepted amount reconciles with exact detail; no invented payments');
 await capture('resumen-desktop');
 await clickSelector('a.dashboard-card[href*="metric=accepted"]');await waitText('Primera fecha de resultado');
 assert.ok(await evaluate('location.search.includes("metric=accepted")'));
 await send('Page.reload');await waitText('Primera fecha de resultado');
 await evaluate('history.back()');await waitText('Cartera actual');await evaluate('history.forward()');await waitText('Primera fecha de resultado');
 if(details.rows.length){await clickSelector('.dashboard-table a');await waitText('Aceptado');await evaluate('history.back()');await waitText('Primera fecha de resultado')}
 report.checks.push('Metric links, direct reload, document detail, back and forward');
 await navigate('/dashboard');await waitText('Cartera actual');await click('Mes anterior');await waitText('No hay actividad en este periodo.');
 assert.equal((await api('dashboard?'+await evaluate('location.search.slice(1)'))).json.data.activity.accepted_cents,'0');
 await click('Mes actual');await waitFor(()=>evaluate('document.getElementById("dashboard-from").value === '+JSON.stringify(dashboard.period.from)), 'current month form');await waitText('Cartera actual');await fill('dashboard-currency','USD');await click('Aplicar filtros');await waitFor(()=>evaluate('location.search.includes("currency=USD")'),'USD filter');await waitFor(()=>evaluate('!document.body.innerText.includes("Cargando resumen")'),'USD data');
 await waitText('Importes en USD');report.checks.push('Previous month zeroes; USD kept separate');
 for(const width of [360,768,1280]){
   await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});await navigate('/dashboard');await waitText('Cartera actual');
   assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));await capture('resumen-'+width);
   if(width===360){await clickSelector('button[aria-label="Abrir men\u00fa"]');await press('Escape');assert.ok(await evaluate('document.activeElement?.getAttribute("aria-label")==="Abrir men\u00fa"'));report.checks.push('Mobile menu Escape restores focus')}
 }
 report.checks.push('360, 768, 1280px without page overflow');
 for(const route of ['/customers','/products','/users','/series','/company','/account','/subscription','/documents']){await navigate(route);assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));report.pages.push(route)}
 await navigate('/not-a-page');await waitText('No encontramos esta p\u00e1gina');
 await signout();await signin('issuer@alfa.example.test');await navigate('/dashboard');await waitText('Cartera actual');assert.equal(await has('Gestionar plan y consumo'),false);assert.ok(await has('Crear comprobante'));await navigate('/users');await waitText('Acceso restringido');
 await signout();await signin('accountant@alfa.example.test');await navigate('/dashboard');await waitText('Cartera actual');assert.equal(await has('Crear comprobante'),false);assert.equal(await has('Gestionar plan y consumo'),false);report.checks.push('Role actions and direct restricted route; logout clears session');
 report.external_sandbox='NOT RUN: credentials and authorized public URL absent';
 assert.deepEqual(browserErrors,[]);report.result='PASS';fs.writeFileSync(path.join(evidence,'verification.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2))
}
main().catch(async error=>{console.error(error.stack);console.error(await evaluate('JSON.stringify({path:location.pathname+location.search,text:document.body.innerText})').catch(()=>''));process.exitCode=1}).finally(async()=>{if(socket?.readyState===WebSocket.OPEN){await send('Browser.close').catch(()=>{});socket.close()}if(browser&&browser.exitCode===null)browser.kill()})
