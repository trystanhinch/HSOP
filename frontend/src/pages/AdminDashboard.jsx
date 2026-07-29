import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Users, Briefcase, FileText, DollarSign, HardHat, TrendingUp, Wallet, Clock, AlertTriangle } from 'lucide-react';
import api from '../api/axios';
import KPICard from '../components/KPICard';
import StatusBadge from '../components/StatusBadge';
import PageHeader from '../components/PageHeader';
import { formatDate } from '../utils/formatDate';

const pipelineStages = [
  { key: 'new', label: 'New Lead', color: 'bg-blue-500', to: '/leads?status=new' },
  { key: 'site_visit', label: 'Site Visit', color: 'bg-yellow-500', to: '/leads?status=site_visit_scheduled' },
  { key: 'quote_needed', label: 'Quote Needed', color: 'bg-orange-500', to: '/leads?status=quote_needed' },
  { key: 'converted', label: 'Converted', color: 'bg-green-500', to: '/leads?status=converted' },
  { key: 'lost', label: 'Lost', color: 'bg-red-500', to: '/leads?status=lost' },
];

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function money(n) {
  return `$${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function AdminDashboard() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [brands, setBrands] = useState([]);
  const [brandId, setBrandId] = useState('');

  const load = (nextBrandId = brandId) => {
    const params = {};
    if (nextBrandId) params.brand_id = nextBrandId;
    api.get('/dashboard/admin/kpis', { params }).then(({ data: d }) => setData(d)).catch(() => {});
  };

  useEffect(() => {
    api.get('/availability/brands').then(({ data: d }) => setBrands(d || [])).catch(() => setBrands([]));
    load('');
  }, []);

  if (!data) {
    return <div className="text-center py-12 text-[#64748B]">Loading dashboard...</div>;
  }

  const refreshed = data.refreshed_at
    ? new Date(data.refreshed_at).toLocaleString()
    : (data.financial_refreshed_at ? new Date(data.financial_refreshed_at).toLocaleString() : null);

  const brandLabel = brandId
    ? (brands.find((b) => String(b.id) === String(brandId))?.company_name || brands.find((b) => String(b.id) === String(brandId))?.name || `Brand #${brandId}`)
    : 'All brands';

  return (
    <div className="space-y-6">
      <PageHeader title="Admin Dashboard">
        <div className="flex flex-wrap items-center gap-2">
          <label className="text-xs text-slate-500" htmlFor="dash-brand-filter">Brand</label>
          <select
            id="dash-brand-filter"
            value={brandId}
            onChange={(e) => {
              const v = e.target.value;
              setBrandId(v);
              load(v);
            }}
            className="border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white"
          >
            <option value="">All brands</option>
            {brands.map((b) => (
              <option key={b.id} value={b.id}>{b.company_name || b.name || b.domain}</option>
            ))}
          </select>
        </div>
      </PageHeader>

      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
        <p>
          Active filters: <span className="font-medium text-slate-700">{brandLabel}</span>
          {' · '}scope: production only · date range: all time
        </p>
        {refreshed && <p>Last refreshed: {refreshed}</p>}
      </div>

      <section>
        <h3 className="text-sm font-semibold text-slate-700 mb-3">Pipeline</h3>
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
          <KPICard title="New Leads" value={data.new_leads ?? 0} icon={Users} color="#3B82F6" to={`/leads?status=new${brandId ? `&brand_id=${brandId}` : ''}`} />
          <KPICard title="Needs Review" value={data.leads_needing_review ?? 0} icon={AlertTriangle} color="#F59E0B" to={`/leads?status=needs_review${brandId ? `&brand_id=${brandId}` : ''}`} />
          <KPICard title="Needing Followup" value={data.leads_needing_followup ?? 0} icon={Clock} color="#F97316" to={`/leads?status=contacted${brandId ? `&brand_id=${brandId}` : ''}`} />
          <KPICard title="Awaiting Price" value={data.jobs_awaiting_price ?? 0} icon={HardHat} color="#64748B" to={`/jobs?contractor_price_status=pending${brandId ? `&brand_id=${brandId}` : ''}`} />
          <KPICard title="Quotes to Review" value={data.quotes_needing_review ?? 0} icon={FileText} color="#EAB308" to="/quotes?status=draft" />
          <KPICard title="Quotes Sent" value={data.quotes_sent ?? 0} icon={FileText} color="#3B82F6" to="/quotes?status=sent" />
        </div>
      </section>

      <section>
        <h3 className="text-sm font-semibold text-slate-700 mb-3">Active Work</h3>
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
          <KPICard title="Need Schedule" value={data.approved_needing_schedule ?? 0} icon={Clock} color="#F97316" to="/jobs?status=needs_schedule" />
          <KPICard title="Scheduled" value={data.scheduled_jobs ?? 0} icon={Briefcase} color="#EAB308" to="/jobs?status=scheduled" />
          <KPICard title="In Progress" value={data.jobs_in_progress ?? 0} icon={Briefcase} color="#22C55E" to="/jobs?status=in_progress" />
          <KPICard title="Ready for Review" value={data.jobs_ready_for_review ?? 0} icon={Briefcase} color="#8B5CF6" to="/jobs?status=completion_pending" />
          <KPICard title="Completed" value={data.completed_jobs ?? 0} icon={Briefcase} color="#22C55E" to="/jobs?status=completed" />
        </div>
      </section>

      <section>
        <h3 className="text-sm font-semibold text-slate-700 mb-3">Milestone 3 Pipeline</h3>
        <div className="grid grid-cols-2 lg:grid-cols-6 gap-4">
          <KPICard title="Site Visits Today" value={data.site_visits_today ?? 0} icon={Clock} color="#6366F1" to="/schedule" />
          <KPICard title="Site Visits This Week" value={data.site_visits_this_week ?? 0} icon={Clock} color="#8B5CF6" to="/schedule" />
          <KPICard title="Pending Approval" value={data.pending_approval ?? 0} icon={Briefcase} color="#F97316" to="/jobs?status=pending_customer_approval" />
          <KPICard title="Revisions" value={data.revision_requested ?? 0} icon={Briefcase} color="#EF4444" to="/jobs?status=revision_requested" />
          <KPICard title="Payment Pending" value={data.payment_pending ?? 0} icon={DollarSign} color="#EAB308" to="/invoices?status=awaiting_payment" />
          <KPICard title="E-Transfer to Confirm" value={data.etransfer_to_confirm ?? 0} icon={DollarSign} color="#3B82F6" to="/jobs?status=etransfer_pending_confirmation" />
        </div>
      </section>

      <section>
        <div className="flex flex-wrap items-baseline justify-between gap-2 mb-3">
          <h3 className="text-sm font-semibold text-slate-700">Financial</h3>
          {data.financial_refreshed_at && (
            <p className="text-xs text-slate-400">
              Ledger last refreshed: {new Date(data.financial_refreshed_at).toLocaleString()}
              {data.financial_filters?.basis ? ` · basis: ${data.financial_filters.basis}` : ''}
            </p>
          )}
        </div>
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
          <KPICard title="Accounts Receivable" value={money(data.accounts_receivable)} icon={DollarSign} color="#EF4444" to="/ledger?metric=accounts_receivable" />
          <KPICard title="Pending Payouts" value={data.payouts_pending ?? 0} icon={Wallet} color="#F97316" to="/payouts" />
          <KPICard title="Projected Profit (This Month)" value={money(data.projected_profit_month)} icon={TrendingUp} color="#22C55E" to="/ledger?metric=projected_profit_month" />
          <KPICard title="Projected Profit (All Time)" value={money(data.projected_profit_all_time)} icon={TrendingUp} color="#16A34A" to="/ledger?metric=projected_profit" />
          <KPICard title="Realized Profit (All Time)" value={money(data.realized_profit_all_time)} icon={DollarSign} color="#0EA5E9" to="/ledger?metric=realized_profit" />
          <KPICard title="Collected Revenue (ex-GST)" value={money(data.total_collected_revenue)} icon={Wallet} color="#3B82F6" to="/ledger?metric=collected_revenue" />
        </div>
        {(data.incomplete_cost_quote_count ?? 0) > 0 && (
          <button
            type="button"
            onClick={() => navigate('/ledger?metric=incomplete_cost_data')}
            className="mt-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"
          >
            {data.incomplete_cost_quote_count} quote(s) flagged incomplete cost data (excluded from Projected Profit)
          </button>
        )}
      </section>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPICard title="Total Leads" value={data.total_leads} icon={Users} color="#3B82F6" to="/leads" />
        <KPICard title="Active Jobs" value={data.active_jobs} icon={Briefcase} color="#22C55E" to="/jobs?status=active" />
        <KPICard title="Total Contractors" value={data.total_contractors} icon={HardHat} color="#F97316" to="/contractors" />
        <KPICard title="Total Customers" value={data.total_customers} icon={Users} color="#3B82F6" to="/customers" />
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Lead Pipeline</h3>
        <div className="flex flex-wrap gap-3">
          {pipelineStages.map(({ key, label, color, to }) => (
            <button
              type="button"
              key={key}
              onClick={() => navigate(to)}
              className="flex items-center gap-2 px-4 py-2 rounded-full bg-slate-50 border border-[#E2E8F0] hover:border-blue-300 hover:bg-blue-50"
            >
              <span className={`w-2.5 h-2.5 rounded-full ${color}`} />
              <span className="text-sm text-[#64748B]">{label}</span>
              <span className="text-sm font-bold text-[#0F172A]">{data.pipeline?.[key] ?? 0}</span>
            </button>
          ))}
        </div>
        <p className="text-[11px] text-slate-400 mt-3">
          Pipeline &quot;New&quot; uses the same definition as the New Leads card (status=new, productionOnly
          {brandId ? ', brand filtered' : ''}).
        </p>
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
                  <th className="text-left px-4 py-2 text-[#64748B] font-medium">Date</th>
                </tr>
              </thead>
              <tbody>
                {(data.recent_leads || []).map((lead) => (
                  <tr key={lead.id} className="border-b border-[#E2E8F0] hover:bg-slate-50 cursor-pointer" onClick={() => navigate(`/leads/${lead.id}`)}>
                    <td className="px-4 py-2">{lead.contact_name}</td>
                    <td className="px-4 py-2 capitalize">{formatCategory(lead.service_category)}</td>
                    <td className="px-4 py-2"><StatusBadge status={lead.status} /></td>
                    <td className="px-4 py-2 text-slate-500">{formatDate(lead.created_at)}</td>
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
