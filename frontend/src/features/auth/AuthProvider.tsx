import { useCallback, useMemo, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchMe, login as loginRequest, logout as logoutRequest } from '../../lib/api/client'
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

  const loginMutation = useMutation({
    mutationFn: ({ email, password, remember }: { email: string; password: string; remember: boolean }) =>
      loginRequest(email, password, remember),
    onSuccess: (result) => {
      if ('data' in result) {
        queryClient.setQueryData<User>(ME_QUERY_KEY, result.data)
      }
    },
  })

  const logoutMutation = useMutation({
    mutationFn: logoutRequest,
    onSuccess: () => {
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
    await logoutMutation.mutateAsync()
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
