import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Briefcase, Hammer, Calendar, Wallet, CheckCircle, AlertTriangle } from 'lucide-react';
import api from '../api/axios';
import KPICard from '../components/KPICard';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function DocStatus({ label, status }) {
  const approved = status === 'approved';
  const pending = status === 'pending_review';
  return (
    <div className="flex justify-between items-center p-3 bg-slate-50 rounded-md">
      <span className="text-sm text-slate-900">{label}</span>
      {approved ? (
        <span className="flex items-center gap-1 text-xs font-medium text-green-700 bg-green-100 px-2 py-1 rounded">
          <CheckCircle size={14} /> Approved
        </span>
      ) : pending ? (
        <span className="text-xs font-medium text-yellow-700 bg-yellow-100 px-2 py-1 rounded">Pending Review</span>
      ) : (
        <span className="flex items-center gap-1 text-xs font-medium text-yellow-700 bg-yellow-100 px-2 py-1 rounded">
          <AlertTriangle size={14} /> Upload Required
        </span>
      )}
    </div>
  );
}

export default function ContractorDashboard() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [priceJob, setPriceJob] = useState(null);
  const [price, setPrice] = useState('');

  useEffect(() => {
    api.get('/dashboard/contractor/kpis')
      .then(({ data: d }) => setData(d))
      .catch((err) => setError(err.response?.data?.message || 'Failed to load dashboard'));
  }, []);

  const submitPrice = async () => {
    if (!priceJob || !price) return;
    const ok = await confirmAction({
      title: 'Submit price?',
      text: `Submit your price of $${parseFloat(price).toFixed(2)} for this job?`,
      confirmText: 'Yes, submit',
    });
    if (!ok) return;

    try {
      await api.post(`/jobs/${priceJob}/submit-price`, { price: parseFloat(price) });
      setPriceJob(null);
      setPrice('');
      await showSuccess('Price submitted successfully.');
      api.get('/dashboard/contractor/kpis').then(({ data: d }) => setData(d));
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to submit price.');
    }
  };

  if (!data) {
    return (
      <div className="text-center py-12 text-slate-500">
        {error || 'Loading dashboard...'}
        {error && (
          <button type="button" onClick={() => window.location.reload()} className="block mx-auto mt-2 text-blue-600 text-sm underline">
            Try again
          </button>
        )}
      </div>
    );
  }

  const profile = data.contractor_profile || data.document_status || {};
  const jobsNeedingPrice = (data.jobs_list || []).filter((job) =>
    ['pending', 'not_requested', null, undefined].includes(job.contractor_price_status)
  );

  return (
    <div className="space-y-6">
      <div className="bg-white rounded-xl border border-slate-200 p-6">
        <h2 className="text-xl font-semibold text-slate-900">Welcome back, {user?.name?.split(' ')[0] || 'Contractor'}</h2>
      </div>

      {jobsNeedingPrice.length > 0 && (
        <div className="space-y-3">
          {jobsNeedingPrice.map((job) => (
            <div
              key={job.id}
              className="bg-orange-50 border border-orange-200 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
            >
              <div>
                <p className="text-sm font-semibold text-orange-800">Price submission needed</p>
                <p className="text-xs text-orange-700">{job.address}</p>
                <p className="text-xs text-orange-600">{job.customer?.name}</p>
              </div>
              <button
                type="button"
                onClick={() => navigate(`/jobs/${job.id}`)}
                className="bg-orange-600 hover:bg-orange-700 text-white text-xs rounded-lg px-4 py-2 font-medium whitespace-nowrap"
              >
                Submit Price
              </button>
            </div>
          ))}
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 p-6">
        <h3 className="text-sm font-semibold text-slate-900 mb-3">Document Status</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-xl">
          <DocStatus label="WCB Certificate" status={profile.wcb || profile.wcb_status} />
          <DocStatus label="Liability Insurance" status={profile.insurance || profile.liability_insurance_status} />
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPICard title="Assigned Jobs" value={data.assigned_jobs} icon={Briefcase} color="#3B82F6" />
        <KPICard title="Active Jobs" value={data.active_jobs} icon={Hammer} color="#22C55E" />
        <KPICard title="Upcoming Jobs" value={data.upcoming_jobs} icon={Calendar} color="#EAB308" />
        <KPICard title="Pending Payout" value={`$${Number(data.pending_payout || 0).toLocaleString()}`} icon={Wallet} color="#8B5CF6" />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-xl">
        <KPICard title="Total Paid to Date" value={`$${Number(data.paid_payout_total || 0).toLocaleString()}`} icon={CheckCircle} color="#22C55E" />
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="px-4 py-3 border-b border-slate-200 bg-slate-50">
          <h3 className="text-sm font-semibold text-slate-900">Your Jobs</h3>
        </div>
        <div className="divide-y divide-slate-200">
          {(data.jobs_list || []).map((job) => (
            <div key={job.id} className="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div>
                <p className="font-medium text-slate-900">{job.address}</p>
                <p className="text-sm text-slate-500 capitalize">{formatCategory(job.service_category)} · Start: {job.scheduled_start_date?.split('T')[0] || 'TBD'}</p>
                <div className="mt-1"><StatusBadge status={job.status} /></div>
              </div>
              <div className="flex flex-wrap gap-2">
                {['pending', 'not_requested', null, undefined].includes(job.contractor_price_status) && (
                  <button type="button" onClick={() => setPriceJob(job.id)}
                    className="px-3 py-1.5 bg-orange-100 text-orange-700 rounded-lg text-xs font-medium">Submit Price</button>
                )}
                {job.status === 'in_progress' && (
                  <Link to={`/jobs/${job.id}`} className="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-xs font-medium">Add Update</Link>
                )}
                <Link to={`/jobs/${job.id}`} className="px-3 py-1.5 bg-slate-100 text-slate-800 rounded-lg text-xs font-medium">View</Link>
              </div>
            </div>
          ))}
        </div>
      </div>

      {(data.recent_messages || []).length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <h3 className="text-sm font-semibold text-slate-900 mb-3">Recent Messages</h3>
          <div className="space-y-2">
            {data.recent_messages.map((m) => (
              <div key={m.id} className="text-sm border border-slate-200 rounded-lg p-3">
                <p className="text-xs text-slate-400">{m.job?.address} · {m.sender?.name}</p>
                <p className="text-slate-700">{m.content}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      {priceJob && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
          <div className="bg-white rounded-xl p-6 w-full max-w-sm">
            <h3 className="font-semibold mb-3">Submit Price</h3>
            <input type="number" min="0" step="0.01" value={price} onChange={(e) => setPrice(e.target.value)}
              placeholder="Your price ($)" className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4" />
            <div className="flex gap-2">
              <button onClick={() => setPriceJob(null)} className="flex-1 py-2 border rounded-lg text-sm">Cancel</button>
              <button onClick={submitPrice} className="flex-1 py-2 bg-blue-600 text-white rounded-lg text-sm">Submit</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
