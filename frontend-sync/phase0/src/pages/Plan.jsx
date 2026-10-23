import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiGet, apiPost } from '../api.js'
import ConfirmDialog from '../components/ConfirmDialog.jsx'
import { formatDate, weeklySummary } from '../format.js'

const GOALS = {
  '5k': '5K', '10k': '10K', half_marathon: 'Half marathon',
  marathon: 'Marathon', general_fitness: 'General fitness',
}

function formatTimestamp(ts) {
  if (!ts) return ''
  const iso = ts.includes('T') ? ts : ts.replace(' ', 'T') + 'Z'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? ts : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function PlanSummary({ data }) {
  const { plan, sessions } = data
  const weeks = weeklySummary(sessions, plan.start_date)
  const totalKm = sessions.reduce((sum, s) => sum + (s.planned_distance_km ?? 0), 0)

  return (
    <>
      <dl className="stats">
        <div><dt>Goal</dt><dd>{GOALS[plan.goal] ?? plan.goal}</dd></div>
        <div><dt>Dates</dt><dd>{formatDate(plan.start_date)} to {formatDate(plan.end_date)}</dd></div>
        <div><dt>Length</dt><dd>{weeks.length} weeks</dd></div>
        <div><dt>Runs per week</dt><dd>{plan.sessions_per_week}</dd></div>
        <div><dt>Total distance</dt><dd>{Math.round(totalKm)} km</dd></div>
      </dl>

      <details className="weeks">
        <summary>Week by week</summary>
        <div className="table-wrap">
          <table>
            <caption className="sr-only">Planned distance for each week</caption>
            <thead>
              <tr><th scope="col">Week</th><th scope="col">Starts</th><th scope="col">Runs</th>
                <th scope="col">Distance</th><th scope="col">Long run</th></tr>
            </thead>
            <tbody>
              {weeks.map((w) => (
                <tr key={w.week}>
                  <th scope="row">{w.week}</th>
                  <td>{formatDate(w.starts)}</td>
                  <td>{w.runs}</td>
                  <td>{w.km.toFixed(1)} km</td>
                  <td>{w.race ? 'Race week' : w.long !== null ? `${w.long} km` : '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </>
  )
}

export default function Plan() {
  const [plans, setPlans] = useState({ active: null, draft: null })
  const [revisions, setRevisions] = useState([])
  const [warnings, setWarnings] = useState([])
  const [activeWarnings, setActiveWarnings] = useState([])
  const [notice, setNotice] = useState('')
  const [state, setState] = useState('loading') // loading | ready | error
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [confirmRebuild, setConfirmRebuild] = useState(false)

  async function loadRevisions(planId) {
    if (!planId) {
      setRevisions([])
      return
    }
    try {
      const rev = await apiGet(`/plans/${planId}/revisions`)
      setRevisions(Array.isArray(rev.data) ? rev.data : [])
    } catch {
      setRevisions([])
    }
  }

  useEffect(() => {
    apiGet('/plans/current')
      .then((body) => {
        setPlans(body.data)
        setState('ready')
        if (body.data?.active?.plan?.id) {
          loadRevisions(body.data.active.plan.id)
        }
      })
      .catch(() => setState('error'))
  }, [])

  async function generate() {
    setBusy(true)
    setError(null)
    setNotice('')
    try {
      const body = await apiPost('/plans/generate')
      const result = body.data
      if (!result?.plan || !Array.isArray(result.sessions)) {
        throw new Error('The server returned an incomplete plan. Please try again.')
      }
      setPlans((p) => ({ ...p, draft: { plan: result.plan, sessions: result.sessions } }))
      setWarnings(result.warnings ?? [])
    } catch (err) {
      setError({ message: err.message, fields: err.fields })
    } finally {
      setBusy(false)
    }
  }

  async function approve() {
    setBusy(true)
    setError(null)
    setNotice('')
    try {
      const body = await apiPost(`/plans/${plans.draft.plan.id}/approve`)
      setPlans({ active: body.data, draft: null })
      setWarnings([])
      setActiveWarnings([])
      setNotice('Your training plan is now active.')
      await loadRevisions(body.data?.plan?.id)
    } catch (err) {
      setError({ message: err.message })
    } finally {
      setBusy(false)
      setConfirming(false)
    }
  }

  async function rebuildRemaining() {
    if (!plans.active?.plan?.id) return
    setBusy(true)
    setError(null)
    setNotice('')
    try {
      const planId = plans.active.plan.id
      const body = await apiPost(`/plans/${planId}/rebuild`)
      const result = body.data
      setPlans((p) => ({ ...p, active: { plan: result.plan, sessions: result.sessions } }))
      setActiveWarnings(result.warnings ?? [])
      setNotice(`Remaining schedule rebuilt (now version v${result.plan.version ?? 1}). Completed runs were preserved.`)
      await loadRevisions(planId)
    } catch (err) {
      setError({ message: err.message, fields: err.fields })
    } finally {
      setBusy(false)
      setConfirmRebuild(false)
    }
  }

  if (state === 'loading') return <p role="status">Loading plan...</p>
  if (state === 'error') return <p role="alert">Could not load your plan.</p>

  const { active, draft } = plans

  return (
    <>
      <h1>Plan</h1>
      <p className="note">
        This is general training guidance, not medical advice. Check with a doctor before
        starting a new training program.
      </p>

      {notice && <p className="success" role="status">{notice}</p>}

      {error && (
        <div className="error" role="alert">
          {error.fields?.profile ? (
            <p>Complete your profile first. <Link to="/profile">Go to Profile</Link></p>
          ) : error.fields?.target_race_date ? (
            <p>{error.fields.target_race_date} <Link to="/profile">Change your race date</Link></p>
          ) : (
            <p>{error.message}</p>
          )}
        </div>
      )}

      {draft && (
        <section className="plan-card" aria-labelledby="draft-title">
          <h2 id="draft-title">Draft plan <span className="badge">Not approved yet</span></h2>
          <PlanSummary data={draft} />
          {warnings.length > 0 && (
            <ul className="warnings">
              {warnings.map((w) => <li key={w}>{w}</li>)}
            </ul>
          )}
          <div className="btn-row">
            <button type="button" className="btn btn-primary" disabled={busy}
              onClick={() => (active ? setConfirming(true) : approve())}>
              {busy ? 'Please wait...' : 'Approve plan'}
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={generate}>
              Generate again
            </button>
          </div>
        </section>
      )}

      {active && (
        <section className="plan-card" aria-labelledby="active-title">
          <h2 id="active-title">
            Your active plan{' '}
            <span className="badge">Active &middot; v{active.plan.version ?? 1}</span>
          </h2>
          <PlanSummary data={active} />
          {activeWarnings.length > 0 && (
            <ul className="warnings">
              {activeWarnings.map((w) => <li key={w}>{w}</li>)}
            </ul>
          )}

          {revisions.length > 0 && (
            <details className="weeks">
              <summary>Version history ({revisions.length})</summary>
              <div className="table-wrap">
                <table>
                  <caption className="sr-only">History of changes to this training plan</caption>
                  <thead>
                    <tr>
                      <th scope="col">Version</th>
                      <th scope="col">Date</th>
                      <th scope="col">Change summary</th>
                    </tr>
                  </thead>
                  <tbody>
                    {revisions.map((r) => (
                      <tr key={r.version}>
                        <th scope="row">v{r.version}</th>
                        <td>{formatTimestamp(r.created_at)}</td>
                        <td className="wrap-cell">{r.summary}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          )}

          <div className="btn-row">
            <Link className="btn btn-secondary" to="/calendar">Open calendar</Link>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={busy}
              onClick={() => setConfirmRebuild(true)}
            >
              Rebuild remaining plan
            </button>
          </div>
        </section>
      )}

      {!draft && !active && <p>You do not have a plan yet. Build one from your profile.</p>}

      {!draft && (
        <button type="button" className="btn btn-primary" disabled={busy} onClick={generate}>
          {busy ? 'Building your plan...' : active ? 'Create a new plan' : 'Generate my plan'}
        </button>
      )}

      <ConfirmDialog
        open={confirming}
        title="Replace your current plan?"
        message="Approving this draft archives your active plan. The runs you already logged are kept."
        confirmLabel="Approve"
        busy={busy}
        onConfirm={approve}
        onCancel={() => setConfirming(false)}
      />

      <ConfirmDialog
        open={confirmRebuild}
        title="Rebuild remaining sessions?"
        message="Completed runs stay untouched. Future sessions from tomorrow onward will be recalculated using your current profile and recent manual runs."
        confirmLabel="Rebuild"
        busy={busy}
        onConfirm={rebuildRemaining}
        onCancel={() => setConfirmRebuild(false)}
      />
    </>
  )
}
