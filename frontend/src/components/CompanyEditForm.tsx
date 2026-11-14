import { useFormGuard } from '../app/FormGuard'
import { useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, updateCompany } from '../lib/api'
import type { Company } from '../lib/api'

interface CompanyEditFormProps {
  company: Company
  onSaved: (company: Company) => void
  onCancel: () => void
  onSessionExpired: () => void
}

type EditableField = 'legal_name' | 'trade_name' | 'address' | 'email'

const fields: {
  name: EditableField
  label: string
  maxLength: number
  required: boolean
  type: 'text' | 'email'
}[] = [
  {
    name: 'legal_name',
    label: 'Razón social',
    maxLength: 200,
    required: true,
    type: 'text',
  },
  {
    name: 'trade_name',
    label: 'Nombre comercial',
    maxLength: 200,
    required: false,
    type: 'text',
  },
  {
    name: 'address',
    label: 'Dirección',
    maxLength: 300,
    required: true,
    type: 'text',
  },
  {
    name: 'email',
    label: 'Correo de contacto',
    maxLength: 200,
    required: true,
    type: 'email',
  },
]

export default function CompanyEditForm({
  company,
  onSaved,
  onCancel,
  onSessionExpired,
}: CompanyEditFormProps) {
  const [values, setValues] = useState<Record<EditableField, string>>({
    legal_name: company.legal_name,
    trade_name: company.trade_name ?? '',
    address: company.address,
    email: company.email,
  })

  const [saving, setSaving] = useState(false)
  const { dirty, markSaved } = useFormGuard(JSON.stringify(values), saving)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [message, setMessage] = useState('')
  const submittingRef = useRef(false)

  function changeField(field: EditableField, value: string) {
    setValues((current) => ({
      ...current,
      [field]: value,
    }))

    setErrors((current) => ({
      ...current,
      [field]: [],
    }))
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (submittingRef.current) return

    submittingRef.current = true
    setSaving(true)
    setErrors({})
    setMessage('')

    try {
      const updated = await updateCompany({
        legal_name: values.legal_name.trim(),
        trade_name: values.trade_name.trim() || null,
        address: values.address.trim(),
        email: values.email.trim(),
      })

      markSaved()
      onSaved(updated)
    } catch (error: unknown) {
      if (error instanceof ApiError) {
        if (error.status === 401) {
          onSessionExpired()
          return
        }

        if (error.status === 422) {
          setErrors(error.errors ?? {})
          setMessage('Revisa los campos indicados.')
          return
        }

        if (error.status === 403) {
          setMessage('Tu cuenta ya no tiene permiso para editar esta empresa.')
          return
        }

        if (error.status === 419) {
          setMessage('La sesión de seguridad venció. Intenta guardar nuevamente.')
          return
        }
      }

      setMessage(
        'No pudimos confirmar el guardado. Tus cambios permanecen en el formulario.',
      )
    } finally {
      submittingRef.current = false
      setSaving(false)
    }
  }

  return (
    <form
      className="company-edit-form"
      onSubmit={handleSubmit}
      aria-busy={saving}
    >
      <h2>Editar empresa</h2>
      <p className="muted">
        Actualiza los datos de contacto e identificación comercial.
        El RUC se conserva.
      </p>

      <div className="readonly-field">
        <span>RUC</span>
        <strong>{company.ruc}</strong>
      </div>

      {dirty && <p className="dirty-notice" role="status">Cambios sin guardar</p>}
      <fieldset disabled={saving}>
        {fields.map((field) => {
          const fieldErrors = errors[field.name] ?? []
          const inputId = `company-${field.name}`
          const errorId = `${inputId}-error`

          return (
            <div key={field.name}>
              <label htmlFor={inputId}>
                {field.label}
                {!field.required && (
                  <span className="optional-label"> — opcional</span>
                )}
              </label>

              <input
                id={inputId}
                name={field.name}
                type={field.type}
                required={field.required}
                maxLength={field.maxLength}
                value={values[field.name]}
                aria-invalid={fieldErrors.length > 0}
                aria-describedby={
                  fieldErrors.length > 0 ? errorId : undefined
                }
                onChange={(event) =>
                  changeField(field.name, event.target.value)
                }
              />

              {fieldErrors.length > 0 && (
                <p id={errorId} className="field-error">
                  {fieldErrors.join(' ')}
                </p>
              )}
            </div>
          )
        })}

        {message && (
          <p className="error" role="alert">
            {message}
          </p>
        )}

        <div className="form-actions">
          <button
            type="button"
            className="secondary"
            onClick={onCancel}
          >
            Cancelar
          </button>

          <button type="submit">
            {saving ? 'Guardando…' : 'Guardar cambios'}
          </button>
        </div>
      </fieldset>
    </form>
  )
}