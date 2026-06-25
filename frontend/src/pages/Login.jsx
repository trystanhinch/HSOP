import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import api from '../api/axios';
import { getRoleDashboard } from '../utils/getRoleDashboard';
import { showError, showSuccess } from '../utils/swal';

export default function Login() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const roleAccounts = [
    { label: 'Admin / Owner', email: 'admin@hsop.com', color: 'purple', icon: '👑' },
    { label: 'Project Manager', email: 'pm@hsop.com', color: 'blue', icon: '📋' },
    { label: 'Contractor', email: 'contractor@hsop.com', color: 'orange', icon: '🔨' },
    { label: 'Customer', email: 'sarah@example.com', color: 'green', icon: '👤' },
  ];

  const colorMap = {
    purple: 'bg-purple-50 border-purple-200 text-purple-700 hover:bg-purple-100',
    blue: 'bg-blue-50 border-blue-200 text-blue-700 hover:bg-blue-100',
    orange: 'bg-orange-50 border-orange-200 text-orange-700 hover:bg-orange-100',
    green: 'bg-green-50 border-green-200 text-green-700 hover:bg-green-100',
  };

  const fillDemo = (demoEmail) => {
    setEmail(demoEmail);
    setPassword('password');
    setError('');
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const res = await api.post('/login', { email, password });
      login(res.data.user, res.data.token);
      await showSuccess(`Welcome back, ${res.data.user.name}!`);
      navigate(getRoleDashboard(res.data.user.role));
    } catch (err) {
      if (!err.response) {
        setError('Cannot reach the API server. Make sure the backend is running.');
        await showError('Cannot reach the API server. Make sure the backend is running.');
      } else {
        setError('Invalid email or password. Please try again.');
        await showError('Invalid email or password. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4">
      <div className="w-full max-w-md">
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-12 h-12 bg-blue-600 rounded-xl mb-3">
            <span className="text-white font-bold text-lg">JC</span>
          </div>
          <h1 className="text-2xl font-bold text-slate-900">Job Command</h1>
          <p className="text-slate-500 text-sm mt-1">HSOP Home Service Operating Platform</p>
        </div>

        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
          <div className="mb-6">
            <p className="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3">
              Demo — Select a role to auto-fill:
            </p>
            <div className="grid grid-cols-2 gap-2">
              {roleAccounts.map(({ label, email: demoEmail, color, icon }) => (
                <button
                  key={demoEmail}
                  type="button"
                  onClick={() => fillDemo(demoEmail)}
                  className={`text-xs border rounded-lg px-3 py-2 font-medium transition-colors text-left ${colorMap[color]}`}
                >
                  <span className="mr-1">{icon}</span> {label}
                </button>
              ))}
            </div>
            <p className="text-xs text-slate-400 text-center mt-2">All demo passwords: <strong>password</strong></p>
          </div>

          <div className="border-t border-slate-100 my-4" />

          {error && (
            <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">{error}</div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required placeholder="Enter your email"
                className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Password</label>
              <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required placeholder="Enter your password"
                className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <button type="submit" disabled={loading}
              className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white font-semibold rounded-lg py-2.5 text-sm transition-colors">
              {loading ? 'Signing in...' : 'Sign In'}
            </button>
          </form>

          <p className="text-center text-sm text-slate-500 mt-4">
            New customer?{' '}
            <Link to="/register" className="text-blue-600 font-medium hover:underline">Create an account</Link>
          </p>
        </div>
      </div>
    </div>
  );
}
