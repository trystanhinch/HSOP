import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Calendar, FileText, Plus, Send, Trash2 } from 'lucide-react';
import api, { storageUrl } from '../api/axios';
import StatusBadge from '../components/StatusBadge';
import AssignUserModal from '../components/AssignUserModal';
import JobUpdateForm from '../components/JobUpdateForm';
import ContractorPriceSubmission from '../components/ContractorPriceSubmission';
import QuoteBuilder from '../components/QuoteBuilder';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';
import { formatDate, formatDateTime, toDateInputValue } from '../utils/formatDate';
import { getStatusLabel } from '../utils/statusLabels';
import NextActionCard from '../components/NextActionCard';
import EventTimeline from '../components/EventTimeline';

const roleLabel = { owner: 'Admin', pm: 'PM', contractor: 'Contractor', customer: 'Customer' };

const jobStatuses = ['new_job','contractor_assigned','site_visit_scheduled','quote_sent','quote_approved','scheduled','in_progress','progress_updated','waiting_on_customer','ready_for_review','pending_customer_approval','corrections_required','revision_requested','payment_pending','etransfer_pending_confirmation','paid_completed','completed','invoiced','paid','cancelled'];

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function calcPriceBreakdown(job) {
  const contractorPct = Number(job?.split_contractor_pct ?? 80);
  const pmPct = Number(job?.split_pm_pct ?? 10);
  const companyPct = Number(job?.split_company_pct ?? 10);
  const contractorPrice = Number(job?.contractor_submitted_price ?? 0);
  if (!contractorPrice || !contractorPct) return null;
  const customerSubtotal = contractorPrice / (contractorPct / 100);
  const pmAmount = customerSubtotal * (pmPct / 100);
  const companyAmount = customerSubtotal * (companyPct / 100);
  const gst = customerSubtotal * 0.05;
  const customerTotal = customerSubtotal + gst;
  return { contractorPrice, contractorPct, pmPct, companyPct, customerSubtotal, pmAmount, companyAmount, gst, customerTotal };
}

