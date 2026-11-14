import { useLocation, useNavigate } from 'react-router'
import { useEffect, useState } from 'react'
import { ApiError, getCompany } from '../lib/api'
import type { Company } from '../lib/api'
import CompanyEditForm from './CompanyEditForm'

type CompanyState =
  | { status: 'loading' }
  | { status: 'ready'; company: Company }
  | { status: 'error'; message: string; retryable: boolean }

interface CompanyDetailsProps {
  onSessionExpired: () => void
  onCompanyUpdated: (company: Company) => void
}

export default function CompanyDetails({
  onSessionExpired,
  onCompanyUpdated,
}: CompanyDetailsProps) {
  const [state, setState] = useState<CompanyState>({
    status: 'loading',
  })
  const [attempt, setAttempt] = useState(0)
  const location = useLocation()
  const navigate = useNavigate()
  const editing = location.pathname.endsWith('/edit')
  const setEditing = (value: boolean) => navigate(value ? '/company/edit' : '/company')
  const [successMessage, setSuccessMessage] = useState('')

  useEffect(() => {
    const controller = new AbortController()

    getCompany(controller.signal)
      .then((company) => {
        if (!controller.signal.aborted) {
          setState({ status: 'ready', company })
          onCompanyUpdated(company)
        }
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
            message: 'Tu cuenta no tiene permiso para consultar esta sección.',
            retryable: false,
          })
          return
        }

        if (error instanceof ApiError && error.status === 404) {
          setState({
            status: 'error',
            message: 'No se encontró la empresa asociada a tu cuenta.',
            retryable: false,
          })
          return
        }

        setState({
          status: 'error',
          message: 'No pudimos cargar los datos de la empresa.',
          retryable: true,
        })
      })

    return () => {
      controller.abort()
    }
  }, [attempt, onSessionExpired, onCompanyUpdated])

  function retry() {
    setState({ status: 'loading' })
    setAttempt((value) => value + 1)
  }

  function startEditing() {
    setSuccessMessage('')
    setEditing(true)
  }

  function handleSaved(company: Company) {
    setState({ status: 'ready', company })
    onCompanyUpdated(company)
    setEditing(false)
    setSuccessMessage('Los datos de la empresa se guardaron correctamente.')
  }

  if (state.status === 'loading') {
    return (
      <p className="muted" role="status">
        Cargando datos de tu empresa…
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
          <button type="button" className="secondary" onClick={retry}>
            Reintentar
          </button>
        )}
      </div>
    )
  }

  const { company } = state

  if (editing) {
    return (
      <CompanyEditForm
        company={company}
        onSaved={handleSaved}
        onCancel={() => setEditing(false)}
        onSessionExpired={onSessionExpired}
      />
    )
  }

  return (
    <>
      <div className="company-heading">
        <p className="muted">
          Información registrada para tu empresa.
        </p>

        <span className={`status-badge ${company.is_active ? 'active' : ''}`}>
          {company.is_active ? 'Activa' : 'Inactiva'}
        </span>
      </div>

      {successMessage && (
        <p className="success-message" role="status">
          {successMessage}
        </p>
      )}

      <dl className="profile company-data">
        <div>
          <dt>RUC</dt>
          <dd>{company.ruc}</dd>
        </div>

        <div>
          <dt>Razón social</dt>
          <dd>{company.legal_name}</dd>
        </div>

        <div>
          <dt>Nombre comercial</dt>
          <dd>{company.trade_name || 'No registrado'}</dd>
        </div>

        <div>
          <dt>Dirección</dt>
          <dd>{company.address}</dd>
        </div>

        <div>
          <dt>Correo de contacto</dt>
          <dd>{company.email}</dd>
        </div>
      </dl>

      <button
        type="button"
        className="secondary"
        onClick={startEditing}
      >
        Editar datos
      </button>
    </>
  )
}