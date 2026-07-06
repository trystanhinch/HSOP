import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Edit2 } from 'lucide-react';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import ContractorDocuments from '../components/ContractorDocuments';
import { getRoleDashboard } from '../utils/getRoleDashboard';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const companyFields = [
  { label: 'Full Name (Login)', field: 'name', placeholder: 'John Smith' },
  { label: 'Legal Name', field: 'legal_name', placeholder: 'Smith Drywall Ltd' },
  { label: 'Operating Name', field: 'operating_name', placeholder: 'Smith Drywall' },
  { label: 'Contact Name', field: 'contact_name', placeholder: 'John Smith' },
  { label: 'Phone', field: 'phone', placeholder: '6045551234' },
  { label: 'Email (Login)', field: 'email', placeholder: 'john@example.com' },
];

export default function ContractorProfile() {
  const { id } = useParams();
  const { user } = useAuth();
  const [contractor, setContractor] = useState(null);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({});
  const [saving, setSaving] = useState(false);
  const [resettingPassword, setResettingPassword] = useState(false);
  const [newPassword, setNewPassword] = useState(null);

  const isAdmin = user?.role === 'owner';
  const isAdminOrPm = ['owner', 'pm'].includes(user?.role);

  const loadContractor = useCallback(() => {
    api.get(`/contractors/${id}`).then(({ data }) => setContractor(data)).catch(() => setContractor(null));
  }, [id]);

  useEffect(() => {
    loadContractor();
  }, [loadContractor]);

  useEffect(() => {
    if (contractor) {
      setForm({
        name: contractor.user?.name || '',
        legal_name: contractor.legal_name || '',
        operating_name: contractor.operating_name || '',
        contact_name: contractor.contact_name || '',
        phone: contractor.user?.phone || contractor.phone || '',
        email: contractor.user?.email || contractor.email || '',
        admin_notes: contractor.admin_notes || '',
      });
    }
  }, [contractor]);

  const saveProfile = async () => {
    setSaving(true);
    try {
      const { data } = await api.put(`/contractors/${contractor.id}`, form);
      setContractor(data.contractor || data);
      setEditing(false);
      await showSuccess('Profile updated successfully');
      loadContractor();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save');
    } finally {
      setSaving(false);
    }
  };

  const resetPassword = async () => {
    const ok = await confirmAction({
      title: 'Reset password?',
      text: "This contractor's password will be reset and sent by SMS if a phone number is on file.",
      confirmText: 'Yes, reset password',
      icon: 'warning',
    });
    if (!ok) return;

    setResettingPassword(true);
    setNewPassword(null);
    try {
      const res = await api.post(`/admin/users/${contractor.user.id}/reset-password`);
      setNewPassword(res.data.password);
      await showSuccess('Password reset successfully');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to reset password');
    } finally {
      setResettingPassword(false);
    }
  };

  if (!contractor) return <div className="text-center py-12 text-slate-500">Loading...</div>;

  const backLink = user?.role === 'contractor' ? getRoleDashboard('contractor') : '/contractors';
  const backLabel = user?.role === 'contractor' ? 'Back to Dashboard' : 'Back to Contractors';

  return (
    <div>
      <Link to={backLink} className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-800 mb-6">
        <ArrowLeft size={16} /> {backLabel}
      </Link>

      <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 className="text-xl font-bold text-slate-800">Contractor Profile</h1>
        {isAdminOrPm && !editing && (
          <button
            type="button"
            onClick={() => setEditing(true)}
            className="flex items-center gap-2 bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-blue-700"
          >
            <Edit2 className="w-4 h-4" />
            Edit Profile
          </button>
        )}
        {editing && (
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => {
                setEditing(false);
                setForm({
                  name: contractor.user?.name || '',
                  legal_name: contractor.legal_name || '',
                  operating_name: contractor.operating_name || '',
                  contact_name: contractor.contact_name || '',
                  phone: contractor.user?.phone || contractor.phone || '',
                  email: contractor.user?.email || contractor.email || '',
                  admin_notes: contractor.admin_notes || '',
                });
              }}
              className="border border-slate-300 text-slate-600 rounded-lg px-4 py-2 text-sm"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={saveProfile}
              disabled={saving}
              className="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50 hover:bg-blue-700"
            >
              {saving ? 'Saving...' : 'Save Changes'}
            </button>
          </div>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="space-y-6">
          <div className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-4">Company Info</h2>
            <div className="space-y-3">
              {companyFields.map(({ label, field, placeholder }) => (
                <div
                  key={field}
                  className="flex justify-between items-center py-2 border-b border-slate-100 last:border-0"
                >
                  <span className="text-sm text-slate-500 w-32 shrink-0">{label}</span>
                  {editing ? (
                    <input
                      value={form[field] || ''}
                      onChange={(e) => setForm({ ...form, [field]: e.target.value })}
                      placeholder={placeholder}
                      className="flex-1 ml-4 border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  ) : (
                    <span className="text-sm font-medium text-slate-800 text-right break-all">
                      {form[field] || '—'}
                    </span>
                  )}
                </div>
              ))}
              {isAdmin && (
                <div className="pt-2">
                  <span className="text-sm text-slate-500 block mb-1">Admin Notes</span>
                  {editing ? (
                    <textarea
                      value={form.admin_notes || ''}
                      onChange={(e) => setForm({ ...form, admin_notes: e.target.value })}
                      rows={3}
                      className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  ) : (
                    <p className="text-sm text-slate-800 whitespace-pre-wrap">{form.admin_notes || '—'}</p>
                  )}
                </div>
              )}
            </div>
          </div>

          {isAdmin && contractor?.user && (
            <div className="bg-white rounded-xl border border-slate-200 p-5">
              <h2 className="font-semibold text-slate-800 mb-4">Login Credentials</h2>
              <div className="space-y-3">
                <div className="flex justify-between items-center py-2 border-b border-slate-100">
                  <span className="text-sm text-slate-500">Login Email</span>
                  <span className="text-sm font-medium text-slate-800 break-all">{contractor.user.email}</span>
                </div>
                <div className="flex justify-between items-center py-2 border-b border-slate-100">
                  <span className="text-sm text-slate-500">Phone</span>
                  <span className="text-sm font-medium text-slate-800">{contractor.user.phone || '—'}</span>
                </div>
                <div className="flex justify-between items-center py-2 border-b border-slate-100">
                  <span className="text-sm text-slate-500">Account Status</span>
                  <span className={`text-xs px-2 py-1 rounded-full font-medium ${
                    contractor.user.status === 'active'
                      ? 'bg-green-100 text-green-700'
                      : 'bg-red-100 text-red-700'
                  }`}
                  >
                    {contractor.user.status}
                  </span>
                </div>
                <div className="flex justify-between items-center py-2 border-b border-slate-100">
                  <span className="text-sm text-slate-500">Member Since</span>
                  <span className="text-sm text-slate-600">
                    {contractor.user.created_at
                      ? new Date(contractor.user.created_at).toLocaleDateString()
                      : '—'}
                  </span>
                </div>
              </div>

              <div className="mt-4 pt-4 border-t border-slate-100">
                <p className="text-xs text-slate-400 mb-3">
                  Reset the contractor&apos;s password. They will receive the new password by SMS.
                </p>
                <button
                  type="button"
                  onClick={resetPassword}
                  disabled={resettingPassword}
                  className="w-full border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg py-2 text-sm font-medium disabled:opacity-50"
                >
                  {resettingPassword ? 'Resetting...' : 'Reset Password'}
                </button>
                {newPassword && (
                  <div className="mt-3 bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p className="text-xs text-blue-600 mb-1">New password generated:</p>
                    <p className="font-mono font-bold text-blue-800 text-lg">{newPassword}</p>
                    <p className="text-xs text-blue-500 mt-1">
                      {contractor.user.phone
                        ? 'SMS sent to contractor with new password.'
                        : 'No phone on file — share this password manually.'}
                    </p>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>

        <div className="space-y-6">
          <div className="bg-white rounded-xl border border-slate-200 p-6">
            <h3 className="text-sm font-semibold mb-4">Services & Cities</h3>
            <div className="flex flex-wrap gap-2 mb-3">
              {(contractor.services || []).map((s) => (
                <span key={s} className="px-2 py-1 bg-slate-100 text-slate-600 rounded text-xs">{s}</span>
              ))}
              {!(contractor.services || []).length && <span className="text-sm text-slate-400">—</span>}
            </div>
            <div className="flex flex-wrap gap-2">
              {(contractor.cities || []).map((c) => (
                <span key={c} className="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs">{c}</span>
              ))}
              {!(contractor.cities || []).length && <span className="text-sm text-slate-400">—</span>}
            </div>
          </div>

          <ContractorDocuments contractorId={contractor.id} />
        </div>
      </div>
    </div>
  );
}
