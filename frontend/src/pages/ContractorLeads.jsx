import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { formatDate, formatTime } from '../utils/formatDate';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

export default function ContractorLeads() {
  const navigate = useNavigate();
  const [leads, setLeads] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/contractor/leads')
      .then(({ data }) => setLeads(data.data || []))
      .catch(() => setLeads([]))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div>
      <PageHeader title="My Leads" subtitle="Assigned leads — submit pricing before these become jobs" />

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm divide-y divide-slate-200">
            <thead className="bg-slate-50">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Customer</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Address</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Category</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500 hidden md:table-cell">Site Visit</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Pricing</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {loading ? (
                <tr><td colSpan={6} className="px-4 py-12 text-center text-slate-500">Loading...</td></tr>
              ) : leads.length === 0 ? (
                <tr><td colSpan={6} className="px-4 py-12 text-center text-slate-500">No assigned leads right now.</td></tr>
              ) : leads.map((lead) => (
                <tr
                  key={lead.id}
                  className="hover:bg-slate-50 cursor-pointer"
                  onClick={() => navigate(`/leads/${lead.id}`)}
                >
                  <td className="px-4 py-3 font-medium text-blue-600">{lead.contact_name}</td>
                  <td className="px-4 py-3 text-slate-600">{lead.address || <span className="text-amber-600">Not set</span>}</td>
                  <td className="px-4 py-3 capitalize">{formatCategory(lead.service_category) || '—'}</td>
                  <td className="px-4 py-3"><StatusBadge status={lead.status} /></td>
                  <td className="px-4 py-3 hidden md:table-cell text-slate-500">
                    {lead.site_visit_date
                      ? `${formatDate(lead.site_visit_date)}${lead.site_visit_time ? ` ${formatTime(lead.site_visit_time)}` : ''}`
                      : '—'}
                  </td>
                  <td className="px-4 py-3">
                    {lead.contractor_price ? (
                      <span className="text-xs text-green-700 bg-green-100 px-2 py-1 rounded-full">
                        ${Number(lead.contractor_price).toFixed(2)} submitted
                      </span>
                    ) : (
                      <span className="text-xs text-orange-700 bg-orange-100 px-2 py-1 rounded-full">Price needed</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
