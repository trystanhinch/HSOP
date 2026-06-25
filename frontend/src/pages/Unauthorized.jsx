import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { getRoleDashboard } from '../utils/getRoleDashboard';

export default function Unauthorized() {
  const { user } = useAuth();
  const dashboard = user ? getRoleDashboard(user.role) : '/login';

  return (
    <div className="min-h-[60vh] bg-slate-50 flex items-center justify-center p-4">
      <div className="text-center">
        <div className="text-6xl mb-4">🔒</div>
        <h1 className="text-2xl font-bold text-slate-800 mb-2">Access Denied</h1>
        <p className="text-slate-500 mb-6">You don&apos;t have permission to view this page.</p>
        <Link to={dashboard} className="inline-block bg-blue-600 text-white px-6 py-2.5 rounded-lg font-medium hover:bg-blue-700">
          Go to My Dashboard
        </Link>
      </div>
    </div>
  );
}
