import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiGet } from '../api.js'
import { TYPE_LABELS, formatDate, formatDuration, formatLongDate, paceLabel } from '../format.js'

const GOAL_LABELS = {
  '5k': '5K Race',
  '10k': '10K Race',
  half_marathon: 'Half Marathon',
  marathon: 'Marathon',
  general_fitness: 'General Fitness',
}

function WeeklyTrendChart({ weeks, plan, metric }) {
  const width = 560
  const height = 185
  const padL = 44
  const padR = 16
  const padT = 16
  const padB = 30
  const innerW = width - padL - padR
  const innerH = height - padT - padB

  const showPlanned = (w) => plan && w.planned_km > 0 && w.start >= plan.start_date

  const values = weeks.map((w) => {
    if (metric === 'pace') return w.actual_pace_seconds_per_km ? w.actual_pace_seconds_per_km / 60 : 0
    if (metric === 'runs') return w.runs ?? 0
    return w.actual_km ?? 0
  })

  const plannedValues = metric === 'distance'
    ? weeks.map((w) => (showPlanned(w) ? w.planned_km : 0))
    : []

  const rawMax = Math.max(1, ...values, ...plannedValues)
  const yMax = metric === 'pace' ? Math.ceil(rawMax + 1) : Math.ceil(rawMax * 1.15)
  const ticks = [0, 0.5, 1].map((t) => Math.round(yMax * t * 10) / 10)

  const xAt = (i) => padL + (weeks.length <= 1 ? innerW / 2 : (i / (weeks.length - 1)) * innerW)
  const yAt = (v) => padT + innerH - (Math.min(v, yMax) / yMax) * innerH

  const points = values.map((v, i) => `${xAt(i)},${yAt(v)}`).join(' ')
  const areaPoints = `${xAt(0)},${padT + innerH} ${points} ${xAt(weeks.length - 1)},${padT + innerH}`

  return (
    <svg className="chart-svg" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`Weekly ${metric} chart`}>
      <defs>
        <linearGradient id="rpBlueArea" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#3b82f6" stopOpacity="0.28" />
          <stop offset="100%" stopColor="#3b82f6" stopOpacity="0.0" />
        </linearGradient>
      </defs>

      {ticks.map((t) => {
        const y = yAt(t)
        const label = metric === 'pace' && t > 0
          ? `${Math.floor(t)}:${String(Math.round((t % 1) * 60)).padStart(2, '0')}`
          : String(t)
        return (
          <g key={t}>
            <line
              x1={padL}
              y1={y}
              x2={width - padR}
              y2={y}
              stroke="var(--border)"
              strokeDasharray="3 4"
            />
            <text
              x={padL - 8}
              y={y + 4}
              textAnchor="end"
              fontSize="10.5"
              fontFamily="var(--font-mono)"
              fill="var(--text-muted)"
            >
              {label}
            </text>
          </g>
        )
      })}

      {metric === 'distance' && weeks.map((w, i) => (
        showPlanned(w) ? (
          <line
            key={`p-${w.end}`}
            x1={xAt(i) - 10}
            x2={xAt(i) + 10}
            y1={yAt(w.planned_km)}
            y2={yAt(w.planned_km)}
            stroke="var(--text-muted)"
            strokeWidth="2"
            strokeDasharray="2 2"
          />
        ) : null
      ))}

      <polygon points={areaPoints} fill="url(#rpBlueArea)" />
      <polyline
        points={points}
        fill="none"
        stroke="var(--accent-bright)"
        strokeWidth="2.4"
        strokeLinecap="round"
        strokeLinejoin="round"
      />

      {weeks.map((w, i) => (
        <g key={w.end}>
          <circle
            cx={xAt(i)}
            cy={yAt(values[i])}
            r="3.8"
            fill="var(--bg-surface)"
            stroke="var(--accent-bright)"
            strokeWidth="2.2"
          />
          <text
            x={xAt(i)}
            y={height - 8}
            textAnchor="middle"
            fontSize="10"
            fontFamily="var(--font-mono)"
            fill="var(--text-muted)"
          >
            {formatDate(w.end)}
          </text>
        </g>
      ))}
    </svg>
  )
}

