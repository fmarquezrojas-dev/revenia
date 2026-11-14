export const pages = {
  dashboard: { title: 'Resumen', description: 'Actividad del negocio, cartera actual y operaciones por atender.', group: 'Operaci\u00f3n', owner: false },
  subscription: {title:'Plan y consumo',description:'Vigencia, emisiones disponibles y periodos contratados.',group:'Cuenta',owner:false},
  documents: { title: 'Comprobantes', description: 'Facturas y boletas: borradores, emisión simulada y seguimiento.', group: 'Operaci\u00f3n', owner: false },
  account: { title: 'Mi cuenta', description: 'Tu perfil y la empresa con la que trabajas.', group: 'Cuenta', owner: false },
  customers: { title: 'Clientes', description: 'Consulta y administra los clientes de tu empresa.', group: 'Operación', owner: false },
  products: { title: 'Productos y servicios', description: 'Tu catálogo, precios y condiciones tributarias.', group: 'Operación', owner: false },
  company: { title: 'Mi empresa', description: 'Información comercial y datos de contacto.', group: 'Configuración', owner: true },
  users: { title: 'Usuarios', description: 'Administra tu equipo. Hasta 5 usuarios, incluidos los inactivos.', group: 'Configuración', owner: true },
  series: { title: 'Series', description: 'Organiza las series de comprobantes de tu empresa.', group: 'Configuración', owner: true },
} as const
export type PageKey = keyof typeof pages
export function safeDestination(value: string | null): string {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return '/dashboard'
  const url = new URL(value, 'https://revenia.invalid')
  if (url.origin !== 'https://revenia.invalid' || !Object.hasOwn(pages, url.pathname.split('/')[1])) return '/dashboard'
  return url.pathname + url.search
}
