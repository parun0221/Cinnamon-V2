import { createContext, useContext } from 'react'
import type { User } from '../../lib/api/types'

export interface AuthContextValue {
  user: User | null
  isLoading: boolean
  login: (email: string, password: string, remember?: boolean) => Promise<{ error?: string }>
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

/**
 * Context and hook live in a file with no React component so the
 * `react-refresh/only-export-components` lint rule stays satisfied.
 */
export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth harus dipakai di dalam AuthProvider.')
  }

  return context
}
