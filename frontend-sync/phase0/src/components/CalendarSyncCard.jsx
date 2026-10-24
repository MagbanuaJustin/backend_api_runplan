import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { apiGet, apiPost } from '../api.js'

const RETURN_MESSAGES = {
  connected: { kind: 'success', text: 'Google Calendar connected successfully.' },
  denied: { kind: 'error', text: 'Google Calendar access was not granted. You can try connecting again.' },
  invalid_state: { kind: 'error', text: 'That Google connection link expired. Start again from this page.' },
  not_configured: { kind: 'error', text: 'Google Calendar is not configured on the server yet.' },
  storage_failed: { kind: 'error', text: 'Google approved access, but RunPlan could not save it securely.' },
  revoked: { kind: 'error', text: 'Google needs you to reconnect your calendar.' },
  unauthorized: { kind: 'error', text: 'Google did not accept the connection. Check the server OAuth settings.' },
  rate_limited: { kind: 'error', text: 'Google is temporarily limiting connection attempts. Try again shortly.' },
  unavailable: { kind: 'error', text: 'Google Calendar could not be reached. Try again shortly.' },
  error: { kind: 'error', text: 'The Google Calendar connection did not finish. Try again.' },
}

function formatDateTime(value) {
  if (!value) return 'Not yet'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}

function badgeFor(connection) {
  if (!connection) return null
  if (connection.status === 'connected') {
    return connection.sync_enabled
      ? { className: 'pill-ok', label: 'Connected · sync enabled' }
      : { className: 'pill-warn', label: 'Connected · paused' }
  }
  if (connection.status === 'needs_reconnect') return { className: 'pill-warn', label: 'Reconnect required' }
  if (connection.status === 'disconnected') return { className: 'pill-muted', label: 'Not connected' }
  if (connection.status === 'not_configured') return { className: 'pill-muted', label: 'Not configured' }
  return { className: 'pill-warn', label: 'Temporarily unavailable' }
}

