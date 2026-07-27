import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { DollarSign, Briefcase, Download } from 'lucide-react';
import api from '../api/axios';
import KPICard from '../components/KPICard';
import PageHeader from '../components/PageHeader';
import { formatDate } from '../utils/formatDate';
import { showError, showSuccess } from '../utils/swal';

function fmt(n) {
  return `$${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function Reports() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [basis, setBasis] = useState('cash');
  const [service, setService] = useState('');
  const [source, setSource] = useState('');
  const [pmId, setPmId] = useState('');
  const [contractorId, setContractorId] = useState('');

  const load = () => {
    setLoading(true);
    const params = { basis };
    if (from) params.from = from;
    if (to) params.to = to;
    if (service) params.service_category = service;
    if (source) params.source = source;
    if (pmId) params.pm_id = pmId;
    if (contractorId) params.contractor_id = contractorId;
    api.get('/reports/profit-breakdown', { params })
      .then(({ data: d }) => setData(d))
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const exportCsv = async () => {
    try {
      const params = { type: 'invoices' };
      if (from) params.from = from;
      if (to) params.to = to;
      const res = await api.get('/accounting/export', { params, responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'reports_accounting_export.csv';
      a.click();
      window.URL.revokeObjectURL(url);
      await showSuccess('CSV downloaded (reconciles to Accounting export)');
    } catch (e) {
      await showError(e.response?.data?.message || 'Export failed');
    }
  };

  if (loading && !data) {
    return <div className="text-center py-12 text-[#64748B]">Loading reports...</div>;
  }

  const quotes = data?.quotes || [];
  const incomplete = data?.incomplete_cost_quotes || [];
  const breakdown = data?.revenue_jobs_breakdown || [];

  return (
    <div>
      <PageHeader title="Reports">
        <div className="flex flex-wrap gap-2 items-end">
          <div>
            <label className="text-xs text-slate-500 block mb-1">From</label>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">To</label>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Basis</label>
            <select value={basis} onChange={(e) => setBasis(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="cash">Cash</option>
              <option value="accrual">Accrual</option>
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Service</label>
            <input value={service} onChange={(e) => setService(e.target.value)} placeholder="e.g. drywall" className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Source</label>
            <input value={source} onChange={(e) => setSource(e.target.value)} placeholder="source company" className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">PM ID</label>
            <input value={pmId} onChange={(e) => setPmId(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm w-24" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Contractor ID</label>
            <input value={contractorId} onChange={(e) => setContractorId(e.target.value)} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm w-28" />
          </div>
          <button type="button" onClick={load} className="px-3 py-1.5 bg-slate-800 text-white rounded-lg text-sm">Apply</button>
          <button type="button" onClick={exportCsv} className="px-3 py-1.5 border border-slate-300 rounded-lg text-sm inline-flex items-center gap-1">
            <Download size={14} /> Export CSV
          </button>
        </div>
      </PageHeader>

      {data?.refreshed_at && (
        <p className="text-xs text-slate-400 mb-4">
          Last refreshed: {new Date(data.refreshed_at).toLocaleString()}
          {' · '}basis: {data.filters?.basis || basis}
          {(data.filters?.from || data.filters?.to) && ` · ${data.filters.from || '…'} → ${data.filters.to || '…'}`}
        </p>
      )}

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <KPICard title="Projected Profit" value={fmt(data?.projected_profit)} icon={DollarSign} color="#22C55E" to="/ledger?metric=projected_profit" />
        <KPICard title="Realized Profit" value={fmt(data?.realized_profit)} icon={DollarSign} color="#0EA5E9" to="/ledger?metric=realized_profit" />
        <KPICard title="Collected Revenue (ex-GST)" value={fmt(data?.collected_revenue)} icon={DollarSign} color="#3B82F6" to="/ledger?metric=collected_revenue" />
        <KPICard title="Jobs (complete cost)" value={data?.total_jobs ?? 0} icon={Briefcase} color="#3B82F6" to="/ledger?metric=projected_profit" />
      </div>

      {incomplete.length > 0 && (
        <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4">
          <p className="text-sm font-medium text-amber-900 mb-2">
            Incomplete cost data — excluded from Projected Profit ({incomplete.length})
          </p>
          <ul className="text-sm text-amber-800 space-y-1">
            {incomplete.map((q) => (
              <li key={q.id}>
                Quote {q.quote_number || q.id} — contractor price {fmt(q.contractor_base_price)} (flag: {q.flag})
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm mb-6">
        <table className="min-w-full text-sm divide-y divide-[#E2E8F0]">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Quote #</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Customer</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Job</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Contractor Price</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Customer Price</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Projected Profit</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Date</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {quotes.length === 0 ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-500">No approved quotes with complete cost data.</td></tr>
            ) : quotes.map((q) => (
              <tr key={q.id} className="hover:bg-slate-50 cursor-pointer" onClick={() => q.job_id && navigate(`/jobs/${q.job_id}`)}>
                <td className="px-4 py-3 font-medium">{q.quote_number}</td>
                <td className="px-4 py-3">{q.customer || '—'}</td>
                <td className="px-4 py-3">#{q.job_id}</td>
                <td className="px-4 py-3 text-right">{fmt(q.contractor_base_price)}</td>
                <td className="px-4 py-3 text-right">{fmt(q.customer_price_before_gst)}</td>
                <td className="px-4 py-3 text-right text-green-600 font-medium">{fmt(q.projected_profit)}</td>
                <td className="px-4 py-3">{formatDate(q.accepted_at)}</td>
              </tr>
            ))}
          </tbody>
          {quotes.length > 0 && (
            <tfoot>
              <tr className="bg-slate-50 font-bold border-t-2 border-slate-200">
                <td colSpan={5} className="px-4 py-3 text-right text-slate-700">Projected Profit:</td>
                <td className="px-4 py-3 text-right text-green-700">{fmt(data?.projected_profit)}</td>
                <td />
              </tr>
            </tfoot>
          )}
        </table>
      </div>

      <div className="overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
        <div className="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
          <h3 className="text-sm font-semibold text-slate-800">Revenue / jobs by month</h3>
          <button type="button" className="text-sm text-blue-600" onClick={() => navigate('/ledger?metric=revenue_jobs_breakdown')}>
            Open drill-down
          </button>
        </div>
        <table className="min-w-full text-sm divide-y divide-[#E2E8F0]">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Period</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Jobs invoiced</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Invoiced (ex-GST)</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Collected (ex-GST)</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {breakdown.length === 0 ? (
              <tr><td colSpan={4} className="px-4 py-8 text-center text-slate-500">No invoice activity in range.</td></tr>
            ) : breakdown.map((row) => (
              <tr key={row.period} className="hover:bg-slate-50">
                <td className="px-4 py-3">{row.period}</td>
                <td className="px-4 py-3 text-right">{row.jobs_invoiced}</td>
                <td className="px-4 py-3 text-right">{fmt(row.invoiced_revenue)}</td>
                <td className="px-4 py-3 text-right">{fmt(row.collected_revenue)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
