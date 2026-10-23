import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiGet, apiPatch } from '../api.js'
import { useWeather, WeatherDayNotes } from '../components/WeatherPanel.jsx'
import {
  STATUS_LABELS, TYPE_LABELS, TYPE_SHORT,
  formatDuration, formatLongDate, monthGrid, paceLabel,
} from '../format.js'

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
const MARKS = { completed: '\u2713', skipped: '\u2715', missed: '!' }
const STATUS_PILL = {
  completed: 'pill-ok',
  skipped: 'pill-warn',
  missed: 'pill-danger',
  planned: '',
}

const monthOf = (iso) => {
  const [y, m] = iso.split('-').map(Number)
  return { y, m: m - 1 }
}
const monthIndex = ({ y, m }) => y * 12 + m
const monthTitle = ({ y, m }) =>
  new Date(Date.UTC(y, m, 1)).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })

function formatStartTime(hhmmss) {
  if (!hhmmss) return 'Flexible'
  const [hStr, mStr] = hhmmss.split(':')
  const h = Number(hStr)
  const m = Number(mStr)
  if (Number.isNaN(h) || Number.isNaN(m)) return hhmmss
  const suffix = h >= 12 ? 'PM' : 'AM'
  const h12 = h % 12 || 12
  return `${h12}:${String(m).padStart(2, '0')} ${suffix}`
}

