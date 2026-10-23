import { useEffect, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { apiPost } from '../api.js'
import { useAuth } from '../auth/AuthContext.jsx'
import { BrandLogo, ThemeToggle } from '../components/Layout.jsx'

export default function Register() {
  const { status, login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [showPw, setShowPw] = useState(false)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    document.documentElement.dataset.theme = localStorage.getItem('rp_theme') || 'dark'
  }, [])

  if (status === 'in') return <Navigate to="/dashboard" replace />

  const invalid = (key, id) => ({
    'aria-invalid': errors[key] ? 'true' : undefined,
    'aria-describedby': errors[key] ? `${id}-error` : undefined,
  })

  async function handleSubmit(e) {
    e.preventDefault()
    setFormError('')

    if (password !== confirm) {
      setErrors({ confirm: 'The passwords do not match.' })
      return
    }

    setErrors({})
    setBusy(true)
    try {
      await apiPost('/auth/register', { email, password })
      try {
        await login(email, password)
        navigate('/dashboard', { replace: true })
      } catch {
        navigate('/login', { replace: true })
      }
    } catch (err) {
      if (err.fields) setErrors(err.fields)
      else if (err.status === 409) setErrors({ email: err.message })
      else setFormError(err.message)
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
            Build a smarter path to your next finish line.
          </h2>
          <p className="auth-hero-copy">
            Set your experience level, target distance, and preferred training days.
            RunPlan structures weekly mileage safely and adapts as you log runs.
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
          <h1>Create account</h1>
          <p className="auth-subtitle">Start planning your training schedule in under a minute.</p>

          <form className="form" onSubmit={handleSubmit} noValidate>
            <div className="field">
              <label htmlFor="reg-email">Email</label>
              <input
                id="reg-email"
                type="email"
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                {...invalid('email', 'reg-email')}
              />
              {errors.email && <p id="reg-email-error" className="error" role="alert">{errors.email}</p>}
            </div>

            <div className="field">
              <label htmlFor="reg-password">Password</label>
              <div className="pw-wrap">
                <input
                  id="reg-password"
                  type={showPw ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  aria-describedby={errors.password ? 'reg-password-error' : 'reg-password-hint'}
                  aria-invalid={errors.password ? 'true' : undefined}
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
              <small id="reg-password-hint">Use at least 8 characters.</small>
              {errors.password && <p id="reg-password-error" className="error" role="alert">{errors.password}</p>}
            </div>

            <div className="field">
              <label htmlFor="reg-confirm">Confirm password</label>
              <input
                id="reg-confirm"
                type={showPw ? 'text' : 'password'}
                autoComplete="new-password"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                {...invalid('confirm', 'reg-confirm')}
              />
              {errors.confirm && <p id="reg-confirm-error" className="error" role="alert">{errors.confirm}</p>}
            </div>

            {formError && <p className="error" role="alert">{formError}</p>}
            <button type="submit" disabled={busy}>{busy ? 'Creating account...' : 'Create account'}</button>
          </form>

          <p className="auth-footer">
            Already have an account? <Link to="/login">Sign in</Link>
          </p>
        </div>
      </main>
    </div>
  )
}
