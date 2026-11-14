import { useCallback, useMemo } from 'react'
import { useLocation, useNavigate, useSearchParams } from 'react-router'
type View = { mode: 'list' } | { mode: 'new' } | { mode: 'detail'; id: string }
export function useModuleNavigation(base: string): [View, (view: View) => void] {
  const location = useLocation()
  const navigate = useNavigate()
  const rest = location.pathname.startsWith(base + '/') ? location.pathname.slice(base.length + 1).split('/')[0] : ''
  const view: View = rest === 'new' ? { mode: 'new' } : rest ? { mode: 'detail', id: rest } : { mode: 'list' }
  return [view, (value) => navigate(base + (value.mode === 'list' ? '' : value.mode === 'new' ? '/new' : '/' + encodeURIComponent(value.id)) + location.search)]
}
export function useListFilters<T extends { page: number }>(defaults: T): [T, (value: T | ((current: T) => T)) => void] {
  const [params, setParams] = useSearchParams()
  const serialized = params.toString()
  const defaultsKey = JSON.stringify(defaults)
  const filters = useMemo(() => {
    const result = JSON.parse(defaultsKey) as T
    const search = new URLSearchParams(serialized)
    for (const key of Object.keys(result)) {
      if (key === 'page') {
        const page = Number(search.get('page') || 1)
        result.page = Number.isSafeInteger(page) && page > 0 ? page : 1
      } else if (search.has(key)) Object.assign(result, { [key]: search.get(key) })
    }
    return result
  }, [serialized, defaultsKey])
  const update = useCallback((value: T | ((current: T) => T)) => {
    const next = typeof value === 'function' ? value(filters) : value
    const search = new URLSearchParams(serialized)
    for (const [key, val] of (Object.entries(next) as [string, unknown][])) {
      if (val === '' || (key === 'page' && val === 1)) search.delete(key)
      else search.set(key, String(val))
    }
    setParams(search, { replace: true })
  }, [filters, serialized, setParams])
  return [filters, update]
}
