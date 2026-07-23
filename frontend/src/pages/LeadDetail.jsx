import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Pencil } from 'lucide-react';
import api from '../api/axios';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import LeadForm from '../components/LeadForm';
import ContractorLeadPriceForm from '../components/ContractorLeadPriceForm';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';
import NextActionCard from '../components/NextActionCard';
import EventTimeline from '../components/EventTimeline';
import LeadReviewPanel from '../components/LeadReviewPanel';
import { formatDate, formatTime, formatDateTime, toDateInputValue } from '../utils/formatDate';

const statuses = [
  'new', 'duplicate_review', 'pm_assigned', 'customer_contacted', 'call_scheduled',
  'site_visit_scheduled', 'converted', 'disqualified',
  'contacted', 'quote_needed', 'lost',
];

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

export default function LeadDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const isContractor = user?.role === 'contractor';
  const isAdminOrPm = ['owner', 'pm'].includes(user?.role);

  const [lead, setLead] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [contractors, setContractors] = useState([]);
  const [panelOpen, setPanelOpen] = useState(false);
  const [converting, setConverting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [siteVisitSaved, setSiteVisitSaved] = useState(false);
  const [visitDate, setVisitDate] = useState('');
  const [visitTime, setVisitTime] = useState('');
  const [contractorId, setContractorId] = useState('');
  const [visitNotes, setVisitNotes] = useState('');
  const [scheduleAddress, setScheduleAddress] = useState('');
  const [selectedContractorId, setSelectedContractorId] = useState('');
  const [assigningContractor, setAssigningContractor] = useState(false);
  const [showContractorSelect, setShowContractorSelect] = useState(false);
  const [quoteScope, setQuoteScope] = useState('');
  const [quoteNotes, setQuoteNotes] = useState('');
  const [sendingQuote, setSendingQuote] = useState(false);
  const [quoteSent, setQuoteSent] = useState(false);
  const [savingNextAction, setSavingNextAction] = useState(false);
  const [addingTimeline, setAddingTimeline] = useState(false);
  const [editingAddress, setEditingAddress] = useState(false);
  const [addressDraft, setAddressDraft] = useState('');
  const [savingAddress, setSavingAddress] = useState(false);
  const [callPrep, setCallPrep] = useState(null);
  const [messageDraft, setMessageDraft] = useState('');
  const [quotePrep, setQuotePrep] = useState(null);
  const [aiBusy, setAiBusy] = useState(false);
  const [overrideForm, setOverrideForm] = useState({ low: '', high: '', reason: '' });
  const [savingOverride, setSavingOverride] = useState(false);
  const [showOverride, setShowOverride] = useState(false);

  const saveNextAction = async (payload) => {
    setSavingNextAction(true);
    try {
      const { data } = await api.put(`/leads/${id}/next-action`, payload);
      setLead((prev) => ({ ...prev, next_action: data.next_action }));
      await showSuccess('Next action saved.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save next action.');
    } finally {
      setSavingNextAction(false);
    }
  };

  const addTimelineNote = async () => {
    setAddingTimeline(true);
    try {
      const { data } = await api.post(`/leads/${id}/timeline`, {
        event_type: 'manual_note',
        description: 'Manual timeline note added from Lead Detail.',
      });
      setLead((prev) => ({
        ...prev,
        event_timeline: [data, ...(prev.event_timeline || [])],
      }));
      await showSuccess('Timeline note added.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to add timeline note.');
    } finally {
      setAddingTimeline(false);
    }
  };

  const runCallPrep = async () => {
    setAiBusy(true);
    try {
      const { data } = await api.post(`/leads/${id}/ai/call-prep`);
      setCallPrep(data);
    } catch (err) {
      await showError(err.response?.data?.message || 'Call prep failed.');
    } finally {
      setAiBusy(false);
    }
  };

  const runDraftMessage = async () => {
    setAiBusy(true);
    try {
      const { data } = await api.post(`/leads/${id}/ai/draft-message`);
      setMessageDraft(data.draft || '');
    } catch (err) {
      await showError(err.response?.data?.message || 'Draft failed.');
    } finally {
      setAiBusy(false);
    }
  };

  const runQuotePrep = async () => {
    setAiBusy(true);
    try {
      const { data } = await api.post(`/leads/${id}/ai/quote-prep`);
      setQuotePrep(data);
      if (data.scope_wording) setQuoteScope(data.scope_wording);
    } catch (err) {
      await showError(err.response?.data?.message || 'Quote prep failed.');
    } finally {
      setAiBusy(false);
    }
  };

  const saveEstimateOverride = async () => {
    setSavingOverride(true);
    try {
      const { data } = await api.post(`/leads/${id}/price-estimate-override`, {
        price_estimate_low: Number(overrideForm.low),
        price_estimate_high: Number(overrideForm.high),
        reason: overrideForm.reason || null,
      });
      setLead(data.lead);
      setShowOverride(false);
      await showSuccess('Estimate override recorded for Learning Centre data.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save estimate override.');
    } finally {
      setSavingOverride(false);
    }
  };

  const load = () => {
    setLoadError(null);
    return api.get(`/leads/${id}`)
      .then(({ data }) => {
        setLead(data);
        setVisitDate(toDateInputValue(data.site_visit_date));
        setVisitTime(data.site_visit_time?.slice(0, 5) || '');
        setContractorId(data.site_visit_contractor_id ? String(data.site_visit_contractor_id) : '');
        setSelectedContractorId(data.assigned_contractor_id ? String(data.assigned_contractor_id) : '');
        setVisitNotes(data.site_visit_notes || '');
        setScheduleAddress(data.address || '');
        setAddressDraft(data.address || '');
        setShowContractorSelect(false);
      })
      .catch((e) => {
        setLead(null);
        setLoadError(e.response?.data?.message || 'Failed to load lead.');
      });
  };

  useEffect(() => { load(); }, [id]);
  useEffect(() => {
    if (isAdminOrPm) {
      api.get('/users/contractors').then(({ data }) => setContractors(data)).catch(() => setContractors([]));
    }
  }, [isAdminOrPm]);

  const updateStatus = async (status) => {
    if (status === lead.status) return;
    const ok = await confirmAction({
      title: 'Change lead status?',
      text: `Update status to "${status.replace(/_/g, ' ')}"?`,
      confirmText: 'Yes, update',
    });
    if (!ok) return;

    try {
      await api.put(`/leads/${id}`, { status });
      await showSuccess('Status updated.');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to update status.');
    }
  };

  const assignContractor = async () => {
    if (!selectedContractorId) return;
    setAssigningContractor(true);
    try {
      await api.put(`/leads/${id}`, {
        assigned_contractor_id: selectedContractorId,
      });
      await showSuccess('Contractor assigned successfully');
      setShowContractorSelect(false);
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to assign contractor');
    } finally {
      setAssigningContractor(false);
    }
  };

  const saveSiteVisit = async () => {
    if (!visitDate || !visitTime || !contractorId) return;

    const ok = await confirmAction({
      title: 'Schedule site visit?',
      text: 'Customer and contractor will be notified by SMS and email.',
      confirmText: 'Yes, schedule',
    });
    if (!ok) return;

    setSaving(true);
    setSiteVisitSaved(false);
    try {
      await api.post(`/leads/${id}/schedule-site-visit`, {
        site_visit_date: visitDate,
        site_visit_time: visitTime,
        site_visit_contractor_id: contractorId,
        site_visit_notes: visitNotes || undefined,
        ...(scheduleAddress ? { address: scheduleAddress } : {}),
      });
      setSiteVisitSaved(true);
      await showSuccess('Site visit scheduled.');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to schedule site visit.');
    } finally {
      setSaving(false);
    }
  };

  const saveAddress = async () => {
    setSavingAddress(true);
    try {
      await api.put(`/leads/${id}`, { address: addressDraft || null });
      setEditingAddress(false);
      await showSuccess('Address saved.');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to save address.');
    } finally {
      setSavingAddress(false);
    }
  };

  const handleEdit = async (form) => {
    const ok = await confirmAction({
      title: 'Save changes?',
      text: 'Update this lead with your changes?',
      confirmText: 'Yes, save',
    });
    if (!ok) return;

    setSaving(true);
    try {
      await api.put(`/leads/${id}`, form);
      setPanelOpen(false);
      await showSuccess('Lead updated.');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to update lead.');
    } finally {
      setSaving(false);
    }
  };

  const convertToJob = async () => {
    const ok = await confirmAction({
      title: 'Convert to job?',
      text: 'This will create a new job from this lead. This action cannot be undone.',
      confirmText: 'Yes, convert',
      icon: 'warning',
    });
    if (!ok) return;

    setConverting(true);
    try {
      const { data } = await api.post(`/leads/${id}/convert-to-job`);
      await showSuccess('Lead converted to job.');
      navigate(`/jobs/${data.job_id}`);
    } catch (e) {
      await showError(e.response?.data?.message || 'Conversion failed.');
    } finally {
      setConverting(false);
    }
  };

  const sendQuoteFromLead = async () => {
    setSendingQuote(true);
    setQuoteSent(false);
    try {
      await api.post(`/leads/${id}/send-quote`, {
        scope_of_work: quoteScope || lead?.project_description || lead?.notes || '',
        customer_notes: quoteNotes || null,
      });
      setQuoteSent(true);
      await showSuccess('Quote sent to customer successfully');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to send quote');
    } finally {
      setSendingQuote(false);
    }
  };

  if (loadError) {
    return (
      <div className="text-center py-12">
        <p className="text-red-600 mb-4">{loadError}</p>
        <button type="button" onClick={() => navigate(-1)} className="text-sm text-blue-600 hover:underline">
          ← Go back
        </button>
      </div>
    );
  }

  if (!lead) return <div className="text-center py-12 text-slate-500">Loading lead...</div>;

  const isAssignedToThisLead = isContractor && (
    Number(lead.assigned_contractor_id) === Number(user?.id)
    || Number(lead.site_visit_contractor_id) === Number(user?.id)
  );

  if (isContractor) {
    if (!isAssignedToThisLead) {
      return (
        <div className="text-center py-12">
          <p className="text-red-600 mb-4">You are not assigned to this lead.</p>
          <button type="button" onClick={() => navigate('/my-leads')} className="text-sm text-blue-600 hover:underline">
            ← Back to Leads
          </button>
        </div>
      );
    }

    const hasSiteVisit = lead.site_visit_date || lead.site_visit_time;

    return (
      <div className="max-w-3xl mx-auto space-y-6">
        <button type="button" onClick={() => navigate('/my-leads')}
          className="text-sm text-slate-500 hover:text-slate-700 flex items-center gap-1">
          <ArrowLeft size={16} /> Back to Leads
        </button>

        <div className="flex items-center gap-3">
          <h1 className="text-xl font-bold text-slate-800">
            {hasSiteVisit ? 'Your Appointment' : 'Lead Details'}
          </h1>
          <StatusBadge status={lead.status} />
        </div>

        <div className="bg-white rounded-xl border border-slate-200 p-5">
          <h2 className="font-semibold text-slate-800 mb-4">
            {hasSiteVisit ? 'Appointment Details' : 'Project Details'}
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            {hasSiteVisit && (
              <div>
                <p className="text-slate-400 text-xs mb-0.5">Date & Time</p>
                <p className="font-medium text-slate-800">
                  {formatDate(lead.site_visit_date)}
                  {lead.site_visit_time && ` at ${formatTime(lead.site_visit_time)}`}
                </p>
              </div>
            )}
            <div>
              <p className="text-slate-400 text-xs mb-0.5">Address</p>
              <p className="font-medium text-slate-800">{lead.address || '—'}</p>
            </div>
            <div>
              <p className="text-slate-400 text-xs mb-0.5">Service Category</p>
              <p className="font-medium text-slate-800 capitalize">{formatCategory(lead.service_category)}</p>
            </div>
            <div>
              <p className="text-slate-400 text-xs mb-0.5">Status</p>
              <StatusBadge status={lead.status} />
            </div>
          </div>
        </div>

        <ContractorLeadPriceForm lead={lead} onSubmitted={load} />

        <div className="bg-white rounded-xl border border-slate-200 p-5">
          <h2 className="font-semibold text-slate-800 mb-4">Customer Contact</h2>
          <div className="space-y-2 text-sm">
            <div className="flex justify-between gap-4">
              <span className="text-slate-400">Name</span>
              <span className="font-medium">{lead.contact_name}</span>
            </div>
            <div className="flex justify-between gap-4">
              <span className="text-slate-400">Phone</span>
              {lead.phone ? (
                <a href={`tel:${lead.phone}`} className="font-medium text-blue-600">{lead.phone}</a>
              ) : (
                <span>—</span>
              )}
            </div>
            <div className="flex justify-between gap-4">
              <span className="text-slate-400">Email</span>
              {lead.email ? (
                <a href={`mailto:${lead.email}`} className="font-medium text-blue-600 text-xs">{lead.email}</a>
              ) : (
                <span>—</span>
              )}
            </div>
          </div>
        </div>

        {(lead.project_description || lead.notes) && (
          <div className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-2">Project Description</h2>
            <p className="text-sm text-slate-600 whitespace-pre-wrap">{lead.project_description || lead.notes}</p>
          </div>
        )}

        {lead.site_visit_notes && (
          <div className="bg-blue-50 border border-blue-200 rounded-xl p-4">
            <p className="text-xs font-semibold text-blue-700 mb-1">Notes for this appointment</p>
            <p className="text-sm text-blue-800">{lead.site_visit_notes}</p>
          </div>
        )}

        {lead.assigned_pm && (
          <div className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-3">Your Project Manager</h2>
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold">
                {lead.assigned_pm.name?.charAt(0)}
              </div>
              <div>
                <p className="font-medium text-slate-800">{lead.assigned_pm.name}</p>
                {lead.assigned_pm.phone && (
                  <a href={`tel:${lead.assigned_pm.phone}`} className="text-sm text-blue-600">{lead.assigned_pm.phone}</a>
                )}
                {lead.assigned_pm.email && !lead.assigned_pm.phone && (
                  <a href={`mailto:${lead.assigned_pm.email}`} className="text-sm text-blue-600">{lead.assigned_pm.email}</a>
                )}
              </div>
            </div>
          </div>
        )}

      </div>
    );
  }

  const formInitial = {
    id: lead.id,
    contact_name: lead.contact_name,
    phone: lead.phone,
    email: lead.email,
    address: lead.address,
    service_category: lead.service_category,
    source: lead.source,
    company_id: lead.company_id,
    assigned_pm_id: lead.assigned_pm_id,
    project_description: lead.project_description || lead.notes,
    internal_notes: lead.internal_notes,
    site_visit_date: visitDate,
    site_visit_time: visitTime,
  };

  const hasJob = Boolean(lead.job);
  const hasContractorPrice = Boolean(lead.contractor_price);
  const quoteApproved = lead.lead_quote?.status === 'approved';
  const pricing = lead.pricing_preview;
  const contractorPct = pricing?.contractor_pct ?? 80;
  const customerSubtotal = pricing?.customer_subtotal ?? (hasContractorPrice ? lead.contractor_price / (contractorPct / 100) : 0);
  const gstAmount = pricing?.gst ?? customerSubtotal * 0.05;
  const customerTotal = pricing?.customer_total ?? customerSubtotal + gstAmount;
  const showSendQuote = isAdminOrPm && hasContractorPrice && !hasJob && lead.lead_quote?.status !== 'sent' && lead.lead_quote?.status !== 'approved';
  const showConvertFallback = !hasJob && lead.status !== 'converted' && (!hasContractorPrice || quoteApproved);

  return (
    <div>
      <Link to="/leads" className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900 mb-6">
        <ArrowLeft size={16} /> Back to Leads
      </Link>

      <div className="flex flex-wrap items-center gap-3 mb-6">
        <h2 className="text-xl font-bold text-slate-900">Lead #{lead.id}</h2>
        <StatusBadge status={lead.status} />
        <button onClick={() => setPanelOpen(true)} className="ml-auto flex items-center gap-1 px-3 py-1.5 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">
          <Pencil className="w-4 h-4" /> Edit
        </button>
        {showConvertFallback && (
          <button onClick={convertToJob} disabled={converting}
            className="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 disabled:opacity-60">
            {converting ? 'Converting...' : 'Convert to Job'}
          </button>
        )}
        {lead.job && <Link to={`/jobs/${lead.job.id}`} className="text-sm text-blue-600 hover:underline">View Job →</Link>}
      </div>

      {user?.role === 'owner' && lead.needs_manual_review && (
        <LeadReviewPanel lead={lead} onResolved={load} />
      )}

      {isAdminOrPm && (
          <div className="bg-white rounded-xl border border-indigo-200 p-5 mb-6 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <div>
                <h3 className="font-semibold text-slate-800">AI assistance</h3>
                <p className="text-xs text-indigo-600">AI-drafted — review before using. Not auto-sent.</p>
              </div>
              <div className="flex flex-wrap gap-2">
                <button type="button" disabled={aiBusy} onClick={runCallPrep} className="text-xs px-3 py-1.5 bg-indigo-600 text-white rounded-lg disabled:opacity-50">Call prep</button>
                <button type="button" disabled={aiBusy} onClick={runDraftMessage} className="text-xs px-3 py-1.5 border border-indigo-300 text-indigo-700 rounded-lg disabled:opacity-50">Draft message</button>
                {lead.contractor_price && (
                  <button type="button" disabled={aiBusy} onClick={runQuotePrep} className="text-xs px-3 py-1.5 border border-indigo-300 text-indigo-700 rounded-lg disabled:opacity-50">Quote prep</button>
                )}
              </div>
            </div>
            {callPrep?.call_prep && (
              <div className="text-sm bg-indigo-50 rounded-lg p-3 space-y-1">
                <p className="font-medium">{callPrep.call_prep.scope_summary || callPrep.short_summary}</p>
                {(callPrep.call_prep.suggested_questions || []).length > 0 && (
                  <ul className="list-disc ml-4 text-xs text-slate-600">
                    {callPrep.call_prep.suggested_questions.map((q) => <li key={q}>{q}</li>)}
                  </ul>
                )}
              </div>
            )}
            {messageDraft && (
              <textarea className="w-full border border-slate-300 rounded-lg p-2 text-sm" rows={4} value={messageDraft} onChange={(e) => setMessageDraft(e.target.value)} />
            )}
            {quotePrep?.pricing && (
              <div className="text-xs text-slate-600 bg-slate-50 rounded-lg p-3">
                <p className="font-medium text-slate-800 mb-1">Calculated pricing (review before send)</p>
                <p>Contractor: ${Number(quotePrep.pricing.contractor_price).toFixed(2)} · Customer total: ${Number(quotePrep.pricing.customer_total).toFixed(2)}</p>
                {quotePrep.scope_wording && <p className="mt-2">{quotePrep.scope_wording}</p>}
              </div>
            )}
          </div>
      )}

      {isAdminOrPm && !lead.address && (
        <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 text-sm text-amber-900">
          <strong>No address on file.</strong> Add the job site address below or via Edit — contractors need it before attending.
        </div>
      )}

      {isAdminOrPm && lead.price_estimate_low != null && lead.price_estimate_high != null && (
        <div className="bg-teal-50 border border-teal-200 rounded-xl p-4 mb-6">
          <h3 className="font-semibold text-teal-900 mb-1">Public ballpark estimate shown to customer</h3>
          <p className="text-2xl font-bold text-teal-800">
            ${Number(lead.price_estimate_low).toLocaleString()} – ${Number(lead.price_estimate_high).toLocaleString()}
            {' '}{lead.price_estimate_snapshot?.currency || 'CAD'}
          </p>
          <p className="text-sm text-teal-800 mt-1">
            Estimate only — separate from contractor Quote / 80-10-10 pricing.
          </p>
          {lead.price_estimate_snapshot?.is_placeholder && (
            <p className="text-xs text-amber-700 mt-1">Uses placeholder rates pending Trystan review.</p>
          )}
          {lead.price_estimate_snapshot?.manual_override && (
            <p className="text-xs text-slate-600 mt-1">Manually overridden (logged for Learning Centre).</p>
          )}
          {lead.price_estimate_snapshot?.message && (
            <p className="text-xs text-slate-600 mt-2">{lead.price_estimate_snapshot.message}</p>
          )}
          {!showOverride ? (
            <button
              type="button"
              onClick={() => {
                setOverrideForm({
                  low: String(lead.price_estimate_low ?? ''),
                  high: String(lead.price_estimate_high ?? ''),
                  reason: '',
                });
                setShowOverride(true);
              }}
              className="mt-3 text-sm text-teal-800 underline"
            >
              Override estimate range
            </button>
          ) : (
            <div className="mt-3 grid gap-2 sm:grid-cols-3">
              <input
                type="number"
                min="0"
                step="1"
                value={overrideForm.low}
                onChange={(e) => setOverrideForm((f) => ({ ...f, low: e.target.value }))}
                className="border border-teal-300 rounded-lg px-3 py-2 text-sm"
                placeholder="Low"
              />
              <input
                type="number"
                min="0"
                step="1"
                value={overrideForm.high}
                onChange={(e) => setOverrideForm((f) => ({ ...f, high: e.target.value }))}
                className="border border-teal-300 rounded-lg px-3 py-2 text-sm"
                placeholder="High"
              />
              <input
                type="text"
                value={overrideForm.reason}
                onChange={(e) => setOverrideForm((f) => ({ ...f, reason: e.target.value }))}
                className="border border-teal-300 rounded-lg px-3 py-2 text-sm sm:col-span-3"
                placeholder="Reason (optional)"
              />
              <div className="sm:col-span-3 flex gap-2">
                <button
                  type="button"
                  onClick={() => setShowOverride(false)}
                  className="border border-slate-300 text-slate-600 rounded-lg px-3 py-2 text-sm"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={savingOverride}
                  onClick={saveEstimateOverride}
                  className="bg-teal-700 hover:bg-teal-800 disabled:opacity-50 text-white rounded-lg px-3 py-2 text-sm"
                >
                  {savingOverride ? 'Saving...' : 'Save override'}
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {isAdminOrPm && hasContractorPrice && !hasJob && (
        <div className="bg-orange-50 border border-orange-200 rounded-xl p-4 mb-6">
          <h3 className="font-semibold text-orange-800 mb-1">Contractor Price Submitted</h3>
          <p className="text-2xl font-bold text-orange-700">
            ${Number(lead.contractor_price).toFixed(2)}
          </p>
          {lead.contractor_price_notes && (
            <p className="text-sm text-orange-600 mt-1">{lead.contractor_price_notes}</p>
          )}
          {lead.contractor_price_submitted_at && (
            <p className="text-xs text-orange-500 mt-1">
              Submitted {new Date(lead.contractor_price_submitted_at).toLocaleString()}
            </p>
          )}
        </div>
      )}

      {showSendQuote && (
        <div className="bg-white rounded-xl border border-slate-200 p-5 mb-6">
          <h3 className="font-semibold text-slate-800 mb-1">Send Quote to Customer</h3>
          <p className="text-sm text-slate-500 mb-4">
            Contractor submitted ${Number(lead.contractor_price).toFixed(2)}.
            Review the pricing and send the estimate to the customer.
          </p>

          <div className="bg-slate-50 rounded-lg p-4 mb-4 space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-slate-500">Contractor Price</span>
              <span className="font-medium">${Number(lead.contractor_price).toFixed(2)}</span>
            </div>
            <div className="flex justify-between text-xs text-slate-400">
              <span>Customer Subtotal (÷{(contractorPct / 100).toFixed(2)})</span>
              <span>${Number(customerSubtotal).toFixed(2)}</span>
            </div>
            <div className="flex justify-between text-xs text-slate-400">
              <span>GST ({pricing?.gst_rate ?? 5}%)</span>
              <span>${Number(gstAmount).toFixed(2)}</span>
            </div>
            <div className="flex justify-between font-bold border-t pt-2">
              <span>Customer Total</span>
              <span>${Number(customerTotal).toFixed(2)}</span>
            </div>
          </div>

          <div className="mb-3">
            <label className="block text-xs font-medium text-slate-600 mb-1">
              Scope of Work (customer-facing)
            </label>
            <textarea
              value={quoteScope}
              onChange={(e) => setQuoteScope(e.target.value)}
              rows={3}
              placeholder={lead.project_description || lead.notes || 'Describe the work to be done...'}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div className="mb-4">
            <label className="block text-xs font-medium text-slate-600 mb-1">
              Additional Notes for Customer (optional)
            </label>
            <textarea
              value={quoteNotes}
              onChange={(e) => setQuoteNotes(e.target.value)}
              rows={2}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4 text-xs text-blue-700">
            {lead.phone && <p>SMS will be sent to {lead.phone}</p>}
            {lead.email && <p>Email will be sent to {lead.email}</p>}
            {!lead.phone && !lead.email && (
              <p className="text-red-600">No phone or email on file — please add contact info first.</p>
            )}
          </div>

          <button
            type="button"
            onClick={sendQuoteFromLead}
            disabled={sendingQuote || (!lead.phone && !lead.email)}
            className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg py-3 text-sm font-semibold"
          >
            {sendingQuote ? 'Sending...' : 'Send Quote to Customer'}
          </button>

          {quoteSent && (
            <div className="mt-3 bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-700">
              Quote sent successfully.
              {lead.phone && ' SMS delivered.'}
              {lead.email && ' Email sent.'}
            </div>
          )}
        </div>
      )}

      {isAdminOrPm && lead.lead_quote?.status === 'sent' && !hasJob && (
        <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 text-sm text-blue-800">
          Quote sent to customer — waiting for approval.
          {lead.lead_quote.sent_at && (
            <span className="block text-xs text-blue-600 mt-1">
              Sent {new Date(lead.lead_quote.sent_at).toLocaleString()}
            </span>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 text-sm">
          <h3 className="font-semibold text-slate-800">Contact Info</h3>
          <div className="flex justify-between"><span className="text-slate-500">Name</span><span>{lead.contact_name}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Phone</span><span>{lead.phone || '—'}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Email</span><span>{lead.email || '—'}</span></div>
          <div className="flex justify-between gap-4">
            <span className="text-slate-500 shrink-0">Address</span>
            {isAdminOrPm && editingAddress ? (
              <div className="flex-1 text-right space-y-2">
                <input
                  type="text"
                  value={addressDraft}
                  onChange={(e) => setAddressDraft(e.target.value)}
                  className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm text-left"
                  placeholder="Job site address"
                />
                <div className="flex gap-2 justify-end">
                  <button type="button" onClick={() => { setEditingAddress(false); setAddressDraft(lead.address || ''); }}
                    className="px-3 py-1.5 text-xs border border-slate-300 rounded-lg">Cancel</button>
                  <button type="button" onClick={saveAddress} disabled={savingAddress}
                    className="px-3 py-1.5 text-xs bg-blue-600 text-white rounded-lg disabled:opacity-50">
                    {savingAddress ? 'Saving...' : 'Save address'}
                  </button>
                </div>
              </div>
            ) : (
              <span className="text-right max-w-[60%]">
                {lead.address || <span className="text-amber-600">Not set</span>}
                {isAdminOrPm && (
                  <button type="button" onClick={() => setEditingAddress(true)}
                    className="block ml-auto mt-1 text-xs text-blue-600 hover:underline">
                    {lead.address ? 'Edit address' : 'Add address'}
                  </button>
                )}
              </span>
            )}
          </div>
        </div>

        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 text-sm">
          <h3 className="font-semibold text-slate-800">Lead Details</h3>
          <div className="flex justify-between"><span className="text-slate-500">Category</span><span className="capitalize">{formatCategory(lead.service_category)}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Source</span><span>{lead.source || '—'}</span></div>
          {lead.company_source && (
            <div className="flex justify-between">
              <span className="text-slate-500">Company Source</span>
              <span className="text-right max-w-[60%]">
                {lead.company_source.company_name}
                {lead.company_source.sender_identity ? ` (${lead.company_source.sender_identity})` : ''}
              </span>
            </div>
          )}
          <div className="flex justify-between"><span className="text-slate-500">PM</span><span>{lead.assigned_pm?.name || '—'}</span></div>
          <div>
            <label className="text-slate-500 text-xs block mb-1">Status</label>
            <select value={lead.status} onChange={(e) => updateStatus(e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
              {statuses.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
          </div>
        </div>

        <div className="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-6">
          <h3 className="font-semibold text-slate-800 mb-2">Project Description</h3>
          <p className="text-sm text-slate-600 whitespace-pre-wrap">{lead.project_description || lead.notes || '—'}</p>
        </div>

        <div className="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-5">
          <h3 className="font-semibold text-slate-800 mb-1">Assigned Contractor</h3>
          <p className="text-sm text-slate-500 mb-3">
            Assign a contractor to this lead. They will be able to view
            the project details and submit their price — even without a
            site visit if the job can be quoted over the phone.
          </p>

          {!lead.assigned_contractor && lead.booking?.match_meta?.reason && !lead.booking?.contractor_id && (
            <div className="mb-3 text-sm bg-amber-50 border border-amber-200 text-amber-900 rounded-lg px-3 py-2">
              Booking confirmed without an auto-matched contractor. {lead.booking.match_meta.reason}
              {lead.booking.match_meta.next_action_id ? ' A Next Action was created for PM follow-up.' : ''}
            </div>
          )}

          {lead.assigned_contractor && !showContractorSelect ? (
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-full bg-orange-100 flex items-center justify-center text-orange-700 font-bold text-sm">
                  {lead.assigned_contractor.name?.charAt(0)}
                </div>
                <div>
                  <p className="font-medium text-slate-800 text-sm">
                    {lead.assigned_contractor.name}
                  </p>
                  <p className="text-xs text-slate-500">
                    {lead.assigned_contractor.phone || lead.assigned_contractor.email}
                  </p>
                  {lead.booking?.auto_matched && (
                    <p className="text-xs text-teal-700 mt-1">
                      Auto-matched ({lead.booking.match_rule || 'rule'})
                      {lead.booking.match_meta?.reason ? ` — ${lead.booking.match_meta.reason}` : ''}
                    </p>
                  )}
                  {lead.booking && !lead.booking.auto_matched && lead.booking.match_rule === 'manual_pm_override' && (
                    <p className="text-xs text-slate-500 mt-1">Manually reassigned by PM (overrides auto-match).</p>
                  )}
                  {lead.booking && !lead.booking.contractor_id && lead.booking.match_meta?.reason && (
                    <p className="text-xs text-amber-700 mt-1">
                      Auto-match pending: {lead.booking.match_meta.reason}
                    </p>
                  )}
                </div>
              </div>
              <button
                type="button"
                onClick={() => {
                  setSelectedContractorId(String(lead.assigned_contractor_id || ''));
                  setShowContractorSelect(true);
                }}
                className="text-xs text-blue-600 hover:underline"
              >
                Change
              </button>
            </div>
          ) : (
            <div>
              <select
                value={selectedContractorId}
                onChange={(e) => setSelectedContractorId(e.target.value)}
                className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 mb-3"
              >
                <option value="">Select a contractor...</option>
                {contractors.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
              <div className="flex gap-2">
                {lead.assigned_contractor && (
                  <button
                    type="button"
                    onClick={() => setShowContractorSelect(false)}
                    className="flex-1 border border-slate-300 text-slate-600 rounded-lg py-2.5 text-sm font-medium"
                  >
                    Cancel
                  </button>
                )}
                <button
                  type="button"
                  onClick={assignContractor}
                  disabled={!selectedContractorId || assigningContractor}
                  className="flex-1 bg-orange-600 hover:bg-orange-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-medium"
                >
                  {assigningContractor ? 'Assigning...' : 'Assign Contractor'}
                </button>
              </div>
              <p className="text-xs text-slate-400 mt-2">
                The contractor will see this lead in their jobs list and
                can submit their price directly.
              </p>
            </div>
          )}
        </div>

        <div className="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-6">
          <div className="space-y-4">
            <h3 className="font-semibold text-slate-800">Schedule Site Visit</h3>

            {lead.site_visit_contractor && (
              <div className="bg-slate-50 border border-slate-200 rounded-lg p-3 text-sm">
                Currently assigned: <strong>{lead.site_visit_contractor?.name}</strong>
                {lead.site_visit_date && <> · {formatDate(lead.site_visit_date)} at {formatTime(lead.site_visit_time)}</>}
              </div>
            )}

            <div>
              <label className="text-xs text-slate-500 block mb-1">Job Address {lead.address ? '' : '*'}</label>
              <input
                type="text"
                value={scheduleAddress}
                onChange={(e) => setScheduleAddress(e.target.value)}
                placeholder="Enter the job site address"
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
              />
              <p className="text-xs text-slate-400 mt-1">
                {lead.address ? 'Update here if the address needs correction.' : 'Required before scheduling a site visit.'}
              </p>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="text-xs text-slate-500 block mb-1">Visit Date *</label>
                <input type="date" value={visitDate} onChange={(e) => setVisitDate(e.target.value)}
                  className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              </div>
              <div>
                <label className="text-xs text-slate-500 block mb-1">Visit Time *</label>
                <input type="time" value={visitTime} onChange={(e) => setVisitTime(e.target.value)}
                  className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              </div>
            </div>

            <div>
              <label className="text-xs text-slate-500 block mb-1">Attending Contractor *</label>
              <select value={contractorId} onChange={(e) => setContractorId(e.target.value)}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Select contractor...</option>
                {contractors.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
              <p className="text-xs text-slate-400 mt-1">
                The contractor will be notified by SMS and email when you save.
              </p>
            </div>

            <div>
              <label className="text-xs text-slate-500 block mb-1">Internal Notes (optional)</label>
              <textarea value={visitNotes} onChange={(e) => setVisitNotes(e.target.value)} rows={2}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>

            <button onClick={saveSiteVisit} disabled={!visitDate || !visitTime || !contractorId || !(lead.address || scheduleAddress) || saving}
              className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-medium">
              {saving ? 'Scheduling...' : 'Save Site Visit & Notify'}
            </button>

            {siteVisitSaved && (
              <div className="bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-700">
                ✓ Site visit scheduled. Customer and contractor have been notified.
              </div>
            )}
          </div>
        </div>

        {lead.activity?.length > 0 && (
          <div className="bg-white rounded-xl border border-slate-200 p-6">
            <h3 className="font-semibold text-slate-800 mb-3">Activity Log</h3>
            <div className="space-y-2">
              {lead.activity.map((a) => (
                <div key={a.id} className="text-xs text-slate-600 flex gap-2">
                  <span className="text-slate-400">{formatDateTime(a.created_at)}</span>
                  <span className="font-medium">{a.user?.name}</span>
                  <span>{a.action_type}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {isAdminOrPm && (
          <>
            <NextActionCard
              nextAction={lead.next_action}
              canEdit={isAdminOrPm}
              onSave={saveNextAction}
              saving={savingNextAction}
            />
            <EventTimeline
              entries={lead.event_timeline || []}
              canAdd={isAdminOrPm}
              onAdd={addTimelineNote}
              adding={addingTimeline}
            />
          </>
        )}
      </div>

      <SlideOverPanel isOpen={panelOpen} onClose={() => setPanelOpen(false)} title="Edit Lead">
        <LeadForm initial={formInitial} onSubmit={handleEdit} onCancel={() => setPanelOpen(false)} saving={saving} />
      </SlideOverPanel>
    </div>
  );
}
