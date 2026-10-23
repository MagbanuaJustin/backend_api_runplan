import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiGet } from '../api.js'
import { formatDate } from '../format.js'

/**
 * Weather advisory UI (Phase 4 / spec 5.2).
 *
 * Two deliberate choices:
 *
 *   1. The component fetches its own data. Adding weather to a page is then a
 *      one-line change, and a weather outage can never break the page it sits
 *      on: every failure is caught here and rendered as a sentence.
 *   2. Everything is advisory. Nothing on this screen changes a workout; the
 *      buttons link to Calendar / Profile and the runner decides.
 */

const STATE_TONE = {
  ok: 'pill-ok',
  stale_cache: 'pill-warn',
  not_configured: 'pill-muted',
  no_location: 'pill-muted',
  unresolved_location: 'pill-warn',
  unavailable: 'pill-danger',
}

const STATE_LABEL = {
  ok: 'Live forecast',
  stale_cache: 'Last known forecast',
  not_configured: 'Weather off',
  no_location: 'No location set',
  unresolved_location: 'Location not found',
  unavailable: 'Weather unavailable',
}

/** Fetch the advisory forecast once per page. */
export function useWeather() {
  const [state, setState] = useState({ loading: true, data: null, error: '' })

  const reload = useCallback(() => {
    const today = new Date().toLocaleDateString('en-CA')
    setState((s) => ({ ...s, loading: true, error: '' }))
    apiGet(`/weather/forecast?today=${today}`)
      .then((body) => setState({ loading: false, data: body?.data ?? null, error: '' }))
      .catch((err) => setState({ loading: false, data: null, error: err.message || 'Weather is unavailable.' }))
  }, [])

  useEffect(() => { reload() }, [reload])

  // date -> day, so Calendar can look up exactly the day it is showing
  const byDate = {}
  for (const day of state.data?.days ?? []) byDate[day.date] = day

  return { ...state, byDate, reload }
}

function Advisory({ note }) {
  return (
    <p className={`weather-adv ${note.level === 'caution' ? 'caution' : ''}`}>
      <strong>{note.title}.</strong> {note.detail}
    </p>
  )
}

/** One forecast day: date, temperature, condition, and its worst advisory. */
export function WeatherDay({ day, compact = false }) {
  const worst = day.advisories?.[0]
  const tone = worst?.level === 'warning' ? ' is-warn' : worst?.level === 'caution' ? ' is-caution' : ''

  return (
    <div className={`weather-day${tone}`}>
      <span className="weather-date">{formatDate(day.date)}</span>
      <div className="weather-temp">
        {day.temp_max_c === null ? '—' : `${Math.round(day.temp_max_c)}°`}
        {day.temp_min_c !== null && (
          <span className="weather-min"> / {Math.round(day.temp_min_c)}°</span>
        )}
      </div>
      <span className="weather-meta">{day.condition_label}</span>
      <span className="weather-meta mono">
        {day.rain_probability > 0 ? `${day.rain_probability}% rain` : 'No rain'}
        {day.wind_max_kmh !== null ? ` · ${Math.round(day.wind_max_kmh)} km/h` : ''}
      </span>
      {!compact && worst && <Advisory note={worst} />}
    </div>
  )
}

/**
 * Calendar: notes for the one day the runner has selected.
 * Renders nothing when that date is outside the forecast horizon.
 */
export function WeatherDayNotes({ day }) {
  if (!day) return null

  return (
    <div className="weather-inline">
      <strong>Weather advisory</strong>{' '}
      <span className="mono">{day.summary}</span>
      {day.advisories?.length
        ? day.advisories.map((note) => <Advisory key={note.kind + note.level} note={note} />)
        : <span className="weather-meta"> · No heat, rain or wind concerns for this day.</span>}
    </div>
  )
}

/** Dashboard: the 5-day advisory strip, or a sentence explaining why there is none. */
export default function WeatherPanel() {
  const { loading, data, error } = useWeather()

  if (loading && !data) {
    return (
      <section className="plan-card" aria-labelledby="weather-title">
        <div className="panel-head"><h2 id="weather-title">Weather advisory</h2></div>
        <p className="note" role="status" style={{ margin: 0 }}>Loading forecast...</p>
      </section>
    )
  }

  if (error) {
    return (
      <section className="plan-card" aria-labelledby="weather-title">
        <div className="panel-head">
          <h2 id="weather-title">Weather advisory</h2>
          <span className="pill pill-warn">Unavailable</span>
        </div>
        <p className="note" style={{ margin: 0 }}>
          {error} Your schedule is unaffected — weather is advisory only.
        </p>
      </section>
    )
  }

  const status = data?.status ?? 'unavailable'
  const days = data?.days ?? []
  const hasDays = days.length > 0

  return (
    <section className="plan-card" aria-labelledby="weather-title">
      <div className="panel-head">
        <h2 id="weather-title">Weather advisory</h2>
        <span className={`pill ${STATE_TONE[status] ?? 'pill-muted'}`}>
          {STATE_LABEL[status] ?? 'Weather'}
        </span>
      </div>

      <p className="note" style={{ marginTop: 0 }}>
        {data?.location?.label ? (
          <>
            Advisory conditions for <strong>{data.location.label}</strong>.{' '}
          </>
        ) : null}
        Weather never changes your schedule; it only tells you what to expect.
      </p>

      {hasDays ? (
        <div className="weather-strip">
          {days.map((day) => <WeatherDay key={day.date} day={day} />)}
        </div>
      ) : (
        <p className="note" style={{ marginBottom: 0 }}>{data?.message}</p>
      )}

      <div className="btn-row" style={{ marginBottom: 0 }}>
        {status === 'no_location' || status === 'unresolved_location' ? (
          <Link className="btn btn-secondary" to="/profile">Set training location</Link>
        ) : (
          <Link className="btn btn-secondary" to="/calendar">Open calendar</Link>
        )}
        {status === 'stale_cache' && (
          <span className="weather-meta">Showing the last forecast we received.</span>
        )}
      </div>
    </section>
  )
}
