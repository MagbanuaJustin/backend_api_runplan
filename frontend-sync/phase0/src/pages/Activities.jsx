import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { apiGet } from '../api.js'
import ActivityForm from '../components/ActivityForm.jsx'
import ActivityList from '../components/ActivityList.jsx'
import { TYPE_LABELS, formatLongDate } from '../format.js'

export default function Activities() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const sessionId = params.get('session')

  const [version, setVersion] = useState(0)
  const [session, setSession] = useState(null)
  const [state, setState] = useState(sessionId ? 'loading' : 'none')

  useEffect(() => {
    if (!sessionId) return
    apiGet('/plans/current')
      .then((body) => {
        const s = body.data.active?.sessions.find((x) => String(x.id) === sessionId)
        if (s && s.status !== 'completed') {
          setSession(s)
          setState('ready')
        } else {
          setState('invalid')
        }
      })
      .catch(() => setState('invalid'))
  }, [sessionId])

  if (state === 'loading') return <p role="status">Loading session...</p>

  const today = new Date().toLocaleDateString('en-CA')
  const defaults = session
    ? {
        date: session.scheduled_date <= today ? session.scheduled_date : today,
        distance: String(session.planned_distance_km),
      }
    : null

  return (
    <>
      <h1>Log a run</h1>

      <div className="split-workspace">
        <section className="plan-card" aria-labelledby="log-form-title">
          <div className="panel-head">
            <h2 id="log-form-title">Record manual workout</h2>
            {session && <span className="pill">Linked session</span>}
          </div>

          {state === 'invalid' && (
            <p className="error" role="alert">
              That session cannot be logged — it may already be completed.{' '}
              <Link to="/calendar">Back to calendar</Link>
            </p>
          )}

          {session && (
            <div className="advisory-banner" role="status">
              Logging your <strong>{TYPE_LABELS[session.type]}</strong> planned for{' '}
              {formatLongDate(session.scheduled_date)} ({session.planned_distance_km} km).{' '}
              <Link to="/activities">Log an unlinked run instead</Link>
            </div>
          )}

          <ActivityForm
            key={session ? `s${session.id}` : 'free'}
            sessionId={session?.id ?? null}
            defaults={defaults}
            onSaved={() => (session ? navigate('/calendar') : setVersion((v) => v + 1))}
          />
        </section>

        <section className="plan-card" aria-labelledby="history-title">
          <div className="panel-head">
            <h2 id="history-title">Activity history</h2>
            <span className="pill pill-muted">Manual runs</span>
          </div>
          <ActivityList key={version} />
        </section>
      </div>
    </>
  )
}
