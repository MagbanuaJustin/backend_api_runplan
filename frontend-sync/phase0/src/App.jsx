import { Routes, Route, Navigate } from 'react-router-dom'
import Layout from './components/Layout.jsx'
import ProtectedRoute from './auth/ProtectedRoute.jsx'
import Login from './pages/Login.jsx'
import Register from './pages/Register.jsx'
import ForgotPassword from './pages/ForgotPassword.jsx'
import ResetPassword from './pages/ResetPassword.jsx'
import Dashboard from './pages/Dashboard.jsx'
import Activities from './pages/Activities.jsx'
import ComingSoon from './pages/ComingSoon.jsx'
import Profile from './pages/Profile.jsx'
import Plan from './pages/Plan.jsx'
import Calendar from './pages/Calendar.jsx'
import Account from './pages/Account.jsx'
import Status from './pages/Status.jsx'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/dashboard" replace />} />
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<Layout />}>
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/calendar" element={<Calendar />} />
          <Route path="/activities" element={<Activities />} />
          <Route path="/plan" element={<Plan />} />
          <Route path="/profile" element={<Profile />} />
          <Route path="/account" element={<Account />} />
          <Route path="/status" element={<Status />} />
        </Route>
      </Route>
      <Route path="*" element={<ComingSoon title="Page not found" />} />
    </Routes>
  )
}
