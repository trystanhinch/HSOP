import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';

function money(n) {
  return `$${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const METRIC_LABELS = {
  accounts_receivable: 'Accounts Receivable',
  collected_revenue: 'Collected Revenue',
  projected_profit: 'Projected Profit',
  projected_profit_month: 'Projected Profit (This Month)',
  realized_profit: 'Realized Profit',
  incomplete_cost_data: 'Incomplete Cost Data',
  open_payouts: 'Open Payouts',
  revenue_jobs_breakdown: 'Revenue / Jobs Breakdown',
};

export default function Ledger() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const metric = params.get('metric') || 'accounts_receivable';
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  const query = useMemo(() => {
    const q = { metric };
    ['from', 'to', 'basis', 'service_category', 'source', 'pm_id', 'contractor_id'].forEach((k) => {
      const v = params.get(k);
      if (v) q[k] = v;
    });
    return q;
  }, [metric, params]);

  useEffect(() => {
    setError('');
    api.get('/ledger/drilldown', { params: query })
      .then(({ data: d }) => setData(d))
      .catch((e) => {
        setData(null);
        setError(e.response?.data?.message || 'Failed to load ledger drill-down');
      });
  }, [query]);

  return (
    <div className="space-y-4">
      <PageHeader title="Financial Ledger">
        <Link to="/reports" className="text-sm text-blue-600 hover:underline">Reports</Link>
      </PageHeader>

      <div className="bg-white border border-slate-200 rounded-lg p-4">
        <div className="flex flex-wrap gap-3 text-sm">
          <span className="font-medium text-slate-800">{METRIC_LABELS[metric] || metric}</span>
          {data?.refreshed_at && (
            <span className="text-slate-400">Last refreshed: {new Date(data.refreshed_at).toLocaleString()}</span>
          )}
          {data?.filters?.basis && (
            <span className="text-slate-500">Basis: {data.filters.basis}</span>
          )}
          {(data?.filters?.from || data?.filters?.to) && (
            <span className="text-slate-500">
              Range: {data.filters.from || '…'} → {data.filters.to || '…'}
            </span>
          )}
        </div>
        {data && (
          <p className="mt-2 text-2xl font-bold text-slate-900">
            {typeof data.total === 'number' && metric !== 'incomplete_cost_data' ? money(data.total) : data.total}
          </p>
        )}
        {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
      </div>

      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="min-w-full text-sm divide-y divide-slate-200">
          <thead className="bg-slate-50">
            <tr>
              {metric === 'revenue_jobs_breakdown' ? (
                <>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Period</th>
                  <th className="text-right px-4 py-3 font-medium text-slate-500">Jobs invoiced</th>
                  <th className="text-right px-4 py-3 font-medium text-slate-500">Invoiced</th>
                  <th className="text-right px-4 py-3 font-medium text-slate-500">Collected</th>
                </>
              ) : metric.startsWith('projected') || metric === 'incomplete_cost_data' ? (
                <>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Quote</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Customer / Job</th>
                  <th className="text-right px-4 py-3 font-medium text-slate-500">Contractor</th>
                  <th className="text-right px-4 py-3 font-medium text-slate-500">Amount</th>
                </>
              ) : metric === 'realized_profit' ? (
                <>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Component</th>
                  <th className="text-right px-4 py-3 font-medium text-slate-500">Amount</th>
                </>
              ) : (
                <>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Record</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                  <th className="text-right px-4 py-3 font-medium text-slate-500">Amount</th>
                </>
              )}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {(data?.records || []).length === 0 && (
              <tr><td colSpan={4} className="px-4 py-8 text-center text-slate-500">No underlying records.</td></tr>
            )}
            {(data?.records || []).map((row, idx) => {
              if (metric === 'revenue_jobs_breakdown') {
                const period = row.period || '';
                const [y, m] = period.split('-').map(Number);
                const last = y && m ? new Date(y, m, 0).getDate() : 0;
                const mm = m ? String(m).padStart(2, '0') : '';
                const monthFrom = y && m ? `${y}-${mm}-01` : '';
                const monthTo = y && m ? `${y}-${mm}-${String(last).padStart(2, '0')}` : '';
                return (
                  <tr
                    key={row.period || idx}
                    className="hover:bg-slate-50 cursor-pointer"
                    onClick={() => {
                      const q = new URLSearchParams({ metric: 'collected_revenue' });
                      ['basis', 'service_category', 'source', 'pm_id', 'contractor_id'].forEach((k) => {
                        const v = params.get(k);
                        if (v) q.set(k, v);
                      });
                      if (monthFrom) q.set('from', monthFrom);
                      if (monthTo) q.set('to', monthTo);
                      navigate(`/ledger?${q.toString()}`);
                    }}
                  >
                    <td className="px-4 py-3 text-blue-700 font-medium">{row.period}</td>
                    <td className="px-4 py-3 text-right">{row.jobs_invoiced}</td>
                    <td className="px-4 py-3 text-right">{money(row.invoiced_revenue)}</td>
                    <td className="px-4 py-3 text-right">{money(row.collected_revenue)}</td>
                  </tr>
                );
              }
              if (metric === 'realized_profit' || metric === 'realized_profit_month') {
                return (
                  <tr key={row.component || idx}>
                    <td className="px-4 py-3">{row.component}</td>
                    <td className="px-4 py-3 text-right">{money(row.amount)}</td>
                  </tr>
                );
              }
              if (metric.startsWith('projected') || metric === 'incomplete_cost_data') {
                return (
                  <tr
                    key={row.id || idx}
                    className="hover:bg-slate-50 cursor-pointer"
                    onClick={() => row.job_id && navigate(`/jobs/${row.job_id}`)}
                  >
                    <td className="px-4 py-3">{row.quote_number || `#${row.id}`}</td>
                    <td className="px-4 py-3">{row.customer || row.flag || `Job #${row.job_id}`}</td>
                    <td className="px-4 py-3 text-right">{money(row.contractor_base_price)}</td>
                    <td className="px-4 py-3 text-right">{money(row.projected_profit ?? row.customer_price_before_gst)}</td>
                  </tr>
                );
              }
              return (
                <tr
                  key={row.id || idx}
                  className="hover:bg-slate-50 cursor-pointer"
                  onClick={() => {
                    if (row.job_id) navigate(`/jobs/${row.job_id}`);
                    else if (row.invoice_number) navigate('/invoices');
                  }}
                >
                  <td className="px-4 py-3">{row.invoice_number || row.recipient_label || `#${row.id}`}</td>
                  <td className="px-4 py-3">{row.status || '—'}</td>
                  <td className="px-4 py-3 text-right">{money(row.balance ?? row.subtotal ?? row.amount)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
