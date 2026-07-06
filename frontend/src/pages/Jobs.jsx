import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmDanger, showError, showSuccess } from '../utils/swal';

const tabs = [
  { label: 'All', value: '' },
  { label: 'New', value: 'new_job' },
  { label: 'Contractor Assigned', value: 'contractor_assigned' },
  { label: 'Quote Sent', value: 'quote_sent' },
  { label: 'Quote Approved', value: 'quote_approved' },
  { label: 'Scheduled', value: 'scheduled' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'Ready for Review', value: 'ready_for_review' },
  { label: 'Completed', value: 'completed' },
  { label: 'Cancelled', value: 'cancelled' },
];

const PAYMENT_STATUSES = ['draft', 'invoice_sent', 'awaiting_payment', 'partially_paid', 'paid', 'overdue', 'cancelled'];
const PAYOUT_STATUSES = ['not_ready', 'ready_for_payout', 'pending', 'approved', 'paid', 'hold_issue'];

function needsPrice(item) {
  return ['pending', 'not_requested', null, undefined].includes(item.contractor_price_status);
}

export default function Jobs() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const isContractor = user?.role === 'contractor';

  const [jobs, setJobs] = useState([]);
  const [activeTab, setActiveTab] = useState('');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [contractorId, setContractorId] = useState('');
  const [pmId, setPmId] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [paymentStatus, setPaymentStatus] = useState('');
  const [payoutStatus, setPayoutStatus] = useState('');
  const [showFilters, setShowFilters] = useState(false);
  const [contractors, setContractors] = useState([]);
  const [pms, setPms] = useState([]);

  useEffect(() => {
    if (!isContractor) {
      api.get('/users').then(({ data }) => {
        const list = data.data || data || [];
        setContractors(list.filter((u) => u.role === 'contractor'));
        setPms(list.filter((u) => u.role === 'pm'));
      }).catch(() => {});
    }
  }, [isContractor]);

  const loadJobs = () => {
    const params = {};
    if (!isContractor) {
      if (search) params.q = search;
      if (statusFilter) params.status = statusFilter;
      else if (activeTab) params.status = activeTab;
      if (contractorId) params.contractor_id = contractorId;
      if (pmId) params.pm_id = pmId;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;
      if (paymentStatus) params.payment_status = paymentStatus;
      if (payoutStatus) params.payout_status = payoutStatus;
    }

    const hasAdvanced = contractorId || pmId || dateFrom || dateTo || paymentStatus || payoutStatus || search;
    const endpoint = !isContractor && hasAdvanced ? '/jobs/search' : '/jobs';

    api.get(endpoint, { params }).then(({ data }) => setJobs(data.data || data)).catch(() => setJobs([]));
  };

  useEffect(() => { loadJobs(); }, [activeTab, isContractor]);

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
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm divide-y divide-slate-200">
              <thead className="bg-slate-50">
                <tr>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Job / Visit</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Customer</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500 hidden sm:table-cell">Date</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Pricing</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {jobs.length === 0 ? (
                  <tr><td colSpan={5} className="px-4 py-12 text-center text-slate-500">No jobs or site visits found.</td></tr>
                ) : jobs.map((item) => (
                  <tr
                    key={item.id}
                    className="hover:bg-slate-50 cursor-pointer"
                    onClick={() => navigate(item.url)}
                  >
                    <td className="px-4 py-3">
                      <p className="font-medium text-slate-800 text-sm">{item.job_title}</p>
                      <p className="text-xs text-slate-500">{item.address}</p>
                      {item.type === 'site_visit' && item.visit_date && (
                        <p className="text-xs text-indigo-600 mt-0.5">
                          📅 {new Date(item.visit_date).toLocaleDateString('en-CA', {
                            month: 'short', day: 'numeric',
                          })}
                          {item.visit_time && ` at ${String(item.visit_time).slice(0, 5)}`}
                        </p>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-slate-600">{item.customer?.name || '—'}</td>
                    <td className="px-4 py-3"><StatusBadge status={item.status} /></td>
                    <td className="px-4 py-3 text-sm text-slate-500 hidden sm:table-cell">
                      {item.type === 'site_visit'
                        ? (item.visit_date ? new Date(item.visit_date).toLocaleDateString() : '—')
                        : (item.scheduled_start_date ? new Date(item.scheduled_start_date).toLocaleDateString() : '—')}
                    </td>
                    <td className="px-4 py-3">
                      {needsPrice(item) && (
                        <button
                          type="button"
                          onClick={(e) => { e.stopPropagation(); navigate(item.url); }}
                          className="bg-orange-500 text-white text-xs px-3 py-1.5 rounded-lg font-medium"
                        >
                          Submit Price
                        </button>
                      )}
                      {item.contractor_price_status === 'submitted' && (
                        <span className="text-xs text-yellow-700 bg-yellow-100 px-2 py-1 rounded-full">
                          Price Submitted
                        </span>
                      )}
                      {item.contractor_price_status === 'approved' && (
                        <span className="text-xs text-green-700 bg-green-100 px-2 py-1 rounded-full">
                          Price Approved
                        </span>
                      )}
                    </td>
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
      <PageHeader title="Jobs" />
      <div className="bg-white rounded-xl border border-slate-200 p-4 mb-4 space-y-3">
        <div className="flex flex-col sm:flex-row gap-2">
          <input type="text" placeholder="Search customer or address..." value={search} onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && loadJobs()}
            className="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm" />
          <button type="button" onClick={loadJobs} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Search</button>
          <button type="button" onClick={() => setShowFilters(!showFilters)} className="px-4 py-2 border border-slate-200 rounded-lg text-sm md:hidden">
            {showFilters ? 'Hide Filters' : 'More Filters'}
          </button>
        </div>
        <div className={`grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 ${showFilters ? 'block' : 'hidden md:grid'}`}>
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm">
            <option value="">All statuses</option>
            {tabs.filter((t) => t.value).map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
          <select value={contractorId} onChange={(e) => setContractorId(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm">
            <option value="">All contractors</option>
            {contractors.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <select value={pmId} onChange={(e) => setPmId(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm">
            <option value="">All PMs</option>
            {pms.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          <select value={paymentStatus} onChange={(e) => setPaymentStatus(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm">
            <option value="">Payment status</option>
            {PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
          </select>
          <select value={payoutStatus} onChange={(e) => setPayoutStatus(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm">
            <option value="">Payout status</option>
            {PAYOUT_STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
          </select>
          <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm" />
          <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm" />
          <button type="button" onClick={loadJobs} className="px-4 py-2 bg-slate-800 text-white rounded-lg text-sm font-medium">Apply Filters</button>
        </div>
      </div>
      <div className="flex flex-wrap gap-2 mb-4">
        {tabs.map(({ label, value }) => (
          <button key={label} type="button" onClick={() => { setActiveTab(value); setStatusFilter(''); }}
            className={`px-3 py-1.5 rounded-lg text-xs sm:text-sm font-medium transition-colors ${
              activeTab === value && !statusFilter ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'
            }`}>
            {label}
          </button>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm divide-y divide-slate-200">
            <thead className="bg-slate-50">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Job Title</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Customer</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Contractor</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500 hidden sm:table-cell">Start</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500 hidden lg:table-cell">PM</th>
                {user?.role === 'owner' && (
                  <th className="text-right px-4 py-3 font-medium text-slate-500 w-12" />
                )}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {jobs.length === 0 ? (
                <tr><td colSpan={user?.role === 'owner' ? 7 : 6} className="px-4 py-12 text-center text-slate-500">No jobs found.</td></tr>
              ) : jobs.map((job) => (
                <tr key={job.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <Link to={`/jobs/${job.id}`} className="text-blue-600 hover:underline font-medium">
                      {job.job_title || `Job #${job.id}`}
                    </Link>
                  </td>
                  <td className="px-4 py-3">{job.customer?.name || '—'}</td>
                  <td className="px-4 py-3">{job.contractor?.name || '—'}</td>
                  <td className="px-4 py-3"><StatusBadge status={job.status} /></td>
                  <td className="px-4 py-3 hidden sm:table-cell">{job.scheduled_start_date?.split('T')[0] || '—'}</td>
                  <td className="px-4 py-3 hidden lg:table-cell">{job.pm?.name || '—'}</td>
                  {user?.role === 'owner' && (
                    <td className="px-4 py-3 text-right">
                      <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); confirmDeleteJob(job.id); }}
                        className="text-red-400 hover:text-red-600 p-1.5 rounded"
                        title="Delete job"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
