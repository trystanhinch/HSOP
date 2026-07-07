import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { formatDate, formatDateTime } from '../utils/formatDate';

export default function Quotes() {
  const navigate = useNavigate();
  const [quotes, setQuotes] = useState([]);

  useEffect(() => {
    api.get('/quotes').then(({ data }) => setQuotes(data.data || data)).catch(() => setQuotes([]));
  }, []);

  return (
    <div>
      <PageHeader title="Quotes" />
      <div className="overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
        <table className="w-full min-w-[640px] text-sm divide-y divide-[#E2E8F0]">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Quote #</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Job</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Customer</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Total</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Status</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Date</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {quotes.length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-12 text-center text-[#64748B]">No quotes found.</td></tr>
            ) : (
              quotes.map((q) => (
                <tr
                  key={q.id}
                  className="hover:bg-slate-50 cursor-pointer transition-colors"
                  onClick={() => (q.job_id ? navigate(`/jobs/${q.job_id}`) : null)}
                >
                  <td className="px-4 py-3 font-medium">#{q.id}</td>
                  <td className="px-4 py-3">{q.job_id ? `#${q.job_id}` : '—'}</td>
                  <td className="px-4 py-3">{q.customer?.name || '—'}</td>
                  <td className="px-4 py-3">${Number(q.customer_total || 0).toFixed(2)}</td>
                  <td className="px-4 py-3"><StatusBadge status={q.status} /></td>
                  <td className="px-4 py-3 hidden md:table-cell">{formatDateTime(q.sent_at) !== '—' ? formatDate(q.sent_at) : formatDate(q.created_at)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
