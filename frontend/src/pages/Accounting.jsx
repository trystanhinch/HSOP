import { useCallback, useEffect, useState } from 'react';
import { Download } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { showError, showSuccess } from '../utils/swal';

function Money({ value }) {
  return <span>${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>;
}

function Stat({ label, value }) {
  return (
    <div className="bg-white rounded-xl border border-slate-200 p-4">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="text-xl font-semibold text-slate-900 mt-1">{value}</p>
    </div>
  );
}

export default function Accounting() {
  const [data, setData] = useState(null);
  const [sources, setSources] = useState(null);
  const [reports, setReports] = useState([]);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true);
    const params = {};
    if (from) params.from = from;
    if (to) params.to = to;
    Promise.all([
      api.get('/accounting/dashboard', { params }),
      api.get('/accounting/source-performance'),
      api.get('/ops-reports'),
    ])
      .then(([dash, src, ops]) => {
        setData(dash.data);
        setSources(src.data);
        setReports(ops.data.data || ops.data || []);
      })
      .catch(async (e) => {
        setData(null);
        await showError(e.response?.data?.message || 'Failed to load accounting dashboard');
      })
      .finally(() => setLoading(false));
  }, [from, to]);

  useEffect(() => { load(); }, [load]);

  const generateReport = async () => {
    try {
      await api.post('/ops-reports/generate', { period: 'daily' });
      await showSuccess('Ops report generated');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Report generation failed');
    }
  };
  const exportCsv = async (type) => {
    try {
      const params = { type };
      if (from) params.from = from;
      if (to) params.to = to;
      const res = await api.get('/accounting/export', { params, responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = `${type}_export.csv`;
      a.click();
      window.URL.revokeObjectURL(url);
      await showSuccess(`${type} CSV downloaded`);
    } catch (e) {
      await showError(e.response?.data?.message || 'Export failed');
    }
  };

  if (loading && !data) {
    return <div className="text-center py-12 text-slate-500">Loading accounting…</div>;
  }

  if (!data) {
    return <div className="text-center py-12 text-slate-500">No accounting data.</div>;
  }

  return (
    <div className="space-y-6">
      <PageHeader title="Accounting">
        <div className="flex flex-wrap gap-2 items-end">
          <div>
            <label className="text-xs text-slate-500 block mb-1">From</label>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="border border-slate-300 rounded-lg px-3 py-1.5 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">To</label>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="border border-slate-300 rounded-lg px-3 py-1.5 text-sm" />
          </div>
          <button type="button" onClick={load} className="px-3 py-1.5 bg-slate-800 text-white rounded-lg text-sm">Apply</button>
        </div>
      </PageHeader>

      <p className="text-xs text-slate-500">Payment provider: <span className="font-mono">{data.payment_provider}</span></p>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <Stat label="Total invoices" value={data.invoices.total} />
        <Stat label="Paid" value={data.invoices.paid} />
        <Stat label="Unpaid" value={data.invoices.unpaid} />
        <Stat label="Overdue" value={data.invoices.overdue} />
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <Stat label="Gross revenue (ex-GST)" value={<Money value={data.gross_revenue} />} />
        <Stat label="GST collected" value={<Money value={data.gst_collected} />} />
        <Stat label="Company profit" value={<Money value={data.company_profit} />} />
        <Stat label="Contractor owed" value={<Money value={data.payouts.contractor_owed} />} />
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <Stat label="Contractor paid" value={<Money value={data.payouts.contractor_paid} />} />
        <Stat label="PM owed" value={<Money value={data.payouts.pm_owed} />} />
        <Stat label="PM paid" value={<Money value={data.payouts.pm_paid} />} />
        <Stat label="Company share paid" value={<Money value={data.payouts.company_paid} />} />
      </div>

      <div className="grid md:grid-cols-2 gap-4">
        <div className="bg-white rounded-xl border border-slate-200 p-5">
          <h3 className="font-semibold text-slate-800 mb-3">Revenue by service category</h3>
          {(data.revenue_by_service_category || []).length === 0 ? (
            <p className="text-sm text-slate-400">No paid invoices yet.</p>
          ) : (
            <ul className="space-y-2 text-sm">
              {data.revenue_by_service_category.map((row) => (
                <li key={row.service_category} className="flex justify-between">
                  <span className="capitalize text-slate-600">{(row.service_category || '').replace(/_/g, ' ')} ({row.count})</span>
                  <Money value={row.subtotal} />
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-5">
          <h3 className="font-semibold text-slate-800 mb-3">Revenue by source company</h3>
          {(data.revenue_by_source_company || []).length === 0 ? (
            <p className="text-sm text-slate-400">No paid invoices yet.</p>
          ) : (
            <ul className="space-y-2 text-sm">
              {data.revenue_by_source_company.map((row) => (
                <li key={row.source_company} className="flex justify-between">
                  <span className="text-slate-600">{row.source_company} ({row.count})</span>
                  <Money value={row.subtotal} />
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        {['invoices', 'payments', 'payouts'].map((type) => (
          <button
            key={type}
            type="button"
            onClick={() => exportCsv(type)}
            className="inline-flex items-center gap-2 px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 hover:bg-slate-50"
          >
            <Download className="w-4 h-4" /> Export {type} CSV
          </button>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-slate-200 p-5">
        <h3 className="font-semibold text-slate-800 mb-3">Source / company performance</h3>
        {!sources?.sources?.length ? (
          <p className="text-sm text-slate-400">No company sources yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="text-left text-slate-500 border-b">
                  <th className="py-2 pr-3">Source</th>
                  <th className="py-2 pr-3">Leads</th>
                  <th className="py-2 pr-3">Quotes sent</th>
                  <th className="py-2 pr-3">Approved</th>
                  <th className="py-2 pr-3">Revenue</th>
                  <th className="py-2 pr-3">Avg ★</th>
                </tr>
              </thead>
              <tbody>
                {sources.sources.map((row) => (
                  <tr key={row.company_source_id || row.company_name} className="border-b border-slate-100">
                    <td className="py-2 pr-3 font-medium">{row.company_name}</td>
                    <td className="py-2 pr-3">{row.leads}</td>
                    <td className="py-2 pr-3">{row.quotes_sent}</td>
                    <td className="py-2 pr-3">{row.quotes_approved}</td>
                    <td className="py-2 pr-3"><Money value={row.revenue_subtotal} /></td>
                    <td className="py-2 pr-3">{row.avg_review_rating ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
        <div className="flex items-center justify-between gap-3">
          <h3 className="font-semibold text-slate-800">AI ops reports</h3>
          <button type="button" onClick={generateReport} className="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm">
            Generate daily report
          </button>
        </div>
        {(reports || []).length === 0 ? (
          <p className="text-sm text-slate-400">No reports yet.</p>
        ) : (
          <ul className="space-y-3">
            {reports.slice(0, 5).map((r) => (
              <li key={r.id} className="border border-slate-100 rounded-lg p-3">
                <p className="text-xs text-slate-500 mb-1">{r.period} · {r.report_date} · {r.provider}</p>
                <p className="text-sm text-slate-700 whitespace-pre-wrap">{r.summary_text}</p>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
