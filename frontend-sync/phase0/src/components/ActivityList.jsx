import { useEffect, useState } from 'react'
import { apiGet, apiDelete } from '../api.js'
import { formatDuration } from '../format.js'
import ActivityForm from './ActivityForm.jsx'
import ConfirmDialog from './ConfirmDialog.jsx'

const newestFirst = (a, b) =>
  b.activity_date.localeCompare(a.activity_date) || b.id - a.id

export default function ActivityList() {
  const [activities, setActivities] = useState([])
  const [state, setState] = useState('loading') // loading | ready | error
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [editingId, setEditingId] = useState(null)
  const [toDelete, setToDelete] = useState(null)
  const [busy, setBusy] = useState(false)
  const [deleteError, setDeleteError] = useState('')

  useEffect(() => {
    const qs = new URLSearchParams()
    if (fromDate) qs.set('from', fromDate)
    if (toDate) qs.set('to', toDate)
    const suffix = qs.toString() ? `?${qs.toString()}` : ''

    setState('loading')
    apiGet(`/activities${suffix}`)
      .then((body) => {
        setActivities(body.data)
        setState('ready')
      })
      .catch(() => setState('error'))
  }, [fromDate, toDate])

  function handleSaved(row) {
    setActivities((list) => list.map((a) => (a.id === row.id ? row : a)).sort(newestFirst))
    setEditingId(null)
  }

  async function confirmDelete() {
    setBusy(true)
    setDeleteError('')
    try {
      await apiDelete(`/activities/${toDelete.id}`)
      setActivities((list) => list.filter((a) => a.id !== toDelete.id))
    } catch (err) {
      setDeleteError(err.message)
    } finally {
      setBusy(false)
      setToDelete(null)
    }
  }

  const hasFilter = fromDate !== '' || toDate !== ''

  return (
    <>
      <div className="filter-bar" role="region" aria-label="Filter runs by date">
        <label className="field">
          <span>From</span>
          <input type="date" value={fromDate} max={toDate || undefined} onChange={(e) => setFromDate(e.target.value)} />
        </label>
        <label className="field">
          <span>To</span>
          <input type="date" value={toDate} min={fromDate || undefined} onChange={(e) => setToDate(e.target.value)} />
        </label>
        {hasFilter && (
          <button
            type="button"
            className="card-action filter-clear"
            onClick={() => { setFromDate(''); setToDate('') }}
          >
            Clear
          </button>
        )}
      </div>

      {state === 'loading' && <p role="status">Loading runs...</p>}
      {state === 'error' && <p role="alert">Could not load your runs.</p>}

      {state === 'ready' && activities.length === 0 && (
        <p>{hasFilter ? 'No runs match that date range.' : 'No runs logged yet.'}</p>
      )}

      {state === 'ready' && activities.length > 0 && (
        <>
          <p className="note">
            Showing {activities.length} manual {activities.length === 1 ? 'run' : 'runs'}
            {hasFilter ? ' in selected range' : ''}.
          </p>
          {deleteError && <p className="error" role="alert">{deleteError}</p>}
          <ul className="runs">
            {activities.map((a) =>
              a.id === editingId ? (
                <li key={a.id} className="card card-edit">
                  <ActivityForm activity={a} onSaved={handleSaved} onCancel={() => setEditingId(null)} />
                </li>
              ) : (
                <li key={a.id} className="card">
                  <strong>{a.activity_date}</strong>
                  <span>{a.distance_km} km</span>
                  <span>{formatDuration(a.duration_seconds)}</span>
                  <span>{a.pace_label} /km</span>
                  {a.effort && <span>Effort {a.effort}/10</span>}
                  <span className="card-actions">
                    <button type="button" className="card-action"
                      onClick={() => setEditingId(a.id)}
                      aria-label={`Edit run on ${a.activity_date}`}>Edit</button>
                    <button type="button" className="card-action"
                      onClick={() => setToDelete(a)}
                      aria-label={`Delete run on ${a.activity_date}`}>Delete</button>
                  </span>
                  {a.notes && <small className="card-note">{a.notes}</small>}
                </li>
              ),
            )}
          </ul>
        </>
      )}

      <ConfirmDialog
        open={toDelete !== null}
        danger
        title="Delete this run?"
        message={toDelete ? `${toDelete.activity_date}, ${toDelete.distance_km} km. This cannot be undone.` : ''}
        confirmLabel="Delete"
        busy={busy}
        onConfirm={confirmDelete}
        onCancel={() => setToDelete(null)}
      />
    </>
  )
}
