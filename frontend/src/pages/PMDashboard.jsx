import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Users, Briefcase, FileText, Clock } from 'lucide-react';
import api from '../api/axios';
import KPICard from '../components/KPICard';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function formatVisitDate(date) {
  if (!date) return '—';
  return new Date(date).toLocaleDateString('en-CA', {
    month: 'short', day: 'numeric', year: 'numeric',
  });
}

export default function PMDashboard() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [data, setData] = useState(null);

  useEffect(() => {
    api.get('/dashboard/pm/kpis').then(({ data: d }) => setData(d)).catch(() => {});
  }, []);

  const today = new Date().toLocaleDateString('en-CA', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

  if (!data) {
    return <div className="text-center py-12 text-[#64748B]">Loading dashboard...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h2 className="text-xl font-semibold text-[#0F172A]">Good morning, {user?.name?.split(' ')[0] || 'PM'}</h2>
        <p className="text-sm text-[#64748B] mt-1">{today}</p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPICard title="My Leads" value={data.my_leads} icon={Users} color="#3B82F6" />
        <KPICard title="My Active Jobs" value={data.active_jobs} icon={Briefcase} color="#22C55E" />
        <KPICard title="Quotes to Send" value={data.pending_quotes} icon={FileText} color="#EAB308" />
        <KPICard title="Awaiting Approval" value={data.awaiting_approval} icon={Clock} color="#8B5CF6" />
      </div>

      <div className="flex flex-wrap gap-3">
        <Link to="/leads" className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">+ New Lead</Link>
        <Link to="/schedule" className="px-4 py-2 border border-[#E2E8F0] rounded-md text-sm font-medium text-[#0F172A] hover:bg-slate-50">
          View My Schedule
        </Link>
      </div>

      {(data.jobs_needing_quote_approval || []).length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-5">
          <h3 className="font-semibold text-slate-800 mb-3">Prices Submitted — Needs Your Review</h3>
          <div className="space-y-3">
            {data.jobs_needing_quote_approval.map((job) => (
              <div
                key={job.id}
                className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-orange-50 border border-orange-200 rounded-xl p-4"
              >
                <div>
                  <p className="text-sm font-semibold text-orange-800">{job.address}</p>
                  <p className="text-xs text-orange-700">
                    Contractor price: ${Number(job.contractor_submitted_price || 0).toFixed(2)}
                  </p>
                  <p className="text-xs text-orange-600">{job.customer?.name}</p>
                </div>
                <button
                  type="button"
                  onClick={() => navigate(`/jobs/${job.id}`)}
                  className="bg-orange-600 hover:bg-orange-700 text-white text-xs rounded-lg px-4 py-2 font-medium whitespace-nowrap"
                >
                  Review & Approve
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] overflow-hidden">
        <div className="px-4 py-3 border-b border-[#E2E8F0] bg-slate-50">
          <h3 className="text-sm font-semibold text-[#0F172A]">My Leads</h3>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="border-b border-[#E2E8F0]">
                <th className="text-left px-4 py-2 text-[#64748B]">Contact</th>
                <th className="text-left px-4 py-2 text-[#64748B] hidden md:table-cell">Address</th>
                <th className="text-left px-4 py-2 text-[#64748B]">Category</th>
                <th className="text-left px-4 py-2 text-[#64748B]">Status</th>
                <th className="text-left px-4 py-2 text-[#64748B] hidden sm:table-cell">Site Visit</th>
                <th className="text-left px-4 py-2 text-[#64748B]">Action</th>
              </tr>
            </thead>
            <tbody>
              {(data.my_leads_list || []).map((lead) => (
                <tr key={lead.id} className="border-b border-[#E2E8F0]">
                  <td className="px-4 py-2 font-medium">{lead.contact_name}</td>
                  <td className="px-4 py-2 hidden md:table-cell text-[#64748B]">{lead.address}</td>
                  <td className="px-4 py-2 capitalize">{formatCategory(lead.service_category)}</td>
                  <td className="px-4 py-2"><StatusBadge status={lead.status} /></td>
                  <td className="px-4 py-2 hidden sm:table-cell">{formatVisitDate(lead.site_visit_date)}</td>
                  <td className="px-4 py-2">
                    <Link to={`/leads/${lead.id}`} className="text-[#3B82F6] text-xs font-medium hover:underline">View Lead</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] overflow-hidden">
        <div className="px-4 py-3 border-b border-[#E2E8F0] bg-slate-50">
          <h3 className="text-sm font-semibold text-[#0F172A]">My Jobs</h3>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="border-b border-[#E2E8F0]">
                <th className="text-left px-4 py-2 text-[#64748B]">Address</th>
                <th className="text-left px-4 py-2 text-[#64748B]">Category</th>
                <th className="text-left px-4 py-2 text-[#64748B]">Contractor</th>
                <th className="text-left px-4 py-2 text-[#64748B]">Status</th>
                <th className="text-left px-4 py-2 text-[#64748B] hidden sm:table-cell">Start</th>
                <th className="text-left px-4 py-2 text-[#64748B]">Action</th>
              </tr>
            </thead>
            <tbody>
              {(data.my_jobs_list || []).map((job) => (
                <tr key={job.id} className="border-b border-[#E2E8F0]">
                  <td className="px-4 py-2">{job.address}</td>
                  <td className="px-4 py-2 capitalize">{formatCategory(job.service_category)}</td>
                  <td className="px-4 py-2">{job.contractor?.name || '—'}</td>
                  <td className="px-4 py-2"><StatusBadge status={job.status} /></td>
                  <td className="px-4 py-2 hidden sm:table-cell">{formatVisitDate(job.scheduled_start_date)}</td>
                  <td className="px-4 py-2">
                    <Link to={`/jobs/${job.id}`} className="text-[#3B82F6] text-xs font-medium hover:underline">View Job</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {(data.recent_updates || []).length > 0 && (
        <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
          <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Recent Updates on My Jobs</h3>
          <div className="space-y-3">
            {data.recent_updates.map((u) => (
              <div key={u.id} className="border border-slate-200 rounded-lg p-3 text-sm">
                <p className="text-xs text-slate-400 mb-1">{u.job?.address} · {(u.posted_by || u.postedBy)?.name}</p>
                <p className="text-slate-700">{u.update_text}</p>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
