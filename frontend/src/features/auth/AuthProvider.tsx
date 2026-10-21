import { useCallback, useEffect, useMemo, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchMe,
  login as loginRequest,
  logout as logoutRequest,
  setSessionExpiredHandler,
} from '../../lib/api/client'
import type { User } from '../../lib/api/types'
import { AuthContext, type AuthContextValue } from './auth-context'

const ME_QUERY_KEY = ['me'] as const

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const meQuery = useQuery({
    queryKey: ME_QUERY_KEY,
    queryFn: fetchMe,
    retry: false,
    staleTime: 1000 * 60,
  })

  useEffect(() => {
    // When the server reports the session is gone, the local session is
    // dropped too. The auth guard then sends the user to the login form
    // instead of leaving them on a shell that looks signed in while every
    // request keeps failing.
    setSessionExpiredHandler(() => {
      queryClient.setQueryData<User | null>(ME_QUERY_KEY, null)
    })

    return () => setSessionExpiredHandler(null)
  }, [queryClient])

  const loginMutation = useMutation({
    mutationFn: ({
      email,
      password,
      remember,
    }: {
      email: string
      password: string
      remember: boolean
    }) => loginRequest(email, password, remember),
    onSuccess: (result) => {
      if ('data' in result) {
        queryClient.setQueryData<User>(ME_QUERY_KEY, result.data)
      }
    },
  })

  const logoutMutation = useMutation({
    mutationFn: logoutRequest,
    onSettled: () => {
      // The local session is dropped even if the server call fails (e.g. a
      // stale CSRF token returns 419). Leaving the user "logged in" after they
      // pressed "Keluar" would be worse than an orphan server session.
      queryClient.setQueryData<User | null>(ME_QUERY_KEY, null)
    },
  })

  const login = useCallback(
    async (email: string, password: string, remember = false) => {
      const result = await loginMutation.mutateAsync({ email, password, remember })

      if ('error' in result) {
        return { error: result.error.message }
      }

      return {}
    },
    [loginMutation],
  )

  const logout = useCallback(async () => {
    try {
      await logoutMutation.mutateAsync()
    } catch {
      // The server call failed, but the local session is already cleared by the
      // mutation's onSettled handler.
    }
  }, [logoutMutation])

  const refresh = useCallback(async () => {
    await queryClient.invalidateQueries({ queryKey: ME_QUERY_KEY })
  }, [queryClient])

  const value = useMemo<AuthContextValue>(
    () => ({
      user: meQuery.data ?? null,
      isLoading: meQuery.isLoading,
      login,
      logout,
      refresh,
    }),
    [meQuery.data, meQuery.isLoading, login, logout, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