export default function Calendar() {
  const [data, setData] = useState(null)
  const [runs, setRuns] = useState([])
  const [state, setState] = useState('loading')
  const [month, setMonth] = useState(null)
  const [selected, setSelected] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [moving, setMoving] = useState(false)
  const [moveDate, setMoveDate] = useState('')
  const [skipping, setSkipping] = useState(false)
  const [skipReason, setSkipReason] = useState('')

  const today = new Date().toLocaleDateString('en-CA')
  const { byDate: weatherByDate } = useWeather()

  useEffect(() => {
    Promise.all([apiGet('/plans/current'), apiGet('/activities')])
      .then(([plans, acts]) => {
        const active = plans.data.active
        setData(active)
        setRuns(acts.data)
        if (active) {
          const { start_date, end_date } = active.plan
          const initial = today < start_date ? start_date : today > end_date ? end_date : today
          const first = active.sessions.find((s) => s.scheduled_date >= initial)
          const pick = active.sessions.some((s) => s.scheduled_date === initial)
            ? initial
            : first?.scheduled_date ?? initial
          setSelected(pick)
          setMonth(monthOf(pick))
        }
        setState('ready')
      })
      .catch(() => setState('error'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const byDate = useMemo(
    () => new Map((data?.sessions ?? []).map((s) => [s.scheduled_date, s])),
    [data],
  )
  const runBySession = useMemo(
    () => new Map(runs.filter((r) => r.session_id).map((r) => [r.session_id, r])),
    [runs],
  )

  function selectDay(date) {
    setSelected(date)
    setMoving(false)
    setSkipping(false)
    setSkipReason('')
    setError('')
    setNotice('')
  }

  async function changeStatus(session, status, reasonText = null) {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const payload = status === 'skipped'
        ? { status, skip_reason: reasonText?.trim() || null }
        : { status }
      const body = await apiPatch(`/sessions/${session.id}`, payload)
      setData((d) => ({ ...d, sessions: d.sessions.map((s) => (s.id === session.id ? body.data : s)) }))
      setSkipping(false)
      setSkipReason('')
      setNotice(status === 'skipped' ? 'Run marked as skipped.' : 'Run restored to planned.')
    } catch (err) {
      setError(err.fields?.skip_reason || err.message)
    } finally {
      setBusy(false)
    }
  }

  function handleSkipSubmit(e, session) {
    e.preventDefault()
    changeStatus(session, 'skipped', skipReason)
  }

  async function submitMove(e, session) {
    e.preventDefault()
    if (!moveDate) {
      setError('Choose a new date for this run.')
      return
    }
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const body = await apiPatch(`/sessions/${session.id}`, { scheduled_date: moveDate })
      const updated = body.data
      setData((d) => ({
        ...d,
        sessions: d.sessions
          .map((s) => (s.id === session.id ? updated : s))
          .sort((a, b) => a.scheduled_date.localeCompare(b.scheduled_date)),
      }))
      setMoving(false)
      setSelected(updated.scheduled_date)
      setMonth(monthOf(updated.scheduled_date))
      setNotice(`Moved ${TYPE_LABELS[updated.type].toLowerCase()} to ${formatLongDate(updated.scheduled_date)}.`)
    } catch (err) {
      setError(err.fields?.scheduled_date || err.message)
    } finally {
      setBusy(false)
    }
  }

  if (state === 'loading') return <p role="status">Loading calendar...</p>
  if (state === 'error') return <p role="alert">Could not load your calendar.</p>
  if (!data) {
    return (
      <>
        <h1>Calendar</h1>
        <section className="plan-card">
          <h2>No active training plan</h2>
          <p className="note">Generate and approve a plan first to view and reschedule workouts on your calendar.</p>
          <div className="btn-row">
            <Link className="btn btn-primary" to="/plan">Create your plan</Link>
          </div>
        </section>
      </>
    )
  }

  const { start_date, end_date } = data.plan
  const cells = monthGrid(month.y, month.m)
  const session = byDate.get(selected)
  const run = session ? runBySession.get(session.id) : null
  const canMove = session && session.status !== 'completed' && session.type !== 'race'

  function goToday() {
    const day = today < start_date ? start_date : today > end_date ? end_date : today
    selectDay(day)
    setMonth(monthOf(day))
  }
  const shift = (delta) => {
    const i = monthIndex(month) + delta
    setMonth({ y: Math.floor(i / 12), m: i % 12 })
  }

  return (
    <>
      <h1>Calendar</h1>
      {notice && <p className="success" role="status">{notice}</p>}

      <div className="split-workspace">
        <section className="plan-card" aria-label="Monthly schedule">
          <div className="cal-nav">
            <button
              type="button"
              className="cal-btn"
              aria-label="Previous month"
              disabled={monthIndex(month) <= monthIndex(monthOf(start_date))}
              onClick={() => shift(-1)}
            >
              &lsaquo;
            </button>
            <h2 aria-live="polite">{monthTitle(month)}</h2>
            <button
              type="button"
              className="cal-btn"
              aria-label="Next month"
              disabled={monthIndex(month) >= monthIndex(monthOf(end_date))}
              onClick={() => shift(1)}
            >
              &rsaquo;
            </button>
            <button type="button" className="cal-btn cal-today" onClick={goToday}>Today</button>
          </div>

          <div className="cal-head" aria-hidden="true">
            {WEEKDAYS.map((d) => <span key={d}>{d}</span>)}
          </div>
          <div className="cal-grid">
            {cells.map((date, i) => {
              if (!date) return <span key={`e${i}`} className="day empty" />
              const s = byDate.get(date)
              const label = s
                ? `${formatLongDate(date)}: ${TYPE_LABELS[s.type]}, ${s.planned_distance_km} km, ${STATUS_LABELS[s.status]}`
                : `${formatLongDate(date)}: rest day`
              return (
                <button
                  key={date}
                  type="button"
                  className={`day status-${s?.status ?? 'none'}${date === today ? ' today' : ''}`}
                  aria-pressed={date === selected}
                  aria-current={date === today ? 'date' : undefined}
                  aria-label={label}
                  onClick={() => selectDay(date)}
                >
                  <span className="day-num">{Number(date.slice(8))}</span>
                  {s && (
                    <span className="day-chip" aria-hidden="true">
                      {TYPE_SHORT[s.type]} {s.planned_distance_km}
                      {MARKS[s.status] ? ` ${MARKS[s.status]}` : ''}
                    </span>
                  )}
                </button>
              )
            })}
          </div>
          <p className="note" style={{ marginBottom: 0, marginTop: 12 }}>
            E easy &middot; L long &middot; T tempo &middot; I intervals &middot; RACE race day &nbsp;|&nbsp;
            {MARKS.completed} done &middot; {MARKS.skipped} skipped &middot; ! missed
          </p>
        </section>

        <section className="plan-card" aria-labelledby="day-title" aria-live="polite">
          <div className="panel-head">
            <h2 id="day-title">{formatLongDate(selected)}</h2>
            {session && (
              <span className={`pill ${STATUS_PILL[session.status] ?? ''}`}>
                {STATUS_LABELS[session.status]}
              </span>
            )}
          </div>

          {!session ? (
            <p className="note">Rest day — no scheduled workout on this date.</p>
          ) : (
            <>
              <p style={{ margin: '0 0 12px', fontWeight: 600, fontSize: 15 }}>
                {TYPE_LABELS[session.type]}
              </p>

              <dl className="stats">
                <div><dt>Planned Distance</dt><dd>{session.planned_distance_km} km</dd></div>
                <div><dt>Estimated Time</dt><dd>{formatDuration(session.planned_duration_seconds)}</dd></div>
                <div>
                  <dt>Target Pace</dt>
                  <dd>{paceLabel(session.planned_duration_seconds, session.planned_distance_km)}</dd>
                </div>
                <div><dt>Planned Start</dt><dd>{formatStartTime(session.planned_start_time)}</dd></div>
              </dl>

              <WeatherDayNotes day={weatherByDate[selected]} />

              {session.notes && <p className="note">{session.notes}</p>}

              {session.status === 'skipped' && session.skip_reason && (
                <p className="note" style={{ marginTop: 0 }}>
                  <strong>Skip reason:</strong> {session.skip_reason}
                </p>
              )}

              {run && (
                <div className="success" role="status" style={{ marginBottom: 0 }}>
                  <strong>Logged run:</strong>{' '}
                  <span className="mono">
                    {run.distance_km} km in {formatDuration(run.duration_seconds)} ({run.pace_label} /km)
                  </span>
                  {run.effort ? ` · Effort ${run.effort}/10` : ''}
                </div>
              )}

              {error && <p className="error" role="alert">{error}</p>}

              {session.status !== 'completed' && (
                <div className="btn-row">
                  <Link className="btn btn-primary" to={`/activities?session=${session.id}`}>Log this run</Link>
                  {canMove && !moving && (
                    <button
                      type="button"
                      className="btn btn-secondary"
                      disabled={busy}
                      onClick={() => {
                        setMoving(true)
                        setSkipping(false)
                        setMoveDate(session.scheduled_date)
                        setError('')
                      }}
                    >
                      Move date
                    </button>
                  )}
                  {session.status === 'skipped' ? (
                    <button
                      type="button"
                      className="btn btn-secondary"
                      disabled={busy}
                      onClick={() => changeStatus(session, 'planned')}
                    >
                      Undo skip
                    </button>
                  ) : (
                    !skipping && (
                      <button
                        type="button"
                        className="btn btn-secondary"
                        disabled={busy}
                        onClick={() => {
                          setSkipping(true)
                          setMoving(false)
                          setSkipReason('')
                          setError('')
                        }}
                      >
                        {session.status === 'missed' ? 'Mark as skipped' : 'Skip run'}
                      </button>
                    )
                  )}
                </div>
              )}

              {skipping && session.status !== 'completed' && session.status !== 'skipped' && (
                <form className="inline-reschedule" onSubmit={(e) => handleSkipSubmit(e, session)}>
                  <label className="field" htmlFor="skip-reason">
                    <span>Reason for skipping this workout (optional)</span>
                    <input
                      id="skip-reason"
                      type="text"
                      maxLength={255}
                      placeholder="e.g., Sore calf, work shift, heavy rain"
                      value={skipReason}
                      onChange={(e) => setSkipReason(e.target.value)}
                    />
                  </label>
                  <div className="btn-row">
                    <button type="submit" className="btn btn-primary" disabled={busy}>
                      {busy ? 'Saving...' : 'Confirm skip'}
                    </button>
                    <button
                      type="button"
                      className="btn btn-secondary"
                      disabled={busy}
                      onClick={() => { setSkipping(false); setSkipReason(''); setError('') }}
                    >
                      Cancel
                    </button>
                  </div>
                </form>
              )}

              {canMove && moving && (
                <form className="inline-reschedule" onSubmit={(e) => submitMove(e, session)}>
                  <label className="field" htmlFor="move-date">
                    <span>Move workout to an open date ({start_date} to {end_date})</span>
                    <input
                      id="move-date"
                      type="date"
                      min={start_date}
                      max={end_date}
                      value={moveDate}
                      onChange={(e) => setMoveDate(e.target.value)}
                    />
                  </label>
                  <div className="btn-row">
                    <button type="submit" className="btn btn-primary" disabled={busy}>
                      {busy ? 'Moving...' : 'Save new date'}
                    </button>
                    <button
                      type="button"
                      className="btn btn-secondary"
                      disabled={busy}
                      onClick={() => { setMoving(false); setError('') }}
                    >
                      Cancel
                    </button>
                  </div>
                </form>
              )}
            </>
          )}
        </section>
      </div>
    </>
  )
}
