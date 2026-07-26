import { useEffect, useState } from 'react';
import { Save } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { showError, showSuccess } from '../utils/swal';
import { useAuth } from '../context/AuthContext';

/**
 * Agency-scoped brand content editor.
 * Only branding / contact_info / seo_defaults / service category labels —
 * no pricing, availability, AI, or ops fields.
 */
export default function BrandContent() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [brand, setBrand] = useState(null);
  const [form, setForm] = useState({
    hero_headline: '',
    hero_lede: '',
    cta_label: '',
    short_name: '',
    tone: '',
    service_area: '',
    email: '',
    phone: '',
    title_template: '',
    description: '',
    service_categories: [],
  });

  const load = () => {
    setLoading(true);
    api
      .get('/brand-content')
      .then(({ data }) => {
        setBrand(data);
        setForm({
          hero_headline: data.branding?.hero_headline || '',
          hero_lede: data.branding?.hero_lede || '',
          cta_label: data.branding?.cta_label || '',
          short_name: data.branding?.short_name || '',
          tone: data.branding?.tone || '',
          service_area: data.contact_info?.service_area || '',
          email: data.contact_info?.email || '',
          phone: data.contact_info?.phone || '',
          title_template: data.seo_defaults?.title_template || '',
          description: data.seo_defaults?.description || '',
          service_categories: (data.service_categories || []).map((s) => ({
            key: s.key,
            label: s.label,
            keywords: (s.keywords || []).join(', '),
          })),
        });
      })
      .catch(() => setBrand(null))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
  }, []);

  const updateCategory = (index, field, value) => {
    setForm((prev) => {
      const next = [...prev.service_categories];
      next[index] = { ...next[index], [field]: value };
      return { ...prev, service_categories: next };
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = {
        branding: {
          ...(brand?.branding || {}),
          short_name: form.short_name,
          hero_headline: form.hero_headline,
          hero_lede: form.hero_lede,
          cta_label: form.cta_label,
          tone: form.tone,
        },
        contact_info: {
          ...(brand?.contact_info || {}),
          service_area: form.service_area,
          email: form.email,
          phone: form.phone,
        },
        seo_defaults: {
          ...(brand?.seo_defaults || {}),
          title_template: form.title_template,
          description: form.description,
        },
        service_categories: form.service_categories.map((s) => ({
          key: s.key,
          label: s.label,
          keywords: s.keywords
            ? s.keywords.split(',').map((k) => k.trim()).filter(Boolean)
            : [],
        })),
      };
      const { data } = await api.put('/brand-content', payload);
      setBrand(data);
      await showSuccess('Brand content saved.');
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save brand content.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <p className="text-slate-500 text-sm p-6">Loading brand content…</p>;
  }

  if (!brand) {
    return <p className="text-red-600 text-sm p-6">Unable to load brand content for this account.</p>;
  }

  const inputClass =
    'mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';

  return (
    <div className="p-6 max-w-3xl">
      <PageHeader title="Brand Content">
        <p className="text-sm text-slate-500">
          {brand.company_name} · {brand.domain}
          {user?.role === 'content_editor' ? ' · content access only' : ''}
        </p>
      </PageHeader>

      <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        You can edit branding, contact info, SEO defaults, and service labels for this brand.
        Operational data (leads, pricing, AI, payments) is not available to this role.
      </div>

      <form onSubmit={handleSubmit} className="space-y-8 bg-white border border-slate-200 rounded-lg p-6">
        <section>
          <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-3">Branding</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-sm text-slate-600 sm:col-span-2">
              Short name
              <input className={inputClass} value={form.short_name} onChange={(e) => setForm({ ...form, short_name: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600 sm:col-span-2">
              Hero headline
              <input className={inputClass} value={form.hero_headline} onChange={(e) => setForm({ ...form, hero_headline: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600 sm:col-span-2">
              Hero lede
              <textarea className={inputClass} rows={3} value={form.hero_lede} onChange={(e) => setForm({ ...form, hero_lede: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              CTA label
              <input className={inputClass} value={form.cta_label} onChange={(e) => setForm({ ...form, cta_label: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              AI tone
              <input className={inputClass} value={form.tone} onChange={(e) => setForm({ ...form, tone: e.target.value })} placeholder="friendly, professional, and concise" />
            </label>
          </div>
        </section>

        <section>
          <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-3">Contact info</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-sm text-slate-600 sm:col-span-2">
              Service area
              <input className={inputClass} value={form.service_area} onChange={(e) => setForm({ ...form, service_area: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Public email
              <input className={inputClass} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Public phone
              <input className={inputClass} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            </label>
          </div>
        </section>

        <section>
          <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-3">SEO defaults</h2>
          <div className="grid gap-4">
            <label className="text-sm text-slate-600">
              Title template
              <input className={inputClass} value={form.title_template} onChange={(e) => setForm({ ...form, title_template: e.target.value })} placeholder="{{company_name}} | Home Services" />
            </label>
            <label className="text-sm text-slate-600">
              Meta description
              <textarea className={inputClass} rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </label>
          </div>
        </section>

        <section>
          <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-3">Service category labels</h2>
          <p className="text-xs text-slate-500 mb-3">Keys are fixed (used by pricing/routing). You can edit display labels and keywords only.</p>
          <div className="space-y-3">
            {form.service_categories.map((s, i) => (
              <div key={s.key} className="grid gap-2 sm:grid-cols-3 border border-slate-100 rounded-md p-3 bg-slate-50">
                <div>
                  <p className="text-xs text-slate-400">Key (read-only)</p>
                  <p className="text-sm font-mono text-slate-700 mt-1">{s.key}</p>
                </div>
                <label className="text-sm text-slate-600">
                  Label
                  <input className={inputClass} value={s.label} onChange={(e) => updateCategory(i, 'label', e.target.value)} />
                </label>
                <label className="text-sm text-slate-600">
                  Keywords (comma-separated)
                  <input className={inputClass} value={s.keywords} onChange={(e) => updateCategory(i, 'keywords', e.target.value)} />
                </label>
              </div>
            ))}
          </div>
        </section>

        <div className="flex justify-end pt-2">
          <button
            type="submit"
            disabled={saving}
            className="inline-flex items-center gap-2 rounded-md bg-slate-800 text-white px-4 py-2 text-sm font-medium hover:bg-slate-700 disabled:opacity-60"
          >
            <Save size={16} />
            {saving ? 'Saving…' : 'Save content'}
          </button>
        </div>
      </form>
    </div>
  );
}
