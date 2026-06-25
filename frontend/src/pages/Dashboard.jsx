import { useEffect, useState } from 'react';
import { Users, Briefcase, FileText, DollarSign } from 'lucide-react';
import api from '../api/axios';
import KPICard from '../components/KPICard';

export default function Dashboard() {
  const [kpis, setKpis] = useState({
    total_leads: 0,
    active_jobs: 0,
    pending_quotes: 0,
    revenue_this_month: 0,
    pipeline: { new: 0, site_visit: 0, quoted: 0, active: 0, completed: 0 },
  });

  useEffect(() => {
    api.get('/dashboard/kpis').then(({ data }) => setKpis(data)).catch(() => {});
  }, []);

  const pipelineStages = [
    { key: 'new', label: 'New Lead' },
    { key: 'site_visit', label: 'Site Visit' },
    { key: 'quoted', label: 'Quoted' },
    { key: 'active', label: 'Active' },
    { key: 'completed', label: 'Completed' },
  ];

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <KPICard title="Total Leads" value={kpis.total_leads} icon={Users} color="#3B82F6" />
        <KPICard title="Active Jobs" value={kpis.active_jobs} icon={Briefcase} color="#22C55E" />
        <KPICard title="Pending Quotes" value={kpis.pending_quotes} icon={FileText} color="#EAB308" />
        <KPICard title="Revenue This Month" value={`$${kpis.revenue_this_month}`} icon={DollarSign} color="#8B5CF6" />
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Pipeline</h3>
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
          {pipelineStages.map(({ key, label }) => (
            <div key={key} className="text-center p-4 rounded-lg bg-slate-50 border border-[#E2E8F0]">
              <p className="text-xs text-[#64748B] mb-2">{label}</p>
              <span className="inline-flex items-center justify-center w-8 h-8 rounded-full bg-[#3B82F6] text-white text-sm font-bold">
                {kpis.pipeline?.[key] ?? 0}
              </span>
            </div>
          ))}
        </div>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Recent Activity</h3>
        <p className="text-sm text-[#64748B] text-center py-8">No recent activity</p>
      </div>
    </div>
  );
}
