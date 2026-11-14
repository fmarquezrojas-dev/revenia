import { useFormGuard } from '../app/FormGuard'
import { centsToDecimal, decimalToCents, taxLabels } from '../lib/money'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, createProduct, productErrorMessage, updateProduct } from '../lib/api'
import type { Product, ProductInput } from '../lib/api'

interface Props {
  product?: Product
  onSaved: (product: Product) => void
  onCancel: () => void
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

const fields = [
  { name: 'code', label: 'Código', max: 30 },
  { name: 'description', label: 'Descripción', max: 250 },
  { name: 'price', label: 'Precio unitario sin IGV', max: 100 },
] as const

export default function ProductForm({ product, onSaved, onCancel, onSessionExpired, onSavingChange }: Props) {
  const [values, setValues] = useState({
    code: product?.code ?? '', description: product?.description ?? '',
    unit_code: product?.unit_code ?? 'NIU', currency: product?.currency ?? 'PEN',
    tax_type: product?.tax_type ?? '10', price: product ? centsToDecimal(product.unit_price_cents) : '',
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

  function change(field: keyof typeof values, value: string) {
    setValues((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field === 'price' ? 'unit_price_cents' : field]: [] }))
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
      let cents: number
      try { cents = decimalToCents(values.price) }
      catch (error) {
        const message = error instanceof Error ? error.message : 'Precio inválido.'
        setErrors({ unit_price_cents: [message] }); setMessage(message); return
      }
      const data: ProductInput = {
        code: values.code.trim(), description: values.description.trim(),
        unit_code: values.unit_code, currency: values.currency, tax_type: values.tax_type,
        unit_price_cents: cents,
      }
      const saved = product ? await updateProduct(product.id, data) : await createProduct(data)
      markSaved()
      onSaved(saved)
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) {
        onSessionExpired()
        return
      }
      if (error instanceof ApiError && error.status === 422) setErrors(error.errors ?? {})
      setMessage(productErrorMessage(error))
    } finally {
      submitting.current = false
      setSaving(false)
      onSavingChange(false)
    }
  }

  return (
    <form className="customer-form" aria-label={product ? 'Editar producto' : 'Nuevo producto o servicio'} onSubmit={submit} aria-busy={saving}>
      <h2>{product ? 'Editar producto' : 'Nuevo producto o servicio'}</h2>
      {dirty && <p className="dirty-notice" role="status">Cambios sin guardar</p>}
      <fieldset disabled={saving}>
        {([
          ['currency', 'Moneda', { PEN: 'Soles (PEN)', USD: 'Dólares (USD)' }],
          ['unit_code', 'Unidad', { NIU: 'Unidad de bien (NIU)', ZZ: 'Servicio (ZZ)' }],
          ['tax_type', 'Tipo de afectación', taxLabels],
        ] as const).map(([field, label, options]) => <div key={field}>
          <label htmlFor={`product-${field}`}>{label}</label>
          <select id={`product-${field}`} ref={field === 'currency' ? typeRef : undefined} value={values[field]}
            onChange={(event) => change(field, event.target.value)} aria-invalid={Boolean(errors[field]?.length)}
            aria-describedby={errors[field]?.length ? `product-${field}-error` : undefined}>
            {Object.entries(options).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
          </select>
          {errors[field]?.length > 0 && <p className="field-error" id={`product-${field}-error`}>{errors[field].join(' ')}</p>}
        </div>)}
        <p className="muted" id="product-price-help">Precio sin IGV. Usa punto o coma y hasta dos decimales, sin separadores de miles.
          Máximo: 90071992547409.91. No se realiza conversión de moneda.</p>
        {fields.map((field) => {
          const errorField = field.name === 'price' ? 'unit_price_cents' : field.name
          const errorId = `product-${field.name}-error`
          const hasError = Boolean(errors[errorField]?.length)
          return (
            <div key={field.name}>
              <label htmlFor={`product-${field.name}`}>{field.label}</label>
              <input id={`product-${field.name}`} name={field.name}
                type="text"
                inputMode={field.name === 'price' ? 'decimal' : undefined}
                maxLength={field.max} required value={values[field.name] ?? ''}
                onChange={(event) => change(field.name, event.target.value)}
                aria-invalid={hasError}
                aria-describedby={[field.name === 'price' ? 'product-price-help' : '', hasError ? errorId : ''].filter(Boolean).join(' ') || undefined} />
              {hasError && <p className="field-error" id={errorId}>{errors[errorField].join(' ')}</p>}
            </div>
          )
        })}
        {message && <p className="error" role="alert" ref={errorRef} tabIndex={-1}>{message}</p>}
        <div className="form-actions">
          <button type="button" className="secondary" onClick={onCancel}>Cancelar</button>
          <button type="submit">{saving ? 'Guardando…' : 'Guardar producto'}</button>
        </div>
        {saving && <p role="status">Guardando producto…</p>}
      </fieldset>
    </form>
  )
}
