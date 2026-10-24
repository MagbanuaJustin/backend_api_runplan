import { useEffect, useState } from 'react'
import { apiGet } from '../api.js'
import { useAuth } from '../auth/AuthContext.jsx'

const PROVIDER_LABELS = {
  anthropic: 'Anthropic Claude (AI plan & feedback)',
  weather: 'OpenWeatherMap (weather advisories)',
  google_calendar: 'Google Calendar (workout sync)',
  push: 'Push reminders (Web Push / FCM)',
  google_identity: 'Google Identity Services (Sign-In)',
  recaptcha: 'Google reCAPTCHA (abuse protection)',
  strava: 'Strava',
}

function calendarStatus(connection, provider) {
  if (!connection) return null

  if (connection.status === 'connected') {
    return connection.sync_enabled
      ? { className: 'pill-ok', text: 'Connected · sync enabled' }
      : { className: 'pill-warn', text: 'Connected · paused' }
  }
  if (connection.status === 'needs_reconnect') {
    return { className: 'pill-warn', text: 'Reconnect required' }
  }
  if (connection.status === 'disconnected') {
    return provider?.ready
      ? { className: 'pill-muted', text: 'Ready · not connected' }
      : { className: 'pill-muted', text: 'Not configured' }
  }
  if (connection.status === 'not_configured') {
    return { className: 'pill-muted', text: 'Not configured' }
  }
  return { className: 'pill-warn', text: 'Temporarily unavailable' }
}

export default function Status() {
  const { user } = useAuth()
  const [health, setHealth] = useState(null)
  const [integrations, setIntegrations] = useState(null)
  const [state, setState] = useState('loading')

  useEffect(() => {
    Promise.all([apiGet('/health'), apiGet('/integrations/status')])
      .then(([h, i]) => {
        setHealth(h)
        setIntegrations(i.data)
        setState('ready')
      })
      .catch(() => setState('error'))
  }, [])

  if (state === 'loading') return <p role="status">Checking system status...</p>
  if (state === 'error') return <p role="alert">Could not load system status.</p>

  const providers = integrations?.providers ?? {}
  const calendarConnection = integrations?.google_calendar_connection
  const apiConnected = health?.status === 'ok'

  return (
    <>
      <h1>System status</h1>

      <section className="status-strip" aria-label="Core service health">
        <div className="strip-item">
          <span className="strip-label">API server</span>
          <span className="strip-value">
            <span className={`dot ${apiConnected ? 'dot-ok' : 'dot-warn'}`} />
            {apiConnected ? 'Connected' : 'Degraded'}
          </span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Core planner</span>
          <span className="strip-value">
            <span className={`dot ${integrations?.core_enabled ? 'dot-blue' : ''}`} />
            {integrations?.core_enabled ? 'Active' : 'Offline'}
          </span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Analytics boundary</span>
          <span className="strip-value">Manual runs only</span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Operator / Runner</span>
          <span className="strip-value mono">{user?.email}</span>
        </div>
      </section>

      <section className="plan-card" aria-labelledby="providers-title">
        <div className="panel-head">
          <h2 id="providers-title">External integration modules</h2>
          <span className="pill pill-muted">Optional integrations</span>
        </div>
        <p className="note">
          Provider readiness reflects server configuration. Google Calendar also shows this runner's connection state.
          Core training features remain available when a provider is disabled or unavailable.
        </p>

        <div className="table-wrap">
          <table>
            <caption className="sr-only">Readiness of external integration modules</caption>
            <thead>
              <tr>
                <th scope="col">Integration</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              {Object.entries(PROVIDER_LABELS).map(([key, label]) => {
                const provider = providers[key]
                let pillClass = 'pill-muted'
                let statusText = '— Not configured'
                let statusTitle

                if (key === 'strava') {
                  pillClass = 'pill-muted'
                  statusText = '— Excluded (policy gate)'
                } else if (key === 'google_calendar' && calendarConnection) {
                  const calendar = calendarStatus(calendarConnection, provider)
                  pillClass = calendar.className
                  statusText = calendar.text
                  statusTitle = calendarConnection.message
                } else if (provider?.ready) {
                  pillClass = 'pill-ok'
                  statusText = '✓ Ready'
                } else if (provider?.configured) {
                  pillClass = 'pill-warn'
                  statusText = '• Configured (disabled)'
                }

                return (
                  <tr key={key}>
                    <th scope="row" className="wrap-cell">{label}</th>
                    <td>
                      <span className={`pill ${pillClass}`} title={statusTitle}>{statusText}</span>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}
