import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { formatDate, formatDateTime } from '../utils/formatDate';
import { showError, showSuccess } from '../utils/swal';

const STATUS_OPTIONS = [
  { label: 'All', value: '' },
  { label: 'Draft', value: 'draft' },
  { label: 'Internal Review', value: 'internal_review' },
  { label: 'Sent', value: 'sent' },
  { label: 'Viewed', value: 'viewed' },
  { label: 'Follow-up Due', value: 'follow_up_due' },
  { label: 'Revision Requested', value: 'revision_requested' },
  { label: 'Approved', value: 'approved' },
  { label: 'Declined', value: 'declined' },
  { label: 'Expired', value: 'expired' },
];

function money(n) {
  return `$${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function Quotes() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const isStaff = user?.role === 'owner' || user?.role === 'pm';
  const showFinancials = isStaff;

  const [quotes, setQuotes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [pms, setPms] = useState([]);
  const [contractors, setContractors] = useState([]);
  const [brands, setBrands] = useState([]);
  const [busyId, setBusyId] = useState(null);

  const [q, setQ] = useState(searchParams.get('q') || '');
  const [status, setStatus] = useState(searchParams.get('status') || '');
  const [brandId, setBrandId] = useState(searchParams.get('brand_id') || '');
  const [pmId, setPmId] = useState(searchParams.get('pm_id') || '');
  const [contractorId, setContractorId] = useState(searchParams.get('contractor_id') || '');
  const [from, setFrom] = useState(searchParams.get('from') || '');
  const [to, setTo] = useState(searchParams.get('to') || '');
  const [viewed, setViewed] = useState(searchParams.get('viewed') || '');
  const [expired, setExpired] = useState(searchParams.get('expired') || '');
  const [revisionNumber, setRevisionNumber] = useState(searchParams.get('revision_number') || '');

  useEffect(() => {
    if (!isStaff) return;
    api.get('/users').then(({ data }) => {
      const list = data.data || data || [];
      setPms(list.filter((u) => u.role === 'pm'));
      setContractors(list.filter((u) => u.role === 'contractor'));
    }).catch(() => {});
    api.get('/companies').then(({ data }) => {
      setBrands(data.data || data || []);
    }).catch(() => {});
  }, [isStaff]);

  const load = () => {
    setLoading(true);
    const params = {};
    if (q) params.q = q;
    if (status) params.status = status;
    if (brandId) params.brand_id = brandId;
    if (pmId) params.pm_id = pmId;
    if (contractorId) params.contractor_id = contractorId;
    if (from) params.from = from;
    if (to) params.to = to;
    if (viewed !== '') params.viewed = viewed;
    if (expired !== '') params.expired = expired;
    if (revisionNumber) params.revision_number = revisionNumber;

    const next = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => { if (v !== '' && v != null) next.set(k, v); });
    setSearchParams(next, { replace: true });

    api.get('/quotes', { params })
      .then(({ data }) => setQuotes(data.data || data || []))
      .catch(() => setQuotes([]))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const runAction = async (quote, action) => {
    setBusyId(quote.id);
    try {
      const map = {
        send: `/quotes/${quote.id}/send`,
        resend: `/quotes/${quote.id}/resend`,
        revise: `/quotes/${quote.id}/revise`,
        follow_up: `/quotes/${quote.id}/follow-up`,
        expire: `/quotes/${quote.id}/expire`,
        decline: `/quotes/${quote.id}/mark-declined`,
        mark_internal_review: `/quotes/${quote.id}/internal-review`,
        review: null,
      };
      if (action === 'review') {
        if (quote.job_id) navigate(`/jobs/${quote.job_id}`);
        return;
      }
      const url = map[action];
      if (!url) return;
      const body = action === 'decline' ? { rejection_reason: 'Marked declined from quote list' } : {};
      const { data } = await api.post(url, body);
      await showSuccess(data.message || 'Done');
      if (action === 'revise' && data.quote?.job_id) {
        navigate(`/jobs/${data.quote.job_id}`);
        return;
      }
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Action failed');
    } finally {
      setBusyId(null);
    }
  };

  const actionButtons = (quote) => {
    const actions = quote.actions || [];
    if (!actions.length) return null;
    const labels = {
      review: 'Review',
      send: 'Send',
      resend: 'Resend',
      revise: 'Revise',
      follow_up: 'Follow up',
      expire: 'Expire',
      decline: 'Decline',
      mark_internal_review: 'Internal review',
    };
    return (
      <div className="flex flex-wrap gap-1" onClick={(e) => e.stopPropagation()}>
        {actions.map((a) => (
          <button
            key={a}
            type="button"
            disabled={busyId === quote.id}
            onClick={() => runAction(quote, a)}
            className="px-2 py-0.5 text-xs border border-slate-300 rounded hover:bg-slate-50 disabled:opacity-50"
          >
            {labels[a] || a}
          </button>
        ))}
      </div>
    );
  };

  return (
    <div>
      <PageHeader title="Quotes">
        <button type="button" onClick={load} className="px-3 py-1.5 bg-slate-800 text-white rounded-lg text-sm">
          Refresh
        </button>
      </PageHeader>

      {isStaff && (
        <div className="mb-4 rounded-lg border border-slate-200 bg-white p-3 flex flex-wrap gap-2 items-end">
          <div>
            <label className="text-xs text-slate-500 block mb-1">Search</label>
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Quote #, customer…" className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm w-40" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Status</label>
            <select value={status} onChange={(e) => setStatus(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              {STATUS_OPTIONS.map((o) => <option key={o.value || 'all'} value={o.value}>{o.label}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Brand</label>
            <select value={brandId} onChange={(e) => setBrandId(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="">All</option>
              {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">PM</label>
            <select value={pmId} onChange={(e) => setPmId(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="">All</option>
              {pms.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Contractor</label>
            <select value={contractorId} onChange={(e) => setContractorId(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="">All</option>
              {contractors.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">From</label>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">To</label>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Viewed</label>
            <select value={viewed} onChange={(e) => setViewed(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="">Any</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Expired</label>
            <select value={expired} onChange={(e) => setExpired(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="">Any</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Revision #</label>
            <input value={revisionNumber} onChange={(e) => setRevisionNumber(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm w-20" />
          </div>
          <button type="button" onClick={load} className="px-3 py-1.5 bg-slate-800 text-white rounded-lg text-sm">Apply</button>
        </div>
      )}

      {loading ? (
        <p className="text-center text-slate-500 py-8">Loading quotes…</p>
      ) : (
        <>
          <div className="md:hidden space-y-3">
            {quotes.length === 0 ? (
              <p className="text-center text-slate-500 py-8">No quotes found.</p>
            ) : quotes.map((quote) => (
              <div key={quote.id} className="mobile-data-card w-full text-left">
                <button
                  type="button"
                  className="w-full text-left"
                  onClick={() => (quote.job_id ? navigate(`/jobs/${quote.job_id}`) : null)}
                >
                  <div className="flex items-start justify-between gap-2">
                    <span className="mobile-data-card-title">{quote.quote_number || `#${quote.id}`} · r{quote.revision_number || 1}</span>
                    <StatusBadge status={quote.status} />
                  </div>
                  {quote.follow_up_due && (
                    <p className="text-xs text-amber-700 font-medium mt-1">Follow-up due (task)</p>
                  )}
                  <p className="mobile-data-card-meta">{quote.customer?.name || '—'}</p>
                  <p className="text-sm font-semibold text-slate-800">{money(quote.customer_total)}</p>
                  {showFinancials && (
                    <p className="mobile-data-card-meta">
                      Contractor {money(quote.contractor_base_price)} · Subtotal {money(quote.subtotal)} · GST {money(quote.gst)}
                      {quote.margin != null ? ` · Margin ${money(quote.margin)}` : ''}
                    </p>
                  )}
                </button>
                {isStaff && <div className="mt-2">{actionButtons(quote)}</div>}
              </div>
            ))}
          </div>

          <div className="hidden md:block overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
            <table className="w-full text-sm divide-y divide-[#E2E8F0]">
              <thead className="bg-slate-50">
                <tr>
                  <th className="text-left px-3 py-3 font-medium text-[#64748B]">Quote</th>
                  <th className="text-left px-3 py-3 font-medium text-[#64748B]">Rev</th>
                  <th className="text-left px-3 py-3 font-medium text-[#64748B]">Job</th>
                  <th className="text-left px-3 py-3 font-medium text-[#64748B]">Customer</th>
                  <th className="text-left px-3 py-3 font-medium text-[#64748B]">Brand / PM</th>
                  <th className="text-right px-3 py-3 font-medium text-[#64748B]">Total</th>
                  {showFinancials && (
                    <>
                      <th className="text-right px-3 py-3 font-medium text-[#64748B]">Contractor</th>
                      <th className="text-right px-3 py-3 font-medium text-[#64748B]">Subtotal</th>
                      <th className="text-right px-3 py-3 font-medium text-[#64748B]">GST</th>
                      <th className="text-right px-3 py-3 font-medium text-[#64748B]">Margin</th>
                    </>
                  )}
                  <th className="text-left px-3 py-3 font-medium text-[#64748B]">Status</th>
                  <th className="text-left px-3 py-3 font-medium text-[#64748B]">Timestamps</th>
                  {isStaff && <th className="text-left px-3 py-3 font-medium text-[#64748B]">Actions</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E2E8F0]">
                {quotes.length === 0 ? (
                  <tr><td colSpan={showFinancials ? 13 : 9} className="px-4 py-12 text-center text-[#64748B]">No quotes found.</td></tr>
                ) : (
                  quotes.map((quote) => (
                    <tr key={quote.id} className="hover:bg-slate-50 transition-colors">
                      <td
                        className="px-3 py-3 font-medium cursor-pointer text-blue-700"
                        onClick={() => (quote.job_id ? navigate(`/jobs/${quote.job_id}`) : null)}
                      >
                        {quote.quote_number || `#${quote.id}`}
                      </td>
                      <td className="px-3 py-3">r{quote.revision_number || 1}</td>
                      <td className="px-3 py-3">{quote.job_id ? `#${quote.job_id}` : '—'}</td>
                      <td className="px-3 py-3">{quote.customer?.name || '—'}</td>
                      <td className="px-3 py-3 text-xs text-slate-600">
                        {quote.brand_name_snapshot || quote.job?.company?.name || '—'}
                        <br />
                        {quote.job?.pm?.name || '—'}
                      </td>
                      <td className="px-3 py-3 text-right font-medium">{money(quote.customer_total)}</td>
                      {showFinancials && (
                        <>
                          <td className="px-3 py-3 text-right">{money(quote.contractor_base_price)}</td>
                          <td className="px-3 py-3 text-right">{money(quote.subtotal)}</td>
                          <td className="px-3 py-3 text-right">{money(quote.gst)}</td>
                          <td className="px-3 py-3 text-right">{quote.margin != null ? money(quote.margin) : '—'}</td>
                        </>
                      )}
                      <td className="px-3 py-3">
                        <StatusBadge status={quote.status} />
                        {quote.follow_up_due && (
                          <span className="ml-1 text-[10px] uppercase tracking-wide text-amber-700">Follow-up</span>
                        )}
                      </td>
                      <td className="px-3 py-3 text-xs text-slate-500">
                        <div>Sent {formatDateTime(quote.sent_at)}</div>
                        <div>Opened {formatDateTime(quote.viewed_at)}</div>
                        <div>Approved {formatDate(quote.accepted_at)}</div>
                        <div>Declined {formatDate(quote.declined_at)}</div>
                      </td>
                      {isStaff && <td className="px-3 py-3">{actionButtons(quote)}</td>}
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
