import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Users, Briefcase, FileText, Clock } from 'lucide-react';
import api from '../api/axios';
import KPICard from '../components/KPICard';
import StatusBadge from '../components/StatusBadge';
import StripeConnectCard from '../components/StripeConnectCard';
import { useAuth } from '../context/AuthContext';
import { formatDate } from '../utils/formatDate';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function WorkflowSection({ title, empty, items, renderItem }) {
  const list = items || [];
  return (
    <div className="bg-white rounded-xl border border-slate-200 p-5">
      <h3 className="font-semibold text-slate-800 mb-3">{title}</h3>
      {list.length === 0 ? (
        <p className="text-sm text-slate-400">{empty}</p>
      ) : (
        <div className="space-y-2">{list.map(renderItem)}</div>
      )}
    </div>
  );
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
        <KPICard title="My Leads" value={data.my_leads} icon={Users} color="#3B82F6" to="/leads" />
        <KPICard title="My Active Jobs" value={data.active_jobs} icon={Briefcase} color="#22C55E" to="/jobs" />
        <KPICard title="Quotes to Send" value={data.pending_quotes} icon={FileText} color="#EAB308" to="/quotes?status=draft" />
        <KPICard title="Awaiting Approval" value={data.awaiting_approval} icon={Clock} color="#8B5CF6" to="/quotes?status=sent" />
      </div>

      <div className="flex flex-wrap gap-3">
        <Link to="/leads" className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">+ New Lead</Link>
        <Link to="/schedule" className="px-4 py-2 border border-[#E2E8F0] rounded-md text-sm font-medium text-[#0F172A] hover:bg-slate-50">
          View My Schedule
        </Link>
      </div>

      <StripeConnectCard />

      {(data.leads_needing_quote_review || []).length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-5">
          <h3 className="font-semibold text-slate-800 mb-3">Lead Prices Submitted — Review &amp; Send Quote</h3>
          <div className="space-y-3">
            {data.leads_needing_quote_review.map((lead) => (
              <div
                key={lead.id}
                className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-blue-50 border border-blue-200 rounded-xl p-4"
              >
                <div>
                  <p className="text-sm font-semibold text-blue-800">{lead.contact_name}</p>
                  <p className="text-xs text-blue-700">{lead.address || 'No address yet'}</p>
                  <p className="text-xs text-blue-600">
                    Contractor price: ${Number(lead.contractor_price || 0).toFixed(2)}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => navigate(`/leads/${lead.id}`)}
                  className="bg-blue-600 hover:bg-blue-700 text-white text-xs rounded-lg px-4 py-2 font-medium whitespace-nowrap"
                >
                  Review Lead &amp; Send Quote
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      <WorkflowSection
        title="Customers needing contact"
        empty="No overdue/pending contact actions."
        items={data.customers_needing_contact}
        renderItem={(item) => (
          <button type="button" key={item.next_action_id} onClick={() => navigate(`/leads/${item.lead_id}`)}
            className={`w-full text-left rounded-lg border p-3 ${item.overdue ? 'border-red-200 bg-red-50' : 'border-slate-200'}`}>
            <p className="text-sm font-medium">{item.contact_name || `Lead #${item.lead_id}`}</p>
            <p className="text-xs text-slate-500">{item.action_description}</p>
            <p className="text-xs mt-1">{item.overdue ? 'Overdue' : 'Pending'} · due {formatDate(item.due_at)}</p>
          </button>
        )}
      />

      <WorkflowSection
        title="Scheduled visits / quote appointments"
        empty="No upcoming site visits."
        items={data.scheduled_calls_and_visits}
        renderItem={(lead) => (
          <button type="button" key={lead.id} onClick={() => navigate(`/leads/${lead.id}`)}
            className="w-full text-left rounded-lg border border-slate-200 p-3">
            <p className="text-sm font-medium">{lead.contact_name}</p>
            <p className="text-xs text-slate-500">{lead.address || '—'}</p>
            <p className="text-xs mt-1">{formatDate(lead.site_visit_date)} {lead.site_visit_time || ''}</p>
          </button>
        )}
      />

      <WorkflowSection
        title="Contractor pricing waiting list"
        empty="No leads waiting on contractor pricing."
        items={data.contractor_pricing_waiting}
        renderItem={(lead) => (
          <button type="button" key={lead.id} onClick={() => navigate(`/leads/${lead.id}`)}
            className="w-full text-left rounded-lg border border-amber-200 bg-amber-50 p-3">
            <p className="text-sm font-medium">{lead.contact_name}</p>
            <p className="text-xs text-slate-500">{lead.address || '—'} · {lead.status}</p>
          </button>
        )}
      />

      <WorkflowSection
        title="Quotes waiting on customer"
        empty="No quotes awaiting customer response."
        items={data.quotes_waiting_on_customer}
        renderItem={(q) => (
          <button type="button" key={q.id} onClick={() => navigate(`/jobs/${q.job_id || q.job?.id}`)}
            className="w-full text-left rounded-lg border border-violet-200 bg-violet-50 p-3">
            <p className="text-sm font-medium">{q.job?.address || `Quote #${q.id}`}</p>
            <p className="text-xs text-slate-500">Status: {q.status}</p>
          </button>
        )}
      />

      <WorkflowSection
        title="Approved jobs needing schedule"
        empty="No approved jobs waiting to schedule."
        items={data.approved_needing_schedule}
        renderItem={(job) => (
          <button type="button" key={job.id} onClick={() => navigate(`/jobs/${job.id}`)}
            className="w-full text-left rounded-lg border border-slate-200 p-3">
            <p className="text-sm font-medium">{job.address}</p>
            <p className="text-xs text-slate-500">{job.customer?.name} · {job.status}</p>
          </button>
        )}
      />

      <WorkflowSection
        title="Jobs missing updates / photos"
        empty="All in-progress jobs have recent updates."
        items={data.jobs_missing_updates}
        renderItem={(job) => (
          <button type="button" key={job.id} onClick={() => navigate(`/jobs/${job.id}`)}
            className="w-full text-left rounded-lg border border-orange-200 bg-orange-50 p-3">
            <p className="text-sm font-medium">{job.address}</p>
            <p className="text-xs text-slate-500">Flagged — no update in configured window</p>
          </button>
        )}
      />

      <WorkflowSection
        title="Customer revision requests"
        empty="No open revision requests."
        items={data.customer_revision_requests}
        renderItem={(job) => (
          <button type="button" key={job.id} onClick={() => navigate(`/jobs/${job.id}`)}
            className="w-full text-left rounded-lg border border-yellow-200 bg-yellow-50 p-3">
            <p className="text-sm font-medium">{job.address}</p>
            <p className="text-xs text-slate-500">{job.status}</p>
          </button>
        )}
      />

      <WorkflowSection
        title="Waiting for completion acceptance"
        empty="No jobs awaiting customer acceptance."
        items={data.awaiting_completion_acceptance}
        renderItem={(job) => (
          <button type="button" key={job.id} onClick={() => navigate(`/jobs/${job.id}`)}
            className="w-full text-left rounded-lg border border-slate-200 p-3">
            <p className="text-sm font-medium">{job.address}</p>
            <p className="text-xs text-slate-500">{job.customer?.name}</p>
          </button>
        )}
      />

      <WorkflowSection
        title="Customer Feedback Needing Follow-up"
        empty="No open customer feedback follow-ups."
        items={data.customer_feedback_follow_up}
        renderItem={(fb) => (
          <button type="button" key={fb.id} onClick={() => navigate(fb.job_id ? `/jobs/${fb.job_id}` : '/jobs')}
            className="w-full text-left rounded-lg border border-rose-200 bg-rose-50 p-3">
            <p className="text-sm font-medium">{fb.star_rating}★ — {fb.job?.address || `Job #${fb.job_id}`}</p>
            <p className="text-xs text-slate-500">{fb.customer?.name || 'Customer'} · {fb.issue_category || 'feedback'} · {fb.follow_up_status}</p>
          </button>
        )}
      />

      <div className="bg-white rounded-xl border border-dashed border-slate-300 p-5">
        <h3 className="font-semibold text-slate-800 mb-1">My payout status</h3>
        <p className="text-sm text-slate-500">{data.payout_status_note || 'Coming soon — Phase 4.'}</p>
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
                <tr
                  key={lead.id}
                  className="border-b border-[#E2E8F0] hover:bg-slate-50 cursor-pointer transition-colors"
                  onClick={() => navigate(`/leads/${lead.id}`)}
                >
                  <td className="px-4 py-2 font-medium">{lead.contact_name}</td>
                  <td className="px-4 py-2 hidden md:table-cell text-[#64748B]">{lead.address || '—'}</td>
                  <td className="px-4 py-2 capitalize">{formatCategory(lead.service_category)}</td>
                  <td className="px-4 py-2"><StatusBadge status={lead.status} /></td>
                  <td className="px-4 py-2 hidden sm:table-cell">{formatDate(lead.site_visit_date)}</td>
                  <td className="px-4 py-2">
                    <span className="text-[#3B82F6] text-xs font-medium">View Lead</span>
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
                <tr
                  key={job.id}
                  className="border-b border-[#E2E8F0] hover:bg-slate-50 cursor-pointer transition-colors"
                  onClick={() => navigate(`/jobs/${job.id}`)}
                >
                  <td className="px-4 py-2">{job.address}</td>
                  <td className="px-4 py-2 capitalize">{formatCategory(job.service_category)}</td>
                  <td className="px-4 py-2">{job.contractor?.name || '—'}</td>
                  <td className="px-4 py-2"><StatusBadge status={job.status} /></td>
                  <td className="px-4 py-2 hidden sm:table-cell">{formatDate(job.scheduled_start_date)}</td>
                  <td className="px-4 py-2">
                    <span className="text-[#3B82F6] text-xs font-medium">View Job</span>
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
