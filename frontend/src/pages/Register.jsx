import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import api from '../api/axios';
import { confirmAction, showError, showSuccess } from '../utils/swal';

export default function Register() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [form, setForm] = useState({
    name: '', email: '', password: '', password_confirmation: '', phone: '', address: '',
  });
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);

  const handleChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });

  const handleSubmit = async (e) => {
    e.preventDefault();
    const ok = await confirmAction({
      title: 'Create account?',
      text: `Register a new customer account for ${form.name}?`,
      confirmText: 'Yes, create account',
    });
    if (!ok) return;

    setErrors({});
    setLoading(true);
    try {
      const res = await api.post('/register', form);
      login(res.data.user, res.data.token);
      await showSuccess('Account created successfully!');
      navigate('/dashboard/customer');
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors || {});
        await showError('Please fix the errors in the form.');
      } else {
        setErrors({ general: 'Registration failed. Please try again.' });
        await showError('Registration failed. Please try again.');
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
          <p className="text-slate-500 text-sm mt-1">Create your customer account</p>
        </div>

        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
          <h2 className="text-lg font-semibold text-slate-800 mb-6">Sign Up</h2>

          {errors.general && (
            <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">{errors.general}</div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Full Name *</label>
              <input name="name" value={form.name} onChange={handleChange} placeholder="Sarah Johnson" required
                className={`w-full border rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${errors.name ? 'border-red-400' : 'border-slate-300'}`} />
              {errors.name && <p className="text-red-500 text-xs mt-1">{errors.name[0]}</p>}
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Email Address *</label>
              <input name="email" type="email" value={form.email} onChange={handleChange} placeholder="sarah@example.com" required
                className={`w-full border rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${errors.email ? 'border-red-400' : 'border-slate-300'}`} />
              {errors.email && <p className="text-red-500 text-xs mt-1">{errors.email[0]}</p>}
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Phone <span className="text-slate-400">(optional)</span></label>
              <input name="phone" value={form.phone} onChange={handleChange} placeholder="604-555-0100"
                className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Address <span className="text-slate-400">(optional)</span></label>
              <input name="address" value={form.address} onChange={handleChange} placeholder="123 Main St, Vancouver, BC"
                className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Password *</label>
              <input name="password" type="password" value={form.password} onChange={handleChange} placeholder="Minimum 8 characters" required
                className={`w-full border rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${errors.password ? 'border-red-400' : 'border-slate-300'}`} />
              {errors.password && <p className="text-red-500 text-xs mt-1">{errors.password[0]}</p>}
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Confirm Password *</label>
              <input name="password_confirmation" type="password" value={form.password_confirmation} onChange={handleChange} placeholder="Repeat your password" required
                className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <button type="submit" disabled={loading}
              className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white font-semibold rounded-lg py-2.5 text-sm transition-colors mt-2">
              {loading ? 'Creating account...' : 'Create Account'}
            </button>
          </form>

          <p className="text-xs text-slate-400 text-center mt-4">
            Customer accounts only. PM and Contractor accounts are created by Admin.
          </p>
        </div>

        <p className="text-center text-sm text-slate-500 mt-4">
          Already have an account?{' '}
          <Link to="/login" className="text-blue-600 font-medium hover:underline">Sign in</Link>
        </p>
      </div>
    </div>
  );
}
