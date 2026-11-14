import { lastSeriesNumber, seriesTypeLabels } from '../lib/series'
import { useEffect, useRef, useState } from 'react'
import { ApiError, seriesErrorMessage, getSeriesDetail, updateSeries } from '../lib/api'
import type { DocumentSeries } from '../lib/api'

interface Props {
  id: string
  onBack: () => void
  onChanged: () => void
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

export default function SeriesDetails({ id, onBack, onChanged, onSessionExpired, onSavingChange }: Props) {
  const [series, setSeries] = useState<DocumentSeries | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [attempt, setAttempt] = useState(0)
  const submitting = useRef(false)

  useEffect(() => {
    const controller = new AbortController()
    getSeriesDetail(id, controller.signal).then((data) => {
      if (!controller.signal.aborted) setSeries(data)
    }).catch((error: unknown) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else setError(seriesErrorMessage(error))
    })
    return () => controller.abort()
  }, [id, attempt, onSessionExpired])

  function saved(updated: DocumentSeries) {
    setSeries(updated)
    setError('')
    setNotice('Serie actualizada correctamente.')
    onChanged()
  }

  async function toggleActive() {
    if (!series || submitting.current) return
    if (!window.confirm(`¿${series.is_active ? 'Desactivar' : 'Activar'} a ${series.code}?`)) return
    submitting.current = true
    setSaving(true)
    onSavingChange(true)
    setError('')
    setNotice('')
    try {
      saved(await updateSeries(series.id, { is_active: !series.is_active }))
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else setError(seriesErrorMessage(error))
    } finally {
      submitting.current = false
      setSaving(false)
      onSavingChange(false)
    }
  }

  return (
    <div className="detail-panel" aria-busy={saving}>
      {notice && <p className="success-message" role="status">{notice}</p>}
      {error && <p className="error" role="alert">{error}</p>}
      {!series && !error && <p role="status">Cargando serie…</p>}
      {!series && error && <button type="button" className="secondary" onClick={() => { setError(''); setAttempt((value) => value + 1) }}>Reintentar</button>}
      {series && (
        <>
          <h2>{series.code}</h2>
          <dl className="profile">
            <div><dt>Código</dt><dd>{series.code}</dd></div>
            <div><dt>Tipo de comprobante</dt><dd>{seriesTypeLabels[series.doc_type]}</dd></div>
            <div><dt>Último correlativo asignado</dt><dd>{lastSeriesNumber(series)}</dd></div>
            <div><dt>Estado</dt><dd>{series.is_active ? 'Activa' : 'Inactiva'}</dd></div>
          </dl>
          <p className="muted">El tipo y el código son inmutables. Crear una serie no emite comprobantes.</p>
          <div className="form-actions">
            <button type="button" className="secondary" disabled={saving} onClick={toggleActive}>
              {series.is_active ? 'Desactivar' : 'Activar'}
            </button>
          </div>
        </>
      )}
      {saving && <p role="status">Guardando estado…</p>}
      <button type="button" className="secondary" disabled={saving} onClick={onBack}>Volver al listado</button>
    </div>
  )
}
