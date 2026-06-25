import { useCallback, useEffect, useState } from 'react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const STATUS_TABS = [
  { key: '', label: 'All' },
  { key: 'not_ready', label: 'Not Ready' },
  { key: 'ready_for_payout', label: 'Ready' },
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'paid', label: 'Paid' },
];

function PayoutEditModal({ payout, onClose, onSuccess }) {
  const [form, setForm] = useState({
    payout_method: payout.payout_method || '',
    payout_due_date: payout.payout_due_date?.split?.('T')?.[0] || payout.payout_due_date || '',
    admin_notes: payout.admin_notes || '',
  });
  const [loading, setLoading] = useState(false);

  const handleSave = async () => {
    setLoading(true);
    try {
      await api.put(`/payouts/${payout.id}`, form);
      await showSuccess('Payout details updated.');
      onSuccess();
      onClose();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to update payout.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl p-6 w-full max-w-md space-y-3">
        <h3 className="font-semibold text-slate-800">Edit Payout #{payout.id}</h3>
        <div>
          <label className="text-xs text-slate-500 block mb-1">Payout Method</label>
          <select value={form.payout_method} onChange={(e) => setForm({ ...form, payout_method: e.target.value })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <option value="">Select method</option>
            <option value="e_transfer">E-Transfer</option>
            <option value="cheque">Cheque</option>
            <option value="direct_deposit">Direct Deposit</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div>
          <label className="text-xs text-slate-500 block mb-1">Due Date</label>
          <input type="date" value={form.payout_due_date} onChange={(e) => setForm({ ...form, payout_due_date: e.target.value })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="text-xs text-slate-500 block mb-1">Admin Notes</label>
          <textarea value={form.admin_notes} onChange={(e) => setForm({ ...form, admin_notes: e.target.value })}
            rows={3} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div className="flex gap-2 pt-2">
          <button type="button" onClick={onClose} className="flex-1 border border-slate-300 rounded-lg py-2 text-sm">Cancel</button>
          <button type="button" onClick={handleSave} disabled={loading}
            className="flex-1 bg-blue-600 text-white rounded-lg py-2 text-sm font-medium disabled:opacity-60">
            {loading ? 'Saving...' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}

function eligibilityLabel(status) {
  return (status || '').replace(/_/g, ' ');
}

export default function Payouts() {
  const { user } = useAuth();
  const isOwner = user?.role === 'owner';
  const [payouts, setPayouts] = useState([]);
  const [statusFilter, setStatusFilter] = useState('');
  const [editPayout, setEditPayout] = useState(null);

  const refreshPayouts = useCallback(() => {
    const params = statusFilter ? { status: statusFilter } : {};
    api.get('/payouts', { params })
      .then(({ data }) => setPayouts(data.data || data))
      .catch(() => setPayouts([]));
  }, [statusFilter]);

  useEffect(() => { refreshPayouts(); }, [refreshPayouts]);

  const handleApprove = async (payoutId) => {
    const ok = await confirmAction({
      title: 'Approve payout?',
      text: 'Approve this contractor payout for payment?',
      confirmText: 'Yes, approve',
    });
    if (!ok) return;

    try {
      await api.put(`/payouts/${payoutId}/approve`);
      await showSuccess('Payout approved.');
      refreshPayouts();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to approve payout.');
    }
  };

  const handleMarkPaid = async (payoutId) => {
    const ok = await confirmAction({
      title: 'Mark payout as paid?',
      text: 'Confirm that this payout has been sent to the contractor.',
      confirmText: 'Yes, mark paid',
    });
    if (!ok) return;

    try {
      await api.put(`/payouts/${payoutId}/mark-paid`);
      await showSuccess('Payout marked as paid.');
      refreshPayouts();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to mark payout as paid.');
    }
  };

  return (
    <div>
      <PageHeader title="Payouts" />

      <div className="flex flex-wrap gap-2 mb-4">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab.key || 'all'}
            type="button"
            onClick={() => setStatusFilter(tab.key)}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
              statusFilter === tab.key ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-100'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <div className="md:hidden space-y-3 mb-4">
        {payouts.length === 0 ? (
          <p className="text-center text-slate-500 py-8">No payouts found.</p>
        ) : payouts.map((p) => (
          <div key={p.id} className="bg-white border border-slate-200 rounded-xl p-4">
            <div className="flex justify-between items-start mb-2">
              <span className="font-medium text-slate-800">Payout #{p.id}</span>
              <StatusBadge status={p.status} />
            </div>
            <p className="text-sm text-slate-500">Job #{p.job_id} — {p.job?.address || '—'}</p>
            {user?.role !== 'contractor' && (
              <p className="text-sm text-slate-500">{p.contractor?.name || '—'}</p>
            )}
            <p className="text-sm font-medium text-slate-800 mt-1">${Number(p.payout_amount || 0).toFixed(2)}</p>
            {isOwner && p.status === 'pending' && (
              <button type="button" onClick={() => handleApprove(p.id)}
                className="mt-3 w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2.5 text-sm font-medium">
                Approve
              </button>
            )}
            {isOwner && p.status === 'ready_for_payout' && (
              <button type="button" onClick={() => handleApprove(p.id)}
                className="mt-3 w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2.5 text-sm font-medium">
                Approve for Payment
              </button>
            )}
            {isOwner && (
              <button type="button" onClick={() => setEditPayout(p)}
                className="mt-2 w-full border border-slate-300 text-slate-700 rounded-lg py-2 text-sm font-medium">
                Edit Details
              </button>
            )}
            {isOwner && (p.status === 'approved' || p.status === 'ready_for_payout') && (
              <button type="button" onClick={() => handleMarkPaid(p.id)}
                className="mt-3 w-full bg-green-600 hover:bg-green-700 text-white rounded-lg py-2.5 text-sm font-medium">
                Mark as Paid
              </button>
            )}
          </div>
        ))}
      </div>

      <div className="hidden md:block overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
        <table className="w-full min-w-[640px] divide-y divide-[#E2E8F0] text-sm">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Payout #</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Job</th>
              {user?.role !== 'contractor' && (
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Contractor</th>
              )}
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Amount</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Status</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Eligibility</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Paid Date</th>
              {isOwner && <th className="text-left px-4 py-3 font-medium text-[#64748B]">Actions</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {payouts.length === 0 ? (
              <tr><td colSpan={isOwner ? 8 : 7} className="px-4 py-8 text-center text-slate-500">No payouts found.</td></tr>
            ) : payouts.map((p) => (
              <tr key={p.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium">#{p.id}</td>
                <td className="px-4 py-3">
                  <div className="font-medium">#{p.job_id}</div>
                  <div className="text-xs text-slate-500 truncate max-w-[180px]">{p.job?.address || '—'}</div>
                </td>
                {user?.role !== 'contractor' && (
                  <td className="px-4 py-3">{p.contractor?.name || '—'}</td>
                )}
                <td className="px-4 py-3">${Number(p.payout_amount || 0).toFixed(2)}</td>
                <td className="px-4 py-3"><StatusBadge status={p.status} /></td>
                <td className="px-4 py-3 hidden md:table-cell capitalize text-xs text-slate-500">{eligibilityLabel(p.eligibility_status)}</td>
                <td className="px-4 py-3 hidden md:table-cell">{p.paid_date?.split?.('T')?.[0] || p.paid_date || '—'}</td>
                {isOwner && (
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-2">
                      {(p.status === 'pending' || p.status === 'ready_for_payout') && (
                        <button type="button" onClick={() => handleApprove(p.id)}
                          className="text-xs px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                          {p.status === 'ready_for_payout' ? 'Approve' : 'Approve'}
                        </button>
                      )}
                      {(p.status === 'approved' || p.status === 'ready_for_payout') && p.status !== 'paid' && (
                        <button type="button" onClick={() => handleMarkPaid(p.id)}
                          className="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium">
                          Mark as Paid
                        </button>
                      )}
                      <button type="button" onClick={() => setEditPayout(p)}
                        className="text-xs px-3 py-1.5 border border-slate-300 text-slate-700 rounded-lg font-medium">
                        Edit
                      </button>
                    </div>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editPayout && (
        <PayoutEditModal payout={editPayout} onClose={() => setEditPayout(null)} onSuccess={refreshPayouts} />
      )}
    </div>
  );
}
