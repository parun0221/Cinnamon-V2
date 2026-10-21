import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './AuthProvider'
import { LoginPage } from './LoginPage'
import { login, fetchMe } from '../../lib/api/client'

vi.mock('../../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api/client')>()
  return { ...actual, login: vi.fn(), fetchMe: vi.fn(), logout: vi.fn() }
})

function renderLogin() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <MemoryRouter initialEntries={['/login']}>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/" element={<div>Dashboard</div>} />
          </Routes>
        </MemoryRouter>
      </AuthProvider>
    </QueryClientProvider>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => {
    vi.mocked(fetchMe).mockRejectedValue(new Error('guest'))
  })

  it('menampilkan field email dan password dengan label yang terlihat', () => {
    renderLogin()

    expect(screen.getByLabelText('Email')).toBeInTheDocument()
    expect(screen.getByLabelText('Password')).toBeInTheDocument()
  })

  it('berpindah ke dashboard setelah login berhasil', async () => {
    vi.mocked(login).mockResolvedValue({
      data: {
        id: '0192f0c0-0000-7000-8000-000000000001',
        name: 'Admin',
        email: 'admin@cinnamon.test',
        role: 'admin',
        is_active: true,
        last_login_at: null,
      },
    })

    renderLogin()

    await userEvent.type(screen.getByLabelText('Email'), 'admin@cinnamon.test')
    await userEvent.type(screen.getByLabelText('Password'), 'password')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    await waitFor(() => {
      expect(screen.getByText('Dashboard')).toBeInTheDocument()
    })

    expect(login).toHaveBeenCalledWith('admin@cinnamon.test', 'password', false)
  })

  it('menampilkan pesan error server dan tidak berpindah halaman', async () => {
    vi.mocked(login).mockResolvedValue({
      error: {
        code: 'UNAUTHENTICATED',
        message: 'Email atau password salah.',
        fields: {},
        details: {},
        requestId: 'req-1',
        status: 401,
      },
    })

    renderLogin()

    await userEvent.type(screen.getByLabelText('Email'), 'admin@cinnamon.test')
    await userEvent.type(screen.getByLabelText('Password'), 'salah')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Email atau password salah.')
    expect(screen.queryByText('Dashboard')).not.toBeInTheDocument()
  })

  it('menampilkan pesan jaringan yang bisa dipahami', async () => {
    vi.mocked(login).mockRejectedValue(new Error('network down'))

    renderLogin()

    await userEvent.type(screen.getByLabelText('Email'), 'admin@cinnamon.test')
    await userEvent.type(screen.getByLabelText('Password'), 'password')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Terjadi kesalahan jaringan.')
  })

  it('mempertahankan input setelah error', async () => {
    vi.mocked(login).mockResolvedValue({
      error: {
        code: 'UNAUTHENTICATED',
        message: 'Email atau password salah.',
        fields: {},
        details: {},
        requestId: 'req-1',
        status: 401,
      },
    })

    renderLogin()

    await userEvent.type(screen.getByLabelText('Email'), 'admin@cinnamon.test')
    await userEvent.type(screen.getByLabelText('Password'), 'salah')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    await screen.findByRole('alert')
    expect(screen.getByLabelText('Email')).toHaveValue('admin@cinnamon.test')
  })
})
