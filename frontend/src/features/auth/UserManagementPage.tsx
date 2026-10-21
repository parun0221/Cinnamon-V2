import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PageState } from '../../components/feedback/PageState'
import { fetchUsers, toApiError, updateUser } from '../../lib/api/client'
import type { User, UserRole } from '../../lib/api/types'
import { useAuth } from './auth-context'
import './UserManagementPage.css'

const USERS_QUERY_KEY = ['users'] as const

export function UserManagementPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [notice, setNotice] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)

  const usersQuery = useQuery({
    queryKey: USERS_QUERY_KEY,
    queryFn: () => fetchUsers(1),
  })

  const updateMutation = useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string
      payload: { role?: UserRole; is_active?: boolean }
    }) => updateUser(id, payload),
    onSuccess: (updated) => {
      setNotice('Perubahan tersimpan.')
      setFailure(null)
      void queryClient.invalidateQueries({ queryKey: USERS_QUERY_KEY })
      void updated
    },
    onError: (error) => {
      setFailure(toApiError(error).message)
      setNotice(null)
    },
  })

  // The server refuses non-admins either way; hiding the page keeps the UI
  // from offering an action that is certain to be rejected.
  if (user?.role !== 'admin') {
    return (
      <section>
        <h1>Pengguna</h1>
        <PageState
          variant="error"
          title="Akses tidak diizinkan"
          message="Hanya Admin yang dapat mengelola pengguna."
        />
      </section>
    )
  }

  const users = usersQuery.data?.data ?? []

  return (
    <section>
      <h1>Pengguna</h1>
      <p className="users__intro">
        Ubah peran dan status akun. Akun yang dinonaktifkan langsung kehilangan aksesnya, termasuk
        sesi yang sedang berjalan.
      </p>

      {notice && (
        <p className="users__notice" role="status">
          {notice}
        </p>
      )}
      {failure && (
        <p className="users__error" role="alert">
          {failure}
        </p>
      )}

      {usersQuery.isLoading && <PageState variant="loading" />}

      {usersQuery.isError && (
        <PageState
          variant="error"
          message="Daftar pengguna gagal dimuat. Silakan coba lagi."
          onRetry={() => void usersQuery.refetch()}
        />
      )}

      {usersQuery.isSuccess && users.length === 0 && (
        <PageState
          variant="empty"
          title="Belum ada pengguna"
          message="Tidak ada akun yang terdaftar. Tambahkan pengguna pertama untuk mulai mencatat aktivitas."
        />
      )}

      {usersQuery.isSuccess && users.length > 0 && (
        <table className="users__table">
          <caption className="users__caption">Daftar pengguna CINNAMON</caption>
          <thead>
            <tr>
              <th scope="col">Nama</th>
              <th scope="col">Email</th>
              <th scope="col">Peran</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            {users.map((row) => (
              <UserRow
                key={row.id}
                row={row}
                isSelf={row.id === user.id}
                pending={updateMutation.isPending}
                onChange={(payload) => updateMutation.mutate({ id: row.id, payload })}
              />
            ))}
          </tbody>
        </table>
      )}
    </section>
  )
}

interface UserRowProps {
  row: User
  isSelf: boolean
  pending: boolean
  onChange: (payload: { role?: UserRole; is_active?: boolean }) => void
}

function UserRow({ row, isSelf, pending, onChange }: UserRowProps) {
  return (
    <tr>
      <th scope="row" className="users__name">
        {row.name}
      </th>
      <td>{row.email}</td>
      <td>
        <label className="visually-hidden" htmlFor={`role-${row.id}`}>
          Peran untuk {row.name}
        </label>
        <select
          id={`role-${row.id}`}
          className="users__select"
          value={row.role}
          disabled={pending || isSelf}
          title={isSelf ? 'Peran akun sendiri tidak dapat diubah.' : undefined}
          onChange={(event) => onChange({ role: event.target.value as UserRole })}
        >
          <option value="admin">Admin</option>
          <option value="karyawan">Karyawan</option>
        </select>
      </td>
      <td>
        <span className={`users__status users__status--${row.is_active ? 'active' : 'inactive'}`}>
          {row.is_active ? 'Aktif' : 'Nonaktif'}
        </span>
        <button
          type="button"
          className="users__toggle"
          disabled={pending || isSelf}
          title={isSelf ? 'Akun sendiri tidak dapat dinonaktifkan.' : undefined}
          onClick={() => onChange({ is_active: !row.is_active })}
        >
          {row.is_active ? 'Nonaktifkan' : 'Aktifkan'}
        </button>
      </td>
    </tr>
  )
}
