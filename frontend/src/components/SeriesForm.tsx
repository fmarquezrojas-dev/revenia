import { useFormGuard } from '../app/FormGuard'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, createSeries, seriesErrorMessage } from '../lib/api'
import type { DocumentSeries, SeriesInput } from '../lib/api'
import { seriesTypeLabels } from '../lib/series'
interface Props {
  onSaved: (series: DocumentSeries) => void
  onCancel: () => void
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}
export default function SeriesForm({ onSaved, onCancel, onSessionExpired, onSavingChange }: Props) {
  const [values, setValues] = useState<SeriesInput>({ doc_type: '01', code: '' })
  const [saving, setSaving] = useState(false)
  const { dirty, markSaved } = useFormGuard(JSON.stringify(values), saving)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [message, setMessage] = useState('')
  const submitting = useRef(false)
  const typeRef = useRef<HTMLSelectElement>(null)
  const errorRef = useRef<HTMLParagraphElement>(null)
  useEffect(() => { typeRef.current?.focus() }, [])
  useEffect(() => { if (message) errorRef.current?.focus() }, [message])
  function change(field: keyof SeriesInput, value: string) {
    setValues((current) => ({ ...current, [field]: value }))
    setErrors({})
  }
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting.current) return
    submitting.current = true
    setSaving(true); onSavingChange(true); setErrors({}); setMessage('')
    try {
      const saved = await createSeries(values)
      markSaved()
      onSaved(saved)
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else {
        if (error instanceof ApiError && error.status === 422) setErrors(error.errors ?? {})
        setMessage(seriesErrorMessage(error))
      }
    } finally {
      submitting.current = false; setSaving(false); onSavingChange(false)
    }
  }
  return <form className="customer-form" aria-label="Nueva serie" onSubmit={submit} aria-busy={saving}>
    <h2>Nueva serie</h2>
    {dirty && <p className="dirty-notice" role="status">Cambios sin guardar</p>}
    <fieldset disabled={saving}>
      <label htmlFor="series-doc_type">Tipo de comprobante</label>
      <select id="series-doc_type" ref={typeRef} value={values.doc_type} onChange={(event) => change('doc_type', event.target.value)}
        aria-invalid={Boolean(errors.doc_type?.length)} aria-describedby={errors.doc_type?.length ? 'series-type-error' : undefined}>
        {Object.entries(seriesTypeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
      </select>
      {errors.doc_type?.length > 0 && <p id="series-type-error" className="field-error">{errors.doc_type.join(' ')}</p>}
      <label htmlFor="series-code">Código de serie</label>
      <input id="series-code" value={values.code} onChange={(event) => change('code', event.target.value)} required maxLength={20}
        aria-invalid={Boolean(errors.code?.length)} aria-describedby={`series-help${errors.code?.length ? ' series-code-error' : ''}`} />
      {errors.code?.length > 0 && <p id="series-code-error" className="field-error">{errors.code.join(' ')}</p>}
      <p className="muted" id="series-help">Usa 4 letras o números. Factura: F001; boleta: B001;
        nota de crédito para factura: FC01, para boleta: BC01. Se recortan espacios exteriores
        y se convierten letras a mayúsculas. El tipo y el código no podrán modificarse.</p>
      <p className="muted">Convenciones del simulador. La serie se crea activa y sin emisiones.</p>
      {message && <p className="error" role="alert" ref={errorRef} tabIndex={-1}>{message}</p>}
      <div className="form-actions">
        <button type="button" className="secondary" onClick={onCancel}>Cancelar</button>
        <button type="submit">{saving ? 'Guardando…' : 'Crear serie'}</button>
      </div>
    </fieldset>
  </form>
}
