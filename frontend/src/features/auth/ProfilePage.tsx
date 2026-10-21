import { useState, type FormEvent } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { updateProfile } from '../../lib/api/client'
import { useAuth } from './auth-context'
import './ProfilePage.css'

export function ProfilePage() {
  const { user, refresh } = useAuth()
  const queryClient = useQueryClient()

  const [name, setName] = useState(user?.name ?? '')
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved'>('idle')
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setStatus('saving')
    setError(null)

    try {
      const updated = await updateProfile({ name })
      queryClient.setQueryData(['me'], updated)
      await refresh()
      setStatus('saved')
    } catch {
      setError('Gagal menyimpan perubahan. Silakan coba lagi.')
      setStatus('idle')
    }
  }

  return (
    <section>
      <h1>Profil</h1>

      <form className="profile__form" onSubmit={handleSubmit}>
        <label className="profile__field" htmlFor="profile-name">
          Nama
          <input
            id="profile-name"
            name="name"
            type="text"
            required
            maxLength={120}
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </label>

        <p className="profile__meta">Email: {user?.email}</p>
        <p className="profile__meta">Peran: {user?.role === 'admin' ? 'Admin' : 'Karyawan'}</p>

        {error && (
          <p className="profile__error" role="alert">
            {error}
          </p>
        )}
        {status === 'saved' && (
          <p className="profile__saved" role="status">
            Perubahan tersimpan.
          </p>
        )}

        <button type="submit" className="profile__submit" disabled={status === 'saving'}>
          {status === 'saving' ? 'Menyimpan…' : 'Simpan'}
        </button>
      </form>
    </section>
  )
}