export default function JobDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [job, setJob] = useState(null);
  const [updates, setUpdates] = useState([]);
  const [messages, setMessages] = useState([]);
  const [msgTab, setMsgTab] = useState('customer_visible');
  const [newMsg, setNewMsg] = useState('');
  const [activeTab, setActiveTab] = useState('Overview');
  const [assignModal, setAssignModal] = useState(null);
  const [showUpdateForm, setShowUpdateForm] = useState(false);
  const [showQuoteBuilder, setShowQuoteBuilder] = useState(false);
  const [scheduleForm, setScheduleForm] = useState({ scheduled_start_date: '', scheduled_start_time: '', estimated_completion_date: '', schedule_notes: '' });
  const [quoteUrl, setQuoteUrl] = useState('');
  const [sendingQuote, setSendingQuote] = useState(false);
  const [approving, setApproving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [activityLog, setActivityLog] = useState([]);
  const [sendSms, setSendSms] = useState(false);
  const [correctionsNotes, setCorrectionsNotes] = useState('');
  const [showCorrections, setShowCorrections] = useState(false);
  const [splitForm, setSplitForm] = useState({ split_contractor_pct: '80', split_pm_pct: '10', split_company_pct: '10' });
  const [paymentRef, setPaymentRef] = useState('');
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().split('T')[0]);
  const [customerRevision, setCustomerRevision] = useState('');
  const [showCustomerRevision, setShowCustomerRevision] = useState(false);
  const [showCompleteConfirm, setShowCompleteConfirm] = useState(false);
  const [completing, setCompleting] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [savingNextAction, setSavingNextAction] = useState(false);
  const [addingTimeline, setAddingTimeline] = useState(false);

  const isCustomer = user?.role === 'customer';
  const canManage = ['owner', 'pm'].includes(user?.role);
  const isAdmin = user?.role === 'owner';
  const isContractor = user?.role === 'contractor';
  const isMyJob = Number(job?.contractor_id) === Number(user?.id);
  const canPostUpdate = job && ((isContractor && isMyJob) || canManage)
    && !['cancelled', 'paid_completed'].includes(job.status);
  const canMarkComplete = isContractor && isMyJob
    && ['in_progress', 'scheduled', 'contractor_assigned', 'progress_updated', 'revision_requested', 'corrections_required'].includes(job?.status);
  const needsPricing = job && ['pending', 'not_requested', null, undefined].includes(job.contractor_price_status);
  const priceSubmitted = job?.contractor_price_status === 'submitted';
  const priceApproved = job?.contractor_price_status === 'approved';
  const hasQuote = !!job?.quote;
  const priceBreakdown = job ? calcPriceBreakdown(job) : null;

  const loadJob = (silent = false) => {
    if (!silent) {
      setLoading(true);
      setError(null);
    }
    return api.get(`/jobs/${id}`)
      .then(({ data }) => {
        setJob(data);
        setScheduleForm({
          scheduled_start_date: toDateInputValue(data.scheduled_start_date),
          scheduled_start_time: data.scheduled_start_time || '',
          estimated_completion_date: toDateInputValue(data.estimated_completion_date) || toDateInputValue(data.scheduled_end_date),
          schedule_notes: data.schedule_notes || '',
        });
        setSplitForm({
          split_contractor_pct: String(data.split_contractor_pct ?? 80),
          split_pm_pct: String(data.split_pm_pct ?? 10),
          split_company_pct: String(data.split_company_pct ?? 10),
        });
      })
      .catch((err) => {
        setError(err.response?.data?.message || 'Failed to load job details');
        setJob(null);
      })
      .finally(() => {
        if (!silent) setLoading(false);
      });
  };

  const loadUpdates = () => {
    api.get(`/jobs/${id}/updates`).then(({ data }) => setUpdates(data)).catch(() => setUpdates([]));
  };

  const loadMessages = (vis) => {
    api.get(`/jobs/${id}/messages`, { params: { visibility: vis } }).then(({ data }) => setMessages(data)).catch(() => setMessages([]));
  };

  const saveNextAction = async (payload) => {
    setSavingNextAction(true);
    try {
      const { data } = await api.put(`/jobs/${id}/next-action`, payload);
      setJob((prev) => ({ ...prev, next_action: data.next_action }));
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
      const { data } = await api.post(`/jobs/${id}/timeline`, {
        event_type: 'manual_note',
        description: 'Manual timeline note added from Job Detail.',
      });
      setJob((prev) => ({
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

  useEffect(() => { loadJob(); }, [id]);
  useEffect(() => { if (activeTab === 'Timeline') loadUpdates(); }, [activeTab, id]);
  useEffect(() => { if (activeTab === 'Messages') loadMessages(msgTab); }, [activeTab, msgTab, id]);
  useEffect(() => {
    if (activeTab === 'Activity Log') {
      api.get(`/jobs/${id}/activity-log`).then(({ data }) => setActivityLog(data)).catch(() => setActivityLog([]));
    }
  }, [activeTab, id]);

  const sendMessage = async (e) => {
    e.preventDefault();
    if (!newMsg.trim()) return;
    const ok = await confirmAction({
      title: 'Send message?',
      text: msgTab === 'internal' ? 'Send this internal note?' : 'Send this message to the customer?',
      confirmText: 'Yes, send',
    });
    if (!ok) return;

    try {
      await api.post(`/jobs/${id}/messages`, { content: newMsg, visibility: msgTab, send_sms: sendSms });
      setNewMsg('');
      loadMessages(msgTab);
      await showSuccess('Message sent.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to send message.');
    }
  };

  const scheduleJob = async (e) => {
    e.preventDefault();
    const ok = await confirmAction({
      title: 'Schedule job?',
      text: 'Save the schedule dates and notes for this job?',
      confirmText: 'Yes, schedule',
    });
    if (!ok) return;

    try {
      await api.post(`/jobs/${id}/schedule`, scheduleForm);
      await showSuccess('Job scheduled.');
      loadJob(true);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to schedule job.');
    }
  };

  const updateJobStatus = async (status) => {
    if (status === job?.status) return;
    const ok = await confirmAction({
      title: 'Change job status?',
      text: `Update status to "${status.replace(/_/g, ' ')}"?`,
      confirmText: 'Yes, update',
    });
    if (!ok) return;

    try {
      await api.put(`/jobs/${id}`, { status });
      await showSuccess('Job status updated.');
      loadJob(true);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to update status.');
    }
  };

  const saveScope = async (scope) => {
    if (scope === (job?.scope_of_work || '')) return;
    const ok = await confirmAction({
      title: 'Save scope of work?',
      text: 'Update the scope of work for this job?',
      confirmText: 'Yes, save',
    });
    if (!ok) return;

    try {
      await api.put(`/jobs/${id}`, { scope_of_work: scope });
      await showSuccess('Scope of work saved.');
      loadJob(true);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save scope.');
    }
  };

  const sendQuote = async () => {
    if (!job?.quote?.id) return;
    const ok = await confirmAction({
      title: 'Send estimate to customer?',
      text: 'The customer will receive SMS and email with a link to review and approve this estimate.',
      confirmText: 'Yes, send estimate',
    });
    if (!ok) return;

    setSendingQuote(true);
    try {
      const { data } = await api.post(`/quotes/${job.quote.id}/send`);
      setQuoteUrl(data.quote_url);
      await showSuccess('Estimate sent to customer.');
      loadJob(true);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to send estimate.');
    } finally {
      setSendingQuote(false);
    }
  };

  const approvePriceAndCreateQuote = async () => {
    setApproving(true);
    try {
      await api.post(`/jobs/${id}/approve-price`);

      if (!job?.quote) {
        await api.post('/quotes', {
          job_id: job.id,
          scope_of_work: job.scope_of_work || job.lead?.project_description || 'Scope of work',
          contractor_price: job.contractor_submitted_price,
          gst_enabled: true,
        });
      }

      await showSuccess('Estimate created. Review it and send to the customer.');
      await loadJob(true);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to approve price.');
    } finally {
      setApproving(false);
    }
  };

  const markComplete = async () => {
    setCompleting(true);
    try {
      await api.post(`/jobs/${id}/contractor-complete`);
      setShowCompleteConfirm(false);
      await showSuccess('Job marked complete. Customer has been notified to review.');
      await loadJob(true);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to mark complete.');
    } finally {
      setCompleting(false);
    }
  };

  const deleteJob = async () => {
    setDeleting(true);
    try {
      await api.delete(`/jobs/${job.id}`);
      await showSuccess('Job deleted successfully');
      navigate('/jobs');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to delete job');
    } finally {
      setDeleting(false);
      setShowDeleteConfirm(false);
    }
  };

  const createInvoice = async () => {
    if (!job?.quote?.id) return;
    const ok = await confirmAction({
      title: 'Create invoice?',
      text: 'Create a draft invoice from this approved quote?',
      confirmText: 'Yes, create invoice',
    });
    if (!ok) return;

    try {
      await api.post(`/quotes/${job.quote.id}/create-invoice`);
      await showSuccess('Invoice draft created.');
      loadJob(true);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to create invoice.');
    }
  };

  if (loading) {
    return <div className="text-center py-12 text-slate-500">Loading job...</div>;
  }

  if (error) {
    return (
      <div className="p-6 text-center">
        <p className="text-red-600 mb-2">{error}</p>
        <button type="button" onClick={() => loadJob()} className="text-blue-600 text-sm underline mr-4">Try again</button>
        <Link to="/jobs" className="text-slate-600 text-sm underline">Back to Jobs</Link>
      </div>
    );
  }

  if (!job) {
    return <div className="text-center py-12 text-slate-500">Job not found.</div>;
  }

  const tabs = isCustomer
    ? ['Overview', 'Timeline', 'Messages']
    : isContractor
      ? ['Overview', 'Timeline', 'Messages']
      : ['Overview', 'Timeline', 'Messages', 'Quote & Pricing', 'Documents', 'Activity Log'];

  return (
    <div>
      <Link to="/jobs" className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900 mb-6">
        <ArrowLeft size={16} /> Back to Jobs
      </Link>

      <div className="bg-white rounded-xl border border-slate-200 p-6 mb-6">
        <div className="flex flex-wrap items-center justify-between gap-3 mb-2">
          <div className="flex flex-wrap items-center gap-3">
            <h2 className="text-lg font-semibold text-slate-900">{job.job_title || `Job #${job.id}`}</h2>
            {canManage ? (
              <select value={job.status} onChange={(e) => updateJobStatus(e.target.value)}
                className="text-sm border border-slate-300 rounded-lg px-2 py-1">
                {jobStatuses.map((s) => <option key={s} value={s}>{getStatusLabel(s)}</option>)}
              </select>
            ) : (
              <StatusBadge status={job.status} />
            )}
          </div>
          {isAdmin && (
            <button
              type="button"
              onClick={() => setShowDeleteConfirm(true)}
              className="flex items-center gap-1.5 text-red-600 border border-red-300 hover:bg-red-50 rounded-lg px-3 py-2 text-sm font-medium"
            >
              <Trash2 className="w-4 h-4" />
              Delete Job
            </button>
          )}
        </div>
        <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-slate-500">Customer</dt>
            <dd className="font-medium mt-0.5">{job.customer?.name || job.lead?.contact_name || 'Not assigned'}</dd>
            {(job.customer?.email || job.lead?.email) && (
              <dd className="text-xs text-slate-500 mt-0.5">{job.customer?.email || job.lead?.email}</dd>
            )}
          </div>
          {!isCustomer && (
            <div>
              <dt className="text-slate-500">Contractor</dt>
              <dd className="font-medium mt-0.5 flex items-center gap-2">
                {job.contractor?.name || '—'}
                {canManage && <button onClick={() => setAssignModal('contractor')} className="text-xs text-blue-600 hover:underline">Change</button>}
              </dd>
            </div>
          )}
          {!isCustomer && (
            <div>
              <dt className="text-slate-500">PM</dt>
              <dd className="font-medium mt-0.5 flex items-center gap-2">
                {job.pm?.name || '—'}
                {isAdmin && <button onClick={() => setAssignModal('pm')} className="text-xs text-blue-600 hover:underline">Change</button>}
              </dd>
            </div>
          )}
          <div><dt className="text-slate-500">Category</dt><dd className="font-medium mt-0.5 capitalize">{formatCategory(job.service_category)}</dd></div>
          <div><dt className="text-slate-500">Address</dt><dd className="font-medium mt-0.5">{job.address || '—'}</dd></div>
        </dl>
      </div>

      {isContractor && isMyJob && (
        <div className="mb-6">
          {needsPricing && (
            <ContractorPriceSubmission job={job} onSubmitted={() => loadJob(true)} />
          )}

          {priceSubmitted && !priceApproved && (
            <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
              <p className="text-sm font-medium text-yellow-800">
                ✓ Price submitted — ${Number(job.contractor_submitted_price || 0).toFixed(2)}
              </p>
              <p className="text-xs text-yellow-700 mt-1">
                Waiting for the project manager to review and send the estimate to the customer.
              </p>
            </div>
          )}

          {priceApproved && (
            <div className="bg-green-50 border border-green-200 rounded-xl p-4">
              <p className="text-sm font-medium text-green-800">
                ✓ Your price has been approved and the estimate has been sent to the customer.
              </p>
            </div>
          )}
        </div>
      )}

      {canManage && priceSubmitted && priceBreakdown && (
        <div className="bg-white rounded-xl border-2 border-orange-300 p-5 mb-6">
          <div className="flex items-center gap-2 mb-4">
            <span className="text-2xl">💰</span>
            <div>
              <h3 className="font-semibold text-slate-800">Contractor Price Submitted</h3>
              {job.contractor_price_submitted_at && (
                <p className="text-xs text-slate-500">
                  Submitted {formatDateTime(job.contractor_price_submitted_at)}
                </p>
              )}
            </div>
          </div>

          <div className="bg-slate-50 rounded-lg p-4 mb-4 space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-slate-500">Contractor price (submitted)</span>
              <span className="font-semibold text-slate-800">${priceBreakdown.contractorPrice.toFixed(2)}</span>
            </div>
            <div className="flex justify-between text-xs text-slate-400">
              <span>Customer subtotal ({priceBreakdown.contractorPct}% split = ÷{(priceBreakdown.contractorPct / 100).toFixed(2)})</span>
              <span>${priceBreakdown.customerSubtotal.toFixed(2)}</span>
            </div>
            <div className="flex justify-between text-xs text-slate-400">
              <span>PM share ({priceBreakdown.pmPct}%)</span>
              <span>${priceBreakdown.pmAmount.toFixed(2)}</span>
            </div>
            <div className="flex justify-between text-xs text-slate-400">
              <span>Company share ({priceBreakdown.companyPct}%)</span>
              <span>${priceBreakdown.companyAmount.toFixed(2)}</span>
            </div>
            <div className="border-t pt-2 flex justify-between font-medium">
              <span className="text-slate-600">Customer total (inc. 5% GST)</span>
              <span className="text-slate-800">${priceBreakdown.customerTotal.toFixed(2)}</span>
            </div>
          </div>

          <button
            type="button"
            onClick={approvePriceAndCreateQuote}
            disabled={approving}
            className="w-full bg-orange-600 hover:bg-orange-700 text-white rounded-lg py-2.5 font-medium text-sm disabled:opacity-60"
          >
            {approving ? 'Approving...' : 'Approve Price & Create Estimate'}
          </button>
        </div>
      )}

      {canManage && priceApproved && hasQuote && ['draft', 'revised'].includes(job.quote.status) && (
        <div className="bg-green-50 border border-green-200 rounded-xl p-5 mb-6">
          <h3 className="font-semibold text-green-800 mb-2">Estimate Ready to Send</h3>
          <div className="text-sm space-y-1 mb-4">
            <div className="flex justify-between">
              <span className="text-slate-500">Customer total</span>
              <span className="font-bold text-slate-800">
                ${Number(job.quote.customer_total || 0).toFixed(2)}
              </span>
            </div>
          </div>
          <button
            type="button"
            onClick={sendQuote}
            disabled={sendingQuote}
            className="w-full bg-green-600 hover:bg-green-700 text-white rounded-lg py-2.5 font-medium text-sm disabled:opacity-60"
          >
            {sendingQuote ? 'Sending...' : 'Send Estimate to Customer via SMS + Email'}
          </button>
        </div>
      )}

      {canManage && priceApproved && hasQuote && !['draft', 'revised'].includes(job.quote.status) && (
        <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
          <p className="text-sm font-medium text-blue-800">✓ Estimate sent to customer</p>
          {job.quote.sent_at && (
            <p className="text-xs text-blue-600 mt-1">
              Sent {formatDateTime(job.quote.sent_at)}
            </p>
          )}
          <p className="text-xs text-blue-600">Status: {job.quote.status}</p>
        </div>
      )}

      <div className="flex overflow-x-auto border-b border-slate-200 -mx-4 px-4 md:mx-0 md:px-0 mb-4">
        <div className="flex gap-1 min-w-max pb-2">
        {tabs.map((tab) => (
          <button key={tab} type="button" onClick={() => setActiveTab(tab)}
            className={`px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition-colors ${activeTab === tab ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-100'}`}>
            {tab}
          </button>
        ))}
        </div>
      </div>

      {/* Overview */}
      {activeTab === 'Overview' && (
        <div className="space-y-6">
          {job.status === 'paid_completed' && job.payment_confirmed_at && (
            <div className="bg-green-50 border border-green-200 text-green-800 rounded-xl p-4 text-sm">
              Payment confirmed on {formatDate(job.payment_confirmed_at)}
            </div>
          )}
          {job.status === 'completed' && job.completed_at && (
            <div className="bg-green-50 border border-green-200 text-green-800 rounded-xl p-4 text-sm">
              Job completed on {formatDate(job.completed_at)}
            </div>
          )}
          {job.status === 'revision_requested' && job.revision_description && (
            <div className="bg-orange-50 border border-orange-200 text-orange-900 rounded-xl p-4 text-sm">
              <strong>Revision requested:</strong> {job.revision_description}
            </div>
          )}
          {job.status === 'corrections_required' && job.corrections_notes && (
            <div className="bg-orange-50 border border-orange-200 text-orange-900 rounded-xl p-4 text-sm">
              <strong>Corrections requested:</strong> {job.corrections_notes}
            </div>
          )}
          {isContractor && isMyJob && job.status === 'pending_customer_approval' && (
            <div className="bg-blue-50 border border-blue-200 rounded-xl p-5">
              <p className="text-sm font-semibold text-blue-800">✓ Job Marked Complete</p>
              <p className="text-sm text-blue-600 mt-1">
                The customer has been notified and is reviewing the completed work.
                You will be notified when they accept or request changes.
              </p>
            </div>
          )}
          {isContractor && isMyJob && job.status === 'revision_requested' && (
            <div className="bg-orange-50 border border-orange-200 rounded-xl p-5">
              <p className="text-sm font-semibold text-orange-800">⚠ Revision Requested</p>
              {job.revision_description && (
                <p className="text-sm text-orange-600 mt-1">{job.revision_description}</p>
              )}
              <p className="text-xs text-orange-500 mt-2">
                Please address the customer&apos;s feedback and post an update when ready.
              </p>
              <button
                type="button"
                onClick={() => setShowCompleteConfirm(true)}
                className="mt-3 w-full bg-orange-600 hover:bg-orange-700 text-white rounded-lg py-2 text-sm font-medium"
              >
                Mark Complete Again
              </button>
            </div>
          )}
          {canMarkComplete && (
            <div className="bg-white rounded-xl border border-slate-200 p-5">
              <h3 className="font-semibold text-slate-800 mb-1">Mark Job Complete</h3>
              <p className="text-sm text-slate-500 mb-4">
                Once all work is finished, mark the job complete. The customer will
                receive a notification to review and accept the completed work.
              </p>
              <button
                type="button"
                onClick={() => setShowCompleteConfirm(true)}
                className="w-full bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg py-3 text-sm"
              >
                Mark Job Complete
              </button>
            </div>
          )}
          {isCustomer && job.status === 'pending_customer_approval' && (
            <div className="flex flex-wrap gap-2">
              <button type="button" onClick={async () => {
                try {
                  const { data } = await api.post(`/jobs/${id}/accept-completion`);
                  window.location.href = data.payment_url;
                } catch (err) { await showError(err.response?.data?.message || 'Failed'); }
              }} className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">
                Accept Completion
              </button>
              <button type="button" onClick={() => setShowCustomerRevision(true)}
                className="px-4 py-2 bg-orange-600 text-white rounded-lg text-sm font-medium hover:bg-orange-700">
                Request Revision
              </button>
            </div>
          )}
          {isCustomer && job.status === 'payment_pending' && (
            <a href={`/payment/${id}`} className="inline-block px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
              Go to Payment
            </a>
          )}
          {isAdmin && job.status === 'etransfer_pending_confirmation' && (
            <div className="bg-white border border-slate-200 rounded-xl p-4 space-y-3">
              <h4 className="font-semibold text-slate-800 text-sm">Confirm E-Transfer Payment</h4>
              <input type="text" value={paymentRef} onChange={(e) => setPaymentRef(e.target.value)} placeholder="Reference number (optional)"
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <button type="button" onClick={async () => {
                try {
                  await api.post(`/jobs/${id}/confirm-payment`, { payment_reference: paymentRef, payment_date: paymentDate });
                  await showSuccess('Payment confirmed.');
                  loadJob(true);
                } catch (err) { await showError(err.response?.data?.message || 'Failed'); }
              }} className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">
                Confirm Payment
              </button>
            </div>
          )}
          {canManage && job.status === 'ready_for_review' && (
            <div className="flex flex-wrap gap-2">
              <button type="button" onClick={async () => {
                try {
                  await api.post(`/jobs/${id}/mark-complete`);
                  await showSuccess('Job marked complete.');
                  loadJob(true);
                } catch (err) { await showError(err.response?.data?.message || 'Failed'); }
              }} className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">
                Mark Complete
              </button>
              <button type="button" onClick={() => setShowCorrections(true)} className="px-4 py-2 bg-orange-600 text-white rounded-lg text-sm font-medium hover:bg-orange-700">
                Request Corrections
              </button>
            </div>
          )}
          <div className="bg-white rounded-xl border border-slate-200 p-6">
            <h3 className="font-semibold text-slate-800 mb-3">Scope of Work</h3>
            <p className="text-sm text-slate-600 whitespace-pre-wrap">{job.scope_of_work || '—'}</p>
            {canManage && (
              <textarea defaultValue={job.scope_of_work || ''} onBlur={(e) => saveScope(e.target.value)}
                className="w-full mt-3 border border-slate-200 rounded-lg px-3 py-2 text-sm" rows={3} placeholder="Edit scope..." />
            )}
          </div>

          {isAdmin && (
            <div className="bg-white rounded-xl border border-slate-200 p-6">
              <h3 className="font-semibold text-slate-800 mb-3">Override Split for This Job</h3>
              <div className="grid grid-cols-3 gap-3 mb-3">
                <div>
                  <label className="text-xs text-slate-500 block mb-1">Contractor %</label>
                  <input type="number" value={splitForm.split_contractor_pct} onChange={(e) => setSplitForm({ ...splitForm, split_contractor_pct: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label className="text-xs text-slate-500 block mb-1">PM %</label>
                  <input type="number" value={splitForm.split_pm_pct} onChange={(e) => setSplitForm({ ...splitForm, split_pm_pct: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label className="text-xs text-slate-500 block mb-1">Company %</label>
                  <input type="number" value={splitForm.split_company_pct} onChange={(e) => setSplitForm({ ...splitForm, split_company_pct: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                </div>
              </div>
              <button type="button" onClick={async () => {
                try {
                  await api.put(`/jobs/${id}/split`, splitForm);
                  await showSuccess('Split updated.');
                  loadJob(true);
                } catch (err) { await showError(err.response?.data?.message || 'Failed'); }
              }} className="px-4 py-2 bg-slate-700 text-white text-sm rounded-lg hover:bg-slate-800">
                Save Split Override
              </button>
            </div>
          )}

          {canManage && (
            <div className="bg-white rounded-xl border border-slate-200 p-6">
              <h3 className="font-semibold text-slate-800 mb-3 flex items-center gap-2"><Calendar className="w-4 h-4" /> Schedule</h3>
              <form onSubmit={scheduleJob} className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="text-xs text-slate-500 block mb-1">Start Date</label>
                  <input type="date" value={scheduleForm.scheduled_start_date} onChange={(e) => setScheduleForm({ ...scheduleForm, scheduled_start_date: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div>
                  <label className="text-xs text-slate-500 block mb-1">Start Time</label>
                  <input type="time" value={scheduleForm.scheduled_start_time} onChange={(e) => setScheduleForm({ ...scheduleForm, scheduled_start_time: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label className="text-xs text-slate-500 block mb-1">Est. Completion</label>
                  <input type="date" value={scheduleForm.estimated_completion_date} onChange={(e) => setScheduleForm({ ...scheduleForm, estimated_completion_date: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div className="sm:col-span-2">
                  <label className="text-xs text-slate-500 block mb-1">Schedule Notes</label>
                  <input type="text" value={scheduleForm.schedule_notes} onChange={(e) => setScheduleForm({ ...scheduleForm, schedule_notes: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <button type="submit" className="sm:col-span-2 w-fit px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">Schedule Job</button>
              </form>
            </div>
          )}

          {canManage && (
            <NextActionCard
              nextAction={job.next_action}
              canEdit={canManage}
              onSave={saveNextAction}
              saving={savingNextAction}
            />
          )}
          <EventTimeline
            entries={job.event_timeline || []}
            canAdd={canManage}
            onAdd={addTimelineNote}
            adding={addingTimeline}
          />
        </div>
      )}

      {/* Timeline */}
      {activeTab === 'Timeline' && (
        <div>
          {canPostUpdate && (
            <button
              type="button"
              onClick={() => setShowUpdateForm(true)}
              className="mb-4 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg px-4 py-2.5 flex items-center gap-2"
            >
              <Plus className="w-4 h-4" />
              Add Update
            </button>
          )}
          <div className="space-y-4">
            {updates.length === 0 ? (
              <p className="text-sm text-slate-500 text-center py-8">No updates yet.</p>
            ) : updates.map((u) => (
              <div key={u.id} className={`rounded-xl border p-4 ${u.visibility === 'internal' ? 'bg-slate-100 border-slate-300' : 'bg-white border-slate-200'}`}>
                <div className="flex items-center gap-2 mb-2">
                  <div className="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-bold">
                    {(u.posted_by?.name || u.postedBy?.name || '?').charAt(0)}
                  </div>
                  <div>
                    <p className="text-sm font-medium text-slate-800">{u.posted_by?.name || u.postedBy?.name}</p>
                    <p className="text-xs text-slate-400">{roleLabel[u.poster_role] || u.poster_role} · {formatDateTime(u.created_at)}</p>
                  </div>
                  {u.visibility === 'internal' && <span className="ml-auto text-xs bg-slate-200 text-slate-600 px-2 py-0.5 rounded-full">Internal Only</span>}
                </div>
                <p className="text-sm text-slate-700">{u.update_text}</p>
                {u.photos?.filter((p) => p.file_url)?.length > 0 && (
                  <div className="flex flex-wrap gap-2 mt-3">
                    {u.photos.filter((p) => p.file_url).map((p) => (
                      <a key={p.id} href={storageUrl(p.file_url)} target="_blank" rel="noreferrer">
                        <img
                          src={storageUrl(p.file_url)}
                          alt={p.file_name}
                          className="w-20 h-20 object-cover rounded-lg border"
                          onError={(e) => { e.target.style.display = 'none'; }}
                        />
                      </a>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Messages */}
      {activeTab === 'Messages' && (
        <div className="bg-white rounded-xl border border-slate-200 flex flex-col" style={{ height: '480px' }}>
          {!isCustomer && (
            <div className="flex border-b border-slate-200">
              {['customer_visible', 'internal'].map((v) => (
                <button key={v} onClick={() => setMsgTab(v)}
                  className={`px-4 py-2.5 text-sm font-medium ${msgTab === v ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-500'}`}>
                  {v === 'customer_visible' ? 'Customer Chat' : 'Internal Notes'}
                </button>
              ))}
            </div>
          )}
          <div className="flex-1 overflow-y-auto p-4 space-y-3">
            {messages.map((m) => {
              const mine = m.sender_id === user?.id;
              return (
                <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                  <div className={`max-w-[75%] rounded-xl px-4 py-2 text-sm ${mine ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'}`}>
                    {!mine && <p className="text-xs opacity-70 mb-1">{m.sender?.name}</p>}
                    {m.content}
                    <p className={`text-xs mt-1 ${mine ? 'text-blue-200' : 'text-slate-400'}`}>{formatDateTime(m.created_at)}</p>
                  </div>
                </div>
              );
            })}
          </div>
          <form onSubmit={sendMessage} className="border-t border-slate-200 p-3 flex flex-col gap-2">
            {canManage && msgTab === 'customer_visible' && (
              <label className="flex items-center gap-2 text-xs text-slate-600">
                <input type="checkbox" checked={sendSms} onChange={(e) => setSendSms(e.target.checked)} />
                Also send as SMS
              </label>
            )}
            <div className="flex gap-2">
            <input value={newMsg} onChange={(e) => setNewMsg(e.target.value)} placeholder="Type a message..."
              className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            <button type="submit" className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">Send</button>
            </div>
          </form>
        </div>
      )}

      {/* Quote & Pricing */}
      {activeTab === 'Quote & Pricing' && canManage && (
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          {!job.quote ? (
            <div className="text-center py-8">
              <FileText className="w-10 h-10 text-slate-300 mx-auto mb-3" />
              <p className="text-slate-500 text-sm mb-4">No quote created yet.</p>
              <button onClick={() => setShowQuoteBuilder(true)} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">Create Quote</button>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="flex flex-wrap items-center gap-3">
                <h3 className="font-semibold text-slate-800">{job.quote.quote_number}</h3>
                <StatusBadge status={job.quote.status} />
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div><p className="text-slate-500">Subtotal</p><p className="font-medium">${parseFloat(job.quote.subtotal || job.quote.customer_price_before_gst || 0).toFixed(2)}</p></div>
                <div><p className="text-slate-500">GST</p><p className="font-medium">${parseFloat(job.quote.gst || 0).toFixed(2)}</p></div>
                <div><p className="text-slate-500">Total</p><p className="font-bold">${parseFloat(job.quote.customer_total || 0).toFixed(2)}</p></div>
                {isAdmin && job.quote.pm_amount != null && (
                  <div><p className="text-slate-500">PM Share</p><p className="font-medium">${parseFloat(job.quote.pm_amount).toFixed(2)}</p></div>
                )}
                {isAdmin && job.quote.company_amount != null && (
                  <div><p className="text-slate-500">Company Share</p><p className="font-medium">${parseFloat(job.quote.company_amount).toFixed(2)}</p></div>
                )}
              </div>
              {job.quote.items?.length > 0 && (
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm divide-y divide-slate-200">
                    <thead><tr className="text-slate-500"><th className="text-left py-2">Item</th><th className="text-right py-2">Total</th></tr></thead>
                    <tbody>{job.quote.items.map((i) => <tr key={i.id}><td className="py-2">{i.description}</td><td className="text-right py-2">${parseFloat(i.total).toFixed(2)}</td></tr>)}</tbody>
                  </table>
                </div>
              )}
              <div className="flex flex-wrap gap-3">
                {['draft', 'revised'].includes(job.quote.status) && (
                  <button onClick={sendQuote} disabled={sendingQuote} className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
                    <Send className="w-4 h-4" /> {sendingQuote ? 'Sending...' : 'Send Quote to Customer'}
                  </button>
                )}
                {job.quote.status === 'approved' && !job.invoice && (
                  <button onClick={createInvoice} className="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700">Create Invoice</button>
                )}
              </div>
              {quoteUrl && (
                <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm">
                  <p className="font-medium text-blue-800 mb-1">Customer Quote Link:</p>
                  <a href={quoteUrl} target="_blank" rel="noreferrer" className="text-blue-600 break-all hover:underline">{quoteUrl}</a>
                </div>
              )}
              {job.quote.customer_token && job.quote.status !== 'draft' && !quoteUrl && (
                <div className="bg-slate-50 border border-slate-200 rounded-lg p-3 text-sm">
                  <p className="text-slate-600">Quote link: <a href={`/quote/view/${job.quote.customer_token}`} target="_blank" rel="noreferrer" className="text-blue-600 hover:underline">/quote/view/{job.quote.customer_token}</a></p>
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {activeTab === 'Documents' && canManage && (
        <div className="bg-white rounded-xl border border-slate-200 p-8 text-center">
          <p className="text-slate-500 text-sm">Job documents — Coming in Milestone 3</p>
        </div>
      )}

      {activeTab === 'Activity Log' && (
        <div className="bg-white rounded-xl border border-slate-200 p-4 space-y-3">
          {activityLog.length === 0 ? (
            <p className="text-sm text-slate-500 text-center py-8">No activity recorded yet.</p>
          ) : activityLog.map((entry) => (
            <div key={entry.id} className="flex gap-3 border-b border-slate-100 pb-3 last:border-0">
              <div className="text-lg">📋</div>
              <div>
                <p className="text-sm text-slate-800">
                  <span className="font-medium">{getStatusLabel(entry.action_type?.replace(/_/g, ' '))}</span>
                  {entry.user && <> — by {entry.user.name} ({entry.user.role})</>}
                </p>
                <p className="text-xs text-slate-400">{formatDateTime(entry.created_at)}</p>
              </div>
            </div>
          ))}
        </div>
      )}

      {showCustomerRevision && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 className="font-semibold mb-3">Request Revision</h3>
            <textarea value={customerRevision} onChange={(e) => setCustomerRevision(e.target.value)} rows={4}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4" placeholder="Describe what needs to be changed..." />
            <div className="flex gap-2">
              <button type="button" onClick={() => setShowCustomerRevision(false)} className="flex-1 border rounded-lg py-2 text-sm">Cancel</button>
              <button type="button" onClick={async () => {
                try {
                  await api.post(`/jobs/${id}/request-revision`, { description: customerRevision });
                  await showSuccess('Revision request submitted.');
                  setShowCustomerRevision(false);
                  loadJob(true);
                } catch (err) { await showError(err.response?.data?.message || 'Failed'); }
              }} className="flex-1 bg-orange-600 text-white rounded-lg py-2 text-sm font-medium">Submit</button>
            </div>
          </div>
        </div>
      )}

      {showCorrections && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 className="font-semibold mb-3">Request Corrections</h3>
            <textarea value={correctionsNotes} onChange={(e) => setCorrectionsNotes(e.target.value)} rows={4}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4" placeholder="Describe what needs to be corrected..." />
            <div className="flex gap-2">
              <button type="button" onClick={() => setShowCorrections(false)} className="flex-1 border rounded-lg py-2 text-sm">Cancel</button>
              <button type="button" onClick={async () => {
                try {
                  await api.post(`/jobs/${id}/request-corrections`, { corrections_notes: correctionsNotes });
                  await showSuccess('Corrections requested.');
                  setShowCorrections(false);
                  loadJob(true);
                } catch (err) { await showError(err.response?.data?.message || 'Failed'); }
              }} className="flex-1 bg-orange-600 text-white rounded-lg py-2 text-sm font-medium">Submit</button>
            </div>
          </div>
        </div>
      )}

      {showCompleteConfirm && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl p-6 max-w-sm w-full">
            <div className="text-center mb-4">
              <div className="text-4xl mb-3">✅</div>
              <h3 className="font-semibold text-slate-800 text-lg">Mark Job Complete?</h3>
              <p className="text-sm text-slate-500 mt-2">
                The customer will be notified and asked to review and accept the
                completed work before payment is processed.
              </p>
            </div>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => setShowCompleteConfirm(false)}
                className="flex-1 border border-slate-300 text-slate-600 rounded-lg py-2.5 text-sm font-medium"
              >
                Not Yet
              </button>
              <button
                type="button"
                onClick={markComplete}
                disabled={completing}
                className="flex-1 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-medium"
              >
                {completing ? 'Submitting...' : 'Yes, Mark Complete'}
              </button>
            </div>
          </div>
        </div>
      )}

      {showDeleteConfirm && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl p-6 max-w-sm w-full">
            <div className="text-center mb-4">
              <div className="text-4xl mb-3">⚠️</div>
              <h3 className="font-semibold text-slate-800 text-lg">Delete This Job?</h3>
              <p className="text-sm text-slate-500 mt-2">
                This will permanently delete the job, all messages, updates,
                photos, quotes, and invoices. The lead will be reset so it
                can be converted again. This cannot be undone.
              </p>
            </div>
            <div className="flex gap-3 mt-4">
              <button
                type="button"
                onClick={() => setShowDeleteConfirm(false)}
                className="flex-1 border border-slate-300 text-slate-600 rounded-lg py-2.5 text-sm font-medium"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={deleteJob}
                disabled={deleting}
                className="flex-1 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-medium"
              >
                {deleting ? 'Deleting...' : 'Yes, Delete Job'}
              </button>
            </div>
          </div>
        </div>
      )}

      {assignModal && (
        <AssignUserModal
          jobId={id}
          type={assignModal}
          currentName={assignModal === 'pm' ? job.pm?.name : job.contractor?.name}
          onClose={() => setAssignModal(null)}
          onAssigned={loadJob}
        />
      )}
      {showUpdateForm && (
        <JobUpdateForm
          jobId={id}
          onClose={() => setShowUpdateForm(false)}
          onPosted={() => { loadUpdates(); loadJob(true); }}
        />
      )}
      {showQuoteBuilder && <QuoteBuilder job={job} onClose={() => setShowQuoteBuilder(false)} onCreated={loadJob} />}
    </div>
  );
}
