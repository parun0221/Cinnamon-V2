import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import { Sidebar } from '../components/layout/Sidebar'
import { Topbar } from '../components/layout/Topbar'
import { useAuth } from '../features/auth/auth-context'
import { navGroupsForRole } from './navigation'
import './app-shell.css'

export function AppShell() {
  const { user, logout } = useAuth()
  const [menuOpen, setMenuOpen] = useState(false)

  const groups = navGroupsForRole(user?.role)

  return (
    <div className="shell">
      <aside
        id="app-sidebar"
        className={`shell__sidebar ${menuOpen ? 'shell__sidebar--open' : ''}`}
      >
        <Sidebar groups={groups} onNavigate={() => setMenuOpen(false)} />
      </aside>

      <div className="shell__body">
        <Topbar
          title="CINNAMON"
          user={user}
          onLogout={() => void logout()}
          onToggleMenu={() => setMenuOpen((open) => !open)}
          menuOpen={menuOpen}
        />

        <main className="shell__content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
