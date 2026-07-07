import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
  const [showForgot, setShowForgot] = useState(false);

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
            <span className="text-white font-bold text-lg">SO</span>
          </div>
          <h1 className="text-2xl font-bold text-slate-900">ServiceOP</h1>
          <p className="text-slate-500 text-sm mt-1">Home Service Operating Platform</p>
        </div>

        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
          {showForgot ? (
            <div className="text-center">
              <p className="text-sm text-slate-600 mb-4">
                Please contact your PM or admin to reset your password.
              </p>
              <button
                type="button"
                onClick={() => setShowForgot(false)}
                className="text-sm text-blue-600 hover:underline"
              >
                Back to sign in
              </button>
            </div>
          ) : (
            <>
              {error && (
                <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">{error}</div>
              )}

              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
                  <input
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                    placeholder="Enter your email"
                    className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Password</label>
                  <input
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    placeholder="Enter your password"
                    className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <button
                  type="submit"
                  disabled={loading}
                  className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white font-semibold rounded-lg py-2.5 text-sm transition-colors"
                >
                  {loading ? 'Signing in...' : 'Sign In'}
                </button>
              </form>

              <p className="text-center mt-4">
                <button
                  type="button"
                  onClick={() => setShowForgot(true)}
                  className="text-sm text-blue-600 hover:underline"
                >
                  Forgot Password?
                </button>
              </p>
            </>
          )}
        </div>

        <p className="text-center text-xs text-slate-400 mt-4">
          Having trouble logging in? Contact your project manager or ServiceOP support.
        </p>
      </div>
    </div>
  );
}
