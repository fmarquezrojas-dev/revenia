// Run only in the manual gallery job against its disposable Compose installation.
const { chromium } = require('playwright-core')
const fs = require('node:fs')
const path = require('node:path')
const { execFileSync } = require('node:child_process')

async function main() {
  if (process.env.GITHUB_ACTIONS !== 'true' || !/^revenia-install-\d+-\d+$/.test(process.env.COMPOSE_PROJECT_NAME || '')) {
    throw new Error('La captura requiere el proyecto aislado de GitHub Actions.')
  }
  const output = path.join(process.env.RUNNER_TEMP, 'revenia-gallery')
  fs.mkdirSync(output)
  const compose = (...args) => execFileSync('docker', ['compose', ...args], { encoding: 'utf8' })
  for (const example of ['galeria-venta', 'galeria-ajuste']) {
    compose('exec', '-T', 'app', 'php', 'artisan', 'demo:credit-note', example)
  }
  const cluster = compose('exec', '-T', 'db', 'psql', '-XAtq', '-U', 'facturly', '-d', 'facturly', '-c',
    'select system_identifier from pg_control_system()').trim()
  const ids = JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', '-e', 'REVENIA_GALLERY_CLUSTER='+cluster, 'app', 'php'],
    { input: fs.readFileSync(path.join(__dirname, 'fixtures.php')), encoding: 'utf8' }))
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] })
  try {
    const page = await browser.newPage({ viewport: { width: 1400, height: 960 }, locale: 'es-PE', timezoneId: 'America/Lima' })
    const base = 'http://localhost:5173'
    async function snap(name, route, target) {
      await page.goto(base + route, { waitUntil: 'networkidle' })
      await page.locator('.user-menu').waitFor()
      await page.evaluate(() => document.fonts.ready)
      if (target) {
        const section = page.locator(target).first()
        if (await section.count()) await section.evaluate(el => el.scrollIntoView({ block: 'start' }))
      }
      const content = await page.locator('body').innerText()
      if (/No encontramos esta página|Acceso restringido|^Cargando/u.test(content)) throw new Error('Ruta no disponible: ' + route)
      await page.screenshot({ path: path.join(output, name + '.png'), animations: 'disabled' })
      console.log(name)
    }
    await page.goto(base+'/acceso', { waitUntil: 'networkidle' })
    await page.screenshot({ path: path.join(output, 'acceso.png') })
    await page.locator('#email').fill('owner@alfa.example.test')
    await page.locator('#password').fill('FacturlyLocal2026!')
    await page.getByRole('button', { name: 'Ingresar', exact: true }).click()
    await page.locator('.user-menu').waitFor()
    const doc='/documents/'+ids.invoice, statement='/customers/'+ids.customer+'/statement'
    const routes=[
      ['resumen','/dashboard'],['comprobantes','/documents'],['borrador','/documents/new'],['factura',doc],
      ['pdf-correo',doc,'section[aria-label="PDF privado y correo"]'],['cobranza',doc,'section[aria-label*="obranza"]'],
      ['nota-credito','/documents/'+ids.note],['cartera','/receivables'],['seguimiento','/receivables/'+ids.invoice+'/followup'],
      ['mi-agenda','/receivables/agenda?scope=mine'],['agenda-equipo','/receivables/agenda?scope=team'],
      ['bancos','/bank-receipts'],['cuentas-receptoras','/bank-receipts/accounts'],['registrar-abono','/bank-receipts/new'],
      ['abono','/bank-receipts/'+ids.receipt],['aplicaciones','/bank-receipts/'+ids.receipt,'h3:has-text("Aplicaciones")'],
      ['clientes','/customers'],['ficha-cliente','/customers/'+ids.customer],['estado-cuenta',statement],
      ['movimientos',statement,'h3:has-text("Movimientos del período")'],['impresion',statement+'?view=print'],
      ['incidencias','/incidents'],['cronologia','/incidents/emission/'+ids.reject],
      ['productos','/products'],['integraciones','/integrations/odoo'],['config-cobros','/collections'],
      ['empresa','/company'],['usuarios','/users'],['series','/series'],['plan','/subscription'],['mi-cuenta','/account']
    ]
    for(const args of routes) await snap(...args)
    await page.setViewportSize({width:390,height:844})
    await snap('movil','/dashboard')
  } finally { await browser.close() }
}
main().catch(error => {console.error(error);process.exitCode=1})
