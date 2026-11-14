import { useFormGuard } from '../app/FormGuard'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, createUser, updateUser } from '../lib/api'
import type { ManagedUser, SessionUser } from '../lib/api'

interface UserFormProps {
  user: ManagedUser | null
  currentUserId: string
  onSaved: (user: ManagedUser) => Promise<void>
  onCancel: () => void
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

export default function UserForm({
  user, currentUserId, onSaved, onCancel, onSessionExpired, onSavingChange,
}: UserFormProps) {
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<SessionUser['role']>(user?.role ?? 'issuer')
  const [active, setActive] = useState(user?.is_active ?? true)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [saving, setSaving] = useState(false)
  const { dirty, markSaved } = useFormGuard(JSON.stringify([name, email, role, active, password, confirmation]), saving)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [message, setMessage] = useState('')
  const submitting = useRef(false)
  const nameRef = useRef<HTMLInputElement>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => { nameRef.current?.focus() }, [])
  useEffect(() => { if (message) errorRef.current?.focus() }, [message])

  function fieldError(field: string) {
    return errors[field]?.length ? (
      <p id={`user-${field}-error`} className="field-error">
        {errors[field].join(' ')}
      </p>
    ) : null
  }

  function errorAttributes(field: string) {
    return {
      'aria-invalid': Boolean(errors[field]?.length),
      'aria-describedby': errors[field]?.length ? `user-${field}-error` : undefined,
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting.current) return

    if (user?.id === currentUserId && (role !== 'owner' || !active)) {
      const accepted = window.confirm(
        !active
          ? 'Vas a desactivar tu propia cuenta y se cerrará tu sesión. ¿Guardar este cambio?'
          : 'Vas a dejar de ser dueño y perderás el acceso a la administración de empresa y usuarios. ¿Guardar este cambio?',
      )
      if (!accepted) return
    }

    submitting.current = true
    setSaving(true)
    onSavingChange(true)
    setErrors({})
    setMessage('')

    try {
      const saved = user
        ? await updateUser(user.id, { name: name.trim(), role, is_active: active })
        : await createUser({
            name: name.trim(), email: email.trim(), role,
            password, password_confirmation: confirmation,
          })
      setPassword('')
      setConfirmation('')
      markSaved()
      await onSaved(saved)
    } catch (error: unknown) {
      setPassword('')
      setConfirmation('')
      if (error instanceof ApiError) {
        if (error.status === 401) {
          onSessionExpired()
          return
        }
        if (error.status === 422) {
          setErrors(error.errors ?? {})
          setMessage(Object.values(error.errors ?? {}).flat().join(' ') || error.message)
          return
        }
        if (error.status === 403) {
          setMessage('Tu cuenta no tiene permiso para administrar usuarios. Los datos del formulario se conservan.')
          return
        }
        if (error.status === 419) {
          setMessage('La sesión de seguridad venció. Intenta guardar nuevamente.')
          return
        }
        if (error.status === 404) {
          setMessage('Este usuario ya no está disponible. Cancela y actualiza la lista.')
          return
        }
      }
      setMessage('No pudimos confirmar el guardado. Conservamos los datos no sensibles. Revisa la conexión y actualiza la lista antes de volver a intentar.')
    } finally {
      submitting.current = false
      setSaving(false)
      onSavingChange(false)
    }
  }

  return (
    <form className="user-form" onSubmit={handleSubmit} aria-busy={saving} aria-labelledby="user-form-title">
      <h2 id="user-form-title">{user ? 'Editar usuario' : 'Nuevo usuario'}</h2>
      {dirty && <p className="dirty-notice" role="status">Cambios sin guardar</p>}
      <fieldset disabled={saving}>
        <label htmlFor="user-name">Nombre</label>
        <input id="user-name" name="name" ref={nameRef} required maxLength={150}
          value={name} onChange={(event) => setName(event.target.value)} {...errorAttributes('name')} />
        {fieldError('name')}

        <label htmlFor="user-email">Correo electrónico{user ? ' (no editable)' : ''}</label>
        <input id="user-email" name="email" type="email" required maxLength={200}
          readOnly={Boolean(user)} value={user?.email ?? email}
          onChange={(event) => setEmail(event.target.value)} {...errorAttributes('email')} />
        {fieldError('email')}

        <label htmlFor="user-role">Rol</label>
        <select id="user-role" name="role" value={role}
          onChange={(event) => setRole(event.target.value as SessionUser['role'])} {...errorAttributes('role')}>
          <option value="owner">Dueño</option>
          <option value="issuer">Emisor</option>
          <option value="accountant">Contador</option>
        </select>
        {fieldError('role')}

        {user ? (
          <>
            <label htmlFor="user-is_active">Estado</label>
            <select id="user-is_active" name="is_active" value={String(active)}
              onChange={(event) => setActive(event.target.value === 'true')} {...errorAttributes('is_active')}>
              <option value="true">Activo</option>
              <option value="false">Inactivo</option>
            </select>
            {fieldError('is_active')}
          </>
        ) : (
          <>
            <p id="password-help" className="muted">
              Usa al menos 12 caracteres con mayúsculas, minúsculas y números. Evita contraseñas excesivamente largas.
            </p>
            <label htmlFor="user-password">Contraseña</label>
            <input id="user-password" name="password" type="password" autoComplete="new-password"
              required minLength={12} maxLength={72} value={password}
              onChange={(event) => setPassword(event.target.value)} {...errorAttributes('password')}
              aria-describedby={`password-help${errors.password?.length ? ' user-password-error' : ''}`} />
            {fieldError('password')}
            <label htmlFor="user-password_confirmation">Confirmar contraseña</label>
            <input id="user-password_confirmation" name="password_confirmation" type="password"
              autoComplete="new-password" required maxLength={72} value={confirmation}
              onChange={(event) => setConfirmation(event.target.value)} {...errorAttributes('password_confirmation')} />
            {fieldError('password_confirmation')}
          </>
        )}
        {message && <p className="error" role="alert" ref={errorRef} tabIndex={-1}>{message}</p>}
        <div className="form-actions">
          <button type="button" className="secondary" onClick={onCancel}>Cancelar</button>
          <button type="submit">{saving ? 'Guardando…' : user ? 'Guardar cambios' : 'Crear usuario'}</button>
        </div>
        {saving && <p role="status">Guardando usuario…</p>}
      </fieldset>
    </form>
  )
}
