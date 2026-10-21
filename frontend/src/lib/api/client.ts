import axios, { type AxiosError, type AxiosInstance } from 'axios'
import { parseApiError, type ApiError } from './errors'
import type { Envelope, User } from './types'

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
