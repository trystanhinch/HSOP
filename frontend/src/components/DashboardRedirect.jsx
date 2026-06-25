import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { getRoleDashboard } from '../utils/getRoleDashboard';

export default function DashboardRedirect() {
  const { user } = useAuth();
  if (!user) return <Navigate to="/login" replace />;
  return <Navigate to={getRoleDashboard(user.role)} replace />;
}
