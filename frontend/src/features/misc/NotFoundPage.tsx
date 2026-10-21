import { Link } from 'react-router-dom'
import './NotFoundPage.css'

export function NotFoundPage() {
  return (
    <section className="not-found">
      <p className="not-found__code">404</p>
      <h1 className="not-found__title">Halaman tidak ditemukan</h1>
      <p className="not-found__message">
        Alamat yang Anda buka tidak tersedia. Modul ini belum tersedia di fase ini, atau tautannya
        salah.
      </p>
      <Link className="not-found__action" to="/">
        Kembali ke Dashboard
      </Link>
    </section>
  )
}
