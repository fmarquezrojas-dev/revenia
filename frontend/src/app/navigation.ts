export const pages = {
  'bank-receipts': { title: 'Cobros bancarios', description: 'Verificación manual de abonos y distribución a la cartera de clientes.', group: 'Tesorería', owner: false },
  dashboard: { title: 'Resumen', description: 'Actividad del negocio, cartera actual y operaciones por atender.', group: 'Operaci\u00f3n', owner: false },
  subscription: {title:'Plan y consumo',description:'Vigencia, emisiones disponibles y periodos contratados.',group:'Cuenta',owner:false},
  documents: { title: 'Comprobantes', description: 'Facturas y boletas: borradores, emisión simulada y seguimiento.', group: 'Operaci\u00f3n', owner: false },
  receivables: { title: 'Cuentas por cobrar', description: 'Consulta la cartera actual por vencimiento y moneda.', group: 'Operación', owner: false },
  incidents: { title: 'Incidencias', description: 'Seguimiento de emisión, PDF, correo, cobranza y transferencias a Odoo.', group: 'Operación', owner: false },
  account: { title: 'Mi cuenta', description: 'Tu perfil y la empresa con la que trabajas.', group: 'Cuenta', owner: false },
  customers: { title: 'Clientes', description: 'Consulta y administra los clientes de tu empresa.', group: 'Operación', owner: false },
  products: { title: 'Productos y servicios', description: 'Tu catálogo, precios y condiciones tributarias.', group: 'Operación', owner: false },
  integrations: { title: 'Integraciones', description: 'Conexiones de la empresa con sistemas externos.', group: 'Configuración', owner: true },
  collections: { title: 'Cobros', description: 'Cuenta receptora de los pagos de tus comprobantes.', group: 'Configuración', owner: true },
  company: { title: 'Mi empresa', description: 'Información comercial y datos de contacto.', group: 'Configuración', owner: true },
  users: { title: 'Usuarios', description: 'Administra tu equipo. Hasta 5 usuarios, incluidos los inactivos.', group: 'Configuración', owner: true },
  series: { title: 'Series', description: 'Organiza las series de comprobantes de tu empresa.', group: 'Configuración', owner: true },
} as const
export type PageKey = keyof typeof pages
export function safeDestination(value: string | null): string {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return '/dashboard'
  let url: URL
  try { url = new URL(value, 'https://revenia.invalid') } catch { return '/dashboard' }
  if (url.origin !== 'https://revenia.invalid') return '/dashboard'
  const path = url.pathname.replace(/\/$/, '')
  const [section, id, action, ...rest] = path.slice(1).split('/')
  if (!Object.hasOwn(pages, section) || rest.length) return '/dashboard'
  if (id !== undefined) {
    const recordSections = ['documents', 'customers', 'products', 'users', 'series']
    const editable = ['documents', 'customers', 'products']
    const recordId = /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(id)
    const valid = (section === 'company' && id === 'edit' && action === undefined)
      || (section === 'customers' && recordId && action === 'statement')
      || (section === 'bank-receipts' && (id === 'accounts' || id === 'new' || recordId) && action === undefined)
      || (section === 'integrations' && id === 'odoo' && action === undefined)
      || (section === 'receivables' && ((id === 'agenda' && action === undefined) || (recordId && action === 'followup')))
      || (recordSections.includes(section) && id === 'new' && action === undefined)
      || (recordSections.includes(section) && recordId && (action === undefined || (action === 'edit' && editable.includes(section))))
    const incident = section === 'incidents' && ['emission','pdf','email','collection','odoo'].includes(id) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(action ?? '')
    if (!valid && !incident) return '/dashboard'
  }
  return path + url.search + url.hash
}
