import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmDanger, showError, showSuccess } from '../utils/swal';
import { formatDate, formatDateTime, formatTime } from '../utils/formatDate';

const adminPmStatusChips = [
  { label: 'All', value: '' },
  { label: 'New', value: 'new_job' },
  { label: 'Contractor Assigned', value: 'contractor_assigned' },
  { label: 'Quote Sent', value: 'quote_sent' },
  { label: 'Needs Schedule', value: 'needs_schedule' },
  { label: 'Scheduled', value: 'scheduled' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'Ready for Review', value: 'completion_pending' },
  { label: 'Revisions', value: 'revision_requested' },
  { label: 'Active', value: 'active' },
  { label: 'Completed', value: 'completed' },
  { label: 'Cancelled', value: 'cancelled' },
];

const contractorStatusChips = [
  { label: 'All', value: '' },
  { label: 'Site Visits', value: 'site_visit_scheduled' },
  { label: 'Price Needed', value: 'contractor_assigned' },
  { label: 'Scheduled', value: 'scheduled' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'Ready for Review', value: 'pending_customer_approval' },
  { label: 'Completed', value: 'completed' },
];

const PAYMENT_STATUSES = ['draft', 'invoice_sent', 'awaiting_payment', 'partially_paid', 'paid', 'overdue', 'cancelled'];
const PAYOUT_STATUSES = ['not_ready', 'ready_for_payout', 'pending', 'approved', 'paid', 'hold_issue'];
const QUOTE_STATUSES = ['draft', 'internal_review', 'sent', 'viewed', 'approved', 'declined', 'expired', 'revision_requested'];
const CATEGORIES = [
  { label: 'Drywall / Paint', value: 'drywall_paint' },
  { label: 'Insulation', value: 'insulation' },
];

const SAVED_VIEWS_KEY = 'hsop_job_saved_views';

const PRESET_VIEWS = [
  { name: 'Needs Attention', params: { attention: '1' } },
  { name: 'My Exceptions', params: { attention: '1', status: 'active' } },
  { name: 'Needs Schedule', params: { status: 'needs_schedule' } },
  { name: 'Missing Updates', params: { attention: '1', status: 'in_progress' } },
];

function needsPrice(item) {
  return ['pending', 'not_requested', null, undefined].includes(item.contractor_price_status);
}

function Field({ label, children }) {
  return (
    <div>
      <label className="text-xs text-slate-500 block mb-1">{label}</label>
      {children}
    </div>
  );
}

