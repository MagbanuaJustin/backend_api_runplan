import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiGet } from '../api.js'
import { TYPE_LABELS, formatDate, formatDuration, formatLongDate, paceLabel } from '../format.js'

function Stat({ label, value }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  )
}

export default function Dashboard() {
  const [data, setData] = useState(null)
  const [state, setState] = useState('loading') // loading | ready | error

  useEffect(() => {
    const today = new Date().toLocaleDateString('en-CA')
    apiGet(`/dashboard?today=${today}`)
      .then((body) => {
        setData(body.data)
        setState('ready')
      })
      .catch(() => setState('error'))
  }, [])

  if (state === 'loading') return <p role="status">Loading dashboard...</p>
  if (state === 'error') return <p role="alert">Could not load your dashboard.</p>

  const { last7, previous7, plan, weeks, recent } = data
  const diff = Math.round((last7.distance_km - previous7.distance_km) * 10) / 10
  const diffText = diff === 0
    ? 'Same as the previous 7 days'
    : `${diff > 0 ? '+' : ''}${diff} km vs the previous 7 days`
  const maxKm = Math.max(1, ...weeks.flatMap((w) => [w.actual_km, w.planned_km]))
  const next = plan?.next_session
  // Show the plan marker only for weeks the plan fully covers
  const showPlanned = (w) => plan && w.planned_km > 0 && w.start >= plan.start_date

  return (
    <>
      <h1>Dashboard</h1>

      <section className="plan-card" aria-labelledby="next-title">
        <h2 id="next-title">Next session</h2>
        {!plan ? (
          <p>You do not have an active plan yet. <Link to="/plan">Create your plan</Link></p>
        ) : !next ? (
          <p>No more planned sessions. <Link to="/calendar">Open your calendar</Link></p>
        ) : (
          <>
            <p>
              <strong>{TYPE_LABELS[next.type]}</strong> on {formatLongDate(next.scheduled_date)}
            </p>
            <dl className="stats">
              <Stat label="Distance" value={`${next.planned_distance_km} km`} />
              <Stat label="Estimated time" value={formatDuration(next.planned_duration_seconds)} />
              <Stat label="Target pace" value={paceLabel(next.planned_duration_seconds, next.planned_distance_km)} />
            </dl>
            <div className="btn-row">
              <Link className="btn btn-primary" to={`/activities?session=${next.id}`}>Log this run</Link>
              <Link className="btn btn-secondary" to="/calendar">Open calendar</Link>
            </div>
          </>
        )}
      </section>

      <section className="plan-card" aria-labelledby="week-title">
        <h2 id="week-title">Last 7 days</h2>
        {last7.runs === 0 ? (
          <p>No runs logged in the last 7 days.</p>
        ) : (
          <>
            <dl className="stats">
              <Stat label="Distance" value={`${last7.distance_km} km`} />
              <Stat label="Runs" value={last7.runs} />
              <Stat label="Time" value={formatDuration(last7.duration_seconds)} />
              <Stat label="Average pace" value={`${last7.pace_label} /km`} />
            </dl>
            <p className="note">{diffText}</p>
          </>
        )}
      </section>

      {plan && (
        <section className="plan-card" aria-labelledby="progress-title">
          <h2 id="progress-title">Plan progress</h2>
          <dl className="stats">
            <Stat label="Week" value={plan.week === 0 ? `Starts ${formatDate(plan.start_date)}` : `${plan.week} of ${plan.total_weeks}`} />
            <Stat label="Completion" value={plan.completion_rate === null ? 'None due yet' : `${plan.completion_rate}%`} />
            <Stat label="Done" value={`${plan.completed} of ${plan.due} due`} />
            <Stat label="Skipped / missed" value={`${plan.skipped} / ${plan.missed}`} />
            <Stat
              label={plan.goal === 'general_fitness' ? 'Plan ends in' : 'Race day in'}
              value={plan.days_left >= 0 ? `${plan.days_left} days` : 'Finished'}
            />
          </dl>
        </section>
      )}

      <section className="plan-card" aria-labelledby="chart-title">
        <h2 id="chart-title">Weekly distance &amp; pace</h2>
        <p className="note">Each bar is a 7-day block of manual runs. The mark shows planned distance.</p>
        <ul className="bars">
          {weeks.map((w) => (
            <li key={w.end} className="bar-row">
              <span className="bar-label">to {formatDate(w.end)}</span>
              <span className="bar-track" aria-hidden="true">
                <span className="bar-actual" style={{ width: `${(w.actual_km / maxKm) * 100}%` }} />
                {showPlanned(w) && (
                  <span className="bar-planned" style={{ left: `${(w.planned_km / maxKm) * 100}%` }} />
                )}
              </span>
              <span className="bar-value">
                {w.actual_km} km{showPlanned(w) ? ` of ${w.planned_km}` : ''}
                {w.actual_pace_label ? ` \u00b7 ${w.actual_pace_label}/km` : ''}
              </span>
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="recent-title">
        <h2 id="recent-title">Recent runs</h2>
        {recent.length === 0 ? (
          <p>No runs logged yet. <Link to="/activities">Log your first run</Link></p>
        ) : (
          <>
            <ul className="runs">
              {recent.map((r) => (
                <li key={r.id} className="card">
                  <strong>{r.activity_date}</strong>
                  <span>{r.distance_km} km</span>
                  <span>{formatDuration(r.duration_seconds)}</span>
                  <span>{r.pace_label} /km</span>
                  {r.from_plan && <span className="badge">Plan run</span>}
                </li>
              ))}
            </ul>
            <p><Link to="/activities">All runs</Link></p>
          </>
        )}
      </section>
    </>
  )
}
