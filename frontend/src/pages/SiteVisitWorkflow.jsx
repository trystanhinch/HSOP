import { useEffect, useState, useRef } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Mail, Camera, Save, Send, CheckCircle, XCircle, RefreshCw } from 'lucide-react';
import api, { storageUrl } from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';
import { formatDate, formatTime } from '../utils/formatDate';
import StatusBadge from '../components/StatusBadge';
import FieldQuickActions from '../components/FieldQuickActions';
import StickyActionBar from '../components/StickyActionBar';

export default function SiteVisitWorkflow() {
  const { id } = useParams();
  const { user } = useAuth();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [uploading, setUploading] = useState(false);
  const photoRef = useRef(null);

  const [form, setForm] = useState({
    measurements: {},
    materials_notes: '',
    labour_estimate: '',
    crew_size: '',
    duration_estimate: '',
    assumptions: '',
    exclusions: '',
    contractor_price: '',
    price_notes: '',
  });

  const load = () => {
    setLoading(true);
    api.get(`/site-visits/${id}`)
      .then(({ data: d }) => {
        setData(d);
        if (d.submission) {
          setForm({
            measurements: d.submission.measurements || {},
            materials_notes: d.submission.materials_notes || '',
            labour_estimate: d.submission.labour_estimate || '',
            crew_size: d.submission.crew_size || '',
            duration_estimate: d.submission.duration_estimate || '',
            assumptions: d.submission.assumptions || '',
            exclusions: d.submission.exclusions || '',
            contractor_price: d.submission.contractor_price || '',
            price_notes: d.submission.price_notes || '',
          });
        }
      })
      .catch((err) => showError(err.response?.data?.message || 'Failed to load site visit'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [id]);

  const acceptVisit = async () => {
    const ok = await confirmAction({ title: 'Accept this site visit?', confirmText: 'Yes, accept' });
    if (!ok) return;
    try {
      await api.post(`/site-visits/${id}/accept`);
      await showSuccess('Site visit accepted.');
      load();
    } catch (e) { await showError(e.response?.data?.message || 'Failed'); }
  };

  const declineVisit = async () => {
    const ok = await confirmAction({ title: 'Decline this site visit?', text: 'The PM will be notified.', confirmText: 'Yes, decline', icon: 'warning' });
    if (!ok) return;
    try {
      await api.post(`/site-visits/${id}/decline`);
      await showSuccess('Site visit declined.');
      load();
    } catch (e) { await showError(e.response?.data?.message || 'Failed'); }
  };

  const saveDraft = async () => {
    setSaving(true);
    try {
      await api.post(`/site-visits/${id}/draft`, form);
      await showSuccess('Draft saved.');
      load();
    } catch (e) { await showError(e.response?.data?.message || 'Failed to save'); }
    finally { setSaving(false); }
  };

  const submitPrice = async () => {
    if (!form.contractor_price || parseFloat(form.contractor_price) <= 0) {
      return showError('Please enter a valid price.');
    }
    const ok = await confirmAction({
      title: 'Submit price?',
      text: `Submit $${parseFloat(form.contractor_price).toFixed(2)} for this site visit? This will notify the PM.`,
      confirmText: 'Submit',
    });
    if (!ok) return;
    setSubmitting(true);
    try {
      const endpoint = data.submission?.status === 'revision_requested'
        ? `/site-visits/${id}/revise`
        : `/site-visits/${id}/submit-price`;
      await api.post(endpoint, form);
      await showSuccess('Price submitted. PM has been notified.');
      load();
    } catch (e) { await showError(e.response?.data?.message || 'Failed'); }
    finally { setSubmitting(false); }
  };

  const markComplete = async () => {
    const ok = await confirmAction({ title: 'Mark visit complete?', confirmText: 'Yes, complete' });
    if (!ok) return;
    try {
      await api.post(`/site-visits/${id}/complete`);
      await showSuccess('Site visit marked complete.');
      load();
    } catch (e) { await showError(e.response?.data?.message || 'Failed'); }
  };

  const uploadPhoto = async () => {
    const file = photoRef.current?.files?.[0];
    if (!file) return showError('Select a photo first.');
    setUploading(true);
    const fd = new FormData();
    fd.append('photo', file);
    try {
      await api.post(`/site-visits/${id}/photos`, fd);
      await showSuccess('Photo uploaded.');
      photoRef.current.value = '';
      load();
    } catch (e) { await showError(e.response?.data?.message || 'Upload failed'); }
    finally { setUploading(false); }
  };

  const set = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

  if (loading) return <div className="text-center py-12 text-slate-500">Loading...</div>;
  if (!data) return <div className="text-center py-12 text-red-500">Site visit not found.</div>;

  const sv = data.site_visit;
  const lead = data.lead;
  const sub = data.submission;
  const isLocked = sub?.status === 'submitted' || sub?.status === 'revised';
  const isRevisionRequested = sub?.status === 'revision_requested';
  const isDeclined = sv.status === 'declined';

  return (
    <div className="space-y-6 max-w-3xl mx-auto pb-28">
      <div className="flex items-center gap-3">
        <button type="button" onClick={() => navigate(-1)} className="text-slate-500 hover:text-slate-700 p-2 min-h-[44px] min-w-[44px]"><ArrowLeft size={20} /></button>
        <h1 className="text-xl font-bold text-slate-900">Site Visit Details</h1>
        <StatusBadge status={sv.status} />
      </div>

      {/* Visit Info */}
      <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <p className="text-xs text-slate-500">Date & Time</p>
            <p className="text-sm font-medium text-slate-900">
              {formatDate(sv.visit_date)}{sv.visit_time ? ` at ${formatTime(sv.visit_time)}` : ''}
            </p>
          </div>
          <div>
            <p className="text-xs text-slate-500">Customer</p>
            <p className="text-sm font-medium text-slate-900">{lead.contact_name}</p>
          </div>
        </div>
        <div>
          <p className="text-xs text-slate-500">Address</p>
          <p className="text-sm font-medium text-slate-900 break-words">{lead.address}</p>
        </div>
        <FieldQuickActions phone={lead.phone} address={lead.address} />
        {lead.email && (
          <a href={`mailto:${lead.email}`} className="text-sm text-blue-600 flex items-center gap-1"><Mail size={14} /> {lead.email}</a>
        )}
        {lead.description && (
          <div>
            <p className="text-xs text-slate-500">Scope / Description</p>
            <p className="text-sm text-slate-700">{lead.description}</p>
          </div>
        )}
        {data.pm && (
          <div>
            <p className="text-xs text-slate-500">Project Manager</p>
            <p className="text-sm text-slate-700">{data.pm.name}</p>
            <FieldQuickActions phone={data.pm.phone} className="mt-2" />
          </div>
        )}
        {sv.notes && <div><p className="text-xs text-slate-500">Visit Notes</p><p className="text-sm text-slate-700">{sv.notes}</p></div>}
      </div>

      {/* Lead Photos */}
      {lead.photos?.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-5">
          <p className="text-sm font-semibold text-slate-800 mb-3">Customer Photos</p>
          <div className="flex gap-3 overflow-x-auto">
            {lead.photos.map((p) => (
              <a key={p.id} href={storageUrl(p.url)} target="_blank" rel="noreferrer">
                <img src={storageUrl(p.url)} alt="" className="h-24 w-24 object-cover rounded-lg border" />
              </a>
            ))}
          </div>
        </div>
      )}

      {/* Accept / Decline */}
      {!sv.accepted_at && !sv.declined_at && (
        <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
          <p className="text-sm font-medium text-blue-800">Accept this site visit to begin your assessment.</p>
          <div className="flex gap-2">
            <button type="button" onClick={acceptVisit} className="btn-primary-action px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg flex items-center gap-1"><CheckCircle size={14} /> Accept</button>
            <button type="button" onClick={declineVisit} className="btn-primary-action px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg flex items-center gap-1"><XCircle size={14} /> Decline</button>
          </div>
        </div>
      )}

      {isDeclined && (
        <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">This site visit was declined.</div>
      )}

      {/* Locked notice */}
      {isLocked && (
        <div className="bg-green-50 border border-green-200 rounded-xl p-4 text-sm text-green-800">
          <CheckCircle size={14} className="inline mr-1" /> Price submitted: <strong>${parseFloat(sub.contractor_price).toFixed(2)}</strong>
          {sub.price_submitted_at && ` on ${formatDate(sub.price_submitted_at)}`}. The PM has been notified.
        </div>
      )}

      {/* Revision requested */}
      {isRevisionRequested && (
        <div className="bg-orange-50 border border-orange-200 rounded-xl p-4 text-sm text-orange-800">
          <RefreshCw size={14} className="inline mr-1" /> The PM has requested revisions. Please update your details and resubmit.
        </div>
      )}

      {/* Submission Form */}
      {(sv.accepted_at || sub) && !isDeclined && (
        <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
          <h3 className="font-semibold text-slate-800">Assessment & Pricing</h3>

          {/* Photos */}
          <div>
            <p className="text-sm font-medium text-slate-700 mb-2">Site Photos</p>
            {sub?.photos?.length > 0 && (
              <div className="flex gap-3 overflow-x-auto mb-3">
                {sub.photos.map((p) => (
                  <a key={p.id} href={storageUrl(p.url)} target="_blank" rel="noreferrer" className="flex-shrink-0">
                    <img src={storageUrl(p.url)} alt={p.caption || ''} className="h-24 w-24 object-cover rounded-lg border" />
                  </a>
                ))}
              </div>
            )}
            {!isLocked && (
              <div className="flex gap-2 items-center flex-wrap">
                <label className="btn-primary-action inline-flex items-center gap-2 px-4 py-2 bg-slate-100 border border-slate-200 rounded-lg text-sm font-medium text-slate-800 cursor-pointer hover:bg-slate-200">
                  <Camera size={16} /> Take / Choose Photo
                  <input ref={photoRef} type="file" accept="image/*" capture="environment" className="sr-only" onChange={uploadPhoto} />
                </label>
                {uploading && <span className="text-xs text-slate-500">Uploading…</span>}
              </div>
            )}
          </div>

          {/* Fields */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="text-xs text-slate-500">Labour Estimate</label>
              <input value={form.labour_estimate} onChange={set('labour_estimate')} disabled={isLocked} className="w-full border rounded-lg px-3 py-2 text-sm" placeholder="e.g. 16 hours" />
            </div>
            <div>
              <label className="text-xs text-slate-500">Crew Size</label>
              <input value={form.crew_size} onChange={set('crew_size')} disabled={isLocked} className="w-full border rounded-lg px-3 py-2 text-sm" placeholder="e.g. 2 people" />
            </div>
            <div>
              <label className="text-xs text-slate-500">Duration Estimate</label>
              <input value={form.duration_estimate} onChange={set('duration_estimate')} disabled={isLocked} className="w-full border rounded-lg px-3 py-2 text-sm" placeholder="e.g. 3 days" />
            </div>
          </div>

          <div>
            <label className="text-xs text-slate-500">Materials Notes</label>
            <textarea value={form.materials_notes} onChange={set('materials_notes')} disabled={isLocked} rows={2} className="w-full border rounded-lg px-3 py-2 text-sm" />
          </div>

          <div>
            <label className="text-xs text-slate-500">Assumptions</label>
            <textarea value={form.assumptions} onChange={set('assumptions')} disabled={isLocked} rows={2} className="w-full border rounded-lg px-3 py-2 text-sm" />
          </div>

          <div>
            <label className="text-xs text-slate-500">Exclusions</label>
            <textarea value={form.exclusions} onChange={set('exclusions')} disabled={isLocked} rows={2} className="w-full border rounded-lg px-3 py-2 text-sm" />
          </div>

          {/* Price */}
          <div className="border-t border-slate-100 pt-4">
            <h4 className="text-sm font-semibold text-slate-800 mb-3">Contractor Price (internal — never shown to customer)</h4>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="text-xs text-slate-500">Your Price ($)</label>
                <input type="number" min="0" step="0.01" value={form.contractor_price} onChange={set('contractor_price')} disabled={isLocked} className="w-full border rounded-lg px-3 py-2 text-sm" placeholder="0.00" />
              </div>
            </div>
            <div className="mt-3">
              <label className="text-xs text-slate-500">Price Notes</label>
              <textarea value={form.price_notes} onChange={set('price_notes')} disabled={isLocked} rows={2} className="w-full border rounded-lg px-3 py-2 text-sm" />
            </div>
          </div>

          {/* Actions — sticky above browser chrome + bottom tabs (PM-12/CT-06) */}
          {!isLocked && (
            <StickyActionBar>
              <button type="button" onClick={saveDraft} disabled={saving} className="btn-primary-action flex-1 sm:flex-none px-4 py-2 border border-slate-300 text-slate-700 text-sm rounded-lg flex items-center justify-center gap-1 hover:bg-slate-50 disabled:opacity-50">
                <Save size={14} /> {saving ? 'Saving...' : 'Save Draft'}
              </button>
              <button type="button" onClick={submitPrice} disabled={submitting} className="btn-primary-action flex-1 sm:flex-none px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg flex items-center justify-center gap-1 disabled:opacity-50">
                <Send size={14} /> {submitting ? 'Submitting...' : (isRevisionRequested ? 'Resubmit Price' : 'Submit Price')}
              </button>
              {sv.accepted_at && !sv.completed_at && (
                <button type="button" onClick={markComplete} className="btn-primary-action flex-1 sm:flex-none px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg flex items-center justify-center gap-1">
                  <CheckCircle size={14} /> Mark Complete
                </button>
              )}
            </StickyActionBar>
          )}
        </div>
      )}
    </div>
  );
}
