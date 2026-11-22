import { lazy, Suspense, useState } from 'react'
import { Link, Navigate, Route, Routes, useNavigate } from 'react-router'
import type { Company, ManagedUser, SessionUser } from '../lib/api'
import AppShell from '../layouts/AppShell'
import CompanyDetails from './CompanyDetails'
import UsersPanel from './UsersPanel'
import CustomersPanel from './CustomersPanel'
import ProductsPanel from './ProductsPanel'
import SeriesPanel from './SeriesPanel'
import DocumentsPanel from './DocumentsPanel'
import SubscriptionPanel from './SubscriptionPanel'
import MerchantConnectionPanel from './MerchantConnectionPanel'
import IncidentsPanel from './IncidentsPanel'
import DashboardPanel from './DashboardPanel'
const OdooIntegrationPanel = lazy(() => import('./OdooIntegrationPanel'))
const BankReceiptsPanel = lazy(() => import('./BankReceiptsPanel'))
const ReceivablesPanel = lazy(() => import('./ReceivablesPanel'))
const CustomerStatement = lazy(() => import('./CustomerStatement'))
const CollectionAgenda = lazy(() => import('./CollectionAgenda'))
const CollectionFollowupPanel = lazy(() => import('./CollectionFollowupPanel'))
interface Props {
  user: SessionUser; busy: boolean; message: string; onLogout: () => Promise<void>
  onSessionExpired: () => void; onCompanyUpdated: (company: Company) => void
  onUserUpdated: (user: ManagedUser) => Promise<void>
}
export default function AuthenticatedView({ user, busy, message, onLogout, onSessionExpired, onCompanyUpdated, onUserUpdated }: Props) {
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const navigate = useNavigate()
  const isOwner = user.role === 'owner'
  const receivablesNav = <nav className="form-actions" aria-label="Vistas de cuentas por cobrar">
    <Link className="button secondary" to="/receivables">Cartera</Link>
    {user.role !== 'accountant' && <Link className="button secondary" to="/receivables/agenda?scope=mine">Mi agenda</Link>}
    {user.role !== 'issuer' && <Link className="button secondary" to="/receivables/agenda?scope=team">Agenda del equipo</Link>}
  </nav>
  async function handleUserSaved(updated: ManagedUser) {
    setNotice('Usuario guardado correctamente.')
    if (updated.id === user.id) {
      await onUserUpdated(updated)
      if (updated.is_active && updated.role !== 'owner') navigate('/account', { replace: true })
    }
  }
  const incidents = <IncidentsPanel actorId={user.id} onSavingChange={setSaving} onSessionExpired={onSessionExpired} />
  const denied = <section className="surface state-panel"><h2>Acceso restringido</h2><p role="alert">Tu rol no tiene permiso para administrar esta sección.</p><Link to="/account" className="button secondary">Volver a mi cuenta</Link></section>
  return <AppShell user={user} busy={busy} saving={saving} onLogout={onLogout}>
    {message && <p className="error" role="alert">{message}</p>}
    {notice && <p className="success-message" role="status">{notice}</p>}
    <Routes>
      <Route path="/customers/:id/statement" element={<Suspense fallback={<p role="status">Cargando estado de cuenta…</p>}><CustomerStatement onSessionExpired={onSessionExpired}/></Suspense>}/>
      <Route path="/bank-receipts/:id?" element={<Suspense fallback={<p role="status">Cargando tesorería…</p>}><BankReceiptsPanel role={user.role} onSessionExpired={onSessionExpired}/></Suspense>}/>
      <Route path="/integrations/odoo?" element={isOwner ? <Suspense fallback={<p role="status">Cargando Integraciones…</p>}><OdooIntegrationPanel onSessionExpired={onSessionExpired} onSavingChange={setSaving}/></Suspense> : denied}/>
      <Route path="/incidents" element={incidents} />
      <Route path="/incidents/:process/:id" element={incidents} />
      <Route path="/collections" element={isOwner ? <MerchantConnectionPanel onSessionExpired={onSessionExpired} onSavingChange={setSaving} /> : denied} />
      <Route path="/receivables" element={<>{receivablesNav}<Suspense fallback={<p role="status">Cargando cartera…</p>}><ReceivablesPanel onSessionExpired={onSessionExpired} /></Suspense></>} />
      <Route path="/receivables/agenda" element={<>{receivablesNav}<Suspense fallback={<p role="status">Cargando agenda…</p>}><CollectionAgenda role={user.role} onSessionExpired={onSessionExpired}/></Suspense></>} />
      <Route path="/receivables/:id/followup" element={<>{receivablesNav}<Suspense fallback={<p role="status">Cargando seguimiento…</p>}><CollectionFollowupPanel onSessionExpired={onSessionExpired}/></Suspense></>} />
      <Route path="/dashboard" element={<DashboardPanel key={user.company.id + user.role} companyName={user.company.trade_name || user.company.legal_name} onSessionExpired={onSessionExpired} />} />
      <Route path="/subscription" element={<SubscriptionPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving}/>} />
      <Route path="/documents" element={<DocumentsPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/documents/new" element={<DocumentsPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/documents/:id" element={<DocumentsPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/documents/:id/edit" element={<DocumentsPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />

      <Route path="/" element={<Navigate to="/dashboard" replace />} />
      <Route path="/account" element={<section className="surface account-profile"><h2>Hola, {user.name}</h2><p className="muted">Esta es la información de tu sesión actual.</p><dl className="profile"><div><dt>Empresa</dt><dd>{user.company.legal_name}</dd></div><div><dt>Correo</dt><dd>{user.email}</dd></div><div><dt>Rol</dt><dd><span className="badge">{user.role_label}</span></dd></div></dl></section>} />
      <Route path="/company" element={isOwner ? <section className="surface"><CompanyDetails onSessionExpired={onSessionExpired} onCompanyUpdated={onCompanyUpdated} /></section> : denied} />
      <Route path="/company/edit" element={isOwner ? <section className="surface"><CompanyDetails onSessionExpired={onSessionExpired} onCompanyUpdated={onCompanyUpdated} /></section> : denied} />
      <Route path="/users" element={isOwner ? <UsersPanel currentUserId={user.id} isOwner={isOwner} busy={busy || saving} onSavingChange={setSaving} onSaved={handleUserSaved} onEditStarted={() => setNotice('')} onSessionExpired={onSessionExpired} /> : denied} />
      <Route path="/users/new" element={isOwner ? <UsersPanel currentUserId={user.id} isOwner={isOwner} busy={busy || saving} onSavingChange={setSaving} onSaved={handleUserSaved} onEditStarted={() => setNotice('')} onSessionExpired={onSessionExpired} /> : denied} />
      <Route path="/users/:id" element={isOwner ? <UsersPanel currentUserId={user.id} isOwner={isOwner} busy={busy || saving} onSavingChange={setSaving} onSaved={handleUserSaved} onEditStarted={() => setNotice('')} onSessionExpired={onSessionExpired} /> : denied} />
      <Route path="/customers" element={<CustomersPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/customers/new" element={<CustomersPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/customers/:id" element={<CustomersPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/customers/:id/edit" element={<CustomersPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/products" element={<ProductsPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/products/new" element={<ProductsPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/products/:id" element={<ProductsPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/products/:id/edit" element={<ProductsPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} />} />
      <Route path="/series" element={isOwner ? <SeriesPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} /> : denied} />
      <Route path="/series/new" element={isOwner ? <SeriesPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} /> : denied} />
      <Route path="/series/:id" element={isOwner ? <SeriesPanel role={user.role} onSessionExpired={onSessionExpired} onSavingChange={setSaving} /> : denied} />
      <Route path="*" element={<section className="surface state-panel"><h2>No encontramos esta página</h2><Link to="/dashboard" className="button">Volver al resumen</Link></section>} />
    </Routes>
  </AppShell>
}
