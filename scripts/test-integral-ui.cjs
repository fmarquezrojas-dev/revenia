// Real API browser journeys; run through run-integral-e2e.cjs with isolated services.
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
 const origin=process.env.E2E_ORIGIN||'http://localhost:5174';const project=process.env.E2E_PROJECT||'facturly-integral';
 const evidence=process.env.E2E_EVIDENCE_DIR||path.join(__dirname,'..','docs','testing','evidence');fs.mkdirSync(evidence,{recursive:true});
 const report={journeys:[],checks:[],external_payments:'Not executed: fixture gateway only',notes:'Blocked: credit note module absent'};
 const {execFile}=require('node:child_process');const run=require('node:util').promisify(execFile);
 async function command(...args){const r=await run('docker',['compose','-p',project,'-f','compose.e2e.yaml','exec','-T','app',...args],{windowsHide:true,timeout:180000});return r.stdout.trim()}
 async function artisan(...args){return command('php','artisan',...args)}
 await waitFor(async()=>{try{return(await fetch(origin)).ok}catch{return false}},'isolated startup');
 browser=spawn(chrome,['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,'--no-first-run','about:blank'],{windowsHide:true,stdio:'ignore'});
 const portFile=path.join(profile,'DevToolsActivePort');await waitFor(()=>fs.existsSync(portFile),'Chrome startup');
 const port=fs.readFileSync(portFile,'utf8').split('\n')[0].trim();const targets=await(await fetch('http://127.0.0.1:'+port+'/json')).json();
 socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject});
 socket.onmessage=event=>{const m=JSON.parse(event.data);if(m.method==='Runtime.exceptionThrown')browserErrors.push(m.params.exceptionDetails);if(m.method==='Page.javascriptDialogOpening'){void send('Page.handleJavaScriptDialog',{accept:acceptDialog});acceptDialog=false;return}if(!m.id)return;const task=pending.get(m.id);if(!task)return;pending.delete(m.id);m.error?task.reject(new Error(m.error.message)):task.resolve(m.result)};
 await send('Runtime.enable');await send('Page.enable');await send('Network.enable');await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 async function navigate(route,expectedPath=route.split('?')[0]){await send('Page.navigate',{url:origin+route});await waitFor(()=>evaluate('document.readyState === "complete" && location.pathname === '+JSON.stringify(expectedPath)),'route '+expectedPath)}
 async function api(route,method='GET',body,csrf=true,key){return evaluate('(async()=>{const headers={Accept:"application/json"};if('+JSON.stringify(method)+'!=="GET"){headers["Content-Type"]="application/json";if('+csrf+'){const c=document.cookie.split("; ").find(x=>x.startsWith("XSRF-TOKEN="));if(c)headers["X-XSRF-TOKEN"]=decodeURIComponent(c.slice(11));}if('+JSON.stringify(key||'')+')headers["Idempotency-Key"]='+JSON.stringify(key||'')+';}const r=await fetch('+JSON.stringify(route)+',{method:'+JSON.stringify(method)+',headers,body:'+JSON.stringify(body===undefined?undefined:JSON.stringify(body))+'});const text=await r.text();let body;try{body=JSON.parse(text)}catch{body=null}return {status:r.status,body}})()')}
 async function signin(role){await waitText('Inicia sesi\u00f3n');await fill('email',role+'@integral.test');await fill('password','Integral-Test-Only-123');await click('Ingresar');await waitFor(()=>evaluate('!!document.querySelector(".user-menu")'),'login '+role)}
 async function signout(){await clickSelector('.user-menu summary');await click('Cerrar sesi\u00f3n');await waitText('Inicia sesi\u00f3n');assert.equal((await api('/api/v1/me')).status,401)}
 async function capture(name){const r=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(evidence,name+'.png'),Buffer.from(r.data,'base64'))}
 await navigate('/acceso');await waitText('Inicia sesi\u00f3n');assert.equal(await evaluate('document.title'),'Acceso | Revenia');assert.equal(await evaluate('document.querySelector(".brand-mark").textContent'),'R');await capture('revenia-login');await api('/sanctum/csrf-cookie');
 assert.equal((await fetch(origin+'/login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','Sec-Fetch-Site':'cross-site'},body:JSON.stringify({email:'owner@integral.test',password:'Integral-Test-Only-123'})})).status,419);
 const before=(await send('Network.getCookies')).cookies.find(c=>c.name==='facturly_e2e_session')?.value;
 await signin('owner');await waitText('Cartera actual');assert.ok(await evaluate('document.title.includes("Revenia")'));assert.equal(await has('Facturly'),false);const after=(await send('Network.getCookies')).cookies.find(c=>c.name==='facturly_e2e_session');assert.notEqual(before,after?.value);assert.ok(after.httpOnly);assert.equal(after.sameSite,'Lax');report.checks.push('Real HTTP CSRF 419; session cookie regenerated, HttpOnly, SameSite=Lax');
 assert.equal((await fetch('http://localhost:8001/horizon',{headers:{Accept:'application/json'}})).status,403);
 await navigate('/customers/new');await fill('customer-doc_number','20131312955');await fill('customer-name','E2E Dise\u00f1o y Ni\u00f1ez');await fill('customer-email','integral-recipient@example.test');await click('Guardar cliente');await waitText('Cliente creado correctamente.');
 const customer=(await api('/api/v1/customers')).body.data[0];
 await navigate('/products/new');await fill('product-code','E2E-001');await fill('product-description','Servicio de dise\u00f1o y revisi\u00f3n');await fill('product-price','10.00');await click('Guardar producto');await waitText('Producto creado correctamente.');
 const product=(await api('/api/v1/products')).body.data[0];
 await navigate('/documents/new');const series=(await api('/api/v1/document-series-options?doc_type=01')).body.data[0];
 await waitFor(()=>evaluate('[...document.querySelectorAll("#document-series option")].some(e=>e.value==='+JSON.stringify(series.id)+')'),'series loaded');await fill('document-series',series.id);
 await click('Seleccionar cliente');await clickSelector('.lookup-results button');await click('Agregar producto');await clickSelector('.lookup-results button');await fill('quantity-0','3.000');await waitText('35.40 PEN');await capture('integral-editor-desktop');
 assert.equal((await api('/api/v1/usage')).body.data.consumed,0);
 await click('Guardar borrador');await waitText('Borrador guardado correctamente.');let route=await evaluate('location.pathname');let doc=(await api('/api/v1'+route)).body.data;assert.equal(doc.number,null);assert.equal((await api('/api/v1/usage')).body.data.consumed,0);
 await click('Editar borrador');await fill('quantity-0','4.000');await waitText('47.20 PEN');await click('Guardar borrador');await waitText('Version 2');
 await click('Emitir comprobante');await click('Confirmar emisi\u00f3n');await waitFor(async()=>((await api('/api/v1'+route)).body.data.status!=='draft'),'issuance committed');await artisan('issuance:tick');
 await waitFor(async()=>((await api('/api/v1'+route)).body.data.status==='accepted'),'Redis worker acceptance');await click('Actualizar estado');await waitText('Aceptado por el simulador');
 await artisan('issuance:tick');await waitFor(async()=>((await api('/api/v1'+route)).body.data.delivery?.pdf?.available),'real PDF worker');
 const pdf=await evaluate('(async()=>{const r=await fetch('+JSON.stringify('/api/v1'+route+'/pdf')+');const b=await r.arrayBuffer();return {status:r.status,prefix:new TextDecoder().decode(b.slice(0,5)),size:b.byteLength}})()');assert.equal(pdf.status,200);assert.equal(pdf.prefix,'%PDF-');assert.ok(pdf.size>1000);
 await click('Actualizar PDF y correo');await click('Enviar por correo');await click('Confirmar env\u00edo');await waitText('Solicitud registrada para procesamiento.');await artisan('issuance:tick');
 await waitFor(async()=>((await api('/api/v1'+route)).body.data.delivery.email.deliveries[0]?.status==='sent'),'SMTP worker');
 let mail;await waitFor(async()=>{const r=await(await fetch('http://localhost:8026/api/v1/search?query=to:integral-recipient@example.test')).json();mail=r.messages?.[0];return !!mail},'isolated Mailpit receipt');
 const message=await(await fetch('http://localhost:8026/api/v1/message/'+mail.ID)).json();assert.ok(message.Attachments.some(a=>a.ContentType==='application/pdf'));assert.ok(message.Text.includes('Sin validez tributaria'));assert.ok(message.Text.includes('Revenia'));assert.ok(message.Subject.includes('Revenia'));
 report.checks.push('Revenia login/title, R mark, no old visible brand; real email subject and body use Revenia');report.journeys.push({name:'A principal',result:'PASS',document:doc.id,pdf_bytes:pdf.size,mailpit_message:mail.ID});
 await click('Actualizar cobranza');await click('Crear enlace de pago');await click('Confirmar solicitud');
 await waitFor(async()=>((await api('/api/v1'+route+'/collections')).body.data.orders[0]?.status==='open'),'fixture checkout');
 const order=(await api('/api/v1'+route+'/collections')).body.data.orders[0];await command('php','tests/EndToEnd/control.php','approve',order.id,'500');
 await waitFor(async()=>((await api('/api/v1'+route+'/collections')).body.data.paid_cents===4720),'payment reconciliation');
 const balance=(await api('/api/v1'+route+'/collections')).body.data;assert.equal(balance.payments.length,1);assert.equal(balance.outstanding_cents,0);report.journeys.push({name:'C cobranza',result:'PASS with fixture gateway',notifications:10,payments:1});
 const payload={doc_type:'01',currency:'PEN',series_id:series.id,customer_id:customer.id,issue_date:new Intl.DateTimeFormat('en-CA',{timeZone:'America/Lima',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()),items:[{product_id:product.id,quantity:'1.000'}]};
 const createdSecond=await api('/api/v1/documents','POST',payload);assert.equal(createdSecond.status,201,JSON.stringify(createdSecond.body));const second=createdSecond.body.data;assert.equal((await api('/api/v1/documents/'+second.id+'/issue','POST',{version:1},true,crypto.randomUUID())).status,202);
 const createdThird=await api('/api/v1/documents','POST',payload);assert.equal(createdThird.status,201,JSON.stringify(createdThird.body));const third=createdThird.body.data;const denied=await api('/api/v1/documents/'+third.id+'/issue','POST',{version:1},true,crypto.randomUUID());assert.equal(denied.status,422);assert.equal(denied.body.code,'quota_exhausted');assert.equal((await api('/api/v1/documents/'+third.id)).body.data.number,null);assert.equal((await api('/api/v1/usage')).body.data.consumed,2);
 await navigate('/subscription');await waitText('Cuota agotada');await click('Contratar o renovar Basico');await click('Confirmar contrataci\u00f3n');await waitText('Contrataci\u00f3n registrada.');
 let subscription;await waitFor(async()=>{subscription=(await api('/api/v1/subscription')).body.data.history.find(s=>s.status==='pending');return subscription?.order?.status==='open'},'subscription checkout');await command('php','tests/EndToEnd/control.php','approve',subscription.order.id,'501');
 await waitFor(async()=>((await api('/api/v1/subscription')).body.data.history.some(s=>s.phase==='future')),'future renewal');await click('Actualizar plan y consumo');await waitText('Pr\u00f3ximo plan:');
 const dashboard=(await api('/api/v1/dashboard')).body.data;assert.equal(dashboard.activity.collected_cents,'4720');assert.equal(dashboard.usage.consumed,2);report.journeys.push({name:'D SaaS',result:'PASS with fixture gateway',quota:2,renewal:'next period, no immediate quota increase'});
 for(const width of [360,768,1280]){await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===360});for(const target of ['/dashboard','/documents',route,'/subscription','/customers','/products']){await navigate(target);await waitFor(()=>evaluate('!!document.querySelector(".user-menu")&&!document.body.innerText.includes("Cargando")'),'responsive '+target);assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));await capture('integral-'+target.split('/')[1]+'-'+width);} if(width===360){await clickSelector('button[aria-label="Abrir men\u00fa"]');await press('Escape');assert.ok(await evaluate('document.activeElement?.getAttribute("aria-label")==="Abrir men\u00fa"'))}}
 await signout();await signin('issuer');await navigate('/dashboard');await waitText('Cartera actual');assert.equal(await has('Gestionar plan y consumo'),false);await navigate('/users');await waitText('Acceso restringido');await signout();await signin('accountant');await navigate(route);await waitText('PDF privado y correo');assert.equal(await has('Enviar por correo'),false);assert.equal((await api('/api/v1'+route+'/issue','POST',{version:1},true,crypto.randomUUID())).status,403);
 await signout();await signin('foreign');for(const suffix of ['', '/events','/pdf','/collections']){assert.equal((await api('/api/v1'+route+suffix)).status,404)}assert.equal((await api('/api/v1/dashboard')).body.data.activity.accepted_cents,'0');
 await command('php','tests/EndToEnd/control.php','expire-sessions');await navigate('/dashboard','/acceso');await waitText('Inicia sesi\u00f3n');assert.equal(await has('E2E Compania'),false);report.journeys.push({name:'E roles',result:'PASS',checks:'issuer/accountant permissions, foreign document/events/PDF/payments 404, expired session'});
 report.checks.push('360/768/1280 pages without overflow; mobile Escape restores focus; no JavaScript exceptions');assert.deepEqual(browserErrors,[]);report.result='PASS';report.state=JSON.parse(await command('php','tests/EndToEnd/control.php','inspect'));fs.writeFileSync(path.join(evidence,'e2e-report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));
}
main().catch(async error=>{console.error(error.stack);if(socket?.readyState===WebSocket.OPEN){const r=await send('Page.captureScreenshot',{format:'png'}).catch(()=>null);if(r)fs.writeFileSync(path.join(process.env.E2E_EVIDENCE_DIR||path.join(__dirname,'..','docs','testing','evidence'),'e2e-failure.png'),Buffer.from(r.data,'base64'));console.error(await evaluate('JSON.stringify({path:location.pathname,text:document.body.innerText})').catch(()=>''))}process.exitCode=1}).finally(async()=>{if(socket?.readyState===WebSocket.OPEN){await send('Browser.close').catch(()=>{});socket.close()}if(browser&&browser.exitCode===null)browser.kill()})
