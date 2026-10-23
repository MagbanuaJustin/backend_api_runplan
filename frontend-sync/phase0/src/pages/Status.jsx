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

  return (
    <>
      <h1>System status</h1>
      <p className="note">
        Core training plans, session rescheduling, manual run logging, and analytics run
        independently of external integrations.
      </p>

      <section className="plan-card" aria-labelledby="core-title">
        <h2 id="core-title">
          Core platform <span className="badge">{integrations?.core_enabled ? 'Active' : 'Offline'}</span>
        </h2>
        <dl className="stats">
          <div><dt>API health</dt><dd>{health?.status === 'ok' ? 'Connected' : 'Degraded'}</dd></div>
          <div><dt>Signed in as</dt><dd>{user?.email}</dd></div>
          <div><dt>Role</dt><dd>{user?.role ?? 'runner'}</dd></div>
          <div><dt>Analytics boundary</dt><dd>Manual runs only</dd></div>
        </dl>
      </section>

      <section className="plan-card" aria-labelledby="providers-title">
        <h2 id="providers-title">External integrations</h2>
        <p className="note">
          External providers are deferred until all core first-party workflows are verified.
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
                const p = providers[key]
                const statusLabel = key === 'strava'
                  ? 'Excluded (policy gate)'
                  : p?.ready
                    ? 'Ready'
                    : p?.configured
                      ? 'Configured (disabled)'
                      : 'Deferred (off)'
                return (
                  <tr key={key}>
                    <th scope="row" className="wrap-cell">{label}</th>
                    <td><span className="badge">{statusLabel}</span></td>
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
