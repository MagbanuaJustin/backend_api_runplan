import { useEffect, useState } from 'react'
import { apiGet, apiPut } from '../api.js'
import Field from '../components/Field.jsx'

const EXPERIENCE = [['beginner', 'Beginner'], ['intermediate', 'Intermediate'], ['advanced', 'Advanced']]
const GOALS = [
  ['5k', '5K'], ['10k', '10K'], ['half_marathon', 'Half marathon'],
  ['marathon', 'Marathon'], ['general_fitness', 'General fitness'],
]
const DAYS = [
  ['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'],
  ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun'],
]
const TIMES = [['morning', 'Morning'], ['afternoon', 'Afternoon'], ['evening', 'Evening']]

const empty = {
  display_name: '', experience: '', goal: '', target_race_date: '',
  available_days: [], preferred_time: '', baseline_distance_km: '', baseline_pace: '',
}

function fromApi(p) {
  return {
    display_name: p.display_name,
    experience: p.experience,
    goal: p.goal,
    target_race_date: p.target_race_date ?? '',
    available_days: p.available_days,
    preferred_time: p.preferred_time ?? '',
    baseline_distance_km: p.baseline_distance_km === null ? '' : String(p.baseline_distance_km),
    baseline_pace: p.baseline_pace_label ?? '',
  }
}

function paceToSeconds(text) {
  const m = /^(\d{1,2}):([0-5]\d)$/.exec(text.trim())
  return m ? Number(m[1]) * 60 + Number(m[2]) : null
}

export default function Profile() {
  const [form, setForm] = useState(empty)
  const [state, setState] = useState('loading')
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [saved, setSaved] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    apiGet('/profile')
      .then((body) => {
        if (body.data) setForm(fromApi(body.data))
        setState('ready')
      })
      .catch(() => setState('error'))
  }, [])

  const change = (patch) => {
    setForm((f) => ({ ...f, ...patch }))
    setSaved(false)
  }
  const set = (key) => (e) => change({ [key]: e.target.value })
  const attrs = (id, key, hint) => ({
    id,
    'aria-invalid': errors[key] ? 'true' : undefined,
    'aria-describedby': errors[key] ? `${id}-error` : hint ? `${id}-hint` : undefined,
  })

  function toggleDay(day) {
    const days = form.available_days.includes(day)
      ? form.available_days.filter((d) => d !== day)
      : [...form.available_days, day]
    change({ available_days: days })
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setFormError('')
    setSaved(false)

    let pace = null
    if (form.baseline_pace.trim() !== '') {
      pace = paceToSeconds(form.baseline_pace)
      if (pace === null) {
        setErrors({ baseline_pace: 'Use m:ss, for example 6:30.' })
        return
      }
    }

    const raceGoal = form.goal !== '' && form.goal !== 'general_fitness'
    const distance = form.baseline_distance_km.trim()

    setErrors({})
    setBusy(true)
    try {
      const body = await apiPut('/profile', {
        display_name: form.display_name,
        experience: form.experience,
        goal: form.goal,
        target_race_date: raceGoal ? form.target_race_date : null,
        available_days: form.available_days,
        preferred_time: form.preferred_time || null,
        baseline_distance_km: distance === '' ? null : distance,
        baseline_pace: pace,
      })
      setForm(fromApi(body.data))
      setSaved(true)
    } catch (err) {
      if (err.fields) setErrors(err.fields)
      else setFormError(err.message)
    } finally {
      setBusy(false)
    }
  }

  if (state === 'loading') return <p role="status">Loading profile...</p>
  if (state === 'error') return <p role="alert">Could not load your profile.</p>

  const raceGoal = form.goal !== '' && form.goal !== 'general_fitness'
  const today = new Date().toLocaleDateString('en-CA')

  return (
    <>
      <h1>Profile</h1>

      <section className="plan-card" style={{ maxWidth: 820 }}>
        <div className="panel-head">
          <h2>Training preferences &amp; baseline</h2>
          <span className="pill">Runner profile</span>
        </div>
        <p className="note">
          RunPlan uses these inputs to calculate weekly distance progression, taper length, and target paces.
        </p>

        {saved && <p className="success" role="status">Profile saved.</p>}

        <form className="form" onSubmit={handleSubmit} noValidate>
          <div className="form-grid-2">
            <Field id="pf-name" label="Display name" error={errors.display_name}>
              <input
                {...attrs('pf-name', 'display_name')}
                autoComplete="nickname"
                maxLength={50}
                value={form.display_name}
                onChange={set('display_name')}
              />
            </Field>

            <Field id="pf-exp" label="Running experience" error={errors.experience}>
              <select {...attrs('pf-exp', 'experience')} value={form.experience} onChange={set('experience')}>
                <option value="">Choose...</option>
                {EXPERIENCE.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </Field>
          </div>

          <div className="form-grid-2">
            <Field id="pf-goal" label="Target goal" error={errors.goal}>
              <select {...attrs('pf-goal', 'goal')} value={form.goal} onChange={set('goal')}>
                <option value="">Choose...</option>
                {GOALS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </Field>

            {raceGoal ? (
              <Field id="pf-date" label="Target race date" error={errors.target_race_date}>
                <input
                  {...attrs('pf-date', 'target_race_date')}
                  type="date"
                  min={today}
                  value={form.target_race_date}
                  onChange={set('target_race_date')}
                />
              </Field>
            ) : (
              <Field id="pf-time" label="Preferred time of day (optional)" error={errors.preferred_time}>
                <select {...attrs('pf-time', 'preferred_time')} value={form.preferred_time} onChange={set('preferred_time')}>
                  <option value="">No preference</option>
                  {TIMES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </Field>
            )}
          </div>

          {raceGoal && (
            <Field id="pf-time" label="Preferred time of day (optional)" error={errors.preferred_time}>
              <select {...attrs('pf-time', 'preferred_time')} value={form.preferred_time} onChange={set('preferred_time')}>
                <option value="">No preference</option>
                {TIMES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </Field>
          )}

          <fieldset className="fieldset" aria-describedby={errors.available_days ? 'pf-days-error' : undefined}>
            <legend>Available training days (choose at least 2)</legend>
            <div className="days">
              {DAYS.map(([v, l]) => (
                <label key={v} className="day">
                  <input type="checkbox" checked={form.available_days.includes(v)} onChange={() => toggleDay(v)} />
                  {l}
                </label>
              ))}
            </div>
            {errors.available_days && (
              <p id="pf-days-error" className="error" role="alert">{errors.available_days}</p>
            )}
          </fieldset>

          <div className="form-grid-2">
            <Field id="pf-km" label="Recent run distance in km (optional)" error={errors.baseline_distance_km}>
              <input
                {...attrs('pf-km', 'baseline_distance_km')}
                inputMode="decimal"
                placeholder="5"
                value={form.baseline_distance_km}
                onChange={set('baseline_distance_km')}
              />
            </Field>

            <Field
              id="pf-pace"
              label="Recent run pace per km, m:ss (optional)"
              hint="Enter both distance and pace, or leave both blank."
              error={errors.baseline_pace}
            >
              <input
                {...attrs('pf-pace', 'baseline_pace', true)}
                placeholder="6:30"
                value={form.baseline_pace}
                onChange={set('baseline_pace')}
              />
            </Field>
          </div>

          {formError && <p className="error" role="alert">{formError}</p>}

          <div className="btn-row">
            <button type="submit" disabled={busy}>{busy ? 'Saving...' : 'Save profile'}</button>
          </div>
        </form>
      </section>
    </>
  )
}
