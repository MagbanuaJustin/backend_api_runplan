import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import ConfirmDialog from './ConfirmDialog.jsx'

const tabs = [
  { to: '/dashboard', label: 'Dashboard' },
  { to: '/calendar', label: 'Calendar' },
  { to: '/activities', label: 'Log run' },
  { to: '/plan', label: 'Plan' },
  { to: '/profile', label: 'Profile' },
]

export default function Layout() {
  const { user, logout } = useAuth()
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)

  async function handleLogout() {
    setBusy(true)
    try {
      await logout() // clears the session, then ProtectedRoute redirects to /login
    } catch {
      /* the session is cleared locally even if the request fails */
    }
  }

  return (
    <>
      <header className="topbar">
        <small className="topbar-email">{user?.email}</small>
        <NavLink className="topbar-link" to="/status">System</NavLink>
        <button type="button" onClick={() => setConfirming(true)}>Log out</button>
      </header>
      <main className="shell">
        <Outlet />
      </main>
      <nav className="tabbar" aria-label="Main">
        {tabs.map((t) => (
          <NavLink key={t.to} to={t.to}>{t.label}</NavLink>
        ))}
      </nav>

      <ConfirmDialog
        open={confirming}
        title="Log out?"
        message="You will need to sign in again to see your training."
        confirmLabel="Log out"
        busy={busy}
        onConfirm={handleLogout}
        onCancel={() => setConfirming(false)}
      />
    </>
  )
}