export default function Dashboard() {
  const [data, setData] = useState(null)
  const [state, setState] = useState('loading')
  const [chartTab, setChartTab] = useState('distance')

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
    ? '\u2192 Same volume as prior 7d'
    : `${diff > 0 ? '\u2197 +' : '\u2198 '}${diff} km vs prior 7d`

  const next = plan?.next_session
  const latestRun = recent?.[0]

  return (
    <>
      <h1>Dashboard</h1>

      <div className="advisory-banner">
        <strong>Training guidance:</strong> Distances and target paces are deterministic conditioning estimates
        computed from your manual runs — not medical advice.
      </div>

      {/* BioSense-style 5-column system/plan status strip */}
      <section className="status-strip" aria-label="Training status overview">
        <div className="strip-item">
          <span className="strip-label">Plan status</span>
          <span className="strip-value">
            <span className={`dot ${plan ? 'dot-ok' : ''}`} />
            {plan ? 'Active' : 'No active plan'}
          </span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Target goal</span>
          <span className="strip-value">
            <span className={`dot ${plan ? 'dot-blue' : ''}`} />
            {plan ? (GOAL_LABELS[plan.goal] ?? plan.goal) : 'Not set'}
          </span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Current block</span>
          <span className="strip-value mono">
            {!plan
              ? '—'
              : plan.week === 0
                ? `Starts ${formatDate(plan.start_date)}`
                : `Week ${plan.week}/${plan.total_weeks}`}
          </span>
        </div>
        <div className="strip-item">
          <span className="strip-label">{plan?.goal === 'general_fitness' ? 'Plan remaining' : 'Race countdown'}</span>
          <span className="strip-value mono">
            {!plan ? '—' : plan.days_left >= 0 ? `${plan.days_left} days` : 'Completed'}
          </span>
        </div>
        <div className="strip-item">
          <span className="strip-label">Last logged run</span>
          <span className="strip-value mono">
            {latestRun ? latestRun.activity_date : 'None yet'}
          </span>
        </div>
      </section>

      {/* BioSense-style 4 monospace KPI cards */}
      <p className="section-eyebrow">Last 7 Days Readings</p>
      <section className="kpi-grid" aria-label="Last 7 days metrics">
        <div className="kpi-card">
          <div className="kpi-top">
            <span className="kpi-label">Weekly Distance</span>
            <span className={`pill ${last7.distance_km > 0 ? 'pill-ok' : 'pill-muted'}`}>
              {last7.distance_km > 0 ? '\u2713 Active' : '\u2014 0 km'}
            </span>
          </div>
          <div className="kpi-reading">
            <span className="kpi-num">{last7.distance_km}</span>
            <span className="kpi-unit">km</span>
          </div>
          <span className="kpi-trend">{diffText}</span>
        </div>

        <div className="kpi-card">
          <div className="kpi-top">
            <span className="kpi-label">Logged Runs</span>
            <span className={`pill ${last7.runs > 0 ? '' : 'pill-muted'}`}>
              {last7.runs > 0 ? `${last7.runs} manual` : '\u2014 Rest'}
            </span>
          </div>
          <div className="kpi-reading">
            <span className="kpi-num">{last7.runs}</span>
            <span className="kpi-unit">{last7.runs === 1 ? 'session' : 'sessions'}</span>
          </div>
          <span className="kpi-trend">&rarr; Prior 7d: {previous7.runs} sessions</span>
        </div>

        <div className="kpi-card">
          <div className="kpi-top">
            <span className="kpi-label">Moving Time</span>
            <span className="pill pill-muted">7-day sum</span>
          </div>
          <div className="kpi-reading">
            <span className="kpi-num">{last7.runs > 0 ? formatDuration(last7.duration_seconds) : '0:00'}</span>
            <span className="kpi-unit">h:mm:ss</span>
          </div>
          <span className="kpi-trend">&rarr; First-party manual logs</span>
        </div>

        <div className="kpi-card">
          <div className="kpi-top">
            <span className="kpi-label">Average Pace</span>
            <span className={`pill ${last7.pace_label ? 'pill-ok' : 'pill-muted'}`}>
              {last7.pace_label ? '\u2713 Tracked' : '\u2014 No pace'}
            </span>
          </div>
          <div className="kpi-reading">
            <span className="kpi-num">{last7.pace_label ?? '—'}</span>
            <span className="kpi-unit">/km</span>
          </div>
          <span className="kpi-trend">
            &rarr; {previous7.pace_label ? `Prior 7d: ${previous7.pace_label} /km` : 'Log runs to compare pace'}
          </span>
        </div>
      </section>

      <p className="note" style={{ marginTop: 0 }}>
        Trends compare your last 7 days against the prior 7-day rolling window using manual RunPlan entries only.
      </p>

      {/* Middle 2-column row: Interactive 8-week chart + Next Session card */}
      <div className="dash-grid-main">
        <section className="plan-card" aria-labelledby="chart-title">
          <div className="panel-head">
            <h2 id="chart-title">Last 8 weeks</h2>
            <Link className="panel-link" to="/activities">Log a Run &rarr;</Link>
          </div>

          <div className="seg-tabs" role="tablist" aria-label="Chart metric">
            {[
              ['distance', 'Distance (km)'],
              ['pace', 'Avg Pace (/km)'],
              ['runs', 'Sessions'],
            ].map(([key, label]) => (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={chartTab === key}
                className={`seg-tab${chartTab === key ? ' active' : ''}`}
                onClick={() => setChartTab(key)}
              >
                {label}
              </button>
            ))}
          </div>

          <WeeklyTrendChart weeks={weeks} plan={plan} metric={chartTab} />
          <small>
            {chartTab === 'distance'
              ? 'Solid blue line = logged km per 7-day block. Dashed grey marks = planned weekly distance.'
              : chartTab === 'pace'
                ? 'Weekly average pace in minutes per km across logged manual runs.'
                : 'Completed manual runs recorded in each 7-day block.'}
          </small>
        </section>

        <section className="plan-card" aria-labelledby="next-title">
          <div className="panel-head">
            <h2 id="next-title">Next scheduled run</h2>
            <Link className="panel-link" to="/calendar">Calendar &rarr;</Link>
          </div>

          {!plan ? (
            <>
              <span className="pill pill-warn">&bull; No active plan</span>
              <p style={{ marginTop: 12 }}>
                Generate a personalized training schedule from your runner profile to start tracking upcoming workouts.
              </p>
              <div className="btn-row">
                <Link className="btn btn-primary" to="/plan">Create your plan</Link>
              </div>
            </>
          ) : !next ? (
            <>
              <span className="pill pill-ok">&checkmark; Schedule complete</span>
              <p style={{ marginTop: 12 }}>
                Every scheduled session in your current plan has passed or been logged.
              </p>
              <div className="btn-row">
                <Link className="btn btn-secondary" to="/plan">Review plan</Link>
              </div>
            </>
          ) : (
            <>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
                <span className="pill">{TYPE_LABELS[next.type]}</span>
                <span className="mono" style={{ fontSize: 12.5, color: 'var(--text-secondary)' }}>
                  {formatLongDate(next.scheduled_date)}
                </span>
              </div>

              <dl className="stats">
                <div>
                  <dt>Target Distance</dt>
                  <dd>{next.planned_distance_km} km</dd>
                </div>
                <div>
                  <dt>Est. Duration</dt>
                  <dd>{formatDuration(next.planned_duration_seconds)}</dd>
                </div>
                <div>
                  <dt>Target Pace</dt>
                  <dd>{paceLabel(next.planned_duration_seconds, next.planned_distance_km)}</dd>
                </div>
              </dl>

              <div className="btn-row">
                <Link className="btn btn-primary" to={`/activities?session=${next.id}`}>Log this run</Link>
                <Link className="btn btn-secondary" to="/calendar">Move or skip</Link>
              </div>
            </>
          )}
        </section>
      </div>

      {/* Bottom 2-column row: Recent runs + Plan adherence */}
      <div className="dash-grid-two">
        <section className="plan-card" aria-labelledby="recent-title">
          <div className="panel-head">
            <h2 id="recent-title">Recent runs</h2>
            <Link className="panel-link" to="/activities">View all &rarr;</Link>
          </div>

          {recent.length === 0 ? (
            <p className="note">
              No manual runs logged yet. Record your first workout on the <Link to="/activities">Log a Run</Link> page.
            </p>
          ) : (
            <ul className="runs">
              {recent.map((r) => (
                <li key={r.id} className="card">
                  <strong>{r.activity_date}</strong>
                  <span>{r.distance_km} km</span>
                  <span>{formatDuration(r.duration_seconds)}</span>
                  <span>{r.pace_label} /km</span>
                  {r.from_plan && <span className="pill">Plan run</span>}
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="plan-card" aria-labelledby="progress-title">
          <div className="panel-head">
            <h2 id="progress-title">Plan adherence</h2>
            <Link className="panel-link" to="/plan">Open plan &rarr;</Link>
          </div>

          {!plan ? (
            <>
              <p className="note">
                Adherence rate, completed sessions, and skipped/missed workouts appear here once a plan is active.
              </p>
              <span className="pill pill-muted">No plan active</span>
            </>
          ) : (
            <>
              <p style={{ margin: '0 0 12px', color: 'var(--text-secondary)' }}>
                Completion rate to date:{' '}
                <strong className="mono" style={{ color: 'var(--text)', fontSize: 16 }}>
                  {plan.completion_rate === null ? 'None due yet' : `${plan.completion_rate}%`}
                </strong>{' '}
                ({plan.completed} of {plan.due} due sessions completed; {plan.skipped} skipped, {plan.missed} missed).
              </p>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                <span className="pill">
                  {plan.completion_rate !== null && plan.completion_rate >= 80
                    ? 'On track'
                    : 'Deterministic schedule'}
                </span>
                {plan.missed > 0 && (
                  <span className="pill pill-danger">{plan.missed} missed</span>
                )}
                {plan.skipped > 0 && (
                  <span className="pill pill-warn">{plan.skipped} skipped</span>
                )}
              </div>
            </>
          )}
        </section>
      </div>
    </>
  )
}
