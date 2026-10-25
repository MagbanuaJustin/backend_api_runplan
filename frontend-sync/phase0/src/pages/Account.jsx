import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { apiGet, apiPost, setAccessToken } from '../api.js'
import { useAuth } from '../auth/AuthContext.jsx'
import Field from '../components/Field.jsx'
import ConfirmDialog from '../components/ConfirmDialog.jsx'

const EVENT_LABELS = {
  account_registered: { label: 'Account registered', pill: 'pill-ok' },
  login_succeeded: { label: 'Signed in with password', pill: 'pill-ok' },
  login_failed: { label: 'Failed sign-in attempt', pill: 'pill-warn' },
  password_changed: { label: 'Password changed', pill: 'pill' },
  password_change_failed: { label: 'Failed password change', pill: 'pill-warn' },
  password_reset_requested: { label: 'Recovery link requested', pill: 'pill' },
  password_reset_completed: { label: 'Password reset completed', pill: 'pill-ok' },
}

export default function Account() {
  const { user, clearSession } = useAuth()
  const navigate = useNavigate()

  const [account, setAccount] = useState(null)
  const [events, setEvents] = useState([])
  const [loadErr, setLoadErr] = useState('')
  const [loading, setLoading] = useState(true)

  // Change password state
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmNewPassword, setConfirmNewPassword] = useState('')
  const [showPw, setShowPw] = useState(false)
  const [pwFields, setPwFields] = useState({})
  const [pwError, setPwError] = useState('')
  const [pwSuccess, setPwSuccess] = useState('')
  const [pwBusy, setPwBusy] = useState(false)

  // Delete account state
  const [delPassword, setDelPassword] = useState('')
  const [delConfirmText, setDelConfirmText] = useState('')
  const [delReason, setDelReason] = useState('')
  const [delFields, setDelFields] = useState({})
  const [delError, setDelError] = useState('')
  const [delDialogOpen, setDelDialogOpen] = useState(false)
  const [delBusy, setDelBusy] = useState(false)

  const loadAccount = useCallback(() => {
    return apiGet('/account')
      .then((res) => {
        setAccount(res.data?.account ?? null)
        setEvents(res.data?.security_events ?? [])
        setLoadErr('')
      })
      .catch((err) => setLoadErr(err.message))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadAccount()
  }, [loadAccount])

  async function handleChangePassword(e) {
    e.preventDefault()
    setPwFields({})
    setPwError('')
    setPwSuccess('')

    const nextErrs = {}
    if (!currentPassword) nextErrs.current_password = 'Enter your current password.'
    if (newPassword.length < 8) nextErrs.new_password = 'Use at least 8 characters.'
    if (newPassword !== confirmNewPassword) nextErrs.confirm_new_password = 'New passwords do not match.'
    if (Object.keys(nextErrs).length > 0) {
      setPwFields(nextErrs)
      return
    }

    setPwBusy(true)
    try {
      const res = await apiPost('/account/password', {
        current_password: currentPassword,
        new_password: newPassword,
      })
      if (res.data?.access_token) {
        setAccessToken(res.data.access_token)
      }
      setCurrentPassword('')
      setNewPassword('')
      setConfirmNewPassword('')
      setPwSuccess(res.data?.message || 'Password updated.')
      await loadAccount()
    } catch (err) {
      if (err.fields) setPwFields(err.fields)
      else setPwError(err.message)
    } finally {
      setPwBusy(false)
    }
  }

  function handleOpenDeleteConfirm(e) {
    e.preventDefault()
    setDelFields({})
    setDelError('')

    const nextErrs = {}
    if (delConfirmText.trim().toUpperCase() !== 'DELETE') {
      nextErrs.confirm_text = 'Type DELETE in uppercase to confirm.'
    }
    if ((account?.has_password ?? true) && !delPassword) {
      nextErrs.password = 'Enter your current password to confirm.'
    }
    if (Object.keys(nextErrs).length > 0) {
      setDelFields(nextErrs)
      return
    }

    setDelDialogOpen(true)
  }

  async function handleConfirmDelete() {
    setDelBusy(true)
    setDelError('')
    try {
      const res = await apiPost('/account/delete', {
        password: delPassword,
        confirm_text: delConfirmText.trim().toUpperCase(),
        reason: delReason.trim() || null,
      })
      setDelDialogOpen(false)
      clearSession()
      navigate('/login', {
        replace: true,
        state: { notice: res.data?.message || 'Your account and training data were deleted.' },
      })
    } catch (err) {
      setDelDialogOpen(false)
      if (err.fields) setDelFields(err.fields)
      else setDelError(err.message)
    } finally {
      setDelBusy(false)
    }
  }

  const counts = account?.data_counts ?? { plans: 0, sessions: 0, activities: 0 }

  return (
    <div>
      <h1>Account &amp; Security</h1>

      {loadErr && <p className="error" role="alert">{loadErr}</p>}

      <div className="status-strip" aria-label="Account summary">
        <div className="strip-item">
          <span className="strip-label">Account Status</span>
          <span className="strip-value">
            <span className="dot dot-ok" />
            {account?.status === 'active' || !account ? 'Active' : account.status}
          </span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Signed-In Email</span>
          <span className="strip-value mono">{account?.email ?? user?.email ?? '—'}</span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Password Sign-In</span>
          <span className="strip-value">
            <span className="dot dot-blue" />
            {account?.has_password !== false ? 'Configured' : 'Not set'}
          </span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Stored Records</span>
          <span className="strip-value mono">
            {loading ? '...' : `${counts.plans} plans · ${counts.sessions} sessions · ${counts.activities} runs`}
          </span>
        </div>
      </div>

      <div className="split-workspace">
        <section className="plan-card" aria-labelledby="pw-heading">
          <div className="panel-head">
            <h2 id="pw-heading">Change password</h2>
            <span className="pill">Session rotation</span>
          </div>
          <p className="note">
            Updating your password immediately revokes refresh tokens on any other signed-in browsers while keeping this session active.
          </p>

          {pwSuccess && <p className="success" role="status">{pwSuccess}</p>}

          <form className="form" onSubmit={handleChangePassword} noValidate>
            <Field id="current-password" label="Current password" error={pwFields.current_password}>
              <div className="pw-wrap">
                <input
                  id="current-password"
                  type={showPw ? 'text' : 'password'}
                  autoComplete="current-password"
                  value={currentPassword}
                  onChange={(e) => setCurrentPassword(e.target.value)}
                  aria-invalid={Boolean(pwFields.current_password)}
                  required
                />
                <button
                  type="button"
                  className="pw-toggle"
                  onClick={() => setShowPw((s) => !s)}
                >
                  {showPw ? 'Hide' : 'Show'}
                </button>
              </div>
            </Field>

            <div className="form-grid-2">
              <Field id="new-password" label="New password" hint="8 to 72 characters" error={pwFields.new_password}>
                <input
                  id="new-password"
                  type={showPw ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  aria-invalid={Boolean(pwFields.new_password)}
                  required
                />
              </Field>

              <Field id="confirm-new-password" label="Confirm new password" error={pwFields.confirm_new_password}>
                <input
                  id="confirm-new-password"
                  type={showPw ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={confirmNewPassword}
                  onChange={(e) => setConfirmNewPassword(e.target.value)}
                  aria-invalid={Boolean(pwFields.confirm_new_password)}
                  required
                />
              </Field>
            </div>

            {pwError && <p className="error" role="alert">{pwError}</p>}

            <div className="btn-row">
              <button type="submit" disabled={pwBusy}>
                {pwBusy ? 'Updating...' : 'Update password'}
              </button>
            </div>
          </form>
        </section>

        <section className="plan-card" aria-labelledby="events-heading">
          <div className="panel-head">
            <h2 id="events-heading">Recent security activity</h2>
            <span className="pill pill-muted">Audit log</span>
          </div>
          <p className="note">
            Privacy-safe events recorded for your account (passwords and tokens are never logged).
          </p>

          {loading ? (
            <p className="note">Loading activity...</p>
          ) : events.length === 0 ? (
            <p className="note">No security events recorded yet.</p>
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Event</th>
                    <th>Timestamp (UTC)</th>
                  </tr>
                </thead>
                <tbody>
                  {events.map((ev) => {
                    const meta = EVENT_LABELS[ev.event_type] ?? { label: ev.event_type, pill: 'pill-muted' }
                    return (
                      <tr key={ev.id}>
                        <td>
                          <span className={`pill ${meta.pill}`}>{meta.label}</span>
                        </td>
                        <td className="mono">{ev.created_at}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>

      <section
        className="plan-card"
        aria-labelledby="danger-heading"
        style={{ marginTop: '18px', borderColor: 'var(--danger-border)' }}
      >
        <div className="panel-head">
          <h2 id="danger-heading">Delete account &amp; training data</h2>
          <span className="pill pill-danger">Permanent</span>
        </div>
        <p className="note">
          Permanently deletes your RunPlan login, runner profile, {counts.plans} training plan(s), {counts.sessions} scheduled session(s), and {counts.activities} logged manual run(s). Only an irreversible non-PII SHA-256 deletion receipt is kept for audit compliance.
        </p>

        <form className="form" onSubmit={handleOpenDeleteConfirm} noValidate>
          <div className="form-grid-2">
            <Field id="del-password" label="Current password" error={delFields.password}>
              <input
                id="del-password"
                type="password"
                autoComplete="current-password"
                value={delPassword}
                onChange={(e) => setDelPassword(e.target.value)}
                aria-invalid={Boolean(delFields.password)}
                required
              />
            </Field>

            <Field id="del-confirm" label="Type DELETE to confirm" error={delFields.confirm_text}>
              <input
                id="del-confirm"
                type="text"
                className="mono"
                placeholder="DELETE"
                value={delConfirmText}
                onChange={(e) => setDelConfirmText(e.target.value)}
                aria-invalid={Boolean(delFields.confirm_text)}
                required
              />
            </Field>
          </div>

          <Field id="del-reason" label="Reason (optional)" hint="Max 255 characters">
            <input
              id="del-reason"
              type="text"
              maxLength={255}
              placeholder="e.g., Finished marathon training cycle"
              value={delReason}
              onChange={(e) => setDelReason(e.target.value)}
            />
          </Field>

          {delError && <p className="error" role="alert">{delError}</p>}

          <div className="btn-row">
            <button type="submit" className="btn btn-danger" disabled={delBusy}>
              Permanently delete account
            </button>
          </div>
        </form>
      </section>

      <ConfirmDialog
        open={delDialogOpen}
        title="Delete your account permanently?"
        message="This action cannot be undone. Your runner profile, training plans, scheduled sessions, and all logged runs will be erased immediately."
        confirmLabel="Yes, delete everything"
        danger={true}
        busy={delBusy}
        onConfirm={handleConfirmDelete}
        onCancel={() => setDelDialogOpen(false)}
      />
    </div>
  )
}
