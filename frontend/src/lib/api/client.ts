import axios, { type AxiosError, type AxiosInstance } from 'axios'
import { isSessionExpired, parseApiError, type ApiError } from './errors'
import type { Envelope, PaginatedEnvelope, User, UserRole } from './types'

const apiClient: AxiosInstance = axios.create({
  // Relative by default so dev traffic goes through the Vite proxy and stays
  // first-party — Sanctum's session cookie is then a same-origin cookie.
  // Deployments serving the API from another origin set VITE_API_URL.
  baseURL: import.meta.env.VITE_API_URL ?? '',
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

/**
 * Called when the server says the session is gone.
 *
 * Without this, a user whose session expired keeps a shell that looks
 * signed in: the sidebar, the topbar, and the page behind it all stay on
 * screen while every request fails, and there is no way back to the login
 * form.
 */
let onSessionExpired: (() => void) | null = null

export function setSessionExpiredHandler(handler: (() => void) | null): void {
  onSessionExpired = handler
}

apiClient.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (axios.isAxiosError(error)) {
      const apiError = toApiError(error)

      // A rejected login is not an expired session — the user is not signed
      // in to begin with, and the login form shows the message itself.
      const isLoginAttempt = error.config?.url?.includes('/auth/login')

      if (!isLoginAttempt && isSessionExpired(apiError)) {
        onSessionExpired?.()
      }
    }

    return Promise.reject(error)
  },
)

export async function fetchCsrf(): Promise<void> {
  await apiClient.get('/sanctum/csrf-cookie')
}

export async function login(
  email: string,
  password: string,
  remember = false,
): Promise<{ data: User } | { error: ApiError }> {
  await fetchCsrf()

  try {
    const response = await apiClient.post<Envelope<User>>('/api/v1/auth/login', {
      email,
      password,
      remember,
    })

    return { data: response.data.data }
  } catch (error) {
    return { error: toApiError(error) }
  }
}

export async function logout(): Promise<void> {
  await apiClient.post('/api/v1/auth/logout')
}

export async function fetchMe(): Promise<User> {
  const response = await apiClient.get<Envelope<User>>('/api/v1/me')

  return response.data.data
}

export async function updateProfile(payload: { name: string }): Promise<User> {
  const response = await apiClient.patch<Envelope<User>>('/api/v1/me', payload)

  return response.data.data
}

export async function updatePassword(payload: {
  current_password: string
  password: string
  password_confirmation: string
}): Promise<void> {
  await apiClient.post('/api/v1/me/password', payload)
}

export async function fetchUsers(page = 1): Promise<PaginatedEnvelope<User>> {
  const { data } = await apiClient.get<PaginatedEnvelope<User>>('/api/v1/users', {
    params: { page },
  })

  return data
}

export async function createUser(payload: {
  name: string
  email: string
  password: string
  role: UserRole
}): Promise<User> {
  const { data } = await apiClient.post<Envelope<User>>('/api/v1/users', payload)

  return data.data
}

export async function updateUser(
  id: string,
  payload: { name?: string; email?: string; role?: UserRole; is_active?: boolean },
): Promise<User> {
  const { data } = await apiClient.patch<Envelope<User>>(`/api/v1/users/${id}`, payload)

  return data.data
}

export function toApiError(error: unknown): ApiError {
  if (axios.isAxiosError(error)) {
    const axiosError = error as AxiosError
    const status = axiosError.response?.status ?? 0

    if (status === 419) {
      return {
        code: 'UNAUTHENTICATED',
        message: 'Sesi Anda telah berakhir. Silakan muat ulang halaman.',
        fields: {},
        details: {},
        requestId: null,
        status: 419,
      }
    }

    if (status === 429) {
      return {
        code: 'RATE_LIMITED',
        message: 'Terlalu banyak percobaan masuk. Tunggu sebentar lalu coba lagi.',
        fields: {},
        details: {},
        requestId: null,
        status: 429,
      }
    }

    return parseApiError(axiosError.response?.data, status)
  }

  return {
    code: 'SERVER_ERROR',
    message: 'Terjadi kesalahan jaringan. Periksa koneksi Anda.',
    fields: {},
    details: {},
    requestId: null,
    status: 0,
  }
}

export default apiClient
