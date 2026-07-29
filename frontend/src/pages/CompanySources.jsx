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
  intake_allow_patterns: '',
  marketing_cost_monthly: '',
  status: 'active',
  priority: '100',
  parser_type: 'lead_email_v1',
  parser_version: '1.0',
  fallback_behavior: 'category_then_quarantine',
};

export default function CompanySources() {
  const [sources, setSources] = useState([]);
  const [pms, setPms] = useState([]);
  const [panelOpen, setPanelOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [testEmail, setTestEmail] = useState('');
  const [testResult, setTestResult] = useState(null);
  const [testing, setTesting] = useState(false);

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
      intake_allow_patterns: (source.intake_allow_patterns || []).join(', '),
      marketing_cost_monthly: source.marketing_cost_monthly ?? '',
      status: source.status || 'active',
      priority: String(source.priority ?? 100),
      parser_type: source.parser_type || 'lead_email_v1',
      parser_version: source.parser_version || '1.0',
      fallback_behavior: source.fallback_behavior || 'category_then_quarantine',
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
      intake_allow_patterns: form.intake_allow_patterns
        ? form.intake_allow_patterns.split(',').map((s) => s.trim()).filter(Boolean)
        : [],
      default_pm_id: form.default_pm_id || null,
      marketing_cost_monthly: form.marketing_cost_monthly === '' ? null : Number(form.marketing_cost_monthly),
      priority: Number(form.priority) || 100,
    };

    try {
      if (editing) {
        await api.put(`/company-sources/${editing.id}`, payload);
        await showSuccess('Company source updated (audited + versioned).');
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

  const runTestParser = async () => {
    if (!testEmail.trim()) return;
    setTesting(true);
    try {
      const { data } = await api.post('/company-sources/test-parser', { raw_email: testEmail });
      setTestResult(data);
    } catch (err) {
      await showError(err.response?.data?.message || 'Test parser failed');
      setTestResult(null);
    } finally {
      setTesting(false);
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
        Matching rules for Gmail/intake emails. Ambiguous or unmatched messages go to quarantine (A-02) — they are never guessed into a lead.
      </p>

      <div className="mb-6 bg-white border border-slate-200 rounded-xl p-4 space-y-3">
        <h3 className="font-semibold text-slate-800 text-sm">Test Parser</h3>
        <p className="text-xs text-slate-500">Paste a sample email. Shows extracted fields and which source rule would match — does not create a lead.</p>
        <textarea
          value={testEmail}
          onChange={(e) => setTestEmail(e.target.value)}
          rows={6}
          className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono"
          placeholder={"From: forms@example.com\nSubject: New lead\n\nName: Jane Doe\nPhone: 604-555-0100\n..."}
        />
        <button type="button" disabled={testing || !testEmail.trim()} onClick={runTestParser}
          className="px-3 py-1.5 bg-slate-800 text-white text-sm rounded-lg disabled:opacity-50">
          {testing ? 'Testing…' : 'Run Test Parser'}
        </button>
        {testResult && (
          <div className="text-sm bg-slate-50 border border-slate-200 rounded-lg p-3 space-y-1">
            <p><span className="text-slate-500">Action:</span> {testResult.evaluation?.action} — {testResult.evaluation?.reason}</p>
            <p><span className="text-slate-500">Parser:</span> {testResult.parser_type} v{testResult.parser_version} ({testResult.email_format})</p>
            <p><span className="text-slate-500">Matched source:</span> {testResult.matched_source
              ? `${testResult.matched_source.company_name} (#${testResult.matched_source.id}) via ${testResult.match_method} / “${testResult.matched_needle}”`
              : 'None — would quarantine'}</p>
            <p className="text-xs text-slate-600 whitespace-pre-wrap">
              Extracted: {JSON.stringify(testResult.extracted, null, 2)}
            </p>
            <p className="text-xs text-green-700">creates_lead = {String(testResult.creates_lead)}</p>
          </div>
        )}
      </div>

      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="w-full min-w-[1100px] text-sm">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-3 py-3 font-medium text-slate-500">Company / Domain</th>
              <th className="text-left px-3 py-3 font-medium text-slate-500">Sender / Patterns</th>
              <th className="text-left px-3 py-3 font-medium text-slate-500">Parser</th>
              <th className="text-left px-3 py-3 font-medium text-slate-500">Brand / PM</th>
              <th className="text-left px-3 py-3 font-medium text-slate-500">Priority / Fallback</th>
              <th className="text-left px-3 py-3 font-medium text-slate-500">Health</th>
              <th className="text-left px-3 py-3 font-medium text-slate-500">Status</th>
              <th className="text-right px-3 py-3 font-medium text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {sources.map((s) => (
              <tr key={s.id} className="hover:bg-slate-50 align-top">
                <td className="px-3 py-3">
                  <p className="font-medium">{s.company_name}</p>
                  <p className="text-xs text-slate-500">{s.domain || '— no domain —'}</p>
                </td>
                <td className="px-3 py-3 text-xs text-slate-600">
                  <p>{s.sender_identity || '—'}</p>
                  <p className="text-slate-400">{(s.intake_allow_patterns || []).join(', ') || 'no subject patterns'}</p>
                </td>
                <td className="px-3 py-3 text-xs">
                  {s.parser_type || 'lead_email_v1'} v{s.parser_version || '1.0'}
                </td>
                <td className="px-3 py-3 text-xs">
                  <p>{s.target_brand?.company_name || '—'}</p>
                  <p className="text-slate-500">{s.default_pm?.name || 'no default PM'}</p>
                </td>
                <td className="px-3 py-3 text-xs">
                  <p>#{s.priority ?? 100}</p>
                  <p className="text-slate-500">{s.fallback_behavior || 'category_then_quarantine'}</p>
                </td>
                <td className="px-3 py-3 text-xs text-slate-600">
                  <p>Last recv: {s.health?.last_received_at ? new Date(s.health.last_received_at).toLocaleString() : '—'}</p>
                  <p>OK: {s.health?.success_count ?? 0} · Ignored: {s.health?.ignored_count ?? 0} · Fail: {s.health?.failure_count ?? 0}</p>
                  {(s.health?.recent_errors || []).slice(0, 1).map((err) => (
                    <p key={err.id} className="text-amber-700 truncate max-w-[180px]" title={err.quarantine_reason}>{err.quarantine_reason}</p>
                  ))}
                </td>
                <td className="px-3 py-3"><StatusBadge status={s.status} /></td>
                <td className="px-3 py-3 text-right space-x-2 whitespace-nowrap">
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
              <tr><td colSpan={8} className="px-4 py-8 text-center text-slate-500">No company sources yet.</td></tr>
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
            <label className="text-xs text-slate-500 block mb-1">Sender identity</label>
            <input value={form.sender_identity} onChange={(e) => setForm({ ...form, sender_identity: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="forms@company.com" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Subject / body allow patterns (comma-separated)</label>
            <input value={form.intake_allow_patterns} onChange={(e) => setForm({ ...form, intake_allow_patterns: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="new lead, contact form" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Service categories (comma-separated)</label>
            <input value={form.service_categories} onChange={(e) => setForm({ ...form, service_categories: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="drywall, painting" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs text-slate-500 block mb-1">Priority (lower = first)</label>
              <input type="number" min="1" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="text-xs text-slate-500 block mb-1">Fallback</label>
              <select value={form.fallback_behavior} onChange={(e) => setForm({ ...form, fallback_behavior: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                <option value="category_then_quarantine">Category then quarantine</option>
                <option value="none">No fallback (quarantine)</option>
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs text-slate-500 block mb-1">Parser type</label>
              <input value={form.parser_type} onChange={(e) => setForm({ ...form, parser_type: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="text-xs text-slate-500 block mb-1">Parser version</label>
              <input value={form.parser_version} onChange={(e) => setForm({ ...form, parser_version: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Default PM</label>
            <select value={form.default_pm_id} onChange={(e) => setForm({ ...form, default_pm_id: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <option value="">None</option>
              {pms.map((pm) => <option key={pm.id} value={pm.id}>{pm.name}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Google review URL</label>
            <input type="url" value={form.google_review_url} onChange={(e) => setForm({ ...form, google_review_url: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Parsing notes</label>
            <textarea value={form.lead_parsing_rule} onChange={(e) => setForm({ ...form, lead_parsing_rule: e.target.value })} rows={2}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
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
