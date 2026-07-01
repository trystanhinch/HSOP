import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Users, Briefcase, FileText, DollarSign, HardHat, TrendingUp, Wallet, Clock } from 'lucide-react';
import api from '../api/axios';
import KPICard from '../components/KPICard';
import StatusBadge from '../components/StatusBadge';
import PageHeader from '../components/PageHeader';

const pipelineStages = [
  { key: 'new', label: 'New Lead', color: 'bg-blue-500' },
  { key: 'site_visit', label: 'Site Visit', color: 'bg-yellow-500' },
  { key: 'quote_needed', label: 'Quote Needed', color: 'bg-orange-500' },
  { key: 'converted', label: 'Converted', color: 'bg-green-500' },
  { key: 'lost', label: 'Lost', color: 'bg-red-500' },
];

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

export default function AdminDashboard() {
  const [data, setData] = useState(null);

  useEffect(() => {
    api.get('/dashboard/admin/kpis').then(({ data: d }) => setData(d)).catch(() => {});
  }, []);

  if (!data) {
    return <div className="text-center py-12 text-[#64748B]">Loading dashboard...</div>;
  }

  return (
    <div className="space-y-6">
      <PageHeader title="Admin Dashboard">
        <select className="px-3 py-2 border border-[#E2E8F0] rounded-md text-sm bg-white text-[#64748B]">
          <option>All Companies</option>
          <option>HSOP Drywall & Paint</option>
        </select>
      </PageHeader>

      <section>
        <h3 className="text-sm font-semibold text-slate-700 mb-3">Pipeline</h3>
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
          <KPICard title="New Leads" value={data.new_leads ?? 0} icon={Users} color="#3B82F6" />
          <KPICard title="Needing Followup" value={data.leads_needing_followup ?? 0} icon={Clock} color="#F97316" />
          <KPICard title="Awaiting Price" value={data.jobs_awaiting_price ?? 0} icon={HardHat} color="#64748B" />
          <KPICard title="Quotes to Review" value={data.quotes_needing_review ?? 0} icon={FileText} color="#EAB308" />
          <KPICard title="Quotes Sent" value={data.quotes_sent ?? 0} icon={FileText} color="#3B82F6" />
        </div>
      </section>

      <section>
        <h3 className="text-sm font-semibold text-slate-700 mb-3">Active Work</h3>
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
          <KPICard title="Need Schedule" value={data.approved_needing_schedule ?? 0} icon={Clock} color="#F97316" />
          <KPICard title="Scheduled" value={data.scheduled_jobs ?? 0} icon={Briefcase} color="#EAB308" />
          <KPICard title="In Progress" value={data.jobs_in_progress ?? 0} icon={Briefcase} color="#22C55E" />
          <KPICard title="Ready for Review" value={data.jobs_ready_for_review ?? 0} icon={Briefcase} color="#8B5CF6" />
          <KPICard title="Completed" value={data.completed_jobs ?? 0} icon={Briefcase} color="#22C55E" />
        </div>
      </section>

      <section>
        <h3 className="text-sm font-semibold text-slate-700 mb-3">Milestone 3 Pipeline</h3>
        <div className="grid grid-cols-2 lg:grid-cols-6 gap-4">
          <KPICard title="Site Visits Today" value={data.site_visits_today ?? 0} icon={Clock} color="#6366F1" />
          <KPICard title="Site Visits This Week" value={data.site_visits_this_week ?? 0} icon={Clock} color="#8B5CF6" />
          <KPICard title="Pending Approval" value={data.pending_approval ?? 0} icon={Briefcase} color="#F97316" />
          <KPICard title="Revisions" value={data.revision_requested ?? 0} icon={Briefcase} color="#EF4444" />
          <KPICard title="Payment Pending" value={data.payment_pending ?? 0} icon={DollarSign} color="#EAB308" />
          <KPICard title="E-Transfer to Confirm" value={data.etransfer_to_confirm ?? 0} icon={DollarSign} color="#3B82F6" />
        </div>
      </section>

      <section>
        <h3 className="text-sm font-semibold text-slate-700 mb-3">Financial</h3>
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
          <KPICard title="Awaiting Payment" value={data.jobs_awaiting_payment ?? 0} icon={DollarSign} color="#EF4444" />
          <KPICard title="Pending Payouts" value={data.payouts_pending ?? 0} icon={Wallet} color="#F97316" />
          <KPICard title="Profit This Month" value={`$${Number(data.total_profit_month || 0).toFixed(2)}`} icon={TrendingUp} color="#22C55E" />
          <KPICard title="Total Profit" value={`$${Number(data.total_profit_all_time || 0).toFixed(2)}`} icon={DollarSign} color="#22C55E" />
          <KPICard title="Revenue Collected" value={`$${Number(data.total_collected_revenue || 0).toFixed(2)}`} icon={Wallet} color="#3B82F6" />
        </div>
      </section>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPICard title="Total Leads" value={data.total_leads} icon={Users} color="#3B82F6" />
        <KPICard title="Active Jobs" value={data.active_jobs} icon={Briefcase} color="#22C55E" />
        <KPICard title="Total Contractors" value={data.total_contractors} icon={HardHat} color="#F97316" />
        <KPICard title="Total Customers" value={data.total_customers} icon={Users} color="#3B82F6" />
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Lead Pipeline</h3>
        <div className="flex flex-wrap gap-3">
          {pipelineStages.map(({ key, label, color }) => (
            <div key={key} className="flex items-center gap-2 px-4 py-2 rounded-full bg-slate-50 border border-[#E2E8F0]">
              <span className={`w-2.5 h-2.5 rounded-full ${color}`} />
              <span className="text-sm text-[#64748B]">{label}</span>
              <span className="text-sm font-bold text-[#0F172A]">{data.pipeline?.[key] ?? 0}</span>
            </div>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] overflow-hidden">
          <div className="px-4 py-3 border-b border-[#E2E8F0] bg-slate-50">
            <h3 className="text-sm font-semibold text-[#0F172A]">Recent Leads</h3>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b border-[#E2E8F0]">
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium">Customer</th>
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium">Category</th>
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium">Status</th>
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium hidden sm:table-cell">PM</th>
                </tr>
              </thead>
              <tbody>
                {(data.recent_leads || []).map((lead) => (
                  <tr key={lead.id} className="border-b border-[#E2E8F0] hover:bg-slate-50">
                    <td className="px-4 py-2">
                      <Link to={`/leads/${lead.id}`} className="text-[#3B82F6] hover:underline">
                        {lead.contact_name || lead.customer?.name || '—'}
                      </Link>
                    </td>
                    <td className="px-4 py-2 capitalize">{formatCategory(lead.service_category)}</td>
                    <td className="px-4 py-2"><StatusBadge status={lead.status} /></td>
                    <td className="px-4 py-2 hidden sm:table-cell">{lead.assigned_pm?.name || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] overflow-hidden">
          <div className="px-4 py-3 border-b border-[#E2E8F0] bg-slate-50">
            <h3 className="text-sm font-semibold text-[#0F172A]">Recent Jobs</h3>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b border-[#E2E8F0]">
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium">Customer</th>
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium">Contractor</th>
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium">Status</th>
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium hidden sm:table-cell">Start</th>
                </tr>
              </thead>
              <tbody>
                {(data.recent_jobs || []).map((job) => (
                  <tr key={job.id} className="border-b border-[#E2E8F0] hover:bg-slate-50">
                    <td className="px-4 py-2">
                      <Link to={`/jobs/${job.id}`} className="text-[#3B82F6] hover:underline">
                        {job.customer?.name || '—'}
                      </Link>
                    </td>
                    <td className="px-4 py-2">{job.contractor?.name || '—'}</td>
                    <td className="px-4 py-2"><StatusBadge status={job.status} /></td>
                    <td className="px-4 py-2 hidden sm:table-cell">{job.scheduled_start_date || job.start_date || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}
