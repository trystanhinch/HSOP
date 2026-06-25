import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Calendar, Send, FileText } from 'lucide-react';
import api, { storageUrl } from '../api/axios';
import StatusBadge from '../components/StatusBadge';
import AssignUserModal from '../components/AssignUserModal';
import JobUpdateForm from '../components/JobUpdateForm';
import QuoteBuilder from '../components/QuoteBuilder';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';
import { getStatusLabel } from '../utils/statusLabels';

const roleLabel = { owner: 'Admin', pm: 'PM', contractor: 'Contractor', customer: 'Customer' };

const jobStatuses = ['new_job','contractor_assigned','quote_sent','quote_approved','scheduled','in_progress','waiting_on_customer','ready_for_review','corrections_required','completed_by_contractor','final_review','completed','invoiced','paid','cancelled'];

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

export default function JobDetail() {
  const { id } = useParams();
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
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [activityLog, setActivityLog] = useState([]);
  const [sendSms, setSendSms] = useState(false);
  const [correctionsNotes, setCorrectionsNotes] = useState('');
  const [showCorrections, setShowCorrections] = useState(false);

  const isCustomer = user?.role === 'customer';
  const canManage = ['owner', 'pm'].includes(user?.role);
  const isAdmin = user?.role === 'owner';
  const isContractor = user?.role === 'contractor';

  const loadJob = (silent = false) => {
    if (!silent) {
      setLoading(true);
      setError(null);
    }
    return api.get(`/jobs/${id}`)
      .then(({ data }) => {
        setJob(data);
        setScheduleForm({
          scheduled_start_date: data.scheduled_start_date?.split('T')[0] || '',
          scheduled_start_time: data.scheduled_start_time || '',
          estimated_completion_date: data.estimated_completion_date?.split('T')[0] || data.scheduled_end_date?.split('T')[0] || '',
          schedule_notes: data.schedule_notes || '',
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
      title: 'Send quote to customer?',
      text: 'The customer will receive a link to review and approve this quote.',
      confirmText: 'Yes, send quote',
    });
    if (!ok) return;

    setSendingQuote(true);
    try {
      const { data } = await api.post(`/quotes/${job.quote.id}/send`);
      setQuoteUrl(data.quote_url);
      await showSuccess('Quote sent to customer.');
      loadJob(true);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to send quote.');
    } finally {
      setSendingQuote(false);
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
    : ['Overview', 'Timeline', 'Messages', 'Quote & Pricing', 'Documents', 'Activity Log'];

  return (
    <div>
      <Link to="/jobs" className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900 mb-6">
        <ArrowLeft size={16} /> Back to Jobs
      </Link>

      <div className="bg-white rounded-xl border border-slate-200 p-6 mb-6">
        <div className="flex flex-wrap items-center gap-3 mb-2">
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
          {job.status === 'completed' && job.completed_at && (
            <div className="bg-green-50 border border-green-200 text-green-800 rounded-xl p-4 text-sm">
              Job completed on {new Date(job.completed_at).toLocaleDateString()}
            </div>
          )}
          {job.status === 'corrections_required' && job.corrections_notes && (
            <div className="bg-orange-50 border border-orange-200 text-orange-900 rounded-xl p-4 text-sm">
              <strong>Corrections requested:</strong> {job.corrections_notes}
            </div>
          )}
          {isContractor && job.status === 'in_progress' && (
            <button type="button" onClick={async () => {
              try {
                await api.post(`/jobs/${id}/mark-ready-for-review`);
                await showSuccess('Marked ready for review.');
                loadJob(true);
              } catch (err) { await showError(err.response?.data?.message || 'Failed'); }
            }} className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700">
              Mark Ready for Review
            </button>
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
        </div>
      )}

      {/* Timeline */}
      {activeTab === 'Timeline' && (
        <div>
          {!isCustomer && (
            <button onClick={() => setShowUpdateForm(true)} className="mb-4 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">
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
                    <p className="text-xs text-slate-400">{roleLabel[u.poster_role] || u.poster_role} · {new Date(u.created_at).toLocaleString()}</p>
                  </div>
                  {u.visibility === 'internal' && <span className="ml-auto text-xs bg-slate-200 text-slate-600 px-2 py-0.5 rounded-full">Internal Only</span>}
                </div>
                <p className="text-sm text-slate-700">{u.update_text}</p>
                {u.photos?.length > 0 && (
                  <div className="flex flex-wrap gap-2 mt-3">
                    {u.photos.map((p) => (
                      <a key={p.id} href={storageUrl(p.file_url)} target="_blank" rel="noreferrer">
                        <img src={storageUrl(p.file_url)} alt={p.file_name} className="w-20 h-20 object-cover rounded-lg border" />
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
                    <p className={`text-xs mt-1 ${mine ? 'text-blue-200' : 'text-slate-400'}`}>{new Date(m.created_at).toLocaleString()}</p>
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
                <p className="text-xs text-slate-400">{new Date(entry.created_at).toLocaleString()}</p>
              </div>
            </div>
          ))}
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

      {assignModal && (
        <AssignUserModal
          jobId={id}
          type={assignModal}
          currentName={assignModal === 'pm' ? job.pm?.name : job.contractor?.name}
          onClose={() => setAssignModal(null)}
          onAssigned={loadJob}
        />
      )}
      {showUpdateForm && <JobUpdateForm jobId={id} onClose={() => setShowUpdateForm(false)} onPosted={loadUpdates} />}
      {showQuoteBuilder && <QuoteBuilder job={job} onClose={() => setShowQuoteBuilder(false)} onCreated={loadJob} />}
    </div>
  );
}
