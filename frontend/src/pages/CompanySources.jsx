import { useEffect, useState } from 'react';
import { Pencil, Plus } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const statusOptions = ['active', 'paused', 'testing', 'archived'];

const emptyForm = {
  company_name: '',
  domain: '',
  service_categories: '',
  google_review_url: '',
  default_pm_id: '',
  sender_identity: '',
  lead_parsing_rule: '',
  marketing_cost_monthly: '',
  status: 'active',
};

export default function CompanySources() {
  const [sources, setSources] = useState([]);
  const [pms, setPms] = useState([]);
  const [panelOpen, setPanelOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);

  const load = () => {
    api.get('/company-sources?include_archived=1').then(({ data }) => setSources(data)).catch(() => setSources([]));
  };

  useEffect(() => {
    load();
    api.get('/users/pms').then(({ data }) => setPms(data)).catch(() => setPms([]));
  }, []);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setPanelOpen(true);
  };

  const openEdit = (source) => {
    setEditing(source);
    setForm({
      company_name: source.company_name || '',
      domain: source.domain || '',
      service_categories: (source.service_categories || []).join(', '),
      google_review_url: source.google_review_url || '',
      default_pm_id: source.default_pm_id || '',
      sender_identity: source.sender_identity || '',
      lead_parsing_rule: source.lead_parsing_rule || '',
      marketing_cost_monthly: source.marketing_cost_monthly ?? '',
      status: source.status || 'active',
    });
    setPanelOpen(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    const payload = {
      ...form,
      service_categories: form.service_categories
        ? form.service_categories.split(',').map((s) => s.trim()).filter(Boolean)
        : [],
      default_pm_id: form.default_pm_id || null,
      marketing_cost_monthly: form.marketing_cost_monthly === '' ? null : Number(form.marketing_cost_monthly),
    };

    try {
      if (editing) {
        await api.put(`/company-sources/${editing.id}`, payload);
        await showSuccess('Company source updated.');
      } else {
        await api.post('/company-sources', payload);
        await showSuccess('Company source created.');
      }
      setPanelOpen(false);
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save.');
    } finally {
      setSaving(false);
    }
  };

  const archive = async (source) => {
    const ok = await confirmAction({
      title: 'Archive this source?',
      text: 'It will be hidden from active lists but retained in the database.',
      confirmText: 'Archive',
    });
    if (!ok) return;
    try {
      await api.delete(`/company-sources/${source.id}`);
      await showSuccess('Company source archived.');
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to archive.');
    }
  };

  return (
    <div>
      <PageHeader title="Company Sources">
        <button type="button" onClick={openCreate}
          className="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg">
          <Plus size={16} /> Add Source
        </button>
      </PageHeader>
      <p className="text-sm text-slate-500 mb-4">
        Configure lead intake sources. When a lead email is parsed, the system matches the source field to these records and uses the default PM for notifications.
      </p>

      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="w-full min-w-[800px] text-sm">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-slate-500">Company</th>
              <th className="text-left px-4 py-3 font-medium text-slate-500">Domain</th>
              <th className="text-left px-4 py-3 font-medium text-slate-500 hidden md:table-cell">Default PM</th>
              <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
              <th className="text-right px-4 py-3 font-medium text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {sources.map((s) => (
              <tr key={s.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium">{s.company_name}</td>
                <td className="px-4 py-3">{s.domain || '—'}</td>
                <td className="px-4 py-3 hidden md:table-cell">{s.default_pm?.name || '—'}</td>
                <td className="px-4 py-3"><StatusBadge status={s.status} /></td>
                <td className="px-4 py-3 text-right space-x-2">
                  <button type="button" onClick={() => openEdit(s)} className="text-blue-600 text-xs font-medium inline-flex items-center gap-1">
                    <Pencil size={14} /> Edit
                  </button>
                  {s.status !== 'archived' && (
                    <button type="button" onClick={() => archive(s)} className="text-red-600 text-xs font-medium">Archive</button>
                  )}
                </td>
              </tr>
            ))}
            {sources.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">No company sources yet.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      <SlideOverPanel isOpen={panelOpen} onClose={() => setPanelOpen(false)} title={editing ? 'Edit Company Source' : 'New Company Source'}>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="text-xs text-slate-500 block mb-1">Company name *</label>
            <input required value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Domain</label>
            <input value={form.domain} onChange={(e) => setForm({ ...form, domain: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="example.com" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Service categories (comma-separated)</label>
            <input value={form.service_categories} onChange={(e) => setForm({ ...form, service_categories: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="drywall, painting" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Google review URL</label>
            <input type="url" value={form.google_review_url} onChange={(e) => setForm({ ...form, google_review_url: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="https://g.page/..." />
            <p className="text-xs text-slate-400 mt-1">Full public Google review link customers can use to leave a review.</p>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Default PM</label>
            <select value={form.default_pm_id} onChange={(e) => setForm({ ...form, default_pm_id: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <option value="">None</option>
              {pms.map((pm) => <option key={pm.id} value={pm.id}>{pm.name}</option>)}
            </select>
            <p className="text-xs text-slate-400 mt-1">PM who receives new-lead notifications for leads from this source. If unset, Admin gets a Next Action to assign a PM.</p>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Sender identity</label>
            <input value={form.sender_identity} onChange={(e) => setForm({ ...form, sender_identity: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="forms@company.com or display name" />
            <p className="text-xs text-slate-400 mt-1">Email address or name shown on outbound messages for this source.</p>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Lead parsing rule (optional notes)</label>
            <textarea value={form.lead_parsing_rule} onChange={(e) => setForm({ ...form, lead_parsing_rule: e.target.value })} rows={2}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
              placeholder="Notes about how leads from this source are formatted (for reference)" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Marketing cost (monthly)</label>
            <input type="number" min="0" step="0.01" value={form.marketing_cost_monthly}
              onChange={(e) => setForm({ ...form, marketing_cost_monthly: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Status</label>
            <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
              {statusOptions.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <button type="submit" disabled={saving}
            className="w-full bg-blue-600 text-white rounded-lg py-2.5 text-sm font-medium disabled:opacity-50">
            {saving ? 'Saving...' : 'Save'}
          </button>
        </form>
      </SlideOverPanel>
    </div>
  );
}
