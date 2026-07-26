import { useEffect, useState } from 'react';
import { Copy, Plus, Save, Trash2, Upload } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { showError, showSuccess } from '../utils/swal';
import { useAuth } from '../context/AuthContext';

const defaultHomeSteps = [
  { eyebrow: '1 — Describe', title: 'Tell us what you see', description: 'A short description is enough to start.' },
  { eyebrow: '2 — Range', title: 'Get a ballpark', description: 'See an estimate range from your details.' },
  { eyebrow: '3 — Book', title: 'Pick a visit time', description: 'Hold an available visit time.' },
];

const emptyLocationForm = () => ({
  city_name: '',
  region: '',
  slug: '',
  headline: '',
  body: '',
  cta_label: '',
  seo_title: '',
  seo_description: '',
  status: 'draft',
});

const emptyPageForm = () => ({
  title: '',
  slug: '',
  template_type: 'simple',
  headline: '',
  body: '',
  seo_title: '',
  seo_description: '',
  status: 'draft',
});

/**
 * Agency-scoped brand content editor.
 * Only branding / page copy / service copy / SEO / contact / images /
 * locations / lightweight pages — no pricing, availability, AI, or ops.
 */
export default function BrandContent() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [brand, setBrand] = useState(null);
  const [images, setImages] = useState({});
  const [imageSlots, setImageSlots] = useState([]);
  const [slotAlts, setSlotAlts] = useState({});
  const [locations, setLocations] = useState([]);
  const [pages, setPages] = useState([]);
  const [duplicableSources, setDuplicableSources] = useState([]);
  const [locationForm, setLocationForm] = useState(emptyLocationForm());
  const [editingLocationId, setEditingLocationId] = useState(null);
  const [pageForm, setPageForm] = useState(emptyPageForm());
  const [editingPageId, setEditingPageId] = useState(null);
  const [duplicateSource, setDuplicateSource] = useState('system:home');
  const [duplicateTitle, setDuplicateTitle] = useState('');
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

  const applyBrandPayload = (data) => {
    setBrand(data);
    setImages(data.images || {});
    setImageSlots(data.image_slots || []);
    const alts = {};
    for (const slot of data.image_slots || []) {
      alts[slot] = slotImage(data.images || {}, slot)?.alt || '';
    }
    setSlotAlts(alts);
    setLocations(data.locations || []);
    setPages(data.pages || []);
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
  };

  const load = () => {
    setLoading(true);
    Promise.all([
      api.get('/brand-content'),
      api.get('/brand-content/pages').catch(() => ({ data: { pages: [], duplicable_sources: [] } })),
    ])
      .then(([contentRes, pagesRes]) => {
        applyBrandPayload(contentRes.data);
        setDuplicableSources(pagesRes.data.duplicable_sources || []);
        if (pagesRes.data.pages) {
          setPages(pagesRes.data.pages);
        }
        if (pagesRes.data.duplicable_sources?.[0]?.key) {
          setDuplicateSource(pagesRes.data.duplicable_sources[0].key);
        }
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
      applyBrandPayload(data);
      await showSuccess('Brand content saved.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save brand content.');
    } finally {
      setSaving(false);
    }
  };

  const uploadSlot = async (slot, file) => {
    if (!file) return;
    const alt = (slotAlts[slot] || '').trim();
    const confirmEmpty = alt === ''
      ? window.confirm('Alt text is empty. Save this image without alt text? (Not recommended for accessibility/SEO.)')
      : false;
    if (alt === '' && !confirmEmpty) return;

    const body = new FormData();
    body.append('slot', slot);
    body.append('image', file);
    body.append('alt', alt);
    if (confirmEmpty) body.append('confirm_empty_alt', '1');

    try {
      const { data } = await api.post('/brand-content/images', body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setImages(data.images || {});
      setSlotAlts((prev) => ({ ...prev, [slot]: data.image?.alt || '' }));
      await showSuccess(`Uploaded ${slotLabel(slot)}.`);
    } catch (err) {
      await showError(
        err.response?.data?.errors?.alt?.[0]
          || err.response?.data?.errors?.image?.[0]
          || err.response?.data?.message
          || 'Image upload failed.'
      );
    }
  };

  const saveSlotAlt = async (slot) => {
    const alt = (slotAlts[slot] || '').trim();
    const confirmEmpty = alt === ''
      ? window.confirm('Clear alt text for this image?')
      : false;
    if (alt === '' && !confirmEmpty) return;
    try {
      const { data } = await api.put('/brand-content/images', {
        slot,
        alt,
        confirm_empty_alt: confirmEmpty,
      });
      setImages(data.images || {});
      await showSuccess('Alt text saved.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save alt text.');
    }
  };

  const removeSlot = async (slot) => {
    if (!window.confirm(`Remove image from ${slotLabel(slot)}?`)) return;
    try {
      const { data } = await api.delete('/brand-content/images', { data: { slot } });
      setImages(data.images || {});
      setSlotAlts((prev) => ({ ...prev, [slot]: '' }));
      await showSuccess('Image removed.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to remove image.');
    }
  };

  const saveLocation = async (e) => {
    e.preventDefault();
    const payload = {
      city_name: locationForm.city_name,
      region: locationForm.region || null,
      slug: locationForm.slug || null,
      content: {
        headline: locationForm.headline,
        body: locationForm.body,
        cta_label: locationForm.cta_label,
      },
      seo_title: locationForm.seo_title || null,
      seo_description: locationForm.seo_description || null,
      status: locationForm.status,
    };
    try {
      if (editingLocationId) {
        const { data } = await api.put(`/brand-content/locations/${editingLocationId}`, payload);
        setLocations((prev) => prev.map((row) => (row.id === editingLocationId ? data.location : row)));
      } else {
        const { data } = await api.post('/brand-content/locations', payload);
        setLocations((prev) => [...prev, data.location].sort((a, b) => a.city_name.localeCompare(b.city_name)));
      }
      setLocationForm(emptyLocationForm());
      setEditingLocationId(null);
      await showSuccess(editingLocationId ? 'Location updated.' : 'Location created.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save location page.');
    }
  };

  const editLocation = (row) => {
    setEditingLocationId(row.id);
    setLocationForm({
      city_name: row.city_name || '',
      region: row.region || '',
      slug: row.slug || '',
      headline: row.content?.headline || '',
      body: row.content?.body || '',
      cta_label: row.content?.cta_label || '',
      seo_title: row.seo_title || '',
      seo_description: row.seo_description || '',
      status: row.status || 'draft',
    });
  };

  const deleteLocation = async (row) => {
    if (!window.confirm(`Delete location page for ${row.city_name}?`)) return;
    try {
      await api.delete(`/brand-content/locations/${row.id}`);
      setLocations((prev) => prev.filter((item) => item.id !== row.id));
      if (editingLocationId === row.id) {
        setEditingLocationId(null);
        setLocationForm(emptyLocationForm());
      }
      await showSuccess('Location deleted.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to delete location.');
    }
  };

  const savePage = async (e) => {
    e.preventDefault();
    const existing = (editingPageId
      ? pages.find((row) => row.id === editingPageId)?.content
      : null) || {};
    const content = { ...existing };
    if (pageForm.template_type === 'quote') {
      content.heading = pageForm.headline;
      content.lede = pageForm.body;
    } else if (pageForm.template_type === 'service') {
      if (pageForm.headline) content.label = pageForm.headline;
      content.lede = pageForm.body;
    } else if (pageForm.template_type === 'home') {
      content.headline = pageForm.headline;
      content.lede = pageForm.body;
    } else {
      content.headline = pageForm.headline;
      content.body = pageForm.body;
    }
    const payload = {
      title: pageForm.title,
      slug: pageForm.slug || null,
      template_type: pageForm.template_type,
      content,
      seo_title: pageForm.seo_title || null,
      seo_description: pageForm.seo_description || null,
      status: pageForm.status,
    };
    try {
      if (editingPageId) {
        const { data } = await api.put(`/brand-content/pages/${editingPageId}`, payload);
        setPages((prev) => prev.map((row) => (row.id === editingPageId ? data.page : row)));
      } else {
        const { data } = await api.post('/brand-content/pages', payload);
        setPages((prev) => [data.page, ...prev]);
      }
      setPageForm(emptyPageForm());
      setEditingPageId(null);
      await showSuccess(editingPageId ? 'Page updated.' : 'Page created.');
      const pagesRes = await api.get('/brand-content/pages');
      setDuplicableSources(pagesRes.data.duplicable_sources || []);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save page.');
    }
  };

  const editPage = (row) => {
    setEditingPageId(row.id);
    setPageForm({
      title: row.title || '',
      slug: row.slug || '',
      template_type: row.template_type || 'simple',
      headline: row.content?.headline || row.content?.heading || '',
      body: row.content?.body || row.content?.lede || '',
      seo_title: row.seo_title || '',
      seo_description: row.seo_description || '',
      status: row.status || 'draft',
    });
  };

  const deletePage = async (row) => {
    if (!window.confirm(`Delete page “${row.title}”?`)) return;
    try {
      await api.delete(`/brand-content/pages/${row.id}`);
      setPages((prev) => prev.filter((item) => item.id !== row.id));
      if (editingPageId === row.id) {
        setEditingPageId(null);
        setPageForm(emptyPageForm());
      }
      await showSuccess('Page deleted.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to delete page.');
    }
  };

  const duplicatePage = async () => {
    try {
      const { data } = await api.post('/brand-content/pages/duplicate', {
        source_key: duplicateSource,
        title: duplicateTitle || undefined,
      });
      setPages((prev) => [data.page, ...prev]);
      setDuplicateTitle('');
      editPage(data.page);
      await showSuccess('Page duplicated as draft. Edit copy, then publish.');
      const pagesRes = await api.get('/brand-content/pages');
      setDuplicableSources(pagesRes.data.duplicable_sources || []);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to duplicate page.');
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
        You can edit branding, images, page copy, locations, lightweight pages, contact info, and SEO for this brand.
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

      <section className="mt-8 space-y-4 bg-white border border-slate-200 rounded-lg p-6">
        <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide">Image slots</h2>
        <p className="text-xs text-slate-500">
          Fixed slots only (logo, hero, Open Graph, one image per service). Alt text is strongly recommended — empty alt requires confirmation.
        </p>
        <div className="space-y-4">
          {imageSlots.map((slot) => {
            const current = slotImage(images, slot);
            return (
              <div key={slot} className="border border-slate-100 rounded-md p-3 bg-slate-50">
                <p className="text-sm font-medium text-slate-800">{slotLabel(slot)}</p>
                <p className="text-xs font-mono text-slate-400 mb-2">{slot}</p>
                {current?.url ? (
                  <img src={current.url} alt={current.alt || ''} className="mb-3 max-h-36 rounded border border-slate-200 object-cover" />
                ) : (
                  <p className="text-xs text-slate-400 mb-3">No image yet</p>
                )}
                <label className="text-sm text-slate-600 block">
                  Alt text
                  <input
                    className={inputClass}
                    value={slotAlts[slot] || ''}
                    onChange={(e) => setSlotAlts((prev) => ({ ...prev, [slot]: e.target.value }))}
                  />
                </label>
                <div className="mt-3 flex flex-wrap gap-2">
                  <label className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50">
                    <Upload size={14} />
                    {current?.url ? 'Replace' : 'Upload'}
                    <input
                      type="file"
                      accept="image/jpeg,image/png,image/gif,image/webp"
                      className="hidden"
                      onChange={(e) => {
                        const file = e.target.files?.[0];
                        e.target.value = '';
                        uploadSlot(slot, file);
                      }}
                    />
                  </label>
                  {current?.url ? (
                    <>
                      <button type="button" onClick={() => saveSlotAlt(slot)} className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm">
                        Save alt
                      </button>
                      <button type="button" onClick={() => removeSlot(slot)} className="inline-flex items-center gap-1 rounded-md border border-red-200 text-red-700 bg-white px-3 py-1.5 text-sm">
                        <Trash2 size={14} /> Remove
                      </button>
                    </>
                  ) : null}
                </div>
              </div>
            );
          })}
        </div>
      </section>

      <section className="mt-8 space-y-4 bg-white border border-slate-200 rounded-lg p-6">
        <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide">Location pages</h2>
        <p className="text-xs text-slate-500">
          One template at <span className="font-mono">/locations/[slug]</span>. Publish to show in the public site footer and sitemap.
        </p>
        <form onSubmit={saveLocation} className="grid gap-3 sm:grid-cols-2 border border-slate-100 rounded-md p-3 bg-slate-50">
          <label className="text-sm text-slate-600">
            City
            <input className={inputClass} required value={locationForm.city_name} onChange={(e) => setLocationForm({ ...locationForm, city_name: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600">
            Region
            <input className={inputClass} value={locationForm.region} onChange={(e) => setLocationForm({ ...locationForm, region: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600">
            Slug (optional)
            <input className={inputClass} value={locationForm.slug} onChange={(e) => setLocationForm({ ...locationForm, slug: e.target.value })} placeholder="auto from city" />
          </label>
          <label className="text-sm text-slate-600">
            Status
            <select className={inputClass} value={locationForm.status} onChange={(e) => setLocationForm({ ...locationForm, status: e.target.value })}>
              <option value="draft">draft</option>
              <option value="published">published</option>
            </select>
          </label>
          <label className="text-sm text-slate-600 sm:col-span-2">
            Headline
            <input className={inputClass} value={locationForm.headline} onChange={(e) => setLocationForm({ ...locationForm, headline: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600 sm:col-span-2">
            Body
            <textarea className={inputClass} rows={3} value={locationForm.body} onChange={(e) => setLocationForm({ ...locationForm, body: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600">
            CTA label
            <input className={inputClass} value={locationForm.cta_label} onChange={(e) => setLocationForm({ ...locationForm, cta_label: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600">
            SEO title
            <input className={inputClass} value={locationForm.seo_title} onChange={(e) => setLocationForm({ ...locationForm, seo_title: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600 sm:col-span-2">
            SEO description
            <textarea className={inputClass} rows={2} value={locationForm.seo_description} onChange={(e) => setLocationForm({ ...locationForm, seo_description: e.target.value })} />
          </label>
          <div className="sm:col-span-2 flex gap-2">
            <button type="submit" className="inline-flex items-center gap-1 rounded-md bg-slate-800 text-white px-3 py-1.5 text-sm">
              <Plus size={14} />
              {editingLocationId ? 'Update location' : 'Add location'}
            </button>
            {editingLocationId ? (
              <button
                type="button"
                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                onClick={() => {
                  setEditingLocationId(null);
                  setLocationForm(emptyLocationForm());
                }}
              >
                Cancel
              </button>
            ) : null}
          </div>
        </form>
        <ul className="space-y-2">
          {locations.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 border border-slate-100 rounded-md px-3 py-2 text-sm">
              <div>
                <span className="font-medium">{row.city_name}{row.region ? `, ${row.region}` : ''}</span>
                <span className="text-slate-400 font-mono ml-2">/{row.slug}</span>
                <span className={`ml-2 text-xs ${row.status === 'published' ? 'text-emerald-700' : 'text-amber-700'}`}>{row.status}</span>
              </div>
              <div className="flex gap-2">
                <button type="button" className="text-slate-700 underline" onClick={() => editLocation(row)}>Edit</button>
                <button type="button" className="text-red-700 underline" onClick={() => deleteLocation(row)}>Delete</button>
              </div>
            </li>
          ))}
        </ul>
      </section>

      <section className="mt-8 space-y-4 bg-white border border-slate-200 rounded-lg p-6">
        <h2 className="text-sm font-semibold text-slate-800 uppercase tracking-wide">Custom pages</h2>
        <p className="text-xs text-slate-500">
          Duplicate an existing layout or create a simple page. Served at <span className="font-mono">/pages/[slug]</span> — never conflicts with /quote, /services/*, or /locations/*.
        </p>

        <div className="grid gap-3 sm:grid-cols-2 border border-slate-100 rounded-md p-3 bg-slate-50">
          <label className="text-sm text-slate-600 sm:col-span-2">
            Duplicate from
            <select className={inputClass} value={duplicateSource} onChange={(e) => setDuplicateSource(e.target.value)}>
              {duplicableSources.map((source) => (
                <option key={source.key} value={source.key}>{source.label}</option>
              ))}
            </select>
          </label>
          <label className="text-sm text-slate-600">
            New title (optional)
            <input className={inputClass} value={duplicateTitle} onChange={(e) => setDuplicateTitle(e.target.value)} />
          </label>
          <div className="flex items-end">
            <button type="button" onClick={duplicatePage} className="inline-flex items-center gap-1 rounded-md bg-slate-800 text-white px-3 py-1.5 text-sm">
              <Copy size={14} /> Duplicate as draft
            </button>
          </div>
        </div>

        <form onSubmit={savePage} className="grid gap-3 sm:grid-cols-2 border border-slate-100 rounded-md p-3 bg-slate-50">
          <label className="text-sm text-slate-600">
            Title
            <input className={inputClass} required value={pageForm.title} onChange={(e) => setPageForm({ ...pageForm, title: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600">
            Slug
            <input className={inputClass} value={pageForm.slug} onChange={(e) => setPageForm({ ...pageForm, slug: e.target.value })} placeholder="auto from title" />
          </label>
          <label className="text-sm text-slate-600">
            Template
            <select className={inputClass} value={pageForm.template_type} onChange={(e) => setPageForm({ ...pageForm, template_type: e.target.value })}>
              <option value="simple">simple</option>
              <option value="home">home</option>
              <option value="service">service</option>
              <option value="quote">quote</option>
            </select>
          </label>
          <label className="text-sm text-slate-600">
            Status
            <select className={inputClass} value={pageForm.status} onChange={(e) => setPageForm({ ...pageForm, status: e.target.value })}>
              <option value="draft">draft</option>
              <option value="published">published</option>
            </select>
          </label>
          <label className="text-sm text-slate-600 sm:col-span-2">
            Headline / heading
            <input className={inputClass} value={pageForm.headline} onChange={(e) => setPageForm({ ...pageForm, headline: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600 sm:col-span-2">
            Body / lede
            <textarea className={inputClass} rows={3} value={pageForm.body} onChange={(e) => setPageForm({ ...pageForm, body: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600">
            SEO title
            <input className={inputClass} value={pageForm.seo_title} onChange={(e) => setPageForm({ ...pageForm, seo_title: e.target.value })} />
          </label>
          <label className="text-sm text-slate-600">
            SEO description
            <input className={inputClass} value={pageForm.seo_description} onChange={(e) => setPageForm({ ...pageForm, seo_description: e.target.value })} />
          </label>
          <div className="sm:col-span-2 flex gap-2">
            <button type="submit" className="inline-flex items-center gap-1 rounded-md bg-slate-800 text-white px-3 py-1.5 text-sm">
              <Plus size={14} />
              {editingPageId ? 'Update page' : 'Create page'}
            </button>
            {editingPageId ? (
              <button
                type="button"
                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                onClick={() => {
                  setEditingPageId(null);
                  setPageForm(emptyPageForm());
                }}
              >
                Cancel
              </button>
            ) : null}
          </div>
        </form>

        <ul className="space-y-2">
          {pages.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 border border-slate-100 rounded-md px-3 py-2 text-sm">
              <div>
                <span className="font-medium">{row.title}</span>
                <span className="text-slate-400 font-mono ml-2">/pages/{row.slug}</span>
                <span className="text-slate-500 ml-2">{row.template_type}</span>
                <span className={`ml-2 text-xs ${row.status === 'published' ? 'text-emerald-700' : 'text-amber-700'}`}>{row.status}</span>
              </div>
              <div className="flex gap-2">
                <button type="button" className="text-slate-700 underline" onClick={() => editPage(row)}>Edit</button>
                <button type="button" className="text-red-700 underline" onClick={() => deletePage(row)}>Delete</button>
              </div>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

function slotImage(images, slot) {
  if (!images) return null;
  if (slot.startsWith('service:')) {
    const key = slot.slice('service:'.length);
    return images.services?.[key] || null;
  }
  return images[slot] || null;
}

function slotLabel(slot) {
  if (slot === 'logo') return 'Logo';
  if (slot === 'hero_image') return 'Hero image';
  if (slot === 'og_image') return 'Open Graph image';
  if (slot.startsWith('service:')) return `Service: ${slot.slice('service:'.length)}`;
  return slot;
}
