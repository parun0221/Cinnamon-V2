export const API_ERROR_CODES = [
  'VALIDATION_ERROR',
  'UNAUTHENTICATED',
  'FORBIDDEN',
  'NOT_FOUND',
  'BAD_REQUEST',
  'RATE_LIMITED',
  'SERVER_ERROR',
] as const

export type ApiErrorCode = (typeof API_ERROR_CODES)[number]

export interface ApiError {
  code: ApiErrorCode
  message: string
  fields: Record<string, string[]>
  details: Record<string, unknown>
  requestId: string | null
  status: number
}

export function isApiErrorPayload(payload: unknown): payload is { error: unknown; meta?: unknown } {
  return typeof payload === 'object' && payload !== null && 'error' in payload
}

export function parseApiError(payload: unknown, status: number): ApiError {
  if (isApiErrorPayload(payload) && typeof payload.error === 'object' && payload.error !== null) {
    const error = payload.error as Partial<{
      code: string
      message: string
      fields: Record<string, string[]>
      details: Record<string, unknown>
    }>
    const meta = payload.meta as { request_id?: string } | undefined

    return {
      code: (API_ERROR_CODES as readonly string[]).includes(error.code ?? '')
        ? (error.code as ApiErrorCode)
        : 'SERVER_ERROR',
      message: error.message ?? 'Terjadi kesalahan. Silakan coba lagi.',
      fields: error.fields ?? {},
      details: error.details ?? {},
      requestId: meta?.request_id ?? null,
      status,
    }
  }

  return {
    code: 'SERVER_ERROR',
    message: 'Terjadi kesalahan. Silakan coba lagi.',
    fields: {},
    details: {},
    requestId: null,
    status,
  }
}

/**
 * A 419 (CSRF token mismatch) means the session cookie is stale, which the user
 * must resolve by reloading — it is not the same as "not logged in".
 */
export function isSessionExpired(error: ApiError): boolean {
  return error.status === 419 || error.code === 'UNAUTHENTICATED'
}
