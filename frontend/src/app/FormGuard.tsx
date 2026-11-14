import { createContext, useContext, useEffect, useRef, useState } from 'react'
export interface Guard { dirty: boolean; saving: boolean }
export const FormGuardContext = createContext<{ state: React.RefObject<Guard>; changed: () => void } | null>(null)
export function useFormGuard(signature: string, saving: boolean) {
  const context = useContext(FormGuardContext)
  const initial = useRef(signature)
  const [clean, setClean] = useState(false)
  const dirty = !clean && signature !== initial.current
  useEffect(() => {
    if (!context) return
    context.state.current = { dirty, saving: saving && !clean }
    context.changed()
    return () => { context.state.current = { dirty: false, saving: false } }
  }, [context, dirty, saving, clean])
  return { dirty, markSaved: () => {
    if (context) context.state.current = { dirty: false, saving: false }
    setClean(true)
  } }
}
