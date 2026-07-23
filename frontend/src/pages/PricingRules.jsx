import { useEffect, useState } from 'react';
import { Pencil, Plus, Calculator } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const emptyForm = {
  brand_id: '',
  service_category: '',
  rule_type: 'per_sqft',
  base_rate: '',
  min_price: '',
  max_price: '',
  currency: 'CAD',
  status: 'active',
  is_placeholder: true,
  notes: '',
  low_rate: '',
  high_rate: '',
  default_low_sqft: '',
  default_high_sqft: '',
  mod_simple: '0.85',
  mod_standard: '1',
  mod_complex: '1.35',
  mod_urgency_high: '1.12',
};

export default function PricingRules() {
  const [rules, setRules] = useState([]);
  const [brands, setBrands] = useState([]);
  const [panelOpen, setPanelOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [preview, setPreview] = useState(null);
  const [previewSize, setPreviewSize] = useState('200');

  const load = () => {
    api.get('/pricing-rules?include_archived=1').then(({ data }) => setRules(data)).catch(() => setRules([]));
  };

  useEffect(() => {
    load();
    api.get('/pricing-rules/brands').then(({ data }) => setBrands(data || [])).catch(() => setBrands([]));
  }, []);

  const selectedBrand = brands.find((b) => String(b.id) === String(form.brand_id));
  const serviceOptions = (selectedBrand?.service_categories || []).map((s) =>
    typeof s === 'string' ? { key: s, label: s } : s
  );

  const openCreate = () => {
    setEditing(null);
    setForm({
      ...emptyForm,
      brand_id: brands[0]?.id ? String(brands[0].id) : '',
    });
    setPreview(null);
    setPanelOpen(true);
  };

  const openEdit = (rule) => {
    setEditing(rule);
    const tiers = rule.size_tiers || {};
    const mods = rule.complexity_modifiers || {};
    setForm({
      brand_id: String(rule.brand_id),
      service_category: rule.service_category || '',
      rule_type: rule.rule_type || 'per_sqft',
      base_rate: rule.base_rate ?? '',
      min_price: rule.min_price ?? '',
      max_price: rule.max_price ?? '',
      currency: rule.currency || 'CAD',
      status: rule.status || 'active',
      is_placeholder: Boolean(rule.is_placeholder),
      notes: rule.notes || '',
      low_rate: tiers.low_rate ?? '',
      high_rate: tiers.high_rate ?? '',
      default_low_sqft: tiers.default_low_sqft ?? '',
      default_high_sqft: tiers.default_high_sqft ?? '',
      mod_simple: mods.simple ?? '0.85',
      mod_standard: mods.standard ?? '1',
      mod_complex: mods.complex ?? '1.35',
      mod_urgency_high: mods.urgency_high ?? '1.12',
    });
    setPreview(null);
    setPanelOpen(true);
  };

  const payloadFromForm = () => ({
    brand_id: Number(form.brand_id),
    service_category: form.service_category,
    rule_type: form.rule_type,
    base_rate: form.base_rate === '' ? null : Number(form.base_rate),
    min_price: form.min_price === '' ? null : Number(form.min_price),
    max_price: form.max_price === '' ? null : Number(form.max_price),
    currency: form.currency || 'CAD',
    status: form.status,
    is_placeholder: Boolean(form.is_placeholder),
    notes: form.notes || null,
    size_tiers: {
      low_rate: form.low_rate === '' ? undefined : Number(form.low_rate),
      high_rate: form.high_rate === '' ? undefined : Number(form.high_rate),
      default_low_sqft: form.default_low_sqft === '' ? undefined : Number(form.default_low_sqft),
      default_high_sqft: form.default_high_sqft === '' ? undefined : Number(form.default_high_sqft),
    },
    complexity_modifiers: {
      simple: Number(form.mod_simple || 1),
      standard: Number(form.mod_standard || 1),
      complex: Number(form.mod_complex || 1),
      unknown: 1,
      urgency_high: Number(form.mod_urgency_high || 1.1),
    },
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = payloadFromForm();
      if (editing) {
        await api.put(`/pricing-rules/${editing.id}`, payload);
        await showSuccess('Pricing rule updated.');
      } else {
        await api.post('/pricing-rules', payload);
        await showSuccess('Pricing rule created.');
      }
      setPanelOpen(false);
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save pricing rule.');
    } finally {
      setSaving(false);
    }
  };

  const runPreview = async () => {
    try {
      const { data } = await api.post('/pricing-rules/preview', {
        brand_id: Number(form.brand_id),
        service_category: form.service_category,
        size_sqft: previewSize === '' ? null : Number(previewSize),
        project_description: 'Admin preview for pricing rule validation',
        complexity: 'standard',
      });
      setPreview(data.estimate);
    } catch (err) {
      await showError(err.response?.data?.message || 'Preview failed. Save the rule first if it is new.');
    }
  };

  const archive = async (rule) => {
    const ok = await confirmAction({
      title: 'Archive this pricing rule?',
      text: 'It will no longer be used for public estimates.',
      confirmText: 'Archive',
    });
    if (!ok) return;
    try {
      await api.delete(`/pricing-rules/${rule.id}`);
      await showSuccess('Pricing rule archived.');
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to archive.');
    }
  };

  return (
    <div>
      <PageHeader title="Pricing Rules">
        <button type="button" onClick={openCreate}
          className="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg">
          <Plus size={16} /> Add Rule
        </button>
      </PageHeader>
      <p className="text-sm text-slate-500 mb-4">
        Brand-scoped ballpark estimate rules for the public intake chat. These are separate from contractor Quote /
        80-10-10 splits. Rules marked Placeholder need Trystan-approved rates before public launch.
      </p>

      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="w-full min-w-[900px] text-sm">
          <thead className="bg-slate-50 text-left text-slate-600">
            <tr>
              <th className="px-4 py-3">Brand</th>
              <th className="px-4 py-3">Service</th>
              <th className="px-4 py-3">Type</th>
              <th className="px-4 py-3">Base</th>
              <th className="px-4 py-3">Min–Max</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {rules.map((rule) => (
              <tr key={rule.id} className="border-t border-slate-100">
                <td className="px-4 py-3">
                  <div className="font-medium text-slate-800">{rule.brand?.company_name || rule.brand_id}</div>
                  <div className="text-xs text-slate-400">{rule.brand?.domain}</div>
                </td>
                <td className="px-4 py-3">{rule.service_category}</td>
                <td className="px-4 py-3">{rule.rule_type}</td>
                <td className="px-4 py-3">
                  {rule.base_rate ?? '—'}
                  {rule.is_placeholder ? (
                    <span className="ml-2 text-[10px] uppercase tracking-wide text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded">
                      Placeholder
                    </span>
                  ) : null}
                </td>
                <td className="px-4 py-3">
                  {rule.min_price ?? '—'} – {rule.max_price ?? '—'} {rule.currency}
                </td>
                <td className="px-4 py-3"><StatusBadge status={rule.status} /></td>
                <td className="px-4 py-3 text-right space-x-2">
                  <button type="button" onClick={() => openEdit(rule)} className="text-blue-600 hover:underline inline-flex items-center gap-1">
                    <Pencil size={14} /> Edit
                  </button>
                  {rule.status !== 'archived' && (
                    <button type="button" onClick={() => archive(rule)} className="text-slate-500 hover:underline">
                      Archive
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {rules.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-slate-400">No pricing rules yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <SlideOverPanel open={panelOpen} onClose={() => setPanelOpen(false)} title={editing ? 'Edit pricing rule' : 'New pricing rule'}>
        <form onSubmit={handleSubmit} className="space-y-4">
          <label className="block text-sm">
            <span className="text-slate-600">Brand</span>
            <select className="mt-1 w-full border rounded-lg px-3 py-2" value={form.brand_id}
              onChange={(e) => setForm({ ...form, brand_id: e.target.value, service_category: '' })} required>
              <option value="">Select brand</option>
              {brands.map((b) => (
                <option key={b.id} value={b.id}>{b.company_name} ({b.domain})</option>
              ))}
            </select>
          </label>

          <label className="block text-sm">
            <span className="text-slate-600">Service category key</span>
            {serviceOptions.length > 0 ? (
              <select className="mt-1 w-full border rounded-lg px-3 py-2" value={form.service_category}
                onChange={(e) => setForm({ ...form, service_category: e.target.value })} required>
                <option value="">Select service</option>
                {serviceOptions.map((s) => (
                  <option key={s.key} value={s.key}>{s.label} ({s.key})</option>
                ))}
              </select>
            ) : (
              <input className="mt-1 w-full border rounded-lg px-3 py-2" value={form.service_category}
                onChange={(e) => setForm({ ...form, service_category: e.target.value })} required />
            )}
          </label>

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">
              <span className="text-slate-600">Rule type</span>
              <select className="mt-1 w-full border rounded-lg px-3 py-2" value={form.rule_type}
                onChange={(e) => setForm({ ...form, rule_type: e.target.value })}>
                <option value="per_sqft">Per sqft</option>
                <option value="flat">Flat</option>
                <option value="tiered">Tiered</option>
              </select>
            </label>
            <label className="block text-sm">
              <span className="text-slate-600">Base rate</span>
              <input type="number" step="0.01" className="mt-1 w-full border rounded-lg px-3 py-2"
                value={form.base_rate} onChange={(e) => setForm({ ...form, base_rate: e.target.value })} />
            </label>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">
              <span className="text-slate-600">Low $/sqft</span>
              <input type="number" step="0.01" className="mt-1 w-full border rounded-lg px-3 py-2"
                value={form.low_rate} onChange={(e) => setForm({ ...form, low_rate: e.target.value })} />
            </label>
            <label className="block text-sm">
              <span className="text-slate-600">High $/sqft</span>
              <input type="number" step="0.01" className="mt-1 w-full border rounded-lg px-3 py-2"
                value={form.high_rate} onChange={(e) => setForm({ ...form, high_rate: e.target.value })} />
            </label>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">
              <span className="text-slate-600">Default low sqft (missing size)</span>
              <input type="number" className="mt-1 w-full border rounded-lg px-3 py-2"
                value={form.default_low_sqft} onChange={(e) => setForm({ ...form, default_low_sqft: e.target.value })} />
            </label>
            <label className="block text-sm">
              <span className="text-slate-600">Default high sqft</span>
              <input type="number" className="mt-1 w-full border rounded-lg px-3 py-2"
                value={form.default_high_sqft} onChange={(e) => setForm({ ...form, default_high_sqft: e.target.value })} />
            </label>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">
              <span className="text-slate-600">Min price</span>
              <input type="number" step="0.01" className="mt-1 w-full border rounded-lg px-3 py-2"
                value={form.min_price} onChange={(e) => setForm({ ...form, min_price: e.target.value })} />
            </label>
            <label className="block text-sm">
              <span className="text-slate-600">Max price</span>
              <input type="number" step="0.01" className="mt-1 w-full border rounded-lg px-3 py-2"
                value={form.max_price} onChange={(e) => setForm({ ...form, max_price: e.target.value })} />
            </label>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">
              <span className="text-slate-600">Status</span>
              <select className="mt-1 w-full border rounded-lg px-3 py-2" value={form.status}
                onChange={(e) => setForm({ ...form, status: e.target.value })}>
                <option value="active">active</option>
                <option value="draft">draft</option>
                <option value="archived">archived</option>
              </select>
            </label>
            <label className="flex items-center gap-2 text-sm mt-6">
              <input type="checkbox" checked={form.is_placeholder}
                onChange={(e) => setForm({ ...form, is_placeholder: e.target.checked })} />
              Placeholder rates (needs review)
            </label>
          </div>

          <label className="block text-sm">
            <span className="text-slate-600">Notes</span>
            <textarea className="mt-1 w-full border rounded-lg px-3 py-2" rows={3}
              value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </label>

          <div className="rounded-lg border border-slate-200 p-3 space-y-2 bg-slate-50">
            <div className="text-sm font-medium text-slate-700 flex items-center gap-2">
              <Calculator size={16} /> Preview estimator
            </div>
            <div className="flex gap-2 items-end">
              <label className="block text-sm flex-1">
                <span className="text-slate-600">Size sqft</span>
                <input type="number" className="mt-1 w-full border rounded-lg px-3 py-2"
                  value={previewSize} onChange={(e) => setPreviewSize(e.target.value)} />
              </label>
              <button type="button" onClick={runPreview}
                className="px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white">
                Run
              </button>
            </div>
            {preview && (
              <div className="text-sm text-slate-700">
                {preview.available ? (
                  <>
                    <div className="font-semibold">
                      ${Number(preview.low).toLocaleString()} – ${Number(preview.high).toLocaleString()} {preview.currency}
                    </div>
                    <div className="text-xs text-slate-500 mt-1">{preview.message}</div>
                    <pre className="text-[11px] mt-2 whitespace-pre-wrap text-slate-500">
                      {(preview.calculation || []).join('\n')}
                    </pre>
                  </>
                ) : (
                  <div className="text-amber-700">{preview.message || 'Unavailable'}</div>
                )}
              </div>
            )}
            <p className="text-xs text-slate-400">
              Preview uses the saved active rule for this brand/category. Save first after editing rates.
            </p>
          </div>

          <button type="submit" disabled={saving}
            className="w-full py-2.5 rounded-lg bg-blue-600 text-white text-sm font-medium disabled:opacity-60">
            {saving ? 'Saving…' : 'Save rule'}
          </button>
        </form>
      </SlideOverPanel>
    </div>
  );
}
