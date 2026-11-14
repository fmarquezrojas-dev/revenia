import { useModuleNavigation } from '../app/useModuleNavigation'
import DataTable from './ui/DataTable'
import { useEffect, useState } from 'react'
import { ApiError, getUsers } from '../lib/api'
import type { ManagedUser } from '../lib/api'
import UserForm from './UserForm'

type UsersState =
  | { status: 'loading' }
  | { status: 'ready'; users: ManagedUser[]; total: number }
  | { status: 'error'; message: string; retryable: boolean }

interface UsersPanelProps {
  currentUserId: string
  isOwner: boolean
  busy: boolean
  onSavingChange: (saving: boolean) => void
  onSaved: (user: ManagedUser) => Promise<void>
  onEditStarted: () => void
  onSessionExpired: () => void
}

function formatDate(value: string | null): string {
  if (!value) return 'No disponible'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return 'No disponible'
  }

  return new Intl.DateTimeFormat('es-PE', {
    dateStyle: 'medium',
    timeZone: 'America/Lima',
  }).format(date)
}

export default function UsersPanel({
  currentUserId,
  isOwner,
  busy,
  onSavingChange,
  onSaved,
  onEditStarted,
  onSessionExpired,
}: UsersPanelProps) {
  const [state, setState] = useState<UsersState>({
    status: 'loading',
  })
  const [attempt, setAttempt] = useState(0)
  const [view, setView] = useModuleNavigation('/users')
  const editor = view.mode === 'new' ? { user: null } : view.mode === 'detail' && state.status === 'ready' ? { user: state.users.find((item) => item.id === view.id) ?? null } : null
  const setEditor = (value: { user: ManagedUser | null } | null) => setView(value ? value.user ? { mode: 'detail', id: value.user.id } : { mode: 'new' } : { mode: 'list' })

  useEffect(() => {
    const controller = new AbortController()

    getUsers(controller.signal)
      .then((response) => {
        if (controller.signal.aborted) return

        setState({
          status: 'ready',
          users: response.data,
          total: response.meta.total,
        })
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return

        if (error instanceof ApiError && error.status === 401) {
          onSessionExpired()
          return
        }

        if (error instanceof ApiError && error.status === 403) {
          setState({
            status: 'error',
            message: 'Tu cuenta no tiene permiso para administrar usuarios.',
            retryable: false,
          })
          return
        }

        setState({
          status: 'error',
          message: 'No pudimos cargar los usuarios de tu empresa.',
          retryable: true,
        })
      })

    return () => {
      controller.abort()
    }
  }, [attempt, onSessionExpired])

  function refreshUsers() {
    setState({ status: 'loading' })
    setAttempt((current) => current + 1)
  }

  async function handleSaved(user: ManagedUser) {
    await onSaved(user)
    if (user.id === currentUserId && (!user.is_active || user.role !== 'owner')) return
    setEditor(null)
    if (user.id !== currentUserId || (user.is_active && user.role === 'owner')) refreshUsers()
  }

  function openEditor(user: ManagedUser | null) {
    if (busy || !isOwner) return
    onEditStarted()
    setEditor({ user })
  }

  if (!isOwner) return null

  if (view.mode === 'detail' && state.status === 'ready' && !state.users.some((item) => item.id === view.id)) return <p className="error" role="alert">Este usuario no está disponible.</p>

  if (editor) {
    return <UserForm
      key={editor.user?.id ?? 'new'}
      user={editor.user}
      currentUserId={currentUserId}
      onSaved={handleSaved}
      onCancel={() => setEditor(null)}
      onSessionExpired={onSessionExpired}
      onSavingChange={onSavingChange}
    />
  }

  if (state.status === 'loading') {
    return (
      <p className="muted" role="status">
        Cargando el equipo de tu empresa…
      </p>
    )
  }

  if (state.status === 'error') {
    return (
      <div>
        <p className="error" role="alert">
          {state.message}
        </p>

        {state.retryable && (
          <button
            type="button"
            className="secondary"
            onClick={refreshUsers}
          disabled={busy}
          >
            Reintentar
          </button>
        )}
      </div>
    )
  }

  return (
    <>
      <div className="module-toolbar"><span className="muted">Personas y permisos de acceso</span><button className="primary-action" type="button" disabled={busy} onClick={() => openEditor(null)}>Nuevo usuario</button></div>
      <div className="users-toolbar">
        <div>
          <p className="muted">
            Personas con una cuenta en tu empresa.
          </p>

          <p className="users-count">
            {state.total === 1
              ? '1 usuario registrado'
              : `${state.total} usuarios registrados`}
          </p>
        </div>

        <button
          type="button"
          className="secondary users-refresh"
          onClick={refreshUsers}
          disabled={busy}
        >
          Actualizar lista
        </button>
      </div>

      {state.users.length === 0 ? (
        <p className="empty-state">
          No hay usuarios para mostrar.
        </p>
      ) : (
        <DataTable label="Usuarios de la empresa" headers={['Nombre', 'Correo', 'Rol', 'Estado', 'Registro', 'Acciones']}>
          {state.users.map((user) => <tr key={user.id}><td className="primary-cell">{user.name}{user.id === currentUserId && <small>Tu cuenta</small>}</td>
            <td>{user.email}</td><td><span className="badge">{user.role_label}</span></td><td><span className={`status-badge ${user.is_active ? 'active' : 'inactive'}`}>{user.is_active ? 'Activo' : 'Inactivo'}</span></td>
            <td>{formatDate(user.created_at)}</td><td><button type="button" className="secondary" disabled={busy} aria-label={`Editar a ${user.name}`} onClick={() => openEditor(user)}>Editar</button></td></tr>)}
        </DataTable>
      )}
    </>
  )
}