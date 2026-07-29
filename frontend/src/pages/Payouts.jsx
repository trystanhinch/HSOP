import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';
import { formatDate } from '../utils/formatDate';

const OWNER_STATUS_TABS = [
  { key: '', label: 'All' },
  { key: 'not_ready', label: 'Not Ready' },
  { key: 'ready_for_payout', label: 'Ready' },
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'paid', label: 'Paid' },
];

const PM_COMMISSION_TABS = [
  { key: '', label: 'All' },
  { key: 'projected', label: 'Projected' },
  { key: 'payable', label: 'Payable' },
  { key: 'processing', label: 'Processing' },
  { key: 'paid', label: 'Paid' },
  { key: 'held', label: 'Held' },
];

const commissionStateColor = {
  projected: 'bg-slate-100 text-slate-700 border-slate-200',
  earned: 'bg-sky-100 text-sky-800 border-sky-200',
  payable: 'bg-amber-100 text-amber-800 border-amber-200',
  processing: 'bg-indigo-100 text-indigo-800 border-indigo-200',
  paid: 'bg-green-100 text-green-700 border-green-200',
  held: 'bg-orange-100 text-orange-800 border-orange-200',
  reversed: 'bg-red-100 text-red-700 border-red-200',
};

function CommissionStateBadge({ state, label }) {
  const cls = commissionStateColor[state] || 'bg-slate-100 text-slate-700 border-slate-200';
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold border ${cls}`}>
      {label || state}
    </span>
  );
}

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

function money(n) {
  return `$${Number(n || 0).toFixed(2)}`;
}

export default function Payouts() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const isOwner = user?.role === 'owner';
  const isPm = user?.role === 'pm';
  const [payouts, setPayouts] = useState([]);
  const [groups, setGroups] = useState([]);
  const [refreshedAt, setRefreshedAt] = useState(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [editPayout, setEditPayout] = useState(null);

  const tabs = isPm ? PM_COMMISSION_TABS : OWNER_STATUS_TABS;

  const refreshPayouts = useCallback(() => {
    if (isOwner) {
      const params = { group_by_job: 1 };
      if (statusFilter) params.status = statusFilter;
      api.get('/payouts', { params })
        .then(({ data }) => {
          setGroups(data.groups || []);
          setPayouts([]);
          setRefreshedAt(data.refreshed_at || null);
        })
        .catch(() => { setGroups([]); setPayouts([]); });
      return;
    }
    const params = {};
    if (statusFilter) {
      if (isPm) params.commission_state = statusFilter;
      else params.status = statusFilter;
    }
    api.get('/payouts', { params })
      .then(({ data }) => setPayouts(data.data || data))
      .catch(() => setPayouts([]));
  }, [statusFilter, isOwner, isPm]);

  useEffect(() => { refreshPayouts(); }, [refreshPayouts]);

  const runAction = async (title, text, path) => {
    const ok = await confirmAction({ title, text, confirmText: 'Confirm' });
    if (!ok) return;
    try {
      await api.put(path);
      await showSuccess('Updated.');
      refreshPayouts();
    } catch (e) {
      await showError(e.response?.data?.message || 'Action failed.');
    }
  };

  const handleApprove = async (payoutId) => {
    await runAction('Approve payout?', 'Approve this payout for payment?', `/payouts/${payoutId}/approve`);
  };

  const handleMarkPaid = async (payoutId) => {
    await runAction('Mark payout as paid?', 'Confirm this payout has been sent.', `/payouts/${payoutId}/mark-paid`);
  };

  const handleHold = async (payoutId) => {
    await runAction('Hold payout?', 'Place this payout on hold.', `/payouts/${payoutId}/hold`);
  };

  const handleRelease = async (payoutId) => {
    await runAction('Release hold?', 'Release this payout from hold.', `/payouts/${payoutId}/release`);
  };

  const handleRetry = async (payoutId) => {
    await runAction('Retry transfer?', 'Retry Stripe/platform transfer for this payout.', `/payouts/${payoutId}/retry`);
  };

  return (
    <div>
      <PageHeader title={isPm ? 'My Commissions' : 'Payouts'} />
      {isOwner && refreshedAt && (
        <p className="text-xs text-slate-400 mb-3">Grouped by job · last refreshed {new Date(refreshedAt).toLocaleString()}</p>
      )}

      {isPm && (
        <div className="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <h2 className="text-lg font-semibold text-slate-800">My Commissions</h2>
          <p className="text-sm text-slate-600 mt-1">
            Amounts shown before completion and cleared customer payment are <strong>Projected</strong> only —
            not earned or guaranteed. Commission becomes <strong>Payable</strong> only after job completion
            acceptance and cleared payment (same gates as the financial ledger).
          </p>
        </div>
      )}

      <div className="flex flex-wrap gap-2 mb-4">
        {tabs.map((tab) => (
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

      {isOwner ? (
        <div className="space-y-4">
          {groups.length === 0 ? (
            <p className="text-center text-slate-500 py-8">No payout groups found.</p>
          ) : groups.map((g) => (
            <div key={g.job_id} className="bg-white border border-slate-200 rounded-xl overflow-hidden">
              <div className="px-4 py-3 bg-slate-50 border-b border-slate-200 flex flex-wrap justify-between gap-2">
                <div>
                  <button type="button" className="font-semibold text-slate-800 hover:underline" onClick={() => navigate(`/jobs/${g.job_id}`)}>
                    Job #{g.job_id} — {g.job_address || '—'}
                  </button>
                  <p className="text-xs text-slate-500">
                    {g.customer_name || '—'} · completion: {g.completion_state || '—'} · payment: {g.payment_state || '—'}
                    {g.reconciles === true && ' · ✓ reconciles'}
                    {g.reconciles === false && ' · ⚠ allocation mismatch'}
                  </p>
                </div>
                <div className="text-sm font-medium">{money(g.total_allocations)}</div>
              </div>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-slate-500">
                    <th className="text-left px-4 py-2 font-medium">Split</th>
                    <th className="text-left px-4 py-2 font-medium">Recipient</th>
                    <th className="text-right px-4 py-2 font-medium">Amount</th>
                    <th className="text-left px-4 py-2 font-medium">Status</th>
                    <th className="text-left px-4 py-2 font-medium">Transfer / reasons</th>
                    <th className="text-left px-4 py-2 font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {(g.allocations || []).map((a) => (
                    <tr key={a.id} className="border-b border-slate-50">
                      <td className="px-4 py-2 capitalize">{a.split_type}</td>
                      <td className="px-4 py-2">{a.recipient_label}</td>
                      <td className="px-4 py-2 text-right">{money(a.amount)}</td>
                      <td className="px-4 py-2"><StatusBadge status={a.status} /></td>
                      <td className="px-4 py-2 text-xs text-slate-500">
                        {a.stripe_transfer_id && <div>Transfer: {a.stripe_transfer_id}</div>}
                        {a.paid_date && <div>Paid: {formatDate(a.paid_date)}</div>}
                        {(a.not_ready_reasons || []).map((r) => <div key={r}>{r}</div>)}
                        {!a.stripe_transfer_id && !(a.not_ready_reasons || []).length && (a.eligibility_status || '—')}
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex flex-wrap gap-1">
                          {['pending', 'ready_for_payout', 'eligible', 'scheduled', 'queued'].includes(a.status) && (
                            <button type="button" onClick={() => handleApprove(a.id)} className="text-xs px-2 py-1 bg-blue-600 text-white rounded">Approve</button>
                          )}
                          {a.status !== 'paid' && a.status !== 'on_hold' && (
                            <button type="button" onClick={() => handleHold(a.id)} className="text-xs px-2 py-1 border rounded">Hold</button>
                          )}
                          {(a.status === 'on_hold' || a.status === 'hold_issue') && (
                            <button type="button" onClick={() => handleRelease(a.id)} className="text-xs px-2 py-1 border rounded">Release</button>
                          )}
                          {['failed', 'queued', 'approved', 'ready_for_payout', 'scheduled'].includes(a.status) && (
                            <button type="button" onClick={() => handleRetry(a.id)} className="text-xs px-2 py-1 border rounded">Retry</button>
                          )}
                          {(a.status === 'approved' || a.status === 'ready_for_payout') && (
                            <button type="button" onClick={() => handleMarkPaid(a.id)} className="text-xs px-2 py-1 bg-green-600 text-white rounded">Mark paid</button>
                          )}
                          <button type="button" onClick={() => setEditPayout({ id: a.id, ...a })} className="text-xs px-2 py-1 border rounded">Details</button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))}
        </div>
      ) : (
      <>
      <div className="md:hidden space-y-3 mb-4">
        {payouts.length === 0 ? (
          <p className="text-center text-slate-500 py-8">{isPm ? 'No commissions found.' : 'No payouts found.'}</p>
        ) : payouts.map((p) => (
          <div key={p.id} className="bg-white border border-slate-200 rounded-xl p-4 space-y-2"
            onClick={() => isPm && p.job_id && navigate(`/jobs/${p.job_id}`)}
            onKeyDown={() => {}}
            role={isPm ? 'button' : undefined}
          >
            <div className="flex justify-between items-start gap-2">
              <span className="font-medium text-slate-800">{p.job?.address || `Job #${p.job_id}`}</span>
              {isPm ? (
                <CommissionStateBadge state={p.commission_state} label={p.commission_state_label} />
              ) : (
                <StatusBadge status={p.status} />
              )}
            </div>
            <p className="text-sm text-slate-500">{p.job?.customer?.name || '—'}</p>
            <p className={`text-sm font-medium ${p.commission_state === 'projected' ? 'text-slate-500' : 'text-slate-800'}`}>
              {money(p.commission_amount ?? p.payout_amount)}
              {p.commission_state === 'projected' && <span className="text-xs font-normal ml-1">(projected)</span>}
            </p>
            {isPm && p.outstanding_condition && (
              <p className="text-xs text-amber-700">Still outstanding: {p.outstanding_condition}</p>
            )}
          </div>
        ))}
      </div>

      <div className="hidden md:block overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
        <table className="w-full min-w-[900px] divide-y divide-[#E2E8F0] text-sm">
          <thead className="bg-slate-50">
            {isPm ? (
              <tr>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Job</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Customer</th>
                <th className="text-right px-4 py-3 font-medium text-[#64748B]">Approved subtotal</th>
                <th className="text-right px-4 py-3 font-medium text-[#64748B]">PM %</th>
                <th className="text-right px-4 py-3 font-medium text-[#64748B]">Commission</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">State</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Outstanding</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Customer payment</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Expected / paid</th>
              </tr>
            ) : (
              <tr>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Payout #</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Job</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Amount</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B]">Status</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Eligibility</th>
                <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Paid Date</th>
              </tr>
            )}
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {payouts.length === 0 ? (
              <tr><td colSpan={isPm ? 9 : 6} className="px-4 py-8 text-center text-slate-500">{isPm ? 'No commissions found.' : 'No payouts found.'}</td></tr>
            ) : isPm ? payouts.map((p) => (
              <tr key={p.id} className="hover:bg-slate-50 cursor-pointer" onClick={() => p.job_id && navigate(`/jobs/${p.job_id}`)}>
                <td className="px-4 py-3">{p.job?.address || p.job?.job_title || `Job #${p.job_id}`}</td>
                <td className="px-4 py-3">{p.job?.customer?.name || '—'}</td>
                <td className="px-4 py-3 text-right">{money(p.approved_subtotal)}</td>
                <td className="px-4 py-3 text-right">{Number(p.pm_percentage || 0).toFixed(1)}%</td>
                <td className={`px-4 py-3 text-right font-medium ${p.commission_state === 'projected' ? 'text-slate-500' : 'text-slate-800'}`}>
                  {money(p.commission_amount ?? p.payout_amount)}
                  {p.commission_state === 'projected' && (
                    <div className="text-[10px] font-normal text-slate-400 uppercase tracking-wide">not earned</div>
                  )}
                </td>
                <td className="px-4 py-3">
                  <CommissionStateBadge state={p.commission_state} label={p.commission_state_label} />
                </td>
                <td className="px-4 py-3 text-xs text-slate-600 max-w-[180px]">
                  {p.outstanding_condition || '—'}
                </td>
                <td className="px-4 py-3 text-xs text-slate-600">{p.customer_payment_state_label || '—'}</td>
                <td className="px-4 py-3 text-xs text-slate-600">
                  {p.paid_date ? (
                    <div>Paid {formatDate(p.paid_date)}</div>
                  ) : p.expected_payout_date ? (
                    <div>Expected {formatDate(p.expected_payout_date)}</div>
                  ) : (
                    '—'
                  )}
                </td>
              </tr>
            )) : payouts.map((p) => (
              <tr key={p.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium">#{p.id}</td>
                <td className="px-4 py-3">#{p.job_id}</td>
                <td className="px-4 py-3">{money(p.payout_amount)}</td>
                <td className="px-4 py-3"><StatusBadge status={p.status} /></td>
                <td className="px-4 py-3 hidden md:table-cell capitalize text-xs text-slate-500">{eligibilityLabel(p.eligibility_status)}</td>
                <td className="px-4 py-3 hidden md:table-cell">{formatDate(p.paid_date)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      </>
      )}

      {editPayout && (
        <PayoutEditModal payout={editPayout} onClose={() => setEditPayout(null)} onSuccess={refreshPayouts} />
      )}
    </div>
  );
}
