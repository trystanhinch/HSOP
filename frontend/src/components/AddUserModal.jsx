import { useState } from 'react';
import api from '../api/axios';

export default function AddUserModal({ onClose, onSuccess }) {
  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    role: 'contractor',
    password: '',
  });
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  const handleSubmit = async () => {
    setSubmitting(true);
    setError('');
    try {
      const payload = { ...form };
      if (!payload.password) delete payload.password;
      if (!payload.phone) delete payload.phone;
      const res = await api.post('/admin/users', payload);
      setResult(res.data);
      onSuccess();
    } catch (err) {
      setError(
        err.response?.data?.message
          || Object.values(err.response?.data?.errors || {})[0]?.[0]
          || 'Failed to create account'
      );
    } finally {
      setSubmitting(false);
    }
  };

  if (result) {
    return (
      <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div className="bg-white rounded-2xl p-6 max-w-sm w-full">
          <div className="text-center mb-4">
            <div className="text-4xl mb-2">✅</div>
            <h3 className="font-semibold text-slate-800">Account Created</h3>
          </div>
          <div className="bg-slate-50 rounded-xl p-4 space-y-2 text-sm mb-4">
            <div className="flex justify-between gap-4">
              <span className="text-slate-500">Name</span>
              <span className="font-medium text-right">{result.user.name}</span>
            </div>
            <div className="flex justify-between gap-4">
              <span className="text-slate-500">Email</span>
              <span className="font-medium text-right break-all">{result.user.email}</span>
            </div>
            <div className="flex justify-between gap-4">
              <span className="text-slate-500">Password</span>
              <span className="font-mono font-bold text-blue-700">{result.password}</span>
            </div>
            <div className="flex justify-between gap-4">
              <span className="text-slate-500">Role</span>
              <span className="font-medium capitalize">{result.user.role}</span>
            </div>
          </div>
          <p className="text-xs text-slate-400 text-center mb-4">
            Share these credentials with the user.
            {result.user.phone && ' They have also received an SMS with their login details.'}
          </p>
          <button
            type="button"
            onClick={onClose}
            className="w-full bg-slate-800 text-white rounded-lg py-2.5 text-sm font-medium"
          >
            Done
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl w-full max-w-md">
        <div className="p-5 border-b border-slate-100">
          <h3 className="font-semibold text-slate-800">Add New User</h3>
          <p className="text-xs text-slate-400 mt-0.5">Create a PM or Contractor account</p>
        </div>
        <div className="p-5 space-y-4">
          {error && (
            <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">
              {error}
            </div>
          )}
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Role *</label>
            <div className="flex gap-2">
              <button
                type="button"
                onClick={() => setForm({ ...form, role: 'contractor' })}
                className={`flex-1 py-2 rounded-lg text-sm font-medium border ${
                  form.role === 'contractor'
                    ? 'bg-orange-600 text-white border-orange-600'
                    : 'bg-white text-slate-600 border-slate-300'
                }`}
              >
                Contractor
              </button>
              <button
                type="button"
                onClick={() => setForm({ ...form, role: 'pm' })}
                className={`flex-1 py-2 rounded-lg text-sm font-medium border ${
                  form.role === 'pm'
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'bg-white text-slate-600 border-slate-300'
                }`}
              >
                Project Manager
              </button>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Full Name *</label>
            <input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="e.g. John Smith"
              className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Email Address *</label>
            <input
              type="email"
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
              placeholder="john@example.com"
              className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Phone Number</label>
            <input
              value={form.phone}
              onChange={(e) => setForm({ ...form, phone: e.target.value })}
              placeholder="6045551234"
              className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <p className="text-xs text-slate-400 mt-1">
              If provided, login credentials will be sent by SMS automatically.
            </p>
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">
              Password <span className="text-slate-400">(leave blank to auto-generate)</span>
            </label>
            <input
              type="text"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              placeholder="Auto-generated if empty"
              className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>
        <div className="p-5 border-t border-slate-100 flex gap-3">
          <button
            type="button"
            onClick={onClose}
            className="flex-1 border border-slate-300 text-slate-600 rounded-lg py-2.5 text-sm font-medium"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={submitting || !form.name || !form.email}
            className="flex-1 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-medium"
          >
            {submitting ? 'Creating...' : 'Create Account'}
          </button>
        </div>
      </div>
    </div>
  );
}
