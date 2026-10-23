import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiGet, apiPost } from '../api.js'
import ConfirmDialog from '../components/ConfirmDialog.jsx'
import { TYPE_LABELS, formatDate, formatDuration, weeklySummary } from '../format.js'

const GOALS = {
  '5k': '5K', '10k': '10K', half_marathon: 'Half marathon',
  marathon: 'Marathon', general_fitness: 'General fitness',
}

const DECISION_PILL = {
  applied: 'pill-ok',
  pending: 'pill-warn',
  rejected: '',
}

function formatTimestamp(ts) {
  if (!ts) return ''
  const iso = ts.includes('T') ? ts : ts.replace(' ', 'T') + 'Z'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? ts : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

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

function PlanSummary({ data }) {
  const { plan, sessions } = data
  const weeks = weeklySummary(sessions, plan.start_date)
  const totalKm = sessions.reduce((sum, s) => sum + (s.planned_distance_km ?? 0), 0)

  return (
    <>
      <dl className="stats">
        <div><dt>Goal</dt><dd>{GOALS[plan.goal] ?? plan.goal}</dd></div>
        <div><dt>Schedule Window</dt><dd>{formatDate(plan.start_date)} &ndash; {formatDate(plan.end_date)}</dd></div>
        <div><dt>Length</dt><dd>{weeks.length} weeks</dd></div>
        <div><dt>Runs / Week</dt><dd>{plan.sessions_per_week}</dd></div>
        <div><dt>Total Volume</dt><dd>{Math.round(totalKm)} km</dd></div>
      </dl>

      <details className="weeks" open>
        <summary>Week-by-week breakdown ({weeks.length} weeks)</summary>
        <div className="table-wrap">
          <table>
            <caption className="sr-only">Planned distance for each week</caption>
            <thead>
              <tr>
                <th scope="col">Week</th>
                <th scope="col">Starts</th>
                <th scope="col">Sessions</th>
                <th scope="col">Volume</th>
                <th scope="col">Long Run</th>
              </tr>
            </thead>
            <tbody>
              {weeks.map((w) => (
                <tr key={w.week}>
                  <th scope="row" className="mono">W{w.week}</th>
                  <td className="mono">{formatDate(w.starts)}</td>
                  <td className="mono">{w.runs}</td>
                  <td className="mono">{w.km.toFixed(1)} km</td>
                  <td className="mono">{w.race ? 'Race week' : w.long !== null ? `${w.long} km` : '—'}</td>
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
  const [draftBrief, setDraftBrief] = useState(null)
  const [activeWarnings, setActiveWarnings] = useState([])
  const [notice, setNotice] = useState('')
  const [state, setState] = useState('loading')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [proposing, setProposing] = useState(false)
  const [proposalReason, setProposalReason] = useState('')

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
      setDraftBrief(result.ai_brief ?? null)
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
      setDraftBrief(null)
      setActiveWarnings([])
      setProposing(false)
      setProposalReason('')
      setNotice('Your training plan is now active.')
      await loadRevisions(body.data?.plan?.id)
    } catch (err) {
      setError({ message: err.message })
    } finally {
      setBusy(false)
      setConfirming(false)
    }
  }

  async function discardDraft() {
    const draftId = plans.draft?.plan?.id
    if (!draftId) return
    setBusy(true)
    setError(null)
    setNotice('')
    try {
      await apiPost(`/plans/${draftId}/discard`)
      const hadActive = Boolean(plans.active)
      setPlans((p) => ({ ...p, draft: null }))
      setWarnings([])
      setDraftBrief(null)
      setNotice(
        hadActive
          ? 'Draft schedule cancelled. Your active training plan is unchanged.'
          : 'Draft schedule cancelled.',
      )
    } catch (err) {
      setError({ message: err.message })
    } finally {
      setBusy(false)
    }
  }

  async function handlePropose(e) {
    e.preventDefault()
    const planId = plans.active?.plan?.id
    if (!planId) return
    setBusy(true)
    setError(null)
    setNotice('')
    try {
      const body = await apiPost(`/plans/${planId}/revisions/propose`, {
        reason: proposalReason.trim() || null,
      })
      setPlans((p) => ({
        ...p,
        active: p.active ? { ...p.active, pending_revision: body.data } : p.active,
      }))
      setProposing(false)
      setProposalReason('')
      setNotice('Revision preview ready below. Review the proposed sessions before applying.')
      await loadRevisions(planId)
    } catch (err) {
      setError({ message: err.message, fields: err.fields })
    } finally {
      setBusy(false)
    }
  }

  async function handleApplyProposal() {
    const planId = plans.active?.plan?.id
    const revId = plans.active?.pending_revision?.id
    if (!planId || !revId) return
    setBusy(true)
    setError(null)
    setNotice('')
    try {
      const body = await apiPost(`/plans/${planId}/revisions/${revId}/apply`)
      const result = body.data
      setPlans((p) => ({
        ...p,
        active: { plan: result.plan, sessions: result.sessions, pending_revision: null },
      }))
      setActiveWarnings(result.warnings ?? [])
      setNotice(`Revision applied — active plan updated to v${result.plan.version ?? 1}. Completed runs were preserved.`)
      await loadRevisions(planId)
    } catch (err) {
      setError({ message: err.message, fields: err.fields })
    } finally {
      setBusy(false)
    }
  }

  async function handleRejectProposal() {
    const planId = plans.active?.plan?.id
    const revId = plans.active?.pending_revision?.id
    if (!planId || !revId) return
    setBusy(true)
    setError(null)
    setNotice('')
    try {
      await apiPost(`/plans/${planId}/revisions/${revId}/reject`)
      setPlans((p) => ({
        ...p,
        active: p.active ? { ...p.active, pending_revision: null } : p.active,
      }))
      setNotice('Revision proposal cancelled. Your active training schedule is unchanged.')
      await loadRevisions(planId)
    } catch (err) {
      setError({ message: err.message })
    } finally {
      setBusy(false)
    }
  }

  if (state === 'loading') return <p role="status">Loading plan...</p>
  if (state === 'error') return <p role="alert">Could not load your plan.</p>

  const { active, draft } = plans
  const pendingRevision = active?.pending_revision ?? null

  return (
    <>
      <h1>Plan</h1>

      <div className="advisory-banner">
        <strong>Safety note:</strong> This schedule provides general running conditioning guidance, not medical advice.
        Consult a qualified clinician before starting an intensive training block.
      </div>

      {notice && <p className="success" role="status">{notice}</p>}

      {error && (
        <div className="error" role="alert" style={{ marginBottom: 14 }}>
          {error.fields?.profile ? (
            <p>Complete your runner profile first. <Link to="/profile">Open Runner Profile &rarr;</Link></p>
          ) : error.fields?.target_race_date ? (
            <p>{error.fields.target_race_date} <Link to="/profile">Update race date &rarr;</Link></p>
          ) : error.fields?.reason ? (
            <p>{error.fields.reason}</p>
          ) : (
            <p>{error.message}</p>
          )}
        </div>
      )}

      {draft && (
        <section className="plan-card" aria-labelledby="draft-title">
          <div className="panel-head">
            <h2 id="draft-title">Proposed draft schedule</h2>
            <span className="pill pill-warn">Pending approval</span>
          </div>
          {draftBrief?.text && (
            <div className="advisory-banner" style={{ marginBottom: 14 }}>
              <strong>{draftBrief.provider_status === 'ok' ? 'Claude AI coach:' : 'Coach overview:'}</strong>{' '}
              {draftBrief.text}
            </div>
          )}
          <PlanSummary data={draft} />
          {warnings.length > 0 && (
            <ul className="warnings">
              {warnings.map((w) => <li key={w}>{w}</li>)}
            </ul>
          )}
          <div className="btn-row">
            <button
              type="button"
              className="btn btn-primary"
              disabled={busy}
              onClick={() => (active ? setConfirming(true) : approve())}
            >
              {busy ? 'Please wait...' : 'Approve & activate plan'}
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={generate}>
              Regenerate draft
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={discardDraft}>
              Cancel draft
            </button>
          </div>
        </section>
      )}

      {pendingRevision && (
        <section className="plan-card" aria-labelledby="proposal-title">
          <div className="panel-head">
            <h2 id="proposal-title">Proposed plan revision preview</h2>
            <span className="pill pill-warn">Pending review &middot; from v{pendingRevision.previous_version}</span>
          </div>

          <p className="note">
            Your active schedule has <strong>not</strong> been changed yet. Review the recalculated workouts below, then
            apply or cancel this proposal.
            {pendingRevision.reason ? ` Reason: "${pendingRevision.reason}"` : ''}
          </p>

          <dl className="stats">
            <div><dt>Effective From</dt><dd>{formatDate(pendingRevision.from_date)}</dd></div>
            <div><dt>Workouts Replaced</dt><dd>{pendingRevision.replaced_count}</dd></div>
            <div><dt>New Workouts</dt><dd>{pendingRevision.proposed_count}</dd></div>
            <div><dt>Completed Protected</dt><dd>{pendingRevision.preserved_completed}</dd></div>
            <div><dt>Runs / Week</dt><dd>{pendingRevision.plan?.sessions_per_week ?? '—'}</dd></div>
          </dl>

          {pendingRevision.warnings?.length > 0 && (
            <ul className="warnings">
              {pendingRevision.warnings.map((w) => <li key={w}>{w}</li>)}
            </ul>
          )}

          {pendingRevision.sessions?.length > 0 && (
            <details className="weeks">
              <summary>Preview proposed workouts ({pendingRevision.sessions.length} upcoming sessions)</summary>
              <div className="table-wrap">
                <table>
                  <caption className="sr-only">Proposed upcoming training sessions</caption>
                  <thead>
                    <tr>
                      <th scope="col">Date</th>
                      <th scope="col">Time</th>
                      <th scope="col">Workout</th>
                      <th scope="col">Distance</th>
                      <th scope="col">Est. Duration</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pendingRevision.sessions.map((s) => (
                      <tr key={s.scheduled_date}>
                        <td className="mono">{formatDate(s.scheduled_date)}</td>
                        <td className="mono">{formatStartTime(s.planned_start_time)}</td>
                        <td>{TYPE_LABELS[s.type] ?? s.type}</td>
                        <td className="mono">{s.planned_distance_km} km</td>
                        <td className="mono">{formatDuration(s.planned_duration_seconds)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          )}

          <div className="btn-row">
            <button type="button" className="btn btn-primary" disabled={busy} onClick={handleApplyProposal}>
              {busy ? 'Applying...' : 'Apply revision'}
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={handleRejectProposal}>
              Cancel proposal
            </button>
          </div>
        </section>
      )}

      {active && (
        <section className="plan-card" aria-labelledby="active-title">
          <div className="panel-head">
            <h2 id="active-title">Active training plan</h2>
            <span className="pill pill-ok">{'\u2713'} Active &middot; v{active.plan.version ?? 1}</span>
          </div>

          <PlanSummary data={active} />

          {activeWarnings.length > 0 && (
            <ul className="warnings">
              {activeWarnings.map((w) => <li key={w}>{w}</li>)}
            </ul>
          )}

          {revisions.length > 0 && (
            <details className="weeks">
              <summary>Version &amp; revision history ({revisions.length})</summary>
              <div className="table-wrap">
                <table>
                  <caption className="sr-only">History of changes and proposals on this training plan</caption>
                  <thead>
                    <tr>
                      <th scope="col">Version</th>
                      <th scope="col">Status</th>
                      <th scope="col">Date</th>
                      <th scope="col">Summary &amp; reason</th>
                    </tr>
                  </thead>
                  <tbody>
                    {revisions.map((r, idx) => {
                      const decision = r.decision ?? 'applied'
                      const versionLabel = decision === 'applied'
                        ? `v${r.resulting_version ?? r.version}`
                        : `v${r.previous_version ?? r.version}`
                      return (
                        <tr key={r.id ?? `${r.version}-${idx}`}>
                          <th scope="row" className="mono">{versionLabel}</th>
                          <td>
                            <span className={`pill ${DECISION_PILL[decision] ?? ''}`}>
                              {decision.charAt(0).toUpperCase() + decision.slice(1)}
                            </span>
                          </td>
                          <td className="mono">{formatTimestamp(r.decision_timestamp || r.created_at)}</td>
                          <td className="wrap-cell">
                            <div>{r.summary}</div>
                            {r.reason && (
                              <div className="note" style={{ margin: '3px 0 0', fontSize: 12 }}>
                                Reason: {r.reason}
                              </div>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </details>
          )}

          {proposing && !pendingRevision && (
            <form className="inline-reschedule" onSubmit={handlePropose}>
              <label className="field" htmlFor="rev-reason">
                <span>Reason for revising remaining schedule (optional)</span>
                <input
                  id="rev-reason"
                  type="text"
                  maxLength={255}
                  placeholder="e.g., Adjusted available days or missed runs last week"
                  value={proposalReason}
                  onChange={(e) => setProposalReason(e.target.value)}
                />
              </label>
              <p className="note" style={{ margin: 0 }}>
                Completed runs stay untouched. You will see a full preview before any workouts change.
              </p>
              <div className="btn-row">
                <button type="submit" className="btn btn-primary" disabled={busy}>
                  {busy ? 'Generating preview...' : 'Preview proposed changes'}
                </button>
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={busy}
                  onClick={() => { setProposing(false); setProposalReason('') }}
                >
                  Cancel
                </button>
              </div>
            </form>
          )}

          <div className="btn-row">
            <Link className="btn btn-secondary" to="/calendar">Open calendar</Link>
            {!pendingRevision && !proposing && (
              <button
                type="button"
                className="btn btn-secondary"
                disabled={busy}
                onClick={() => setProposing(true)}
              >
                Propose plan revision
              </button>
            )}
            {!draft && (
              <button type="button" className="btn btn-primary" disabled={busy} onClick={generate}>
                {busy ? 'Building draft...' : 'Generate new draft'}
              </button>
            )}
          </div>
        </section>
      )}

      {!draft && !active && (
        <section className="plan-card">
          <h2>No training plan yet</h2>
          <p className="note">
            Generate a deterministic training schedule tailored to your goal, race date, and available days.
          </p>
          <div className="btn-row">
            <button type="button" className="btn btn-primary" disabled={busy} onClick={generate}>
              {busy ? 'Building your plan...' : 'Generate my plan'}
            </button>
            <Link className="btn btn-secondary" to="/profile">Review profile</Link>
          </div>
        </section>
      )}

      <ConfirmDialog
        open={confirming}
        title="Replace your current plan?"
        message="Approving this draft archives your active plan. All manual runs you already logged remain safe."
        confirmLabel="Approve"
        busy={busy}
        onConfirm={approve}
        onCancel={() => setConfirming(false)}
      />
    </>
  )
}
