import { createContext, useContext, useEffect, useRef, useState } from 'react'
import { apiGet, apiPost, setAccessToken, setRefreshHandler } from '../api.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [status, setStatus] = useState('loading') // loading | in | out
  const started = useRef(false)
  const refreshing = useRef(null)

  function startSession(data) {
    setAccessToken(data.access_token)
    setUser(data.user)
    setStatus('in')
  }

  function endSession() {
    setAccessToken(null)
    setUser(null)
    setStatus('out')
  }

  async function refreshUser() {
    const body = await apiGet('/auth/me')
    if (body?.data) setUser(body.data)
    return body?.data ?? null
  }

  // Used by api.js when a request gets 401. One shared promise, so parallel
  // 401s trigger only ONE refresh (the refresh token is single-use).
  useEffect(() => {
    setRefreshHandler(() => {
      if (!refreshing.current) {
        refreshing.current = apiPost('/auth/refresh')
          .then((body) => { startSession(body.data); return true })
          .catch(() => { endSession(); return false })
          .finally(() => { refreshing.current = null })
      }
      return refreshing.current
    })
  }, [])

  useEffect(() => {
    if (started.current) return // StrictMode runs effects twice in dev
    started.current = true
    apiPost('/auth/refresh')
      .then((body) => {
        startSession(body.data)
        return apiGet('/auth/me').then((me) => {
          if (me?.data) setUser(me.data)
        }).catch(() => {})
      })
      .catch(() => setStatus('out'))
  }, [])

  async function login(email, password) {
    const body = await apiPost('/auth/login', { email, password })
    startSession(body.data)
  }

  async function logout() {
    try {
      await apiPost('/auth/logout')
    } finally {
      endSession()
    }
  }

  return (
    <AuthContext.Provider value={{ user, status, login, logout, refreshUser, clearSession: endSession }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  return useContext(AuthContext)
}
