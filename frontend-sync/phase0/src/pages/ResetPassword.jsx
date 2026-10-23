import { useEffect, useState } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { apiPost } from '../api.js'
import { useAuth } from '../auth/AuthContext.jsx'
import { BrandLogo, ThemeToggle } from '../components/Layout.jsx'
import Field from '../components/Field.jsx'

export default function ResetPassword() {
  const { status } = useAuth()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()

  const urlToken = searchParams.get('token') ?? ''
  const [token, setToken] = useState(urlToken)
  const [password, setPassword] = useState('')
  const [confirmPw, setConfirmPw] = useState('')
  const [showPw, setShowPw] = useState(false)
  const [fields, setFields] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    document.documentElement.dataset.theme = localStorage.getItem('rp_theme') || 'dark'
  }, [])

  useEffect(() => {
    if (urlToken) setToken(urlToken)
  }, [urlToken])

  if (status === 'in') return <Navigate to="/dashboard" replace />

  async function handleSubmit(e) {
    e.preventDefault()
    setFields({})
    setError('')

    const nextFields = {}
    if (!token.trim()) nextFields.token = 'Paste or open a valid password reset token.'
    if (password.length < 8) nextFields.password = 'Use at least 8 characters.'
    if (password !== confirmPw) nextFields.confirmPw = 'Passwords do not match.'
    if (Object.keys(nextFields).length > 0) {
      setFields(nextFields)
      return
    }

    setBusy(true)
    try {
      const body = await apiPost('/auth/reset-password', {
        token: token.trim(),
        password,
      })
      navigate('/login', {
        replace: true,
        state: { notice: body.data?.message || 'Password updated. Sign in with your new password.' },
      })
    } catch (err) {
      if (err.fields) setFields(err.fields)
      else setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="auth-split">
      <section className="auth-hero" aria-label="Choose a New Password">
        <div>
          <BrandLogo />
        </div>

        <div className="auth-hero-body">
          <p className="auth-eyebrow">Credential Rotation</p>
          <h2 className="auth-hero-title">
            Choose a strong new password.
          </h2>
          <p className="auth-hero-copy">
            Once saved, this recovery token is permanently consumed and any active refresh sessions on other browsers are revoked immediately.
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
          <h1>Set new password</h1>
          <p className="auth-subtitle">Use 8 to 72 characters for your updated RunPlan password.</p>

          <form className="form" onSubmit={handleSubmit} noValidate>
            {!urlToken && (
              <Field id="token" label="Reset token" error={fields.token}>
                <input
                  id="token"
                  type="text"
                  className="mono"
                  value={token}
                  onChange={(e) => setToken(e.target.value)}
                  aria-invalid={Boolean(fields.token)}
                  placeholder="Paste 64-character reset token"
                  required
                />
              </Field>
            )}

            <Field id="password" label="New password" hint="8 to 72 characters" error={fields.password}>
              <div className="pw-wrap">
                <input
                  id="password"
                  type={showPw ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  aria-invalid={Boolean(fields.password)}
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
            </Field>

            <Field id="confirm-password" label="Confirm new password" error={fields.confirmPw}>
              <input
                id="confirm-password"
                type={showPw ? 'text' : 'password'}
                autoComplete="new-password"
                value={confirmPw}
                onChange={(e) => setConfirmPw(e.target.value)}
                aria-invalid={Boolean(fields.confirmPw)}
                required
              />
            </Field>

            {error && (
              <div>
                <p className="error" role="alert">{error}</p>
                <p className="note" style={{ marginTop: '6px' }}>
                  Need a fresh link? <Link to="/forgot-password">Request another reset link</Link>.
                </p>
              </div>
            )}

            <button type="submit" disabled={busy}>
              {busy ? 'Updating password...' : 'Save new password'}
            </button>
          </form>

          <p className="auth-footer">
            <Link to="/login">Back to sign in</Link>
          </p>
        </div>
      </main>
    </div>
  )
}
