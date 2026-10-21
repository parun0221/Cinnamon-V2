import { useState, type FormEvent } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toApiError, updatePassword, updateProfile } from '../../lib/api/client'
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

      <PasswordForm />
    </section>
  )
}

function PasswordForm() {
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved'>('idle')
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setStatus('saving')
    setError(null)
    setFieldErrors({})

    try {
      await updatePassword({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      })

      setCurrentPassword('')
      setPassword('')
      setPasswordConfirmation('')
      setStatus('saved')
    } catch (caught) {
      const apiError = toApiError(caught)

      setFieldErrors(apiError.fields)
      setError(apiError.message)
      setStatus('idle')
    }
  }

  return (
    <form className="profile__form" onSubmit={handleSubmit} noValidate>
      <h2>Ganti password</h2>

      {error && (
        <p className="profile__error" role="alert">
          {error}
        </p>
      )}

      <label className="profile__field" htmlFor="profile-current-password">
        Password saat ini
        <input
          id="profile-current-password"
          name="current_password"
          type="password"
          autoComplete="current-password"
          required
          aria-invalid={fieldErrors.current_password ? true : undefined}
          aria-describedby={
            fieldErrors.current_password ? 'profile-current-password-error' : undefined
          }
          value={currentPassword}
          onChange={(event) => setCurrentPassword(event.target.value)}
        />
      </label>
      {fieldErrors.current_password && (
        <p className="profile__field-error" id="profile-current-password-error" role="alert">
          {fieldErrors.current_password.join(' ')}
        </p>
      )}

      <label className="profile__field" htmlFor="profile-new-password">
        Password baru
        <input
          id="profile-new-password"
          name="password"
          type="password"
          autoComplete="new-password"
          required
          minLength={8}
          aria-invalid={fieldErrors.password ? true : undefined}
          aria-describedby={fieldErrors.password ? 'profile-new-password-error' : undefined}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />
      </label>
      {fieldErrors.password && (
        <p className="profile__field-error" id="profile-new-password-error" role="alert">
          {fieldErrors.password.join(' ')}
        </p>
      )}

      <label className="profile__field" htmlFor="profile-password-confirmation">
        Ulangi password baru
        <input
          id="profile-password-confirmation"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          required
          aria-invalid={fieldErrors.password_confirmation ? true : undefined}
          aria-describedby={
            fieldErrors.password_confirmation ? 'profile-password-confirmation-error' : undefined
          }
          value={passwordConfirmation}
          onChange={(event) => setPasswordConfirmation(event.target.value)}
        />
      </label>
      {fieldErrors.password_confirmation && (
        <p className="profile__field-error" id="profile-password-confirmation-error" role="alert">
          {fieldErrors.password_confirmation.join(' ')}
        </p>
      )}

      {status === 'saved' && (
        <p className="profile__saved" role="status">
          Password berhasil diganti.
        </p>
      )}

      <button type="submit" className="profile__submit" disabled={status === 'saving'}>
        {status === 'saving' ? 'Menyimpan…' : 'Ganti password'}
      </button>
    </form>
  )
}