export default function Jobs() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const isContractor = user?.role === 'contractor';
  const isPm = user?.role === 'pm';
  const isOwner = user?.role === 'owner';

  const [jobs, setJobs] = useState([]);
  const [allContractorJobs, setAllContractorJobs] = useState([]);
  const [contractors, setContractors] = useState([]);
  const [pms, setPms] = useState([]);
  const [brands, setBrands] = useState([]);
  const [savedViews, setSavedViews] = useState([]);

  const [search, setSearch] = useState(searchParams.get('q') || '');
  const [statusFilter, setStatusFilter] = useState(searchParams.get('status') || '');
  const [activeTab, setActiveTab] = useState(searchParams.get('status') || '');
  const [contractorId, setContractorId] = useState(searchParams.get('contractor_id') || '');
  const [pmId, setPmId] = useState(searchParams.get('pm_id') || '');
  const [dateFrom, setDateFrom] = useState(searchParams.get('date_from') || '');
  const [dateTo, setDateTo] = useState(searchParams.get('date_to') || '');
  const [paymentStatus, setPaymentStatus] = useState(searchParams.get('payment_status') || '');
  const [payoutStatus, setPayoutStatus] = useState(searchParams.get('payout_status') || '');
  const [quoteStatus, setQuoteStatus] = useState(searchParams.get('quote_status') || '');
  const [category, setCategory] = useState(searchParams.get('service_category') || searchParams.get('category') || '');
  const [priceStatus, setPriceStatus] = useState(searchParams.get('contractor_price_status') || '');
  const [brandId, setBrandId] = useState(searchParams.get('brand_id') || '');
  const [attention, setAttention] = useState(searchParams.get('attention') === '1');
  const [showFilters, setShowFilters] = useState(true);

  useEffect(() => {
    try {
      setSavedViews(JSON.parse(localStorage.getItem(SAVED_VIEWS_KEY) || '[]'));
    } catch {
      setSavedViews([]);
    }
  }, []);

  useEffect(() => {
    setSearch(searchParams.get('q') || '');
    setStatusFilter(searchParams.get('status') || '');
    setActiveTab(searchParams.get('status') || '');
    setContractorId(searchParams.get('contractor_id') || '');
    setPmId(searchParams.get('pm_id') || '');
    setDateFrom(searchParams.get('date_from') || '');
    setDateTo(searchParams.get('date_to') || '');
    setPaymentStatus(searchParams.get('payment_status') || '');
    setPayoutStatus(searchParams.get('payout_status') || '');
    setQuoteStatus(searchParams.get('quote_status') || '');
    setCategory(searchParams.get('service_category') || searchParams.get('category') || '');
    setPriceStatus(searchParams.get('contractor_price_status') || '');
    setBrandId(searchParams.get('brand_id') || '');
    setAttention(searchParams.get('attention') === '1');
  }, [searchParams]);

  useEffect(() => {
    if (!isContractor) {
      api.get('/users').then(({ data }) => {
        const list = data.data || data || [];
        setContractors(list.filter((u) => u.role === 'contractor'));
        setPms(list.filter((u) => u.role === 'pm'));
      }).catch(() => {});
      api.get('/companies').then(({ data }) => setBrands(data.data || data || [])).catch(() => {});
    }
  }, [isContractor]);

  const syncUrl = (overrides = {}) => {
    const next = new URLSearchParams();
    const state = {
      q: search,
      status: statusFilter || activeTab,
      contractor_id: contractorId,
      pm_id: pmId,
      date_from: dateFrom,
      date_to: dateTo,
      payment_status: paymentStatus,
      payout_status: payoutStatus,
      quote_status: quoteStatus,
      service_category: category,
      contractor_price_status: priceStatus,
      brand_id: brandId,
      attention: attention ? '1' : '',
      ...overrides,
    };
    Object.entries(state).forEach(([k, v]) => {
      if (v) next.set(k, v);
    });
    setSearchParams(next, { replace: true });
    return state;
  };

  const loadJobs = (overrides = {}) => {
    if (isContractor) {
      api.get('/jobs').then(({ data }) => setAllContractorJobs(data.data || data)).catch(() => setAllContractorJobs([]));
      return;
    }

    const state = syncUrl(overrides);
    const params = {};
    Object.entries(state).forEach(([k, v]) => {
      if (v) params[k] = v;
    });

    const hasAdvanced = Object.keys(params).some((k) => k !== 'status');
    const endpoint = hasAdvanced ? '/jobs/search' : '/jobs';
    api.get(endpoint, { params }).then(({ data }) => setJobs(data.data || data)).catch(() => setJobs([]));
  };

  useEffect(() => { loadJobs(); }, [isContractor]);

  const applyPreset = (params) => {
    setAttention(params.attention === '1');
    setStatusFilter(params.status || '');
    setActiveTab(params.status || '');
    setSearch(params.q || '');
    setBrandId(params.brand_id || '');
    setPmId(params.pm_id || (isPm ? String(user.id) : ''));
    const next = { ...params };
    if (params.name === 'My Exceptions' && isPm) next.pm_id = String(user.id);
    loadJobs(next);
  };

  const saveCurrentView = () => {
    const name = window.prompt('Name this filter view');
    if (!name) return;
    const view = {
      name,
      params: {
        q: search || undefined,
        status: statusFilter || undefined,
        attention: attention ? '1' : undefined,
        brand_id: brandId || undefined,
        contractor_id: contractorId || undefined,
        pm_id: pmId || undefined,
        payment_status: paymentStatus || undefined,
        payout_status: payoutStatus || undefined,
        quote_status: quoteStatus || undefined,
        service_category: category || undefined,
      },
    };
    const next = [...savedViews.filter((v) => v.name !== name), view];
    setSavedViews(next);
    localStorage.setItem(SAVED_VIEWS_KEY, JSON.stringify(next));
  };

  const setLifecycleTab = (value) => {
    setActiveTab(value);
    setStatusFilter(value);
    loadJobs({ status: value });
  };

  const statusChips = isContractor ? contractorStatusChips : adminPmStatusChips;

  const contractorDisplayedJobs = useMemo(() => {
    let list = allContractorJobs;
    if (activeTab) list = list.filter((j) => j.status === activeTab);
    if (search.trim()) {
      const q = search.toLowerCase();
      list = list.filter((j) =>
        (j.address || '').toLowerCase().includes(q)
        || (j.customer?.name || '').toLowerCase().includes(q)
        || (j.job_title || '').toLowerCase().includes(q)
        || String(j.id || '').includes(q)
        || (j.customer?.phone || '').includes(q)
      );
    }
    return list;
  }, [allContractorJobs, activeTab, search]);

  const confirmDeleteJob = async (jobId) => {
    const ok = await confirmDanger({
      title: 'Delete This Job?',
      text: 'This will permanently delete the job and all related records. The lead will be reset so it can be converted again. This cannot be undone.',
      confirmText: 'Yes, Delete Job',
    });
    if (!ok) return;
    try {
      await api.delete(`/jobs/${jobId}`);
      await showSuccess('Job deleted successfully');
      loadJobs();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to delete job');
    }
  };

  if (isContractor) {
    return (
      <div>
        <PageHeader title="Jobs" />
        <div className="bg-white rounded-xl border border-slate-200 p-4 mb-4 space-y-3">
          <div className="flex flex-col sm:flex-row gap-2 items-end">
            <Field label="Search">
              <input
                type="text"
                placeholder="Address, customer, phone, job #"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && loadJobs()}
                className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"
              />
            </Field>
            <button type="button" onClick={loadJobs} className="px-4 py-2 bg-slate-800 text-white rounded-lg text-sm font-medium">
              Apply Filters
            </button>
          </div>
        </div>
        <div className="flex flex-wrap gap-2 mb-4">
          {statusChips.map(({ label, value }) => (
            <button
              key={label}
              type="button"
              onClick={() => setLifecycleTab(value)}
              className={`px-3 py-1.5 rounded-lg text-xs sm:text-sm font-medium transition-colors ${
                activeTab === value ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'
              }`}
            >
              {label}
            </button>
          ))}
        </div>
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div className="hidden md:block overflow-x-auto">
            <table className="w-full text-sm divide-y divide-slate-200">
              <thead className="bg-slate-50">
                <tr>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Job / Visit</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Customer</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Date</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Pricing</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {contractorDisplayedJobs.length === 0 ? (
                  <tr><td colSpan={5} className="px-4 py-12 text-center text-slate-500">No jobs or site visits found.</td></tr>
                ) : contractorDisplayedJobs.map((item) => (
                  <tr key={`${item.type || 'job'}-${item.lead_id || item.id}`} className="hover:bg-slate-50 cursor-pointer" onClick={() => navigate(item.url)}>
                    <td className="px-4 py-3">
                      <p className="font-medium text-slate-800 text-sm">{item.job_title}</p>
                      <p className="text-xs text-slate-500">{item.address}</p>
                    </td>
                    <td className="px-4 py-3 text-sm text-slate-600">{item.customer?.name || '—'}</td>
                    <td className="px-4 py-3"><StatusBadge status={item.status} /></td>
                    <td className="px-4 py-3 text-sm text-slate-500">{item.type === 'site_visit' ? formatDate(item.visit_date) : formatDate(item.scheduled_start_date)}</td>
                    <td className="px-4 py-3">{needsPrice(item) && <span className="text-xs text-orange-700">Submit Price</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div>
      <PageHeader title={isPm ? 'My Jobs' : 'Jobs'}>
        <button type="button" onClick={saveCurrentView} className="px-3 py-1.5 border border-slate-300 rounded-lg text-sm">Save view</button>
      </PageHeader>

      <div className="flex flex-wrap gap-2 mb-3">
        {[...PRESET_VIEWS, ...savedViews].map((v) => (
          <button
            key={v.name}
            type="button"
            onClick={() => applyPreset(v.params || v)}
            className="px-3 py-1.5 rounded-lg text-xs border border-slate-200 bg-white hover:bg-slate-50"
          >
            {v.name}
          </button>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-slate-200 p-4 mb-4 space-y-3">
        <div className="flex flex-col sm:flex-row gap-2 items-end">
          <div className="flex-1">
            <Field label="Search (customer, address, job #, phone, quote #)">
              <input
                type="text"
                placeholder="Customer, address, job #, phone, quote #"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && loadJobs()}
                className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"
              />
            </Field>
          </div>
          <button type="button" onClick={() => loadJobs()} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Search</button>
          <button type="button" onClick={() => setShowFilters(!showFilters)} className="px-4 py-2 border border-slate-200 rounded-lg text-sm md:hidden">
            {showFilters ? 'Hide Filters' : 'More Filters'}
          </button>
        </div>

        <div className={`grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 ${showFilters ? 'grid' : 'hidden md:grid'}`}>
          <Field label="Lifecycle state">
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
              <option value="">All statuses</option>
              {adminPmStatusChips.filter((t) => t.value).map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </Field>
          <Field label="Brand">
            <select value={brandId} onChange={(e) => setBrandId(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
              <option value="">All brands</option>
              {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </Field>
          <Field label="Category">
            <select value={category} onChange={(e) => setCategory(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
              <option value="">All categories</option>
              {CATEGORIES.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
            </select>
          </Field>
          <Field label="Quote status">
            <select value={quoteStatus} onChange={(e) => setQuoteStatus(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
              <option value="">Any quote status</option>
              {QUOTE_STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
          </Field>
          <Field label="Contractor">
            <select value={contractorId} onChange={(e) => setContractorId(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
              <option value="">All contractors</option>
              {contractors.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          {isOwner && (
            <Field label="Project manager">
              <select value={pmId} onChange={(e) => setPmId(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                <option value="">All PMs</option>
                {pms.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </Field>
          )}
          <Field label="Payment status">
            <select value={paymentStatus} onChange={(e) => setPaymentStatus(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
              <option value="">Any payment status</option>
              {PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
          </Field>
          <Field label="Payout status">
            <select value={payoutStatus} onChange={(e) => setPayoutStatus(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
              <option value="">Any payout status</option>
              {PAYOUT_STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
          </Field>
          <Field label="Date from">
            <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
          </Field>
          <Field label="Date to">
            <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
          </Field>
          <Field label="Attention state">
            <label className="flex items-center gap-2 border border-slate-200 rounded-lg px-3 py-2 text-sm h-[38px]">
              <input type="checkbox" checked={attention} onChange={(e) => setAttention(e.target.checked)} />
              Needs action (dashboard exceptions)
            </label>
          </Field>
          <div className="flex items-end">
            <button type="button" onClick={() => loadJobs()} className="w-full px-4 py-2 bg-slate-800 text-white rounded-lg text-sm font-medium">Apply Filters</button>
          </div>
        </div>
      </div>

      <div className="flex flex-wrap gap-2 mb-4">
        {statusChips.map(({ label, value }) => (
          <button
            key={label}
            type="button"
            onClick={() => setLifecycleTab(value)}
            className={`px-3 py-1.5 rounded-lg text-xs sm:text-sm font-medium transition-colors ${
              activeTab === value ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="hidden md:block overflow-x-auto">
          <table className="w-full text-sm divide-y divide-slate-200">
            <thead className="bg-slate-50">
              <tr>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Job</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Customer</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">{isPm ? 'Lifecycle' : 'Status'}</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Next action</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Owner</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Deadline</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Last update</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Appointment</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Flags</th>
                {isOwner && <th className="text-right px-3 py-3 font-medium text-slate-500 w-12" />}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {jobs.length === 0 ? (
                <tr><td colSpan={isOwner ? 10 : 9} className="px-4 py-12 text-center text-slate-500">No jobs found.</td></tr>
              ) : jobs.map((job) => (
                <tr key={job.id} className="hover:bg-slate-50 cursor-pointer" onClick={() => navigate(`/jobs/${job.id}`)}>
                  <td className="px-3 py-3">
                    <p className="font-medium text-blue-600">#{job.id} · {job.job_title || 'Job'}</p>
                    <p className="text-xs text-slate-500">{job.address}</p>
                    {job.quote?.quote_number && <p className="text-xs text-slate-400">{job.quote.quote_number}</p>}
                  </td>
                  <td className="px-3 py-3">
                    <p>{job.customer?.name || '—'}</p>
                    <p className="text-xs text-slate-500">{job.customer?.phone || ''}</p>
                  </td>
                  <td className="px-3 py-3"><StatusBadge status={job.status} /></td>
                  <td className="px-3 py-3 text-xs max-w-[180px]">
                    {job.next_action?.action_description || '—'}
                  </td>
                  <td className="px-3 py-3 text-xs">{job.owner_name || job.pm?.name || '—'}</td>
                  <td className="px-3 py-3 text-xs">{formatDateTime(job.deadline_at)}</td>
                  <td className="px-3 py-3 text-xs">{formatDateTime(job.last_update_at)}</td>
                  <td className="px-3 py-3 text-xs">{job.appointment_at || formatDate(job.scheduled_start_date)}</td>
                  <td className="px-3 py-3">
                    {job.overdue && <span className="inline-block text-[10px] uppercase tracking-wide bg-red-100 text-red-700 px-1.5 py-0.5 rounded mr-1">Overdue</span>}
                    {job.attention && !job.overdue && <span className="inline-block text-[10px] uppercase tracking-wide bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded">Attention</span>}
                  </td>
                  {isOwner && (
                    <td className="px-3 py-3 text-right">
                      <button type="button" onClick={(e) => { e.stopPropagation(); confirmDeleteJob(job.id); }} className="text-red-400 hover:text-red-600 p-1.5 rounded" title="Delete job">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="md:hidden p-3 space-y-3">
          {jobs.length === 0 ? (
            <p className="text-center text-slate-500 py-8">No jobs found.</p>
          ) : jobs.map((job) => (
            <button key={job.id} type="button" className="mobile-data-card w-full text-left" onClick={() => navigate(`/jobs/${job.id}`)}>
              <div className="flex justify-between gap-2">
                <span className="mobile-data-card-title">#{job.id} · {job.job_title || 'Job'}</span>
                <StatusBadge status={job.status} />
              </div>
              <p className="mobile-data-card-meta">{job.customer?.name}</p>
              <p className="mobile-data-card-meta">{job.next_action?.action_description || 'No next action'}</p>
              {(job.overdue || job.attention) && (
                <p className="text-xs text-red-600 mt-1">{job.overdue ? 'Overdue' : 'Needs attention'}</p>
              )}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
