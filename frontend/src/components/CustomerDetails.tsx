import { Link, useLocation, useNavigate } from 'react-router'
import { useEffect, useRef, useState } from 'react'
import { ApiError, customerErrorMessage, getCustomer, updateCustomer } from '../lib/api'
import type { Customer } from '../lib/api'
import CustomerForm from './CustomerForm'

interface Props {
  id: string
  canWrite: boolean
  onBack: () => void
  onChanged: () => void
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

export default function CustomerDetails({ id, canWrite, onBack, onChanged, onSessionExpired, onSavingChange }: Props) {
  const [customer, setCustomer] = useState<Customer | null>(null)
  const location = useLocation()
  const navigate = useNavigate()
  const editing = location.pathname.endsWith('/edit')
  const setEditing = (value: boolean) => navigate('/customers/' + encodeURIComponent(id) + (value ? '/edit' : '') + location.search)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [attempt, setAttempt] = useState(0)
  const submitting = useRef(false)

  useEffect(() => {
    const controller = new AbortController()
    getCustomer(id, controller.signal).then((data) => {
      if (!controller.signal.aborted) setCustomer(data)
    }).catch((error: unknown) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else setError(customerErrorMessage(error))
    })
    return () => controller.abort()
  }, [id, attempt, onSessionExpired])

  function saved(updated: Customer) {
    setCustomer(updated)
    if (editing) setEditing(false)
    setError('')
    setNotice('Cliente guardado correctamente.')
    onChanged()
  }

  async function toggleActive() {
    if (!customer || !canWrite || submitting.current) return
    if (!window.confirm(`¿${customer.is_active ? 'Desactivar' : 'Reactivar'} a ${customer.name}?`)) return
    submitting.current = true
    setSaving(true)
    onSavingChange(true)
    setError('')
    setNotice('')
    try {
      saved(await updateCustomer(customer.id, { is_active: !customer.is_active }))
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else setError(customerErrorMessage(error))
    } finally {
      submitting.current = false
      setSaving(false)
      onSavingChange(false)
    }
  }

  if (editing && !canWrite) return <p role="alert" className="error">Tu rol solo permite consultar.</p>

  if (editing && customer && canWrite) return (
    <CustomerForm customer={customer} onSaved={saved} onCancel={() => setEditing(false)}
      onSessionExpired={onSessionExpired} onSavingChange={onSavingChange} />
  )

  return (
    <div className="detail-panel" aria-busy={saving}>
      {notice && <p className="success-message" role="status">{notice}</p>}
      {error && <p className="error" role="alert">{error}</p>}
      {!customer && !error && <p role="status">Cargando cliente…</p>}
      {!customer && error && <button type="button" className="secondary" onClick={() => { setError(''); setAttempt((value) => value + 1) }}>Reintentar</button>}
      {customer && (
        <>
          <h2>{customer.name}</h2>
          <dl className="profile">
            <div><dt>Documento</dt><dd>{customer.doc_type.toUpperCase()} {customer.doc_number}</dd></div>
            <div><dt>Estado</dt><dd>{customer.is_active ? 'Activo' : 'Inactivo'}</dd></div>
            <div><dt>Dirección</dt><dd>{customer.address || 'No registrada'}</dd></div>
            <div><dt>Correo electrónico</dt><dd>{customer.email || 'No registrado'}</dd></div>
          </dl>
          <div className="form-actions">
            <Link to={'/customers/' + customer.id + '/statement'}>Estado de cuenta</Link>
            <Link to={'/bank-receipts?customer_id=' + customer.id}>Cobros bancarios del cliente</Link>
          </div>
          {canWrite && <div className="form-actions">
            <button type="button" disabled={saving} onClick={() => { setNotice(''); setEditing(true) }}>Editar cliente</button>
            <button type="button" className="secondary" disabled={saving} onClick={toggleActive}>
              {customer.is_active ? 'Desactivar' : 'Reactivar'}
            </button>
          </div>}
        </>
      )}
      {saving && <p role="status">Guardando estado…</p>}
      <button type="button" className="secondary" disabled={saving} onClick={onBack}>Volver al listado</button>
    </div>
  )
}
