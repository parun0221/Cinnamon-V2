import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './AuthProvider'
import { AuthGuard } from './AuthGuard'
import { fetchMe } from '../../lib/api/client'

vi.mock('../../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api/client')>()
  return { ...actual, login: vi.fn(), fetchMe: vi.fn(), logout: vi.fn() }
})

function renderGuard() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <MemoryRouter initialEntries={['/rahasia']}>
          <Routes>
            <Route element={<AuthGuard />}>
              <Route path="/rahasia" element={<div>Halaman Rahasia</div>} />
            </Route>
            <Route path="/login" element={<div>Layar Login</div>} />
          </Routes>
        </MemoryRouter>
      </AuthProvider>
    </QueryClientProvider>,
  )
}

describe('AuthGuard', () => {
  beforeEach(() => {
    vi.mocked(fetchMe).mockRejectedValue(new Error('guest'))
  })

  it('mengalihkan tamu ke layar login', async () => {
    renderGuard()

    await waitFor(() => {
      expect(screen.getByText('Layar Login')).toBeInTheDocument()
    })

    expect(screen.queryByText('Halaman Rahasia')).not.toBeInTheDocument()
  })

  it('merender halaman terproteksi untuk user yang sudah login', async () => {
    vi.mocked(fetchMe).mockResolvedValue({
      id: '0192f0c0-0000-7000-8000-000000000002',
      name: 'Karyawan',
      email: 'karyawan@cinnamon.test',
      role: 'karyawan',
      is_active: true,
      last_login_at: null,
    })

    renderGuard()

    await waitFor(() => {
      expect(screen.getByText('Halaman Rahasia')).toBeInTheDocument()
    })
  })
})
