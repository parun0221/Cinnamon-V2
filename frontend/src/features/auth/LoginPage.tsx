import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from './auth-context'
import './LoginPage.css'

export function LoginPage() {
  const { user, isLoading, login } = useAuth()
  const navigate = useNavigate()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  if (!isLoading && user) {
    return <Navigate to="/" replace />
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setIsSubmitting(true)
    setError(null)

    try {
      const result = await login(email, password)

      if (result.error) {
        setError(result.error)
        return
      }

      navigate('/', { replace: true })
    } catch {
      setError('Terjadi kesalahan jaringan. Periksa koneksi Anda.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login">
      <form className="login__card" onSubmit={handleSubmit} noValidate>
        <p className="login__brand">CINNAMON</p>
        <h1>Masuk</h1>

        {error && (
          <p className="login__error" role="alert">
            {error}
          </p>
        )}

        <label className="login__field" htmlFor="email">
          Email
          <input
            id="email"
            name="email"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
        </label>

        <label className="login__field" htmlFor="password">
          Password
          <input
            id="password"
            name="password"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </label>

        <button type="submit" className="login__submit" disabled={isSubmitting}>
          {isSubmitting ? 'Memproses…' : 'Masuk'}
        </button>
      </form>
    </main>
  )
}
