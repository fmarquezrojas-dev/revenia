import { useLocation, useNavigate } from 'react-router'
import { centsToDecimal, taxLabels } from '../lib/money'
import { useEffect, useRef, useState } from 'react'
import { ApiError, productErrorMessage, getProduct, updateProduct } from '../lib/api'
import type { Product } from '../lib/api'
import ProductForm from './ProductForm'

interface Props {
  id: string
  canWrite: boolean
  onBack: () => void
  onChanged: () => void
  onSessionExpired: () => void
  onSavingChange: (saving: boolean) => void
}

export default function ProductDetails({ id, canWrite, onBack, onChanged, onSessionExpired, onSavingChange }: Props) {
  const [product, setProduct] = useState<Product | null>(null)
  const location = useLocation()
  const navigate = useNavigate()
  const editing = location.pathname.endsWith('/edit')
  const setEditing = (value: boolean) => navigate('/products/' + encodeURIComponent(id) + (value ? '/edit' : '') + location.search)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [attempt, setAttempt] = useState(0)
  const submitting = useRef(false)

  useEffect(() => {
    const controller = new AbortController()
    getProduct(id, controller.signal).then((data) => {
      if (!controller.signal.aborted) setProduct(data)
    }).catch((error: unknown) => {
      if (controller.signal.aborted) return
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else setError(productErrorMessage(error))
    })
    return () => controller.abort()
  }, [id, attempt, onSessionExpired])

  function saved(updated: Product) {
    setProduct(updated)
    if (editing) setEditing(false)
    setError('')
    setNotice('Producto guardado correctamente.')
    onChanged()
  }

  async function toggleActive() {
    if (!product || !canWrite || submitting.current) return
    if (!window.confirm(`¿${product.is_active ? 'Desactivar' : 'Reactivar'} a ${product.description}?`)) return
    submitting.current = true
    setSaving(true)
    onSavingChange(true)
    setError('')
    setNotice('')
    try {
      saved(await updateProduct(product.id, { is_active: !product.is_active }))
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) onSessionExpired()
      else setError(productErrorMessage(error))
    } finally {
      submitting.current = false
      setSaving(false)
      onSavingChange(false)
    }
  }

  if (editing && !canWrite) return <p role="alert" className="error">Tu rol solo permite consultar.</p>

  if (editing && product && canWrite) return (
    <ProductForm product={product} onSaved={saved} onCancel={() => setEditing(false)}
      onSessionExpired={onSessionExpired} onSavingChange={onSavingChange} />
  )

  return (
    <div className="detail-panel" aria-busy={saving}>
      {notice && <p className="success-message" role="status">{notice}</p>}
      {error && <p className="error" role="alert">{error}</p>}
      {!product && !error && <p role="status">Cargando producto…</p>}
      {!product && error && <button type="button" className="secondary" onClick={() => { setError(''); setAttempt((value) => value + 1) }}>Reintentar</button>}
      {product && (
        <>
          <h2>{product.description}</h2>
          <dl className="profile">
            <div><dt>Código</dt><dd>{product.code}</dd></div>
            <div><dt>Precio unitario sin IGV</dt><dd>{centsToDecimal(product.unit_price_cents)} {product.currency}</dd></div>
            <div><dt>Unidad</dt><dd>{product.unit_code === 'NIU' ? 'Unidad de bien (NIU)' : 'Servicio (ZZ)'}</dd></div>
            <div><dt>Afectación</dt><dd>{taxLabels[product.tax_type]}</dd></div>
            <div><dt>Estado</dt><dd>{product.is_active ? 'Activo' : 'Inactivo'}</dd></div>
          </dl>
          {canWrite && <div className="form-actions">
            <button type="button" disabled={saving} onClick={() => { setNotice(''); setEditing(true) }}>Editar producto</button>
            <button type="button" className="secondary" disabled={saving} onClick={toggleActive}>
              {product.is_active ? 'Desactivar' : 'Reactivar'}
            </button>
          </div>}
        </>
      )}
      {saving && <p role="status">Guardando estado…</p>}
      <button type="button" className="secondary" disabled={saving} onClick={onBack}>Volver al listado</button>
    </div>
  )
}
