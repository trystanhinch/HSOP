import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ClipboardList, AlertTriangle } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { useAuth } from '../context/AuthContext';
import { formatDateTime } from '../utils/formatDate';
import { showError, showSuccess } from '../utils/swal';

const STATUSES = [
  { value: 'pending_review', label: 'Pending review' },
  { value: 'provisional', label: 'Provisional' },
  { value: 'verified', label: 'Verified' },
  { value: 'excluded', label: 'Excluded' },
];

const RECOMMEND_STATUSES = [
  { value: 'verified', label: 'Verified' },
  { value: 'excluded', label: 'Excluded' },
  { value: 'pending_review', label: 'Pending review' },
  { value: 'provisional', label: 'Provisional' },
];

const statusBadge = (status) => {
  const map = {
    pending_review: 'bg-slate-100 text-slate-800',
    provisional: 'bg-sky-100 text-sky-900',
    verified: 'bg-green-100 text-green-800',
    excluded: 'bg-red-100 text-red-800',
  };
  return map[status] || 'bg-slate-100 text-slate-700';
};

const recStateBadge = (state) => {
  const map = {
    none: 'bg-slate-50 text-slate-500 border-slate-200',
    pending_approval: 'bg-amber-50 text-amber-900 border-amber-300',
    accepted: 'bg-green-50 text-green-800 border-green-200',
    overridden: 'bg-violet-50 text-violet-900 border-violet-200',
  };
  return map[state] || 'bg-slate-50 text-slate-600 border-slate-200';
};

const recStateLabel = (state) => {
  const map = {
    none: 'No recommendation',
    pending_approval: 'Pending approval',
    accepted: 'Recommendation accepted',
    overridden: 'Recommendation overridden',
  };
  return map[state] || state || '—';
};

/**
 * Milestone 6B Phase 3 — recommend (PM) vs finalize (Owner / can_finalize).
 */
