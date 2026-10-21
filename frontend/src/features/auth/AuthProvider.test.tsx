import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './AuthProvider'
import { useAuth } from './auth-context'
import { fetchMe, logout } from '../../lib/api/client'

vi.mock('../../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api/client')>()
  return { ...actual, login: vi.fn(), fetchMe: vi.fn(), logout: vi.fn() }
})

const ADMIN = {
  id: '0192f0c0-0000-7000-8000-000000000003',
  name: 'Admin',
  email: 'admin@cinnamon.test',
  role: 'admin' as const,
  is_active: true,
  last_login_at: null,
}

function LogoutButton() {
  const { user, logout: signOut } = useAuth()

  return (
    <>
      <span>{user ? user.email : 'tamu'}</span>
      <button type="button" onClick={() => void signOut()}>
        Keluar
      </button>
    </>
  )
}

function renderProvider() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AuthProvider>
          <LogoutButton />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AuthProvider logout', () => {
  beforeEach(() => {
    vi.mocked(fetchMe).mockResolvedValue(ADMIN)
  })

  it('membersihkan session lokal meski permintaan logout ke server gagal', async () => {
    vi.mocked(logout).mockRejectedValue(new Error('419 CSRF mismatch'))

    renderProvider()

    await waitFor(() => {
      expect(screen.getByText('admin@cinnamon.test')).toBeInTheDocument()
    })

    await userEvent.click(screen.getByRole('button', { name: 'Keluar' }))

    await waitFor(() => {
      expect(logout).toHaveBeenCalled()
    })

    await waitFor(() => {
      expect(screen.getByText('tamu')).toBeInTheDocument()
    })
  })

  it('membersihkan session lokal ketika logout berhasil', async () => {
    vi.mocked(logout).mockResolvedValue(undefined)

    renderProvider()

    await waitFor(() => {
      expect(screen.getByText('admin@cinnamon.test')).toBeInTheDocument()
    })

    await userEvent.click(screen.getByRole('button', { name: 'Keluar' }))

    await waitFor(() => {
      expect(screen.getByText('tamu')).toBeInTheDocument()
    })
  })
})
