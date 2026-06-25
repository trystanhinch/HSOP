import { useEffect, useState } from 'react';
import { DollarSign, Briefcase, TrendingUp } from 'lucide-react';
import api from '../api/axios';
import KPICard from '../components/KPICard';
import PageHeader from '../components/PageHeader';

function fmt(n) {
  return `$${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function Reports() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/reports/profit-breakdown')
      .then(({ data: d }) => setData(d))
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return <div className="text-center py-12 text-[#64748B]">Loading reports...</div>;
  }

  const quotes = data?.quotes || [];

  return (
    <div>
      <PageHeader title="Reports" />

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <KPICard title="Total Profit" value={fmt(data?.total_profit)} icon={DollarSign} color="#22C55E" />
        <KPICard title="Jobs Quoted (Approved)" value={data?.total_jobs ?? 0} icon={Briefcase} color="#3B82F6" />
      </div>

      <div className="overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm mb-6">
        <table className="min-w-full text-sm divide-y divide-[#E2E8F0]">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Quote #</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Customer</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Job</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Contractor Price</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Customer Price</th>
              <th className="text-right px-4 py-3 font-medium text-[#64748B]">Profit</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Date</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {quotes.length === 0 ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-500">No approved quotes yet.</td></tr>
            ) : quotes.map((q) => (
              <tr key={q.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium">{q.quote_number}</td>
                <td className="px-4 py-3">{q.customer?.name || '—'}</td>
                <td className="px-4 py-3 max-w-[200px] truncate">{q.job?.address || '—'}</td>
                <td className="px-4 py-3 text-right">{fmt(q.contractor_base_price)}</td>
                <td className="px-4 py-3 text-right">{fmt(q.customer_price_before_gst)}</td>
                <td className="px-4 py-3 text-right text-green-600 font-medium">{fmt(q.hsop_markup)}</td>
                <td className="px-4 py-3">{q.accepted_at ? new Date(q.accepted_at).toLocaleDateString() : '—'}</td>
              </tr>
            ))}
          </tbody>
          {quotes.length > 0 && (
            <tfoot>
              <tr className="bg-slate-50 font-bold border-t-2 border-slate-200">
                <td colSpan={5} className="px-4 py-3 text-right text-slate-700">Total Profit:</td>
                <td className="px-4 py-3 text-right text-green-700">{fmt(data?.total_profit)}</td>
                <td />
              </tr>
            </tfoot>
          )}
        </table>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="border-2 border-dashed border-[#E2E8F0] rounded-lg p-12 text-center bg-white">
          <TrendingUp className="w-8 h-8 text-slate-300 mx-auto mb-2" />
          <p className="text-sm text-[#64748B] font-medium">Revenue Chart — Coming in Milestone 3</p>
        </div>
        <div className="border-2 border-dashed border-[#E2E8F0] rounded-lg p-12 text-center bg-white">
          <Briefcase className="w-8 h-8 text-slate-300 mx-auto mb-2" />
          <p className="text-sm text-[#64748B] font-medium">Jobs Over Time — Coming in Milestone 3</p>
        </div>
      </div>
    </div>
  );
}
