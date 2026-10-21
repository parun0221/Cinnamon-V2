import type { User } from '../../lib/api/types'
import './Topbar.css'

interface TopbarProps {
  title: string
  user: User | null
  onLogout: () => void
  onToggleMenu: () => void
  menuOpen: boolean
}

export function Topbar({ title, user, onLogout, onToggleMenu, menuOpen }: TopbarProps) {
  return (
    <header className="topbar">
      <button
        type="button"
        className="topbar__menu-toggle"
        aria-expanded={menuOpen}
        aria-controls="app-sidebar"
        onClick={onToggleMenu}
      >
        <span className="topbar__menu-icon" aria-hidden="true">
          ☰
        </span>
        Menu
      </button>

      <h1 className="topbar__title">{title}</h1>

      <div className="topbar__user">
        <span className="topbar__user-name">{user?.name}</span>
        <span className="topbar__user-role">{user?.role === 'admin' ? 'Admin' : 'Karyawan'}</span>
        <button type="button" className="topbar__logout" onClick={onLogout}>
          Keluar
        </button>
      </div>
    </header>
  )
}
