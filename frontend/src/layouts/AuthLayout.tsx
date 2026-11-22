import type { ReactNode } from 'react'
export default function AuthLayout({ children }: { children: ReactNode }) {
  return <main className="auth-layout"><div className="auth-container">
    <a className="brand" href="/acceso" aria-label="Revenia, acceso"><span className="brand-mark" aria-hidden="true">R</span><span>Revenia</span></a>
    <p className="muted">Plataforma de facturación y cobranza para empresas</p>
    <section className="auth-card">{children}</section>
  </div></main>
}
