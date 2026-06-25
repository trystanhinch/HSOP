import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Plus } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import LeadForm from '../components/LeadForm';
import { confirmAction, showError, showSuccess } from '../utils/swal';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

export default function Leads() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [leads, setLeads] = useState([]);
  const [meta, setMeta] = useState({});
  const [panelOpen, setPanelOpen] = useState(false);
  const [saving, setSaving] = useState(false);

  const status = searchParams.get('status') || '';
  const category = searchParams.get('category') || '';
  const search = searchParams.get('search') || '';
  const page = searchParams.get('page') || '1';

  const fetchLeads = () => {
    const params = { page };
    if (status) params.status = status;
    if (category) params.category = category;
    if (search) params.search = search;
    api.get('/leads', { params }).then(({ data }) => {
      setLeads(data.data || []);
      setMeta({ current: data.current_page, last: data.last_page, total: data.total });
    }).catch(() => setLeads([]));
  };

  useEffect(() => { fetchLeads(); }, [status, category, search, page]);

  const goToPage = (p) => {
    const next = new URLSearchParams(searchParams);
    next.set('page', String(p));
    setSearchParams(next);
  };

  const setFilter = (key, value) => {
    const next = new URLSearchParams(searchParams);
    if (value) next.set(key, value); else next.delete(key);
    next.delete('page');
    setSearchParams(next);
  };

  const handleCreate = async (form) => {
    const ok = await confirmAction({
      title: 'Create lead?',
      text: `Create a new lead for ${form.contact_name}?`,
      confirmText: 'Yes, create lead',
    });
    if (!ok) return;

    setSaving(true);
    try {
      await api.post('/leads', form);
      setPanelOpen(false);
      await showSuccess('Lead created successfully.');
      fetchLeads();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to create lead.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div>
      <PageHeader title="Leads">
        <button type="button" onClick={() => setPanelOpen(true)}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
          <Plus size={16} /> Add Lead
        </button>
      </PageHeader>

      <div className="bg-white rounded-xl border border-slate-200 p-4 mb-4">
        <div className="flex flex-col sm:flex-row gap-3">
          <select value={status} onChange={(e) => setFilter('status', e.target.value)} className="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
            <option value="">All Statuses</option>
            {['new', 'contacted', 'site_visit_scheduled', 'quote_needed', 'converted', 'lost'].map((s) => (
              <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
            ))}
          </select>
          <select value={category} onChange={(e) => setFilter('category', e.target.value)} className="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
            <option value="">All Categories</option>
            <option value="drywall_paint">Drywall & Paint</option>
            <option value="insulation">Insulation</option>
          </select>
          <div className="flex flex-1 gap-2">
            <input type="text" placeholder="Search leads..." defaultValue={search}
              onKeyDown={(e) => e.key === 'Enter' && setFilter('search', e.target.value)}
              className="flex-1 px-3 py-2 border border-slate-200 rounded-lg text-sm outline-none" />
            <button type="button" onClick={() => setFilter('search', document.querySelector('input[placeholder="Search leads..."]')?.value || '')}
              className="px-4 py-2 bg-slate-100 rounded-lg text-sm font-medium">Search</button>
          </div>
        </div>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm divide-y divide-slate-200">
            <thead className="bg-slate-50">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Contact</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Phone</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500 hidden md:table-cell">Address</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Category</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500 hidden lg:table-cell">PM</th>
                <th className="text-left px-4 py-3 font-medium text-slate-500 hidden sm:table-cell">Date</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {leads.length === 0 ? (
                <tr><td colSpan={7} className="px-4 py-12 text-center text-slate-500">No leads found.</td></tr>
              ) : leads.map((lead) => (
                <tr key={lead.id} className="hover:bg-slate-50 cursor-pointer" onClick={() => window.location.href = `/leads/${lead.id}`}>
                  <td className="px-4 py-3"><Link to={`/leads/${lead.id}`} className="text-blue-600 hover:underline font-medium">{lead.contact_name}</Link></td>
                  <td className="px-4 py-3">{lead.phone || '—'}</td>
                  <td className="px-4 py-3 hidden md:table-cell">{lead.address || '—'}</td>
                  <td className="px-4 py-3 capitalize">{formatCategory(lead.service_category)}</td>
                  <td className="px-4 py-3"><StatusBadge status={lead.status} /></td>
                  <td className="px-4 py-3 hidden lg:table-cell">{lead.assigned_pm?.name || '—'}</td>
                  <td className="px-4 py-3 hidden sm:table-cell">{lead.created_at?.split('T')[0]}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {meta.last > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200 text-sm">
            <span className="text-slate-500">{meta.total} leads</span>
            <div className="flex gap-2">
              <button disabled={meta.current <= 1} onClick={() => goToPage(meta.current - 1)}
                className="px-3 py-1 border rounded-lg disabled:opacity-40">Prev</button>
              <span className="px-2 py-1">Page {meta.current} of {meta.last}</span>
              <button disabled={meta.current >= meta.last} onClick={() => goToPage(meta.current + 1)}
                className="px-3 py-1 border rounded-lg disabled:opacity-40">Next</button>
            </div>
          </div>
        )}
      </div>

      <SlideOverPanel isOpen={panelOpen} onClose={() => setPanelOpen(false)} title="Create Lead">
        <LeadForm onSubmit={handleCreate} onCancel={() => setPanelOpen(false)} saving={saving} />
      </SlideOverPanel>
    </div>
  );
}
