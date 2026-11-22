import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, NavLink, useBlocker, useLocation } from 'react-router'
import { Plug, ClipboardList, Wallet, LayoutDashboard, FileText, Building2, ChevronDown, ContactRound, Hash, LogOut, Menu, Package, UserRound, Users, X } from 'lucide-react'
import { pages } from '../app/navigation'
import type { PageKey } from '../app/navigation'
import { FormGuardContext } from '../app/FormGuard'
import type { Guard } from '../app/FormGuard'
import type { SessionUser } from '../lib/api'
const icons = { 'bank-receipts': Wallet, integrations: Plug, incidents: ClipboardList, collections: Wallet, receivables: Wallet, dashboard: LayoutDashboard, subscription: Hash, documents: FileText, account: UserRound, customers: ContactRound, products: Package, company: Building2, users: Users, series: Hash }
interface Props { user: SessionUser; busy: boolean; saving: boolean; onLogout: () => Promise<void>; children: ReactNode }
export default function AppShell({ user, busy, saving, onLogout, children }: Props) {
  const location = useLocation()
  const key = location.pathname.split('/')[1] as PageKey
  const page = pages[key]
  const heading = useRef<HTMLHeadingElement>(null)
  const drawer = useRef<HTMLDialogElement>(null)
  const menuButton = useRef<HTMLButtonElement>(null)
  const userMenu = useRef<HTMLDetailsElement>(null)
  const [open, setOpen] = useState(false)
  const [, rerender] = useState(0)
  const guard = useRef<Guard>({ dirty: false, saving: false })
  const changed = useCallback(() => rerender((value) => value + 1), [])
  const guardContext = useMemo(() => ({ state: guard, changed }), [changed])
  const blocker = useBlocker(() => busy || guard.current.saving || guard.current.dirty)
  useEffect(() => { guard.current.saving = saving }, [saving])
  const blocked = busy || saving
  useEffect(() => {
    if (blocker.state !== 'blocked') return
    if (busy || guard.current.saving) { blocker.reset(); return }
    if (window.confirm('Tienes cambios sin guardar. ¿Salir y descartarlos?')) blocker.proceed()
    else blocker.reset()
  }, [blocker, busy])
  useEffect(() => {
    const beforeUnload = (event: BeforeUnloadEvent) => {
      if (busy || guard.current.saving || guard.current.dirty) { event.preventDefault(); event.returnValue = '' }
    }
    window.addEventListener('beforeunload', beforeUnload)
    return () => window.removeEventListener('beforeunload', beforeUnload)
  }, [busy])
  useEffect(() => {
    drawer.current?.close()
    if (userMenu.current) userMenu.current.open = false
    const segment = location.pathname.split('/')[2]
    document.title = `${segment === 'new' ? 'Nuevo registro - ' : ''}${page?.title ?? 'Página no encontrada'} | Revenia`
    const timer = window.setTimeout(() => {
      const form = document.querySelector<HTMLFormElement>('#main-content form')
      if (form) form.querySelector<HTMLElement>('input:not([readonly]), select, textarea')?.focus()
      else heading.current?.focus()
    }, 0)
    return () => window.clearTimeout(timer)
  }, [location.pathname, page])
  const navigation = (mobile: boolean) => <>
    <Link to="/dashboard" className="brand"><span className="brand-mark" aria-hidden="true">R</span><span>Revenia</span></Link>
    <div className="company-context"><span className="eyebrow">EMPRESA ACTUAL</span><strong>{user.company.trade_name || user.company.legal_name}</strong><span className="muted">Espacio de trabajo</span></div>
    <nav aria-label={mobile ? 'Navegación móvil' : 'Navegación principal'}>
      {['Operación', 'Tesorería', 'Configuración', 'Cuenta'].map((group) => {
        const entries = (Object.entries(pages) as [PageKey, typeof pages[PageKey]][]).filter(([, item]) => item.group === group && (!item.owner || user.role === 'owner'))
        return entries.length > 0 && <div className="nav-group" key={group}><p>{group}</p>{entries.map(([id, item]) => {
          const Icon = icons[id]
          return <NavLink key={id} to={'/' + id} aria-disabled={blocked || undefined} onClick={(event) => { if (blocked) event.preventDefault(); else if (mobile) drawer.current?.close() }}><Icon size={19} aria-hidden="true" /><span>{item.title}</span></NavLink>
        })}</div>
      })}
    </nav>
  </>
  return <FormGuardContext.Provider value={guardContext}>
    <div className="workspace">
      <a href="#main-content" className="skip-link">Saltar al contenido</a>
      <aside className="sidebar">{navigation(false)}</aside>
      <dialog ref={drawer} className="navigation-drawer" onKeyDown={(event) => {
        if (event.key !== 'Tab') return
        const items = [...event.currentTarget.querySelectorAll<HTMLElement>('a[href], button:not(:disabled)')]
        const first = items[0], last = items[items.length - 1]
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
      }} aria-label="Menú de navegación" onClose={() => { setOpen(false); menuButton.current?.focus() }} onClick={(event) => { if (event.target === event.currentTarget) drawer.current?.close() }}>
        <div className="drawer-content"><button className="icon-button drawer-close" aria-label="Cerrar menú" onClick={() => drawer.current?.close()}><X aria-hidden="true" /></button>{navigation(true)}</div>
      </dialog>
      <div className="workspace-body">
        <header className="workspace-header"><div className="header-context">
          <button ref={menuButton} className="icon-button mobile-menu" aria-label="Abrir menú" aria-expanded={open} onClick={() => { drawer.current?.showModal(); setOpen(true) }}><Menu aria-hidden="true" /></button>
          <span className="header-section">{page?.group ?? 'Revenia'}<span aria-hidden="true"> / </span><strong>{page?.title ?? 'Página no encontrada'}</strong></span>
        </div>
        <details className="user-menu" ref={userMenu} onToggle={(event) => event.currentTarget.querySelector('summary')?.setAttribute('aria-expanded', String(event.currentTarget.open))} onKeyDown={(event) => { if (event.key === 'Escape' && userMenu.current) { userMenu.current.open = false; userMenu.current.querySelector('summary')?.focus() } }}>
          <summary aria-label={`Menú de ${user.name}, ${user.role_label}`} aria-expanded="false"><span className="avatar" aria-hidden="true">{user.name.slice(0, 1).toUpperCase()}</span><span className="user-menu-name">{user.name}<small>{user.role_label}</small></span><ChevronDown size={16} aria-hidden="true" /></summary>
          <div className="user-dropdown"><p>{user.email}</p><Link to="/account"><UserRound size={17} aria-hidden="true" />Mi cuenta</Link><button disabled={blocked} onClick={() => {
            if (!guard.current.dirty || window.confirm('Tienes cambios sin guardar. ¿Cerrar sesión y descartarlos?')) { guard.current = { dirty: false, saving: false }; void onLogout() }
          }}><LogOut size={17} aria-hidden="true" />{busy ? 'Cerrando sesión…' : 'Cerrar sesión'}</button></div>
        </details></header>
        <main id="main-content" className="main-content"><div className="page-heading"><span className="eyebrow">{page?.group ?? 'Revenia'}</span><h1 ref={heading} tabIndex={-1}>{page?.title ?? 'Página no encontrada'}</h1><p>{page?.description ?? 'Comprueba la dirección o vuelve a una sección disponible.'}</p></div>{children}</main>
      </div>
    </div>
  </FormGuardContext.Provider>
}
