import { Navigate, useLocation, useNavigate } from 'react-router'
import AuthLayout from './layouts/AuthLayout'
import { safeDestination } from './app/navigation'
import { Eye, EyeOff } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'

import AuthenticatedView from './components/AuthenticatedView'
import { ApiError, getSession, login, logout } from './lib/api'
import type { Company, ManagedUser, SessionUser } from './lib/api'

type SessionState =
  | { status: 'loading' }
  | { status: 'guest' }
  | { status: 'authenticated'; user: SessionUser }
  | { status: 'error'; message: string }

function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 422) {
      const firstError = Object.values(error.errors ?? {}).flat()[0]

      return firstError ?? 'Revisa los datos ingresados.'
    }

    if (error.status === 419) {
      return 'La sesión de seguridad venció. Intenta nuevamente.'
    }

    if (error.status === 429) {
      return 'Hay demasiados intentos. Espera un minuto y vuelve a intentar.'
    }

    if (error.status === 401) {
      return 'Tu sesión ya no está disponible. Inicia sesión nuevamente.'
    }

    if (error.status === 403) {
      return 'No tienes permiso para realizar esta acción.'
    }

    return 'No se pudo completar la operación. Intenta nuevamente.'
  }

  return 'No pudimos conectar con el servidor. Revisa la conexión e intenta nuevamente.'
}

export default function App() {
  const location = useLocation()
  const navigate = useNavigate()
  const locationRef = useRef(location)
  useEffect(() => { locationRef.current = location; if (location.pathname === '/acceso') document.title = 'Acceso | Revenia' }, [location])
  const [visiblePassword, setVisiblePassword] = useState(false)
  const loginLock = useRef(false)

  const [session, setSession] = useState<SessionState>({
    status: 'loading',
  })

  const [sessionAttempt, setSessionAttempt] = useState(0)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')

  const messageRef = useRef<HTMLParagraphElement>(null)

  const handleSessionExpired = useCallback(() => {
    setEmail('')
    setPassword('')
    setSession({ status: 'guest' })
    setMessage(
      'Tu sesión ya no está disponible. Inicia sesión nuevamente.',
    )
    const target = safeDestination(locationRef.current.pathname + locationRef.current.search)
    navigate('/acceso?next=' + encodeURIComponent(target), { replace: true })
  }, [navigate])

  const handleUserUpdated = useCallback(async (updated: ManagedUser) => {
    if (!updated.is_active) {
      try {
        await logout()
      } catch {
        // The inactive account is also rejected by the API middleware.
      } finally {
        handleSessionExpired()
      }
      return
    }
    setSession((current) => current.status === 'authenticated' && current.user.id === updated.id
      ? { status: 'authenticated', user: {
          ...current.user, name: updated.name, role: updated.role, role_label: updated.role_label,
        } }
      : current)
  }, [handleSessionExpired])

  const handleCompanyUpdated = useCallback((company: Company) => {
    setSession((current) => {
      if (
        current.status !== 'authenticated' ||
        current.user.company.id !== company.id
      ) {
        return current
      }

      return {
        status: 'authenticated',
        user: {
          ...current.user,
          company: {
            id: company.id,
            legal_name: company.legal_name,
            trade_name: company.trade_name,
          },
        },
      }
    })
  }, [])

  useEffect(() => {
    let active = true

    getSession()
      .then((user) => {
        if (!active) return

        setSession({
          status: 'authenticated',
          user,
        })
      })
      .catch((error: unknown) => {
        if (!active) return

        if (error instanceof ApiError && error.status === 401) {
          setSession({ status: 'guest' })
          return
        }

        setSession({
          status: 'error',
          message: errorMessage(error),
        })
      })

    return () => {
      active = false
    }
  }, [sessionAttempt])

  useEffect(() => {
    if (message) {
      messageRef.current?.focus()
    }
  }, [message])

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (loginLock.current) return
    loginLock.current = true

    setBusy(true)
    setMessage('')

    try {
      const user = await login(email.trim(), password)

      setPassword('')
      setSession({
        status: 'authenticated',
        user,
      })
      navigate(safeDestination(new URLSearchParams(location.search).get('next')), { replace: true })
    } catch (error: unknown) {
      setPassword('')
      setMessage(errorMessage(error))
    } finally {
      loginLock.current = false
      setBusy(false)
    }
  }

  async function handleLogout() {
    if (loginLock.current) return
    loginLock.current = true

    setBusy(true)
    setMessage('')

    try {
      await logout()

      setEmail('')
      setPassword('')
      setSession({ status: 'guest' })
      navigate('/acceso', { replace: true })
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) {
        setEmail('')
        setPassword('')
        setSession({ status: 'guest' })
      } else {
        setMessage(errorMessage(error))
      }
    } finally {
      loginLock.current = false
      setBusy(false)
    }
  }

  function retrySession() {
    setMessage('')
    setSession({ status: 'loading' })
    setSessionAttempt((attempt) => attempt + 1)
  }

  if (session.status === 'loading') return <AuthLayout><p role="status">Comprobando tu sesión…</p></AuthLayout>
  if (session.status === 'error') return <AuthLayout><h1>No pudimos cargar tu sesión</h1><p role="alert">{session.message}</p><button onClick={retrySession}>Reintentar</button></AuthLayout>
  if (session.status === 'guest' && location.pathname !== '/acceso') {
    return <Navigate to={'/acceso?next=' + encodeURIComponent(safeDestination(location.pathname + location.search))} replace />
  }
  if (session.status === 'authenticated') {
    if (location.pathname === '/acceso' || location.pathname === '/') return <Navigate to={safeDestination(new URLSearchParams(location.search).get('next'))} replace />
    return <AuthenticatedView key={session.user.id + session.user.company.id} user={session.user} busy={busy} message={message}
      onLogout={handleLogout} onSessionExpired={handleSessionExpired} onCompanyUpdated={handleCompanyUpdated} onUserUpdated={handleUserUpdated} />
  }
  return <AuthLayout>
    <span className="eyebrow">TU ESPACIO DE TRABAJO</span>
    <h1>Inicia sesión</h1><p className="muted">Accede a la cuenta de tu empresa.</p>
    <form onSubmit={handleLogin} aria-busy={busy} className="login-form">
      <fieldset disabled={busy}>
        <label htmlFor="email">Correo electrónico</label>
        <input id="email" name="email" type="email" autoComplete="username" required maxLength={200} value={email} onChange={(event) => setEmail(event.target.value)} />
        <label htmlFor="password">Contraseña</label>
        <div className="password-field"><input id="password" name="password" type={visiblePassword ? 'text' : 'password'} autoComplete="current-password" required maxLength={1024} value={password} onChange={(event) => setPassword(event.target.value)} />
          <button type="button" className="icon-button" aria-label={visiblePassword ? 'Ocultar contraseña' : 'Mostrar contraseña'} aria-pressed={visiblePassword} onClick={() => setVisiblePassword(!visiblePassword)}>{visiblePassword ? <EyeOff aria-hidden="true" size={18} /> : <Eye aria-hidden="true" size={18} />}</button></div>
        {message && <p className="error" role="alert" tabIndex={-1} ref={messageRef}>{message}</p>}
        <button type="submit" className="login-submit">{busy ? 'Ingresando…' : 'Ingresar'}</button>
      </fieldset>
    </form>
  </AuthLayout>
}
