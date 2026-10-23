import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import { BrandLogo, ThemeToggle } from '../components/Layout.jsx'

export default function Login() {
  const { status, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPw, setShowPw] = useState(false)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    document.documentElement.dataset.theme = localStorage.getItem('rp_theme') || 'dark'
  }, [])

  const from = location.state?.from?.pathname ?? '/dashboard'

  if (status === 'in') return <Navigate to={from} replace />

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      await login(email, password)
      navigate(from, { replace: true })
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="auth-split">
      <section className="auth-hero" aria-label="About RunPlan AI">
        <div>
          <BrandLogo />
        </div>

        <div className="auth-hero-body">
          <p className="auth-eyebrow">Adaptive Marathon Training Planner</p>
          <h2 className="auth-hero-title">
            See how every stride shapes race day.
          </h2>
          <p className="auth-hero-copy">
            RunPlan builds validated weekly schedules around your goal and available days,
            turning logged manual runs into pace history, adherence metrics, and safe plan revisions.
          </p>
          <svg className="auth-sparkline" viewBox="0 0 340 46" fill="none" aria-hidden="true">
            <polyline
              points="4,36 52,32 96,26 140,30 186,18 232,22 282,10 336,14"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
        </div>

        <div className="auth-hero-foot">Plan &middot; Train &middot; Adapt</div>
      </section>

      <main className="auth-panel">
        <div className="auth-panel-top">
          <ThemeToggle />
        </div>

        <div className="auth-card">
          <h1>Sign in</h1>
          <p className="auth-subtitle">Enter your runner account to open your training dashboard.</p>

          <form className="form" onSubmit={handleSubmit} noValidate>
            <div className="field">
              <label htmlFor="email">Email</label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>

            <div className="field">
              <label htmlFor="password">Password</label>
              <div className="pw-wrap">
                <input
                  id="password"
                  type={showPw ? 'text' : 'password'}
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                />
                <button
                  type="button"
                  className="pw-toggle"
                  onClick={() => setShowPw((s) => !s)}
                  aria-label={showPw ? 'Hide password' : 'Show password'}
                >
                  {showPw ? 'Hide' : 'Show'}
                </button>
              </div>
            </div>

            {error && <p className="error" role="alert">{error}</p>}
            <button type="submit" disabled={busy}>{busy ? 'Signing in...' : 'Sign in'}</button>
          </form>

          <p className="auth-footer">
            New here? <Link to="/register">Create an account</Link>
          </p>
        </div>
      </main>
    </div>
  )
}