export default function LearningEligibility() {
  const { user } = useAuth();
  const canFinalize = !!(user?.can_finalize_learning || user?.role === 'owner' || user?.can_finalize_learning_eligibility);

  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({});
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('pending_review');
  const [pendingRecOnly, setPendingRecOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    const params = { page, per_page: 25 };
    if (statusFilter) params.status = statusFilter;
    if (pendingRecOnly) params.recommendation_state = 'pending_approval';

    return api.get('/admin/learning-eligibility', { params })
      .then(({ data }) => {
        setRows(data.data || []);
        setMeta({
          current: data.current_page,
          last: data.last_page,
          total: data.total,
          canFinalize: data.viewer?.can_finalize ?? canFinalize,
        });
      })
      .catch(async (e) => {
        setRows([]);
        await showError(e.response?.data?.message || 'Failed to load eligibility backlog');
      })
      .finally(() => setLoading(false));
  }, [statusFilter, page, pendingRecOnly, canFinalize]);

  useEffect(() => {
    load();
  }, [load]);

  const viewerCanFinalize = meta.canFinalize ?? canFinalize;

  const openRecommend = (row) => {
    setModal({
      mode: 'recommend',
      row,
      status: row.learning_recommended_status || 'verified',
      reason: '',
      missing_actuals: '',
      submitting: false,
    });
  };

  const openApprove = (row, presetStatus = null) => {
    setModal({
      mode: 'approve',
      row,
      status: presetStatus
        || row.learning_recommended_status
        || row.learning_eligibility_status
        || 'pending_review',
      reason: '',
      missing_actuals: '',
      submitting: false,
      acceptingRecommendation: presetStatus != null
        && row.learning_recommended_status != null
        && presetStatus === row.learning_recommended_status,
    });
  };

  const closeModal = () => setModal(null);

  const submitModal = async () => {
    if (!modal) return;
    const reason = modal.reason.trim();
    if (!reason) {
      await showError(modal.mode === 'recommend'
        ? 'A reason is required for recommendations.'
        : 'A reason is required to finalize eligibility.');
      return;
    }

    setModal((m) => (m ? { ...m, submitting: true } : m));
    try {
      if (modal.mode === 'recommend') {
        await api.patch(`/admin/learning-eligibility/${modal.row.id}/recommend`, {
          status: modal.status,
          reason,
          missing_actuals: modal.missing_actuals.trim() || null,
        });
        await showSuccess('Recommendation submitted — awaiting Owner/authorized finalization');
      } else {
        const { data } = await api.patch(`/admin/learning-eligibility/${modal.row.id}/approve`, {
          status: modal.status,
          reason,
        });
        const override = data?.override;
        await showSuccess(override
          ? 'Finalized (overrode PM recommendation)'
          : 'Eligibility finalized');
      }
      closeModal();
      await load();
    } catch (e) {
      setModal((m) => (m ? { ...m, submitting: false } : m));
      await showError(e.response?.data?.message || 'Request failed');
    }
  };

  const jobStatus = (row) => row.job?.learning_eligibility_status ?? null;
  const hasDrift = (row) => {
    const js = jobStatus(row);
    if (!js || !row.job_id) return false;
    return js !== row.learning_eligibility_status;
  };

  return (
    <div className="space-y-6 max-w-6xl">
      <PageHeader title="Learning Eligibility">
        <span className="text-sm text-slate-500 flex items-center gap-1.5">
          <ClipboardList className="w-4 h-4 text-slate-400" />
          {viewerCanFinalize
            ? 'Finalize Verified/Excluded — Owner & authorized'
            : 'Recommend status changes — PM (not final)'}
        </span>
      </PageHeader>

      <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 space-y-1">
        <p>
          PMs <span className="font-medium">recommend</span> Verified / Excluded / Pending review (with reason + known missing actuals).
          Only Owner (or a user with finalize permission) can <span className="font-medium">finalize</span> eligibility.
        </p>
        <p>
          Recommendations alone do <span className="font-medium">not</span> enter the production learning set.
          Provisional stays labelled and does not carry Verified weight.
        </p>
      </div>

      <div className="flex flex-wrap gap-2 items-center">
        {STATUSES.map((s) => (
          <button
            key={s.value}
            type="button"
            onClick={() => { setStatusFilter(s.value); setPage(1); }}
            className={`px-3 py-1.5 rounded-lg text-sm font-medium border ${
              statusFilter === s.value
                ? 'border-slate-800 bg-slate-800 text-white'
                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
            }`}
          >
            {s.label}
          </button>
        ))}
        <button
          type="button"
          onClick={() => { setStatusFilter(''); setPage(1); }}
          className={`px-3 py-1.5 rounded-lg text-sm font-medium border ${
            statusFilter === ''
              ? 'border-slate-800 bg-slate-800 text-white'
              : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
          }`}
        >
          All
        </button>
        {viewerCanFinalize && (
          <button
            type="button"
            onClick={() => { setPendingRecOnly((v) => !v); setPage(1); }}
            className={`px-3 py-1.5 rounded-lg text-sm font-medium border ml-auto ${
              pendingRecOnly
                ? 'border-amber-600 bg-amber-600 text-white'
                : 'border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100'
            }`}
          >
            Pending recommendations
          </button>
        )}
      </div>

      {loading ? (
        <p className="text-sm text-slate-500">Loading eligibility backlog…</p>
      ) : rows.length === 0 ? (
        <p className="text-sm text-slate-500 bg-white rounded-xl border border-slate-200 p-6">
          No estimate outcomes match this filter.
        </p>
      ) : (
        <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-slate-500 border-b border-slate-100 bg-slate-50">
                <th className="px-3 py-2 font-medium">Estimate</th>
                <th className="px-3 py-2 font-medium">Lead / Job</th>
                <th className="px-3 py-2 font-medium">Current status</th>
                <th className="px-3 py-2 font-medium">Recommendation</th>
                <th className="px-3 py-2 font-medium">Flags</th>
                <th className="px-3 py-2 font-medium"> </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const recState = row.recommendation_state || 'none';
                const pending = recState === 'pending_approval';
                return (
                  <tr
                    key={row.id}
                    className={`border-b border-slate-50 hover:bg-slate-50/60 align-top ${
                      pending ? 'bg-amber-50/40' : ''
                    }`}
                  >
                    <td className="px-3 py-2.5 font-medium text-slate-800 whitespace-nowrap">
                      #{row.id}
                      {row.price_low != null && (
                        <span className="block text-xs font-normal text-slate-500">
                          ${Number(row.price_low).toFixed(0)}–${Number(row.price_high ?? row.price_low).toFixed(0)}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-slate-700">
                      {row.lead_id ? (
                        <Link to={`/leads/${row.lead_id}`} className="text-blue-700 hover:underline">
                          Lead #{row.lead_id}
                        </Link>
                      ) : '—'}
                      {row.lead?.contact_name && (
                        <span className="block text-xs text-slate-500">{row.lead.contact_name}</span>
                      )}
                      {row.job_id ? (
                        <Link to={`/jobs/${row.job_id}`} className="block text-xs text-blue-700 hover:underline mt-0.5">
                          Job #{row.job_id}
                        </Link>
                      ) : (
                        <span className="block text-xs text-slate-400 mt-0.5">No job yet</span>
                      )}
                      {hasDrift(row) && (
                        <span className="mt-1 flex items-center gap-1 text-xs text-amber-800">
                          <AlertTriangle className="w-3.5 h-3.5 shrink-0" />
                          Job status drift
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2.5">
                      <span className={`text-xs px-2 py-0.5 rounded ${statusBadge(row.learning_eligibility_status)}`}>
                        {row.learning_eligibility_status || '—'}
                      </span>
                      {row.learning_eligibility_status === 'provisional' && (
                        <span className="block text-[11px] text-sky-800 mt-1">Provisional ≠ Verified weight</span>
                      )}
                      {row.learning_approved_at && (
                        <span className="block text-[11px] text-slate-400 mt-1">
                          Finalized {formatDateTime(row.learning_approved_at)}
                          {row.learning_approved_by != null && ` · #${row.learning_approved_by}`}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2.5">
                      <span className={`inline-block text-[11px] px-2 py-0.5 rounded border ${recStateBadge(recState)}`}>
                        {recStateLabel(recState)}
                      </span>
                      {row.learning_recommended_status && (
                        <span className="block mt-1">
                          <span className={`text-xs px-2 py-0.5 rounded ${statusBadge(row.learning_recommended_status)}`}>
                            → {row.learning_recommended_status}
                          </span>
                          {row.learning_recommended_by != null && (
                            <span className="block text-[11px] text-slate-400 mt-0.5">
                              by user #{row.learning_recommended_by}
                              {row.learning_recommended_at ? ` · ${formatDateTime(row.learning_recommended_at)}` : ''}
                            </span>
                          )}
                          {row.learning_recommendation_reason && (
                            <span className="block text-[11px] text-slate-600 mt-0.5 line-clamp-2">
                              {row.learning_recommendation_reason}
                            </span>
                          )}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 space-y-1">
                      {(row.flags?.is_placeholder_estimate || row.is_placeholder) && (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-amber-900 bg-amber-100 border border-amber-200 px-2 py-0.5 rounded">
                          <AlertTriangle className="w-3.5 h-3.5" />
                          Placeholder
                        </span>
                      )}
                      {row.flags?.in_production_learning_set && (
                        <span className="block text-[11px] text-green-800 font-medium">In production set</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-right whitespace-nowrap space-y-1">
                      <button
                        type="button"
                        onClick={() => openRecommend(row)}
                        className="block w-full text-sm text-blue-700 hover:text-blue-900 font-medium"
                      >
                        Recommend
                      </button>
                      {viewerCanFinalize && (
                        <>
                          {pending && row.learning_recommended_status && (
                            <button
                              type="button"
                              onClick={() => openApprove(row, row.learning_recommended_status)}
                              className="block w-full text-sm text-green-700 hover:text-green-900 font-medium"
                            >
                              Approve rec.
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={() => openApprove(row)}
                            className="block w-full text-sm text-slate-700 hover:text-slate-900 font-medium"
                          >
                            Finalize / override
                          </button>
                        </>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <div className="flex items-center justify-between px-3 py-2 border-t border-slate-100 text-xs text-slate-500">
            <span>
              Page {meta.current || 1} of {meta.last || 1} · {meta.total || 0} total
            </span>
            <div className="flex gap-2">
              <button
                type="button"
                disabled={!meta.current || meta.current <= 1}
                onClick={() => setPage((meta.current || 1) - 1)}
                className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40"
              >
                Prev
              </button>
              <button
                type="button"
                disabled={!meta.last || (meta.current || 1) >= meta.last}
                onClick={() => setPage((meta.current || 1) + 1)}
                className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40"
              >
                Next
              </button>
            </div>
          </div>
        </div>
      )}

      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40">
          <div className="bg-white rounded-xl border border-slate-200 shadow-lg max-w-md w-full p-5 space-y-4">
            <h3 className="font-semibold text-slate-900">
              {modal.mode === 'recommend'
                ? `Recommend status — estimate #${modal.row.id}`
                : `Finalize eligibility — estimate #${modal.row.id}`}
            </h3>
            {modal.mode === 'recommend' && (
              <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-950">
                This is a <span className="font-medium">recommendation</span>, not a final transition.
                The estimate stays at its current status until an Owner/authorized user finalizes it.
              </div>
            )}
            {modal.mode === 'approve' && modal.row.learning_recommended_status && (
              <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                PM recommended: <span className="font-medium">{modal.row.learning_recommended_status}</span>
                {modal.row.learning_recommendation_reason && (
                  <span className="block mt-1 text-slate-600">{modal.row.learning_recommendation_reason}</span>
                )}
                {modal.status !== modal.row.learning_recommended_status && (
                  <span className="block mt-1 text-violet-800 font-medium">
                    Choosing a different status will log an override.
                  </span>
                )}
              </div>
            )}
            {(modal.row.flags?.is_placeholder_estimate || modal.row.is_placeholder) && (
              <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-950 flex gap-2">
                <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                <span>
                  Placeholder estimate — prefer provisional or excluded unless production data is confirmed.
                </span>
              </div>
            )}
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">
                {modal.mode === 'recommend' ? 'Recommended status' : 'Final status'}
              </label>
              <select
                value={modal.status}
                onChange={(e) => setModal((m) => ({ ...m, status: e.target.value }))}
                className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white"
              >
                {(modal.mode === 'recommend' ? RECOMMEND_STATUSES : STATUSES).map((s) => (
                  <option key={s.value} value={s.value}>{s.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">
                Reason <span className="text-red-600">*</span>
              </label>
              <textarea
                value={modal.reason}
                onChange={(e) => setModal((m) => ({ ...m, reason: e.target.value }))}
                rows={3}
                placeholder={modal.mode === 'recommend'
                  ? 'Evidence / why you recommend this status'
                  : 'Required — why finalize to this status?'}
                className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm"
              />
            </div>
            {modal.mode === 'recommend' && (
              <div>
                <label className="block text-xs font-medium text-slate-600 mb-1">
                  Known missing actuals
                </label>
                <textarea
                  value={modal.missing_actuals}
                  onChange={(e) => setModal((m) => ({ ...m, missing_actuals: e.target.value }))}
                  rows={2}
                  placeholder="Optional — labour hours, materials, invoice gaps…"
                  className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm"
                />
              </div>
            )}
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                onClick={closeModal}
                disabled={modal.submitting}
                className="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-700 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={submitModal}
                disabled={modal.submitting || !modal.reason.trim()}
                className="px-3 py-1.5 rounded-lg bg-slate-800 text-white text-sm font-medium hover:bg-slate-900 disabled:opacity-40"
              >
                {modal.submitting
                  ? 'Saving…'
                  : modal.mode === 'recommend'
                    ? 'Submit recommendation'
                    : 'Finalize'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
