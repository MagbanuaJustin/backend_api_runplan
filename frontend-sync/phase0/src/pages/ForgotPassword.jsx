import { useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { apiPost } from '../api.js'
import { useAuth } from '../auth/AuthContext.jsx'
import { BrandLogo, ThemeToggle } from '../components/Layout.jsx'
import Field from '../components/Field.jsx'

export default function ForgotPassword() {
  const { status } = useAuth()
  const [email, setEmail] = useState('')
  const [fieldError, setFieldError] = useState('')
  const [error, setError] = useState('')
  const [result, setResult] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    document.documentElement.dataset.theme = localStorage.getItem('rp_theme') || 'dark'
  }, [])

  if (status === 'in') return <Navigate to="/dashboard" replace />

  async function handleSubmit(e) {
    e.preventDefault()
    setFieldError('')
    setError('')
    setResult(null)
    setBusy(true)
    try {
      const body = await apiPost('/auth/forgot-password', { email: email.trim() })
      setResult(body.data)
    } catch (err) {
      if (err.fields?.email) {
        setFieldError(err.fields.email)
      } else {
        setError(err.message)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="auth-split">
      <section className="auth-hero" aria-label="Account Recovery">
        <div>
          <BrandLogo />
        </div>

        <div className="auth-hero-body">
          <p className="auth-eyebrow">Account Recovery</p>
          <h2 className="auth-hero-title">
            Get back to your training block safely.
          </h2>
          <p className="auth-hero-copy">
            Password reset links are single-use, HMAC-hashed in storage, and expire after 30 minutes.
            Completing a reset automatically revokes any older signed-in sessions.
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
          <h1>Reset password</h1>
          <p className="auth-subtitle">
            Enter the email address for your RunPlan account and we&apos;ll generate a recovery link.
          </p>

          {result ? (
            <div>
              <p className="success" role="status">{result.message}</p>

              {result.dev_reset?.reset_path && (
                <div className="advisory-banner" style={{ marginBottom: '14px' }}>
                  <strong>Local Dev Preview:</strong> Email delivery is not attached in local mode, so you can test the reset link directly ({result.dev_reset.expires_in_minutes} min expiry):
                  <div className="btn-row" style={{ marginTop: '10px' }}>
                    <Link className="btn btn-primary" to={result.dev_reset.reset_path} style={{ width: '100%' }}>
                      Open reset password form
                    </Link>
                  </div>
                </div>
              )}

              <button
                type="button"
                className="btn btn-secondary"
                style={{ width: '100%' }}
                onClick={() => { setResult(null); setEmail('') }}
              >
                Send another link
              </button>
            </div>
          ) : (
            <form className="form" onSubmit={handleSubmit} noValidate>
              <Field id="email" label="Account email" error={fieldError}>
                <input
                  id="email"
                  type="email"
                  autoComplete="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  aria-invalid={Boolean(fieldError)}
                  required
                />
              </Field>

              {error && <p className="error" role="alert">{error}</p>}
              <button type="submit" disabled={busy}>
                {busy ? 'Generating link...' : 'Send recovery link'}
              </button>
            </form>
          )}

          <p className="auth-footer">
            Remembered your password? <Link to="/login">Back to sign in</Link>
          </p>
        </div>
      </main>
    </div>
  )
}
