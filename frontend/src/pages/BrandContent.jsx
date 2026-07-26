import { useEffect, useState } from 'react';
import { Save } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { showError, showSuccess } from '../utils/swal';
import { useAuth } from '../context/AuthContext';

const defaultHomeSteps = [
  { eyebrow: '1 — Describe', title: 'Tell us what you see', description: 'A short description is enough to start.' },
  { eyebrow: '2 — Range', title: 'Get a ballpark', description: 'See an estimate range from your details.' },
  { eyebrow: '3 — Book', title: 'Pick a visit time', description: 'Hold an available visit time.' },
];

/**
 * Agency-scoped brand content editor.
 * Only branding / page copy / service copy / SEO / contact fields —
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
    header_quote_cta_label: '',
    home_details_label: '',
    home_steps: [],
    home_licensed_label: '',
    home_insured_label: '',
    home_serving_prefix: '',
    home_trust_fallback: '',
    home_bottom_cta_label: '',
    service_home_label: '',
    service_request_prefix: '',
    quote_heading: '',
    quote_lede: '',
    footer_fallback_label: '',
    service_categories: [],
    seo_pages: [],
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
          header_quote_cta_label: data.content?.header?.quote_cta_label || '',
          home_details_label: data.content?.home?.details_label || '',
          home_steps: (
            data.content?.home?.steps?.length === 3
              ? data.content.home.steps
              : defaultHomeSteps
          ).map((step) => ({
            eyebrow: step.eyebrow || '',
            title: step.title || '',
            description: step.description || '',
          })),
          home_licensed_label: data.content?.home?.licensed_label || '',
          home_insured_label: data.content?.home?.insured_label || '',
          home_serving_prefix: data.content?.home?.serving_prefix || '',
          home_trust_fallback: data.content?.home?.trust_fallback || '',
          home_bottom_cta_label: data.content?.home?.bottom_cta_label || '',
          service_home_label: data.content?.service?.home_label || '',
          service_request_prefix: data.content?.service?.request_prefix || '',
          quote_heading: data.content?.quote?.heading || '',
          quote_lede: data.content?.quote?.lede || '',
          footer_fallback_label: data.content?.footer?.fallback_label || '',
          service_categories: (data.service_categories || []).map((s) => ({
            key: s.key,
            label: s.label,
            keywords: (s.keywords || []).join(', '),
            lede: s.lede || '',
            points: (s.points || []).join('\n'),
          })),
          seo_pages: (data.seo_pages || []).map((page) => ({
            page_key: page.page_key,
            title: page.title || '',
            description: page.description || '',
            og_image: page.og_image || '',
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

  const updateStep = (index, field, value) => {
    setForm((prev) => {
      const next = [...prev.home_steps];
      next[index] = { ...next[index], [field]: value };
      return { ...prev, home_steps: next };
    });
  };

  const updateSeoPage = (index, field, value) => {
    setForm((prev) => {
      const next = [...prev.seo_pages];
      next[index] = { ...next[index], [field]: value };
      return { ...prev, seo_pages: next };
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
        content: {
          header: {
            quote_cta_label: form.header_quote_cta_label,
          },
          home: {
            details_label: form.home_details_label,
            steps: form.home_steps,
            licensed_label: form.home_licensed_label,
            insured_label: form.home_insured_label,
            serving_prefix: form.home_serving_prefix,
            trust_fallback: form.home_trust_fallback,
            bottom_cta_label: form.home_bottom_cta_label,
          },
          service: {
            home_label: form.service_home_label,
            request_prefix: form.service_request_prefix,
          },
          quote: {
            heading: form.quote_heading,
            lede: form.quote_lede,
          },
          footer: {
            fallback_label: form.footer_fallback_label,
          },
        },
        service_categories: form.service_categories.map((s) => ({
          key: s.key,
          label: s.label,
          keywords: s.keywords
            ? s.keywords.split(',').map((k) => k.trim()).filter(Boolean)
            : [],
          lede: s.lede,
          points: s.points
            ? s.points.split('\n').map((point) => point.trim()).filter(Boolean)
            : [],
        })),
        seo_pages: form.seo_pages,
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
        You can edit branding, existing page copy, service descriptions, contact info, and SEO for this brand.
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
          <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-3">Existing page copy</h2>
          <p className="text-xs text-slate-500 mb-4">
            Hero headline, lede, CTA, and services heading remain in Branding above. These fields cover the remaining existing page text without duplicating them.
          </p>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-sm text-slate-600">
              Header quote button
              <input className={inputClass} value={form.header_quote_cta_label} onChange={(e) => setForm({ ...form, header_quote_cta_label: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Homepage service-link hint
              <input className={inputClass} value={form.home_details_label} onChange={(e) => setForm({ ...form, home_details_label: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Licensed label
              <input className={inputClass} value={form.home_licensed_label} onChange={(e) => setForm({ ...form, home_licensed_label: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Insured label
              <input className={inputClass} value={form.home_insured_label} onChange={(e) => setForm({ ...form, home_insured_label: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Serving prefix
              <input className={inputClass} value={form.home_serving_prefix} onChange={(e) => setForm({ ...form, home_serving_prefix: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Homepage lower CTA
              <input className={inputClass} value={form.home_bottom_cta_label} onChange={(e) => setForm({ ...form, home_bottom_cta_label: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600 sm:col-span-2">
              Homepage trust fallback
              <input className={inputClass} value={form.home_trust_fallback} onChange={(e) => setForm({ ...form, home_trust_fallback: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Service breadcrumb home label
              <input className={inputClass} value={form.service_home_label} onChange={(e) => setForm({ ...form, service_home_label: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600">
              Service CTA prefix
              <input className={inputClass} value={form.service_request_prefix} onChange={(e) => setForm({ ...form, service_request_prefix: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600 sm:col-span-2">
              Quote page heading
              <input className={inputClass} value={form.quote_heading} onChange={(e) => setForm({ ...form, quote_heading: e.target.value })} />
            </label>
            <label className="text-sm text-slate-600 sm:col-span-2">
              Quote page introduction
              <textarea className={inputClass} rows={3} value={form.quote_lede} onChange={(e) => setForm({ ...form, quote_lede: e.target.value })} />
              <span className="text-xs text-slate-400">Supports {'{{company_name}}'}.</span>
            </label>
            <label className="text-sm text-slate-600 sm:col-span-2">
              Footer fallback label
              <input className={inputClass} value={form.footer_fallback_label} onChange={(e) => setForm({ ...form, footer_fallback_label: e.target.value })} />
            </label>
          </div>
        </section>

        <section>
          <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-3">Homepage: how a quote works</h2>
          <p className="text-xs text-slate-500 mb-3">Exactly three fixed steps. Text is editable; steps cannot be added, removed, or reordered.</p>
          <div className="space-y-3">
            {form.home_steps.map((step, i) => (
              <div key={i} className="grid gap-3 sm:grid-cols-2 border border-slate-100 rounded-md p-3 bg-slate-50">
                <label className="text-sm text-slate-600">
                  Step label
                  <input className={inputClass} value={step.eyebrow} onChange={(e) => updateStep(i, 'eyebrow', e.target.value)} />
                </label>
                <label className="text-sm text-slate-600">
                  Heading
                  <input className={inputClass} value={step.title} onChange={(e) => updateStep(i, 'title', e.target.value)} />
                </label>
                <label className="text-sm text-slate-600 sm:col-span-2">
                  Description
                  <textarea className={inputClass} rows={2} value={step.description} onChange={(e) => updateStep(i, 'description', e.target.value)} />
                </label>
              </div>
            ))}
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
          <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-3">Services</h2>
          <p className="text-xs text-slate-500 mb-3">Keys are fixed (used by pricing/routing). Edit display labels, descriptions, bullet points, and intake keywords.</p>
          <div className="space-y-3">
            {form.service_categories.map((s, i) => (
              <div key={s.key} className="grid gap-3 sm:grid-cols-2 border border-slate-100 rounded-md p-3 bg-slate-50">
                <div>
                  <p className="text-xs text-slate-400">Key (read-only)</p>
                  <p className="text-sm font-mono text-slate-700 mt-1">{s.key}</p>
                </div>
                <label className="text-sm text-slate-600">
                  Label
                  <input className={inputClass} value={s.label} onChange={(e) => updateCategory(i, 'label', e.target.value)} />
                </label>
                <label className="text-sm text-slate-600 sm:col-span-2">
                  Service page introduction
                  <textarea className={inputClass} rows={3} value={s.lede} onChange={(e) => updateCategory(i, 'lede', e.target.value)} />
                </label>
                <label className="text-sm text-slate-600 sm:col-span-2">
                  Bullet points (one per line)
                  <textarea className={inputClass} rows={4} value={s.points} onChange={(e) => updateCategory(i, 'points', e.target.value)} />
                </label>
                <label className="text-sm text-slate-600 sm:col-span-2">
                  Keywords (comma-separated)
                  <input className={inputClass} value={s.keywords} onChange={(e) => updateCategory(i, 'keywords', e.target.value)} />
                </label>
              </div>
            ))}
          </div>
        </section>

        <section>
          <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-3">Per-page SEO overrides</h2>
          <p className="text-xs text-slate-500 mb-3">Leave blank to use the brand-level SEO defaults above. No schema or redirects are managed here.</p>
          <div className="space-y-3">
            {form.seo_pages.map((page, i) => (
              <div key={page.page_key} className="grid gap-3 border border-slate-100 rounded-md p-3 bg-slate-50">
                <p className="text-sm font-mono text-slate-700">{page.page_key}</p>
                <label className="text-sm text-slate-600">
                  Page title
                  <input className={inputClass} value={page.title} onChange={(e) => updateSeoPage(i, 'title', e.target.value)} />
                </label>
                <label className="text-sm text-slate-600">
                  Meta description
                  <textarea className={inputClass} rows={2} value={page.description} onChange={(e) => updateSeoPage(i, 'description', e.target.value)} />
                </label>
                <label className="text-sm text-slate-600">
                  Open Graph image URL (optional)
                  <input className={inputClass} value={page.og_image} onChange={(e) => updateSeoPage(i, 'og_image', e.target.value)} />
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
