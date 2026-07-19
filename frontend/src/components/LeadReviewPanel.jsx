import { useEffect, useState } from 'react';
import { AlertTriangle, ChevronDown, ChevronRight } from 'lucide-react';
import api from '../api/axios';
import { showError, showSuccess } from '../utils/swal';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function lowConfidenceFields(parseMetadata) {
  const confidence = parseMetadata?.field_confidence || {};
  return Object.entries(confidence)
    .filter(([, score]) => score < 0.7)
    .map(([field]) => field.replace(/_/g, ' '));
}

function missingFields(lead) {
  const missing = [];
  if (!lead.contact_name) missing.push('contact name');
  if (!lead.phone && !lead.email) missing.push('phone or email');
  if (!lead.service_category) missing.push('service category');
  if (!lead.project_description) missing.push('project description');
  return missing;
}

export default function LeadReviewPanel({ lead, onResolved }) {
  const [form, setForm] = useState({
    contact_name: lead.contact_name || '',
    phone: lead.phone || '',
    email: lead.email || '',
    address: lead.address || '',
    service_category: lead.service_category || '',
    project_description: lead.project_description || '',
    assigned_pm_id: lead.assigned_pm_id || '',
  });
  const [pms, setPms] = useState([]);
  const [saving, setSaving] = useState(false);
  const [showRawEmail, setShowRawEmail] = useState(false);

  useEffect(() => {
    api.get('/users/pms').then(({ data }) => setPms(data)).catch(() => setPms([]));
  }, []);

  useEffect(() => {
    setForm({
      contact_name: lead.contact_name || '',
      phone: lead.phone || '',
      email: lead.email || '',
      address: lead.address || '',
      service_category: lead.service_category || '',
      project_description: lead.project_description || '',
      assigned_pm_id: lead.assigned_pm_id || '',
    });
  }, [lead]);

  const ambiguous = lead.parse_metadata?.classification?.flags?.ambiguous_service;
  const lowConf = lowConfidenceFields(lead.parse_metadata);
  const missing = missingFields({ ...lead, ...form });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = {
        ...form,
        service_category: form.service_category || null,
        assigned_pm_id: form.assigned_pm_id || null,
      };
      await api.post(`/leads/${lead.id}/resolve-review`, payload);
      await showSuccess('Lead review cleared. Lead is now available for normal workflow.');
      onResolved?.();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to resolve review.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="bg-amber-50 border border-amber-200 rounded-xl p-6 mb-6">
      <div className="flex items-start gap-3 mb-4">
        <AlertTriangle className="text-amber-600 shrink-0 mt-0.5" size={20} />
        <div>
          <h3 className="font-semibold text-amber-900">Needs Manual Review</h3>
          <p className="text-sm text-amber-800 mt-1">
            This lead was created from email intake but could not be fully parsed. Review the fields below, correct anything missing or ambiguous, then confirm.
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 text-sm">
        <div className="bg-white/70 rounded-lg p-3 border border-amber-100">
          <p className="font-medium text-slate-700 mb-2">Parse summary</p>
          {lead.parse_metadata?.email_format === 'voicemail' && (
            <p className="text-amber-700">• Voicemail lead — listen to recording and fill in details</p>
          )}
          {ambiguous && <p className="text-amber-700">• Ambiguous service type</p>}
          {lowConf.length > 0 && (
            <p className="text-amber-700">• Low confidence: {lowConf.join(', ')}</p>
          )}
          {missing.length > 0 && (
            <p className="text-red-700">• Still missing: {missing.join(', ')}</p>
          )}
          {lowConf.length === 0 && missing.length === 0 && !ambiguous && lead.parse_metadata?.email_format !== 'voicemail' && (
            <p className="text-slate-600">All key fields look complete — confirm to clear the flag.</p>
          )}
          {lead.source && <p className="text-slate-600 mt-2">Source: {lead.source}</p>}
          {lead.parse_metadata?.source_label && (
            <p className="text-slate-600">Listing label: {lead.parse_metadata.source_label}</p>
          )}
          {lead.parse_metadata?.recording_url && (
            <p className="mt-2">
              <a
                href={lead.parse_metadata.recording_url}
                target="_blank"
                rel="noreferrer"
                className="text-blue-600 hover:underline font-medium"
              >
                Open voicemail recording →
              </a>
              {lead.parse_metadata?.call_duration && (
                <span className="text-slate-500 ml-2">({lead.parse_metadata.call_duration})</span>
              )}
            </p>
          )}
        </div>

        <div className="bg-white/70 rounded-lg p-3 border border-amber-100">
          <p className="font-medium text-slate-700 mb-2">Parsed metadata</p>
          <pre className="text-xs text-slate-600 whitespace-pre-wrap overflow-x-auto max-h-32">
            {JSON.stringify(lead.parse_metadata || {}, null, 2)}
          </pre>
        </div>
      </div>

      <button
        type="button"
        onClick={() => setShowRawEmail(!showRawEmail)}
        className="flex items-center gap-1 text-sm text-slate-600 hover:text-slate-800 mb-3"
      >
        {showRawEmail ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
        {showRawEmail ? 'Hide' : 'Show'} raw email copy
      </button>
      {showRawEmail && (
        <pre className="text-xs bg-white border border-slate-200 rounded-lg p-3 mb-4 whitespace-pre-wrap max-h-48 overflow-y-auto text-slate-700">
          {lead.raw_email_copy || 'No raw email stored.'}
        </pre>
      )}

      <form onSubmit={handleSubmit} className="space-y-3 bg-white rounded-lg border border-amber-100 p-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label className="text-xs text-slate-500 block mb-1">Contact name *</label>
            <input required value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Service category</label>
            <select value={form.service_category} onChange={(e) => setForm({ ...form, service_category: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <option value="">— Select —</option>
              <option value="drywall_paint">Drywall & Paint</option>
              <option value="insulation">Insulation</option>
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Phone</label>
            <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Email</label>
            <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div className="md:col-span-2">
            <label className="text-xs text-slate-500 block mb-1">Address</label>
            <input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div className="md:col-span-2">
            <label className="text-xs text-slate-500 block mb-1">Project description</label>
            <textarea value={form.project_description} onChange={(e) => setForm({ ...form, project_description: e.target.value })} rows={3}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Assign PM</label>
            <select value={form.assigned_pm_id} onChange={(e) => setForm({ ...form, assigned_pm_id: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <option value="">— None —</option>
              {pms.map((pm) => <option key={pm.id} value={pm.id}>{pm.name}</option>)}
            </select>
          </div>
        </div>
        <button type="submit" disabled={saving}
          className="w-full bg-amber-600 hover:bg-amber-700 text-white rounded-lg py-2.5 text-sm font-medium disabled:opacity-50">
          {saving ? 'Saving...' : 'Confirm corrections & clear review flag'}
        </button>
      </form>
    </div>
  );
}
