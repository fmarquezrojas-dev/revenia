import type { ReactElement } from 'react'
import { render as renderUI } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
export function render(ui: ReactElement, path = '/') {
  const router = createMemoryRouter([{ path: '*', element: ui }], { initialEntries: [path] })
  return { ...renderUI(<RouterProvider router={router} />), router }
}
