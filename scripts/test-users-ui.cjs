// Browser smoke tests with an isolated, mocked API. No development database writes.
// Requires Node 22+, Chrome/Chromium and the frontend at FRONTEND_URL (default localhost:5173).
// Run: node scripts/test-users-ui.cjs (optionally set CHROME_PATH).
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { spawn } = require('node:child_process')
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const chrome = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe'
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'facturly-ui-'))
let browser, socket
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
async function submit() {
  await evaluate('document.querySelector(".user-form").requestSubmit()')
}
async function openUsers() {
  await waitText('Hola,')
  await click('Usuarios')
  await waitText('Nuevo usuario')
}
async function editSelf() {
  await evaluate('document.querySelectorAll(".user-card button")[0].click()')
  await waitText('Editar usuario')
}
async function reset() {
  await send('Page.reload')
  await openUsers()
}
function mockApi() {
  const company = { id: 'company-1', legal_name: 'Empresa de prueba', trade_name: null }
  const owner = { id: 'owner-1', name: 'Dueño de prueba', email: 'owner@example.test', role: 'owner', role_label: 'Dueño', is_active: true, created_at: null }
  window.mock = { users: [owner], current: { ...owner, company }, requests: [], failure: null, hold: false, confirm: true, confirmations: 0, logoutFails: false }
  window.confirm = () => { mock.confirmations++; return mock.confirm }
  const role = sessionStorage.getItem('ui-role') || 'owner'
  mock.current.role = role
  mock.current.role_label = { owner: 'Dueño', issuer: 'Emisor', accountant: 'Contador' }[role]
  mock.current.name = 'Alex Rivera'
  document.cookie = 'XSRF-TOKEN=test-csrf; path=/'
  window.fetch = async (url, options = {}) => {
    const method = options.method || 'GET'
    const pathname = String(url).split('?')[0]
    const respond = (status, body) => new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
    if (pathname === '/sanctum/csrf-cookie') return respond(204)
    if (pathname === '/login') { sessionStorage.removeItem('ui-guest'); return respond(204) }
    if (pathname === '/api/v1/me') return sessionStorage.getItem('ui-guest') ? respond(401, {}) : respond(200, { data: mock.current })
    if (pathname.startsWith('/api') && mock.expired) return respond(401, {})
    if (pathname === '/api/v1/company') {
      mock.company ??= { ...company, ruc: '20131312955', address: 'Av. Ejemplo 120, Lima', email: 'empresa@example.test', is_active: true }
      if (method === 'PATCH') Object.assign(mock.company, JSON.parse(options.body))
      return respond(200, { data: mock.company })
    }
    if (['POST','PATCH'].includes(method) && mock.failure) return respond(mock.failure.status, mock.failure.body)
    if (pathname === '/logout') {
      mock.requests.push({ method, pathname })
      sessionStorage.setItem('ui-guest', '1')
      if (mock.logoutFails) throw new TypeError('Simulated offline logout')
      return respond(204)
    }
    if (pathname === '/api/v1/users' && method === 'GET') return respond(200, { data: mock.users, meta: { total: mock.users.length, current_page: 1, last_page: 1, per_page: 100 } })

    if (pathname.startsWith('/api/v1/series')) {
      if (mock.current.role !== 'owner') return respond(403, {})
      mock.series ??= Array.from({ length: 16 }, (_, i) => ({
        id: 'series-' + i, code: 'F' + String(i).padStart(3, '0'), doc_type: '01',
        current_number: i === 0 ? 42 : 0, is_active: true, created_at: null, updated_at: null,
      }))
      if (method === 'GET' && pathname === '/api/v1/series') {
        const params = new URL(String(url), location.origin).searchParams
        const filtered = mock.series.filter(s => (!params.get('doc_type') || s.doc_type === params.get('doc_type')) &&
          (!params.has('is_active') || s.is_active === (params.get('is_active') === '1')))
        const page = Number(params.get('page') || 1)
        return respond(200, { data: filtered.slice((page - 1) * 15, page * 15), meta: { current_page: page, last_page: Math.max(1, Math.ceil(filtered.length / 15)), total: filtered.length, per_page: 15 } })
      }
      const existing = mock.series.find(s => pathname.endsWith('/' + s.id))
      if (method === 'GET') return respond(existing ? 200 : 404, { data: existing })
      const body = JSON.parse(options.body)
      mock.requests.push({ method, pathname, keys: Object.keys(body).sort(), credentials: options.credentials, csrf: options.headers.get('X-XSRF-TOKEN') })
      const saved = method === 'POST' ? { ...body, code: body.code.trim().toUpperCase(), id: 'series-' + mock.series.length, current_number: 0, is_active: true, created_at: null, updated_at: null } : { ...existing, is_active: body.is_active }
      mock.series = method === 'POST' ? [...mock.series, saved] : mock.series.map(s => s.id === saved.id ? saved : s)
      return respond(method === 'POST' ? 201 : 200, { data: saved })
    }
    if (pathname.startsWith('/api/v1/products')) {
      mock.products ??= Array.from({ length: 16 }, (_, i) => ({
        id: 'product-' + i, description: 'Producto ' + String(i).padStart(2, '0'),
        unit_code: 'NIU', currency: 'PEN', unit_price_cents: 1050, tax_type: '10', code: String(i).padStart(8, '0'),
        is_active: true, created_at: null, updated_at: null,
      }))
      if (method === 'GET' && pathname === '/api/v1/products') {
        const params = new URL(String(url), location.origin).searchParams
        const query = (params.get('q') || '').toLowerCase()
        const active = params.get('is_active')
        const filtered = mock.products.filter(c => (c.description.toLowerCase().includes(query) || c.code.includes(query)) && (active === null || c.is_active === (active === '1')) && (!params.get('currency') || c.currency === params.get('currency')) && (!params.get('tax_type') || c.tax_type === params.get('tax_type')))
        const page = Number(params.get('page') || 1)
        return respond(200, { data: filtered.slice((page - 1) * 15, page * 15), meta: { current_page: page, last_page: Math.max(1, Math.ceil(filtered.length / 15)), total: filtered.length, per_page: 15 } })
      }
      const existing = mock.products.find(c => pathname.endsWith('/' + c.id))
      if (method === 'GET') return respond(existing ? 200 : 404, { data: existing })
      const body = JSON.parse(options.body)
      mock.requests.push({ method, pathname, keys: Object.keys(body).sort(), credentials: options.credentials, csrf: options.headers.get('X-XSRF-TOKEN') })
      const saved = method === 'POST'
        ? { ...body, id: 'product-' + mock.products.length, is_active: true, created_at: null, updated_at: null }
        : { ...existing, ...body }
      mock.products = method === 'POST' ? [...mock.products, saved] : mock.products.map(c => c.id === saved.id ? saved : c)
      return respond(method === 'POST' ? 201 : 200, { data: saved })
    }
    if (pathname.startsWith('/api/v1/customers')) {
      mock.customers ??= Array.from({ length: 16 }, (_, i) => ({
        id: 'customer-' + i, name: 'Cliente ' + String(i).padStart(2, '0'),
        doc_type: 'dni', doc_number: String(i).padStart(8, '0'),
        address: null, email: null, is_active: true, created_at: null, updated_at: null,
      }))
      if (method === 'GET' && pathname === '/api/v1/customers') {
        const params = new URL(String(url), location.origin).searchParams
        const query = (params.get('q') || '').toLowerCase()
        const active = params.get('is_active')
        const filtered = mock.customers.filter(c => (c.name.toLowerCase().includes(query) || c.doc_number.includes(query)) && (active === null || c.is_active === (active === '1')))
        const page = Number(params.get('page') || 1)
        return respond(200, { data: filtered.slice((page - 1) * 15, page * 15), meta: { current_page: page, last_page: Math.max(1, Math.ceil(filtered.length / 15)), total: filtered.length, per_page: 15 } })
      }
      const existing = mock.customers.find(c => pathname.endsWith('/' + c.id))
      if (method === 'GET') return respond(existing ? 200 : 404, { data: existing })
      const body = JSON.parse(options.body)
      mock.requests.push({ method, pathname, keys: Object.keys(body).sort(), credentials: options.credentials, csrf: options.headers.get('X-XSRF-TOKEN') })
      const saved = method === 'POST'
        ? { ...body, id: 'customer-' + mock.customers.length, is_active: true, created_at: null, updated_at: null }
        : { ...existing, ...body }
      mock.customers = method === 'POST' ? [...mock.customers, saved] : mock.customers.map(c => c.id === saved.id ? saved : c)
      return respond(method === 'POST' ? 201 : 200, { data: saved })
    }
    if (method === 'POST' || method === 'PATCH') {
      const body = JSON.parse(options.body)
      // Record only keys and transport settings, never password values.
      mock.requests.push({ method, pathname, keys: Object.keys(body).sort(), credentials: options.credentials, csrf: options.headers.get('X-XSRF-TOKEN') })
      while (mock.hold) await new Promise((resolve) => setTimeout(resolve, 20))
      if (mock.failure === 'network') throw new TypeError('Simulated offline')
      if (mock.failure) return respond(mock.failure.status, mock.failure.body)
      const labels = { owner: 'Dueño', issuer: 'Emisor', accountant: 'Contador' }
      const saved = method === 'POST'
        ? { id: 'user-' + mock.users.length, name: body.name, email: body.email, role: body.role, is_active: true, created_at: null }
        : { ...mock.users.find((u) => pathname.endsWith('/' + u.id)), ...body }
      saved.role_label = labels[saved.role]
      mock.users = method === 'POST' ? [...mock.users, saved] : mock.users.map((u) => u.id === saved.id ? saved : u)
      return respond(method === 'POST' ? 201 : 200, { data: saved })
    }
    throw Error('Unexpected API call: ' + method + ' ' + pathname)
  }
}
async function main() {
  browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=0', '--user-data-dir=' + profile, '--no-first-run', '--no-default-browser-check', 'about:blank'], { windowsHide: true, stdio: 'ignore' })
  browser.on('error', (error) => { console.error(error.stack) })
  const portFile = path.join(profile, 'DevToolsActivePort')
  await waitFor(() => fs.existsSync(portFile), 'Chrome startup')
  const port = fs.readFileSync(portFile, 'utf8').split('\n')[0].trim()
  const targets = await (await fetch('http://127.0.0.1:' + port + '/json')).json()
  socket = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl)
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject })
  socket.onmessage = (event) => {
    const message = JSON.parse(event.data)
    if (!message.id) return
    const task = pending.get(message.id)
    if (!task) return
    pending.delete(message.id)
    message.error ? task.reject(new Error(message.error.message)) : task.resolve(message.result)
  }
  await send('Page.enable')
  await send('Page.addScriptToEvaluateOnNewDocument', { source: '(' + mockApi.toString() + ')()' })
  await send('Page.navigate', { url: (process.env.FRONTEND_URL || 'http://localhost:5173') + '/customers' })

  const origin = process.env.FRONTEND_URL || 'http://localhost:5173'
  const evidence = path.join(__dirname, '..', 'docs', 'capturas', 'redisenio-frontend')
  fs.mkdirSync(evidence, { recursive: true })
  async function screenshot(name) {
    const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })
    fs.writeFileSync(path.join(evidence, name + '.png'), Buffer.from(data, 'base64'))
  }
  async function width(value) {
    await send('Emulation.setDeviceMetricsOverride', { width: value, height: 980, deviceScaleFactor: 1, mobile: false })
    await delay(150)
  }
  async function direct(route) { console.log('CHECK direct ' + route); await send('Page.navigate', { url: origin + route }); await delay(250) }
  async function reload() { await send('Page.reload'); await delay(250) }
  async function assertNoOverflow() { assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true) }
  await width(1440)
  await waitText('16 clientes encontrados')
  assert.equal(await evaluate('location.pathname'), '/customers')
  await screenshot('clientes-escritorio')
  await fill('customer-search', 'Cliente 00')
  await waitText('1 clientes encontrados')
  assert.equal(await evaluate('new URLSearchParams(location.search).get("q")'), 'Cliente 00')
  await reload()
  await waitText('1 clientes encontrados')
  assert.equal(await evaluate('document.getElementById("customer-search").value'), 'Cliente 00')
  await click('Productos y servicios')
  await waitText('16 productos encontrados')
  await evaluate('history.back()')
  await waitText('1 clientes encontrados')
  await evaluate('history.forward()')
  await waitText('16 productos encontrados')
  console.log('PASS private reload, URL filters, back/forward and real data tables')

  await click('Nuevo producto o servicio')
  await fill('product-code', '001-SERV')
  await fill('product-description', 'Servicio de soporte mensual')
  await fill('product-price', '10,50')
  await fill('product-unit_code', 'ZZ')
  await screenshot('producto-formulario-escritorio')
  const previousConfirmations = await evaluate('mock.confirmations')
  await evaluate('mock.confirm = false')
  await click('Clientes')
  await waitFor(() => evaluate('mock.confirmations > ' + previousConfirmations), 'unsaved confirmation')
  await delay(100)
  assert.equal(await evaluate('location.pathname'), '/products/new')
  await evaluate('mock.confirm = true')
  await click('Guardar producto')
  await waitText('Producto creado correctamente.')
  assert.equal(await evaluate('mock.products.at(-1).unit_price_cents'), 1050)
  await fill('product-search', 'Servicio de soporte mensual')
  await waitText('1 productos encontrados')
  await click('Ver detalle')
  await click('Editar producto')
  await fill('product-description', 'Servicio actualizado')
  await click('Guardar producto')
  await waitText('Producto guardado correctamente.')
  await click('Desactivar')
  await waitFor(() => has('Reactivar'), 'reactivate')
  await click('Reactivar')
  console.log('PASS product create/edit/state, exact money and unsaved navigation protection')

  await click('Clientes')
  await click('Nuevo cliente')
  await fill('customer-doc_type', 'dni')
  await fill('customer-doc_number', '00123456')
  await fill('customer-name', 'Cliente de prueba visual')
  await evaluate('mock.failure = {status:422,body:{errors:{doc_number:["Documento duplicado."]}}}')
  await click('Guardar cliente')
  await waitText('Documento duplicado.')
  assert.equal(await evaluate('document.getElementById("customer-doc_number").getAttribute("aria-invalid")'), 'true')
  await evaluate('mock.failure = null')
  await click('Guardar cliente')
  await waitText('Cliente creado correctamente.')
  await fill('customer-search', 'Cliente de prueba visual')
  await waitText('1 clientes encontrados')
  await click('Ver detalle')
  await click('Editar cliente')
  await fill('customer-name', 'Cliente actualizado')
  await click('Guardar cliente')
  await waitText('Cliente guardado correctamente.')

  await click('Usuarios')
  await waitText('1 usuario registrado')
  await click('Nuevo usuario')
  await fill('user-name', 'Persona de prueba')
  await fill('user-email', 'person@example.test')
  await fill('user-password', 'TestOnlyPassword123')
  await fill('user-password_confirmation', 'TestOnlyPassword123')
  await evaluate('mock.hold = true')
  await click('Crear usuario')
  await waitText('Guardando usuario')
  await evaluate('document.querySelector(".user-form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))')
  assert.equal(await evaluate('mock.requests.filter(r=>r.pathname==="/api/v1/users"&&r.method==="POST").length'), 1)
  await evaluate(`document.querySelector('.sidebar a[href="/customers"]').click()`)
  assert.equal(await evaluate('location.pathname'), '/users/new')
  await evaluate('mock.hold = false')
  await waitText('2 usuarios registrados')
  await clickSelector('tbody tr:last-child button')
  await fill('user-name', 'Persona actualizada')
  await click('Guardar cambios')
  await waitText('Persona actualizada')
  await clickSelector('tbody tr:first-child button')
  await fill('user-role', 'accountant')
  await evaluate('mock.confirm = false')
  await click('Guardar cambios')
  assert.equal(await evaluate('location.pathname.includes("owner-1")'), true)
  await evaluate('mock.confirm = true; mock.failure={status:422,body:{errors:{role:["La empresa debe conservar al menos un dueño activo."]}}}')
  await click('Guardar cambios')
  await waitText('La empresa debe conservar al menos un dueño activo.')
  await evaluate('mock.failure = null')
  await click('Guardar cambios')
  await waitText('Hola, Dueño de prueba')
  assert.equal(await evaluate('location.pathname'), '/account')
  assert.equal(await evaluate('[...document.querySelectorAll(".sidebar a")].some(a=>a.textContent==="Usuarios")'), false)
  console.log('PASS customer/user create and edit, validation, last-owner error, repeated submit and navigation lock')

  await reload()
  await waitText('Hola, Alex Rivera')
  await click('Mi empresa')
  await waitText('20131312955')
  await click('Editar datos')
  await fill('company-address', 'Nueva dirección de prueba')
  await click('Guardar cambios')
  await waitText('Nueva dirección de prueba')
  await click('Series')
  await waitText('16 series encontradas')
  await click('Nueva serie')
  await fill('series-code', 'bc01')
  await fill('series-doc_type', '07')
  await click('Crear serie')
  await waitText('Serie creada correctamente.')
  await fill('series-type', '07')
  await waitText('1 series encontradas')
  await click('Ver detalle')
  await waitText('Sin emisiones')
  await click('Desactivar')
  await waitFor(() => evaluate('[...document.querySelectorAll("button")].some(b=>b.textContent==="Activar")'), 'activate')
  await click('Activar')
  await waitFor(() => evaluate('[...document.querySelectorAll("button")].some(b=>b.textContent==="Desactivar"&&!b.disabled)'), 'series activation complete')
  await delay(100)
  assert.equal(await evaluate('mock.series.at(-1).current_number'), 0)
  console.log('PASS company and series forms, readonly identifiers and unchanged numbering')

  for (const role of ['issuer','accountant']) {
    await evaluate('sessionStorage.setItem("ui-role",' + JSON.stringify(role) + ')')
    await direct('/series')
    await waitText('Acceso restringido')
    await direct('/products')
    await waitText('16 productos encontrados')
    assert.equal(await has('Nuevo producto o servicio'), role === 'issuer')
    await direct('/products/new')
    await waitText(role === 'issuer' ? 'Guardar producto' : 'Tu rol solo permite consultar.')
  }
  await evaluate('sessionStorage.setItem("ui-role","owner")')
  await direct('/customers')
  await waitText('16 clientes encontrados')
  for (const size of [360,390,768,1024,1440]) {
    console.log('CHECK width ' + size)
    await width(size)
    await assertNoOverflow()
    if (size < 1024) {
      await clickSelector('[aria-label="Abrir menú"]')
      assert.equal(await evaluate('document.querySelector("dialog").open'), true)
      for (let i=0;i<15;i++) { await press('Tab'); assert.equal(await evaluate('document.querySelector("dialog").contains(document.activeElement)'), true) }
      if (size === 390) await screenshot('navegacion-movil')
      await press('Escape')
      assert.equal(await evaluate('document.activeElement.getAttribute("aria-label")'), 'Abrir menú')
      await clickSelector('[aria-label="Abrir menú"]')
      await click('Productos y servicios')
      await waitFor(() => evaluate('Boolean(mock.products) && document.body.innerText.includes(mock.products.length + " productos encontrados")'), 'product list ready')
      assert.equal(await evaluate('document.querySelector("dialog").open'), false)
      await assertNoOverflow()
      if (size === 390) await screenshot('productos-movil')
      await click('Nuevo producto o servicio')
      await fill('product-code', 'MOV' + size)
      await fill('product-description', 'Producto desde móvil')
      await fill('product-price', '0,35')
      await click('Guardar producto')
      await waitText('Producto creado correctamente.')
      await assertNoOverflow()
      await clickSelector('[aria-label="Abrir menú"]')
      await click('Clientes')
      await waitText('16 clientes encontrados')
    }
  }
  await width(1440)
  await evaluate('mock.expired = true')
  await click('Productos y servicios')
  await waitText('Inicia sesión')
  assert.equal(await evaluate('location.pathname'), '/acceso')
  await evaluate('mock.expired=false;sessionStorage.setItem("ui-guest","1")')
  await reload()
  await waitText('Inicia sesión')
  await screenshot('acceso')
  await clickSelector('[aria-label="Mostrar contraseña"]')
  assert.equal(await evaluate('document.getElementById("password").type'), 'text')
  await fill('email', 'demo@example.test')
  await fill('password', 'SyntheticPassword123')
  await click('Ingresar')
  await waitFor(() => evaluate('Boolean(mock.products) && document.body.innerText.includes(mock.products.length + " productos encontrados")'), 'product list ready')
  await clickSelector('.user-menu summary')
  await click('Cerrar sesión')
  await waitText('Inicia sesión')
  assert.equal(await evaluate('document.querySelector(".sidebar")'), null)
  await reload()
  await waitText('Inicia sesión')
  console.log('PASS roles/direct URLs, mobile drawer/focus/Escape, 360/390/768/1024/1440 reflow, mobile create, session expiration, login/logout and access reload')
  console.log('Screenshots: docs/capturas/redisenio-frontend (5 PNG files)')
}
main().catch(async (error) => { console.error(error.stack); console.error(await evaluate('JSON.stringify({path:location.pathname, buttons:[...document.querySelectorAll("button")].map(b=>({text:b.textContent,disabled:b.disabled})), alerts:[...document.querySelectorAll("[role=alert]")].map(e=>e.textContent)})').catch(()=>'')); process.exitCode = 1 }).finally(async () => {
  if (socket?.readyState === WebSocket.OPEN) {
    await send('Browser.close').catch(() => {})
    socket.close()
  }
  if (browser && browser.exitCode === null) browser.kill()
  // Leave the isolated Chrome profile in the OS temporary directory.
})
