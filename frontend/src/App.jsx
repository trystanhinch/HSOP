import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { ToastProvider } from './context/ToastContext';
import AppLayout from './components/AppLayout';
import ProtectedRoute from './components/ProtectedRoute';
import RoleGuard from './components/RoleGuard';
import DashboardRedirect from './components/DashboardRedirect';
import Login from './pages/Login';
import Register from './pages/Register';
import AdminDashboard from './pages/AdminDashboard';
import PMDashboard from './pages/PMDashboard';
import ContractorDashboard from './pages/ContractorDashboard';
import CustomerDashboard from './pages/CustomerDashboard';
import Leads from './pages/Leads';
import LeadDetail from './pages/LeadDetail';
import Jobs from './pages/Jobs';
import JobDetail from './pages/JobDetail';
import Contractors from './pages/Contractors';
import ContractorProfile from './pages/ContractorProfile';
import Customers from './pages/Customers';
import CustomerDetail from './pages/CustomerDetail';
import Quotes from './pages/Quotes';
import Schedule from './pages/Schedule';
import Messages from './pages/Messages';
import Invoices from './pages/Invoices';
import Payouts from './pages/Payouts';
import Reports from './pages/Reports';
import Settings from './pages/Settings';
import Unauthorized from './pages/Unauthorized';
import CustomerQuoteView from './pages/CustomerQuoteView';

export default function App() {
  return (
    <AuthProvider>
      <ToastProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route path="/quote/view/:token" element={<CustomerQuoteView />} />

          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route path="/" element={<DashboardRedirect />} />
              <Route path="/dashboard" element={<DashboardRedirect />} />

              <Route path="/dashboard/admin" element={<RoleGuard roles={['owner']}><AdminDashboard /></RoleGuard>} />
              <Route path="/dashboard/pm" element={<RoleGuard roles={['pm']}><PMDashboard /></RoleGuard>} />
              <Route path="/dashboard/contractor" element={<RoleGuard roles={['contractor']}><ContractorDashboard /></RoleGuard>} />
              <Route path="/dashboard/customer" element={<RoleGuard roles={['customer']}><CustomerDashboard /></RoleGuard>} />

              <Route path="/leads" element={<RoleGuard roles={['owner', 'pm']}><Leads /></RoleGuard>} />
              <Route path="/leads/:id" element={<RoleGuard roles={['owner', 'pm']}><LeadDetail /></RoleGuard>} />
              <Route path="/jobs" element={<RoleGuard roles={['owner', 'pm', 'contractor']}><Jobs /></RoleGuard>} />
              <Route path="/jobs/:id" element={<RoleGuard roles={['owner', 'pm', 'contractor', 'customer']}><JobDetail /></RoleGuard>} />
              <Route path="/contractors" element={<RoleGuard roles={['owner', 'pm']}><Contractors /></RoleGuard>} />
              <Route path="/contractors/:id" element={<RoleGuard roles={['owner', 'pm', 'contractor']}><ContractorProfile /></RoleGuard>} />
              <Route path="/customers" element={<RoleGuard roles={['owner', 'pm']}><Customers /></RoleGuard>} />
              <Route path="/customers/:id" element={<RoleGuard roles={['owner', 'pm']}><CustomerDetail /></RoleGuard>} />
              <Route path="/quotes" element={<RoleGuard roles={['owner', 'pm']}><Quotes /></RoleGuard>} />
              <Route path="/schedule" element={<RoleGuard roles={['owner', 'pm', 'contractor']}><Schedule /></RoleGuard>} />
              <Route path="/messages" element={<RoleGuard roles={['owner', 'pm', 'contractor', 'customer']}><Messages /></RoleGuard>} />
              <Route path="/invoices" element={<RoleGuard roles={['owner', 'pm']}><Invoices /></RoleGuard>} />
              <Route path="/payouts" element={<RoleGuard roles={['owner', 'pm', 'contractor']}><Payouts /></RoleGuard>} />
              <Route path="/reports" element={<RoleGuard roles={['owner']}><Reports /></RoleGuard>} />
              <Route path="/settings" element={<RoleGuard roles={['owner']}><Settings /></RoleGuard>} />
              <Route path="/unauthorized" element={<Unauthorized />} />
            </Route>
          </Route>
        </Routes>
      </BrowserRouter>
      </ToastProvider>
    </AuthProvider>
  );
}
