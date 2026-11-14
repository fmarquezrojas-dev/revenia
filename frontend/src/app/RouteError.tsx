import AuthLayout from '../layouts/AuthLayout'
export default function RouteError() {
  return <AuthLayout><h1>No pudimos mostrar esta página</h1><p role="alert">Ocurrió un error inesperado. Recarga la página para volver a intentarlo.</p><button onClick={() => window.location.reload()}>Recargar</button></AuthLayout>
}
