import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import ConfirmDialog from './ConfirmDialog.jsx'

export function BrandLogo() {
  return (
    <span className="brand">
      <span className="brand-icon" aria-hidden="true">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M3 12h4l3-7 4 14 3-7h4" />
        </svg>
      </span>
      <span>RunPlan</span>
    </span>
  )
}

export function ThemeToggle() {
  const [theme, setTheme] = useState(() => localStorage.getItem('rp_theme') || 'dark')

  useEffect(() => {
    document.documentElement.dataset.theme = theme
    localStorage.setItem('rp_theme', theme)
  }, [theme])

  const next = theme === 'dark' ? 'light' : 'dark'

  return (
    <button
      type="button"
      className="theme-btn"
      aria-label={`Switch to ${next} theme`}
      title={`Switch to ${next} theme`}
      onClick={() => setTheme(next)}
    >
      {theme === 'dark' ? (
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="4" />
          <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
        </svg>
      ) : (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
        </svg>
      )}
    </button>
  )
}

const NAV_GROUPS = [
  {
    label: null,
    items: [
      {
        to: '/dashboard',
        label: 'Dashboard',
        icon: (
          <svg className="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1.5" />
            <rect x="14" y="3" width="7" height="7" rx="1.5" />
            <rect x="3" y="14" width="7" height="7" rx="1.5" />
            <rect x="14" y="14" width="7" height="7" rx="1.5" />
          </svg>
        ),
      },
    ],
  },
  {
    label: 'Training',
    items: [
      {
        to: '/calendar',
        label: 'Calendar',
        icon: (
          <svg className="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" />
            <path d="M16 2v4M8 2v4M3 10h18" />
          </svg>
        ),
      },
      {
        to: '/activities',
        label: 'Log a Run',
        icon: (
          <svg className="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
          </svg>
        ),
      },
    ],
  },
  {
    label: 'Planning',
    items: [
      {
        to: '/plan',
        label: 'Training Plan',
        icon: (
          <svg className="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 2L2 7l10 5 10-5-10-5z" />
            <path d="M2 17l10 5 10-5M2 12l10 5 10-5" />
          </svg>
        ),
      },
      {
        to: '/profile',
        label: 'Runner Profile',
        icon: (
          <svg className="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
            <circle cx="12" cy="7" r="4" />
          </svg>
        ),
      },
    ],
  },
  {
    label: 'System',
    items: [
      {
        to: '/status',
        label: 'System Status',
        icon: (
          <svg className="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <rect x="2" y="3" width="20" height="7" rx="2" />
            <rect x="2" y="14" width="20" height="7" rx="2" />
            <path d="M6 6.5h.01M6 17.5h.01" />
          </svg>
        ),
      },
    ],
  },
]

const PAGE_TITLES = {
  '/dashboard': 'Dashboard',
  '/calendar': 'Training Calendar',
  '/activities': 'Log a Run',
  '/plan': 'Training Plan',
  '/profile': 'Runner Profile',
  '/status': 'System Status',
}

export default function Layout() {
  const { user, logout } = useAuth()
  const location = useLocation()
  const [confirming, setConfirming] = useState(false)
  const [menuOpen, setMenuOpen] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    const saved = localStorage.getItem('rp_theme') || 'dark'
    document.documentElement.dataset.theme = saved
  }, [])

  async function handleLogout() {
    setBusy(true)
    try {
      await logout()
    } catch {
      /* session cleared locally */
    }
  }

  const initial = (user?.email?.[0] ?? 'R').toUpperCase()
  const pageTitle = PAGE_TITLES[location.pathname] ?? 'RunPlan AI'

  return (
    <div className="app-frame">
      {menuOpen && (
        <div className="sidebar-backdrop" onClick={() => setMenuOpen(false)} aria-hidden="true" />
      )}

      <aside className={`sidebar${menuOpen ? ' open' : ''}`} aria-label="Sidebar">
        <div className="sidebar-brand">
          <BrandLogo />
        </div>

        <nav className="sidebar-nav" aria-label="Main">
          {NAV_GROUPS.map((group, idx) => (
            <div key={group.label ?? idx} className="nav-group">
              {group.label && <span className="nav-group-label">{group.label}</span>}
              {group.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  className="nav-link"
                  onClick={() => setMenuOpen(false)}
                >
                  {item.icon}
                  <span>{item.label}</span>
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <div className="sidebar-footer">
          <div className="user-pill">
            <span className="user-avatar" aria-hidden="true">{initial}</span>
            <div className="user-meta">
              <span className="user-email" title={user?.email}>{user?.email}</span>
              <span className="user-role">{user?.role ?? 'runner'}</span>
            </div>
          </div>
          <button type="button" className="logout-btn" onClick={() => setConfirming(true)}>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            <span>Log out</span>
          </button>
        </div>
      </aside>

      <div className="workspace">
        <header className="workspace-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <button
              type="button"
              className="theme-btn mobile-menu-btn"
              aria-label="Open navigation menu"
              onClick={() => setMenuOpen((o) => !o)}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <path d="M4 6h16M4 12h16M4 18h16" />
              </svg>
            </button>
            <h1 className="workspace-title">{pageTitle}</h1>
          </div>
          <ThemeToggle />
        </header>

        <main className="shell">
          <Outlet />
        </main>
      </div>

      <ConfirmDialog
        open={confirming}
        title="Log out?"
        message="You will need to sign in again to access your training dashboard."
        confirmLabel="Log out"
        busy={busy}
        onConfirm={handleLogout}
        onCancel={() => setConfirming(false)}
      />
    </div>
  )
}
