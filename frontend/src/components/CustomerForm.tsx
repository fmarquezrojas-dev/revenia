import { useFormGuard } from '../app/FormGuard'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, createCustomer, customerErrorMessage, updateCustomer } from '../lib/api'
import type { Customer, CustomerInput } from '../lib/api'

interface Props {
  customer?: Customer
  onSaved: (customer: Customer) => void
  onCancel: () => void
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

const fields = [
  { name: 'doc_number', label: 'Número de documento', max: 20, required: true },
  { name: 'name', label: 'Nombre o razón social', max: 200, required: true },
  { name: 'address', label: 'Dirección (opcional)', max: 300, required: false },
  { name: 'email', label: 'Correo electrónico (opcional)', max: 200, required: false },
] as const

export default function CustomerForm({ customer, onSaved, onCancel, onSessionExpired, onSavingChange }: Props) {
  const [values, setValues] = useState<CustomerInput>({
    doc_type: customer?.doc_type ?? 'ruc',
    doc_number: customer?.doc_number ?? '',
    name: customer?.name ?? '',
    address: customer?.address ?? '',
    email: customer?.email ?? '',
  })
  const [saving, setSaving] = useState(false)
  const { dirty, markSaved } = useFormGuard(JSON.stringify(values), saving)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [message, setMessage] = useState('')
  const submitting = useRef(false)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const typeRef = useRef<HTMLSelectElement>(null)

  useEffect(() => { typeRef.current?.focus() }, [])
  useEffect(() => { if (message) errorRef.current?.focus() }, [message])

  function change(field: keyof CustomerInput, value: string) {
    setValues((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: [] }))
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting.current) return
    submitting.current = true
    setSaving(true)
    onSavingChange(true)
    setMessage('')
    setErrors({})
    try {
      const data: CustomerInput = {
        doc_type: values.doc_type,
        doc_number: values.doc_number.trim(),
        name: values.name.trim(),
        address: values.address?.trim() || null,
        email: values.email?.trim() || null,
      }
      const saved = customer ? await updateCustomer(customer.id, data) : await createCustomer(data)
      markSaved()
      onSaved(saved)
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) {
        onSessionExpired()
        return
      }
      if (error instanceof ApiError && error.status === 422) setErrors(error.errors ?? {})
      setMessage(customerErrorMessage(error))
    } finally {
      submitting.current = false
      setSaving(false)
      onSavingChange(false)
    }
  }

  return (
    <form className="customer-form" aria-label={customer ? 'Editar cliente' : 'Nuevo cliente'} onSubmit={submit} aria-busy={saving}>
      <h2>{customer ? 'Editar cliente' : 'Nuevo cliente'}</h2>
      {dirty && <p className="dirty-notice" role="status">Cambios sin guardar</p>}
      <fieldset disabled={saving}>
        <label htmlFor="customer-doc_type">Tipo de documento</label>
        <select id="customer-doc_type" ref={typeRef} value={values.doc_type}
          onChange={(event) => change('doc_type', event.target.value)}
          aria-invalid={Boolean(errors.doc_type?.length)}
          aria-describedby={errors.doc_type?.length ? 'customer-doc_type-error' : undefined}>
          <option value="ruc">RUC</option>
          <option value="dni">DNI</option>
          <option value="other">Otro documento</option>
        </select>
        {errors.doc_type?.length > 0 && <p id="customer-doc_type-error" className="field-error">{errors.doc_type.join(' ')}</p>}
        <p className="muted" id="customer-document-help">
          RUC: 11 dígitos. DNI: 8 dígitos. Otros documentos: hasta 20 caracteres;
          se normalizan a mayúsculas y espacios simples. La validación del RUC no consulta SUNAT.
        </p>
        {fields.map((field) => {
          const errorId = `customer-${field.name}-error`
          const hasError = Boolean(errors[field.name]?.length)
          return (
            <div key={field.name}>
              <label htmlFor={`customer-${field.name}`}>{field.label}</label>
              <input id={`customer-${field.name}`} name={field.name}
                type={field.name === 'email' ? 'email' : 'text'}
                inputMode={field.name === 'doc_number' && values.doc_type !== 'other' ? 'numeric' : undefined}
                maxLength={field.max} required={field.required} value={values[field.name] ?? ''}
                onChange={(event) => change(field.name, event.target.value)}
                aria-invalid={hasError}
                aria-describedby={[field.name === 'doc_number' ? 'customer-document-help' : '', hasError ? errorId : ''].filter(Boolean).join(' ') || undefined} />
              {hasError && <p className="field-error" id={errorId}>{errors[field.name].join(' ')}</p>}
            </div>
          )
        })}
        {message && <p className="error" role="alert" ref={errorRef} tabIndex={-1}>{message}</p>}
        <div className="form-actions">
          <button type="button" className="secondary" onClick={onCancel}>Cancelar</button>
          <button type="submit">{saving ? 'Guardando…' : 'Guardar cliente'}</button>
        </div>
        {saving && <p role="status">Guardando cliente…</p>}
      </fieldset>
    </form>
  )
}
