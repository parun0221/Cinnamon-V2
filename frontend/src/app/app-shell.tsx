import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../features/auth/auth-context'
import './app-shell.css'

export function AppShell() {
  const { user, logout } = useAuth()

  return (
    <div className="shell">
      <aside className="shell__sidebar">
        <p className="shell__brand">CINNAMON</p>
        <nav aria-label="Navigasi utama">
          <ul className="shell__nav">
            <li>
              <NavLink to="/" end>
                Dashboard
              </NavLink>
            </li>
            <li>
              <NavLink to="/profile">Profil</NavLink>
            </li>
          </ul>
        </nav>
      </aside>

      <div className="shell__body">
        <header className="shell__topbar">
          <h1 className="shell__page-title">CINNAMON</h1>
          <div className="shell__user">
            <span className="shell__user-name">{user?.name}</span>
            <span className="shell__user-role">{user?.role === 'admin' ? 'Admin' : 'Karyawan'}</span>
            <button type="button" className="shell__logout" onClick={() => void logout()}>
              Keluar
            </button>
          </div>
        </header>

        <main className="shell__content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
