import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';

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

export default function Jobs() {
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
    api.get('/users').then(({ data }) => {
      const list = data.data || data || [];
      setContractors(list.filter((u) => u.role === 'contractor'));
      setPms(list.filter((u) => u.role === 'pm'));
    }).catch(() => {});
  }, []);

  const loadJobs = () => {
    const params = {};
    if (search) params.q = search;
    if (statusFilter) params.status = statusFilter;
    else if (activeTab) params.status = activeTab;
    if (contractorId) params.contractor_id = contractorId;
    if (pmId) params.pm_id = pmId;
    if (dateFrom) params.date_from = dateFrom;
    if (dateTo) params.date_to = dateTo;
    if (paymentStatus) params.payment_status = paymentStatus;
    if (payoutStatus) params.payout_status = payoutStatus;

    const hasAdvanced = contractorId || pmId || dateFrom || dateTo || paymentStatus || payoutStatus || search;
    const endpoint = hasAdvanced ? '/jobs/search' : '/jobs';

    api.get(endpoint, { params }).then(({ data }) => setJobs(data.data || data)).catch(() => setJobs([]));
  };

  useEffect(() => { loadJobs(); }, [activeTab]);

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
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {jobs.length === 0 ? (
                <tr><td colSpan={6} className="px-4 py-12 text-center text-slate-500">No jobs found.</td></tr>
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
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
