import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import StatusBadge from '../components/StatusBadge';
import { formatDate } from '../utils/formatDate';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function formatMoney(val) {
  return `$${Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

export default function CustomerDashboard() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let isMounted = true;
    setLoading(true);
    setError(null);

    const timeout = setTimeout(() => {
      if (isMounted) {
        setError('This is taking longer than expected. Please refresh.');
        setLoading(false);
      }
    }, 8000);

    api.get('/dashboard/customer/summary')
      .then(({ data: d }) => {
        if (isMounted) setData(d);
      })
      .catch((err) => {
        if (isMounted) {
          setError(err.response?.data?.message || 'Failed to load dashboard');
        }
      })
      .finally(() => {
        if (isMounted) setLoading(false);
        clearTimeout(timeout);
      });

    return () => {
      isMounted = false;
      clearTimeout(timeout);
    };
  }, []);

  if (loading) {
    return <div className="text-center py-12 text-[#64748B]">Loading dashboard...</div>;
  }

  if (error) {
    return (
      <div className="p-6 text-center">
        <p className="text-red-600 mb-2">{error}</p>
        <button type="button" onClick={() => window.location.reload()} className="text-blue-600 text-sm underline">
          Try again
        </button>
      </div>
    );
  }

  if (!data) {
    return <div className="text-center py-12 text-[#64748B]">No dashboard data available.</div>;
  }

  const pendingQuotes = (data.pending_quotes || data.quotes || []).filter((q) => ['sent', 'viewed'].includes(q.status));
  const acceptedQuotes = (data.quotes || []).filter((q) => q.status === 'approved');

  return (
    <div className="space-y-6">
      <div className="bg-gradient-to-r from-[#3B82F6] to-[#2563EB] rounded-lg p-6 text-white">
        <h2 className="text-xl font-semibold">Welcome, {data.name}</h2>
        <p className="text-blue-100 text-sm mt-1">Here&apos;s an update on your projects with ServiceOP</p>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Quotes Waiting for Your Approval</h3>
        {pendingQuotes.length === 0 ? (
          <p className="text-sm text-[#64748B] text-center py-6">No pending quotes</p>
        ) : (
          <div className="space-y-4">
            {pendingQuotes.map((quote) => (
              <div key={quote.id} className="border border-[#E2E8F0] rounded-lg p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                  <p className="font-medium text-[#0F172A] capitalize">{formatCategory(quote.job?.service_category)}</p>
                  <p className="text-sm text-[#64748B]">{quote.job?.address}</p>
                  <p className="text-lg font-bold text-[#0F172A] mt-1">Total: {formatMoney(quote.customer_total)} <span className="text-xs font-normal text-[#64748B]">(inc. GST)</span></p>
                </div>
                <Link
                  to={quote.customer_token ? `/quote/view/${quote.customer_token}` : '#'}
                  className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium whitespace-nowrap text-center hover:bg-blue-700"
                >
                  Review & Approve Quote
                </Link>
              </div>
            ))}
          </div>
        )}
      </div>

      {acceptedQuotes.length > 0 && (
        <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
          <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Accepted Quotes</h3>
          <div className="space-y-3">
            {acceptedQuotes.map((quote) => (
              <div key={quote.id} className="flex justify-between items-center p-3 bg-green-50 rounded-lg border border-green-100">
                <div>
                  <p className="text-sm font-medium capitalize">{formatCategory(quote.job?.service_category)}</p>
                  <p className="text-xs text-[#64748B]">{quote.job?.address}</p>
                </div>
                <div className="text-right">
                  <p className="font-bold text-[#0F172A]">{formatMoney(quote.customer_total)}</p>
                  <StatusBadge status="approved" />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {(data.recent_updates || []).length > 0 && (
        <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-6">
          <h3 className="text-sm font-semibold text-slate-900 mb-4">Recent Progress Updates</h3>
          <div className="space-y-3">
            {data.recent_updates.map((u) => (
              <div key={u.id} className="border border-slate-200 rounded-lg p-3">
                <p className="text-xs text-slate-400 mb-1">{u.job?.address} · {new Date(u.created_at).toLocaleDateString()}</p>
                <p className="text-sm text-slate-700">{u.update_text}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Your Projects</h3>
        {(data.jobs || []).length === 0 ? (
          <p className="text-sm text-[#64748B] text-center py-6">No active projects</p>
        ) : (
          <div className="space-y-4">
            {data.jobs.map((job) => (
              <div key={job.id} className="border border-[#E2E8F0] rounded-lg p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                  <p className="font-medium text-[#0F172A]">{job.address}</p>
                  <p className="text-sm text-[#64748B] capitalize">{formatCategory(job.service_category)}</p>
                  <div className="mt-1"><StatusBadge status={job.status} /></div>
                  <p className="text-xs text-[#64748B] mt-1">
                    {job.scheduled_start_date ? formatDate(job.scheduled_start_date) : '—'}
                    {' → '}
                    {job.scheduled_end_date ? formatDate(job.scheduled_end_date) : '—'}
                  </p>
                </div>
                <Link to={`/jobs/${job.id}`} className="px-4 py-2 border border-[#E2E8F0] rounded-md text-sm font-medium hover:bg-slate-50 text-center">
                  View Project
                </Link>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Your Invoices</h3>
        {(data.invoices || []).length === 0 ? (
          <p className="text-sm text-[#64748B] text-center py-6">No invoices yet</p>
        ) : (
          <div className="overflow-x-auto rounded-lg border border-[#E2E8F0]">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="bg-slate-50 border-b border-[#E2E8F0]">
                  <th className="text-left px-4 py-2 text-[#64748B]">Amount</th>
                  <th className="text-left px-4 py-2 text-[#64748B]">GST</th>
                  <th className="text-left px-4 py-2 text-[#64748B]">Total</th>
                  <th className="text-left px-4 py-2 text-[#64748B]">Status</th>
                  <th className="text-left px-4 py-2 text-[#64748B]">Due Date</th>
                </tr>
              </thead>
              <tbody>
                {data.invoices.map((inv) => (
                  <tr key={inv.id} className="border-b border-[#E2E8F0]">
                    <td className="px-4 py-2">{formatMoney(inv.amount)}</td>
                    <td className="px-4 py-2">{formatMoney(inv.gst)}</td>
                    <td className="px-4 py-2 font-medium">{formatMoney(inv.balance)}</td>
                    <td className="px-4 py-2"><StatusBadge status={inv.status} /></td>
                    <td className="px-4 py-2">{inv.due_date || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
