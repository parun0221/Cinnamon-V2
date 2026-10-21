import { NavLink } from 'react-router-dom'
import type { NavGroup } from '../../app/navigation'
import './Sidebar.css'

interface SidebarProps {
  groups: NavGroup[]
  onNavigate?: () => void
}

export function Sidebar({ groups, onNavigate }: SidebarProps) {
  return (
    <div className="sidebar__inner">
      <p className="sidebar__brand">CINNAMON</p>

      <nav aria-label="Navigasi utama" className="sidebar__nav">
        {groups.map((group) => (
          <div key={group.label} className="sidebar__group">
            <p className="sidebar__group-label">{group.label}</p>
            <ul className="sidebar__list">
              {group.items.map((item) => (
                <li key={item.to}>
                  <NavLink to={item.to} end={item.to === '/'} onClick={onNavigate}>
                    {item.label}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </nav>
    </div>
  )
}
