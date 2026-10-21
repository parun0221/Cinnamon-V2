import { describe, expect, it } from 'vitest'
import { AxiosError, AxiosHeaders } from 'axios'
import { toApiError } from './client'
import { isSessionExpired, parseApiError } from './errors'

function axiosErrorWithStatus(status: number, data: unknown): AxiosError {
  const error = new AxiosError('Request failed')
  error.response = {
    status,
    statusText: '',
    data,
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  }

  return error
}

describe('toApiError', () => {
  it('menerjemahkan 419 menjadi pesan sesi kedaluwarsa', () => {
    const error = toApiError(axiosErrorWithStatus(419, {}))

    expect(error.status).toBe(419)
    expect(error.code).toBe('UNAUTHENTICATED')
    expect(error.message).toBe('Sesi Anda telah berakhir. Silakan muat ulang halaman.')
    expect(isSessionExpired(error)).toBe(true)
  })

  it('membaca kode dan pesan dari envelope error server', () => {
    const error = toApiError(
      axiosErrorWithStatus(422, {
        error: {
          code: 'VALIDATION_ERROR',
          message: 'Validasi gagal.',
          fields: { email: ['Email atau password salah.'] },
          details: {},
        },
        meta: { request_id: 'req-1' },
      }),
    )

    expect(error.code).toBe('VALIDATION_ERROR')
    expect(error.status).toBe(422)
    expect(error.fields.email).toEqual(['Email atau password salah.'])
    expect(error.requestId).toBe('req-1')
  })

  it('menerjemahkan 429 menjadi pesan terlalu banyak percobaan', () => {
    const error = toApiError(axiosErrorWithStatus(429, {}))

    expect(error.status).toBe(429)
    expect(error.code).toBe('RATE_LIMITED')
    expect(error.message).toBe('Terlalu banyak percobaan masuk. Tunggu sebentar lalu coba lagi.')
  })

  it('mengembalikan pesan jaringan untuk kegagalan tanpa respons', () => {
    const error = toApiError(new Error('network down'))

    expect(error.code).toBe('SERVER_ERROR')
    expect(error.status).toBe(0)
    expect(error.message).toBe('Terjadi kesalahan jaringan. Periksa koneksi Anda.')
  })
})

describe('parseApiError', () => {
  it('menyimpan kode yang tidak dikenal sebagai SERVER_ERROR', () => {
    const error = parseApiError({ error: { code: 'WIDGET_ERROR' } }, 500)

    expect(error.code).toBe('SERVER_ERROR')
  })

  it('mengembalikan pesan aman untuk payload yang bukan envelope error', () => {
    const error = parseApiError({ data: [] }, 500)

    expect(error.code).toBe('SERVER_ERROR')
    expect(error.message).toBe('Terjadi kesalahan. Silakan coba lagi.')
    expect(error.requestId).toBeNull()
  })
})