export default function CalendarSyncCard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [connection, setConnection] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [confirmDisconnect, setConfirmDisconnect] = useState(false)
  const callbackOutcome = searchParams.get('calendar')

  const loadStatus = useCallback(async (showSpinner = true) => {
    if (showSpinner) setLoading(true)
    try {
      const response = await apiGet('/calendar/status')
      setConnection(response.data ?? null)
      if (showSpinner) setError('')
    } catch (err) {
      setError(err.message || 'Could not load Google Calendar status.')
    } finally {
      if (showSpinner) setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadStatus()
  }, [loadStatus])

  useEffect(() => {
    if (!callbackOutcome) return
    const result = RETURN_MESSAGES[callbackOutcome] || RETURN_MESSAGES.error
    if (result.kind === 'success') {
      setNotice(result.text)
      setError('')
    } else {
      setError(result.text)
      setNotice('')
    }
    const cleaned = new URLSearchParams(searchParams)
    cleaned.delete('calendar')
    setSearchParams(cleaned, { replace: true })
  }, [callbackOutcome, searchParams, setSearchParams])

  async function connectCalendar() {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const response = await apiGet('/calendar/connect')
      const flow = response.data || {}
      if (!flow.auth_url) {
        setError(flow.message || 'Google Calendar connection is not available.')
        return
      }
      window.location.assign(flow.auth_url)
    } catch (err) {
      setError(err.message || 'Could not start the Google Calendar connection.')
    } finally {
      setBusy(false)
    }
  }

  async function syncNow() {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const response = await apiPost('/calendar/sync', {})
      const result = response.data || {}
      if (result.ok) setNotice(result.message || 'Google Calendar sync complete.')
      else setError(result.message || 'Google Calendar sync could not complete.')
      await loadStatus(false)
    } catch (err) {
      setError(err.message || 'Google Calendar sync could not complete.')
    } finally {
      setBusy(false)
    }
  }

  async function toggleSync() {
    if (!connection) return
    const enabled = !connection.sync_enabled
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const response = await apiPost('/calendar/sync-enabled', { enabled })
      const updated = response.data || null
      if (updated) setConnection(updated)
      if (updated?.status === 'connected' && updated.sync_enabled === enabled) {
        setNotice(enabled ? 'Google Calendar sync resumed.' : 'Google Calendar sync paused.')
      } else {
        setError(updated?.message || 'Could not update the Google Calendar sync setting.')
      }
    } catch (err) {
      setError(err.message || 'Could not update the Google Calendar sync setting.')
    } finally {
      setBusy(false)
    }
  }

  async function disconnectCalendar() {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const response = await apiPost('/calendar/disconnect', {})
      const result = response.data || {}
      if (result.connection) setConnection(result.connection)
      setConfirmDisconnect(false)
      if (result.disconnected) setNotice(result.message || 'Google Calendar disconnected.')
      else setError(result.message || 'Could not disconnect Google Calendar.')
    } catch (err) {
      setConfirmDisconnect(false)
      setError(err.message || 'Could not disconnect Google Calendar.')
    } finally {
      setBusy(false)
    }
  }

  const badge = badgeFor(connection)
  const isConnected = connection?.status === 'connected'
  const canConnect = Boolean(connection?.ready) && !isConnected

  return (
    <section className="plan-card" aria-labelledby="calendar-sync-title">
      <div className="panel-head">
        <div>
          <h2 id="calendar-sync-title">Google Calendar sync</h2>
          <p className="note" style={{ marginBottom: 0 }}>
            Manually sync upcoming planned workouts. RunPlan updates this calendar when you press Sync now.
          </p>
        </div>
        {badge && <span className={`pill ${badge.className}`}>{badge.label}</span>}
      </div>

      <p className="note">
        Skipped workouts and sessions removed from a revised plan are removed from Google Calendar on the next sync.
        Disconnecting does not delete events already in your Google Calendar.
      </p>

      {loading ? (
        <p role="status">Checking Google Calendar connection...</p>
      ) : (
        <>
          {connection?.status === 'connected' && (
            <div className="status-strip" aria-label="Google Calendar sync details">
              <div className="strip-item">
                <span className="strip-label">Calendar</span>
                <span className="strip-value">{connection.calendar_id || 'Primary calendar'}</span>
              </div>
              <div className="strip-item">
                <span className="strip-label">Connected</span>
                <span className="strip-value">{formatDateTime(connection.connected_at)}</span>
              </div>
              <div className="strip-item">
                <span className="strip-label">Last synced</span>
                <span className="strip-value">{formatDateTime(connection.last_synced_at)}</span>
              </div>
            </div>
          )}

          {!connection?.ready && connection?.message && (
            <p className="note" role="status">{connection.message}</p>
          )}
          {connection?.last_error && (
            <p className="error" role="alert">Last sync issue: {connection.last_error}</p>
          )}
          {error && <p className="error" role="alert">{error}</p>}
          {notice && <p className="success" role="status">{notice}</p>}

          {isConnected ? (
            <div className="btn-row">
              <button
                type="button"
                className="btn btn-primary"
                onClick={syncNow}
                disabled={busy || !connection.sync_enabled}
              >
                {busy ? 'Working...' : 'Sync now'}
              </button>
              <button type="button" className="btn btn-secondary" onClick={toggleSync} disabled={busy}>
                {connection.sync_enabled ? 'Pause sync' : 'Resume sync'}
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setConfirmDisconnect(true)}
                disabled={busy}
              >
                Disconnect
              </button>
            </div>
          ) : canConnect ? (
            <div className="btn-row">
              <button type="button" className="btn btn-primary" onClick={connectCalendar} disabled={busy}>
                {busy ? 'Opening Google...' : connection.status === 'needs_reconnect' ? 'Reconnect Google' : 'Connect Google Calendar'}
              </button>
            </div>
          ) : null}

          {confirmDisconnect && (
            <div className="inline-reschedule" role="group" aria-label="Confirm Google Calendar disconnect">
              <p className="note">
                Disconnect RunPlan from Google Calendar? Existing Google events will stay in your calendar.
              </p>
              <div className="btn-row">
                <button type="button" className="btn btn-danger" onClick={disconnectCalendar} disabled={busy}>
                  {busy ? 'Disconnecting...' : 'Yes, disconnect'}
                </button>
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => setConfirmDisconnect(false)}
                  disabled={busy}
                >
                  Cancel
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </section>
  )
}
