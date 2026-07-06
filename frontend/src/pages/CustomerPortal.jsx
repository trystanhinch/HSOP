import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import api, { storageUrl } from '../api/axios';
import { showError, showSuccess } from '../utils/swal';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function formatDate(date) {
  if (!date) return null;
  return new Date(date).toLocaleDateString('en-CA', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
  });
}

function statusBannerText(jobStatus) {
  switch (jobStatus) {
    case 'estimate_accepted':
    case 'quote_approved':
    case 'start_date_scheduled':
      return '✓ Estimate Accepted — Your project is being scheduled';
    case 'scheduled':
      return '📅 Your project is scheduled';
    case 'in_progress':
    case 'progress_updated':
      return '🔨 Work is in progress';
    case 'pending_customer_approval':
      return '✅ Work complete — Please review and accept';
    case 'revision_requested':
    case 'corrections_required':
      return '🔄 Revision in progress';
    case 'payment_pending':
      return '💳 Please complete your payment';
    case 'etransfer_pending_confirmation':
      return '💳 E-transfer received — awaiting confirmation';
    case 'paid_completed':
    case 'paid':
    case 'completed':
      return '🎉 Project complete — Thank you!';
    default:
      return '📋 Your project is active';
  }
}

function statusBannerClass(jobStatus) {
  if (['paid_completed', 'paid', 'completed'].includes(jobStatus)) {
    return 'bg-green-50 border-green-200';
  }
  if (jobStatus === 'pending_customer_approval') return 'bg-orange-50 border-orange-200';
  if (['revision_requested', 'corrections_required'].includes(jobStatus)) return 'bg-yellow-50 border-yellow-200';
  if (['payment_pending', 'etransfer_pending_confirmation'].includes(jobStatus)) return 'bg-blue-50 border-blue-200';
  return 'bg-green-50 border-green-200';
}

export default function CustomerPortal() {
  const { token } = useParams();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [revisionText, setRevisionText] = useState('');
  const [rejectReason, setRejectReason] = useState('');
  const [showRevision, setShowRevision] = useState(false);
  const [showReject, setShowReject] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [selectedPhoto, setSelectedPhoto] = useState(null);

  const load = () => {
    api.get(`/portal/${token}`)
      .then(({ data: d }) => setData(d))
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [token]);

  const acceptQuote = async () => {
    setSubmitting(true);
    try {
      await api.post(`/portal/${token}/accept-quote`);
      await showSuccess('Estimate accepted. Thank you!');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to approve estimate.');
    } finally {
      setSubmitting(false);
    }
  };

  const rejectQuote = async () => {
    if (!rejectReason.trim()) return;
    setSubmitting(true);
    try {
      await api.post(`/portal/${token}/reject-quote`, { rejection_reason: rejectReason });
      await showSuccess('Estimate declined. The team has been notified.');
      setShowReject(false);
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to decline estimate.');
    } finally {
      setSubmitting(false);
    }
  };

  const acceptCompletion = async () => {
    setSubmitting(true);
    try {
      const { data: res } = await api.post(`/portal/${token}/accept-completion`);
      const paymentUrl = res.payment_url + (res.payment_url.includes('?') ? '&' : '?') + `token=${token}`;
      window.location.href = paymentUrl;
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to accept completion.');
      setSubmitting(false);
    }
  };

  const requestRevision = async () => {
    if (!revisionText.trim()) return;
    setSubmitting(true);
    try {
      await api.post(`/portal/${token}/request-revision`, { description: revisionText });
      await showSuccess('Revision request submitted.');
      setShowRevision(false);
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to submit revision.');
    } finally {
      setSubmitting(false);
    }
  };

  const notifyPayment = async () => {
    setSubmitting(true);
    try {
      await api.post(`/portal/${token}/notify-payment`);
      await showSuccess('Thank you. The team has been notified to confirm your payment.');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to notify payment.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <div className="min-h-screen flex items-center justify-center text-slate-500">Loading your portal...</div>;
  if (!data) return <div className="min-h-screen flex items-center justify-center text-red-600">This link is invalid or has expired.</div>;

  const quote = data.quote;
  const job = data.job;
  const invoice = data.invoice;
  const jobStatus = job?.status;
  const updates = data.updates || [];

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="bg-slate-800 px-4 py-4">
        <h1 className="text-white font-bold text-lg">Your Project Portal</h1>
        <p className="text-slate-300 text-sm">
          Hi {data.lead.contact_name} · {data.lead.address}
        </p>
      </header>

      <main className="max-w-2xl mx-auto px-4 py-6 space-y-5">
        {job && (
          <section className={`rounded-xl p-4 border ${statusBannerClass(jobStatus)}`}>
            <p className="font-semibold text-slate-800 text-sm">{statusBannerText(jobStatus)}</p>
            {job.scheduled_start_date && ['quote_approved', 'scheduled', 'in_progress'].includes(jobStatus) && (
              <p className="text-xs text-slate-600 mt-1">
                Start date: {formatDate(job.scheduled_start_date)}
                {job.scheduled_start_time && ` at ${String(job.scheduled_start_time).slice(0, 5)}`}
              </p>
            )}
          </section>
        )}

        {data.lead.status === 'site_visit_scheduled' && data.lead.site_visit_date && !job && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-2">Site Visit Scheduled</h2>
            <p className="text-sm text-slate-600">
              Your appointment is on <strong>{formatDate(data.lead.site_visit_date)}</strong>
              {data.lead.site_visit_time && <> at <strong>{String(data.lead.site_visit_time).slice(0, 5)}</strong></>}
              {' '}at <strong>{data.lead.address}</strong>.
            </p>
          </section>
        )}

        {job?.scheduled_start_date && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-3">📅 Your Schedule</h2>
            <div className="space-y-2 text-sm">
              <div className="flex justify-between gap-4">
                <span className="text-slate-500">Start Date</span>
                <span className="font-medium text-right">{formatDate(job.scheduled_start_date)}</span>
              </div>
              {job.scheduled_start_time && (
                <div className="flex justify-between">
                  <span className="text-slate-500">Start Time</span>
                  <span className="font-medium">{String(job.scheduled_start_time).slice(0, 5)}</span>
                </div>
              )}
              {job.estimated_completion && (
                <div className="flex justify-between">
                  <span className="text-slate-500">Est. Completion</span>
                  <span className="font-medium">{formatDate(job.estimated_completion)}</span>
                </div>
              )}
            </div>
          </section>
        )}

        {quote && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-3">📄 Your Estimate</h2>
            <div className="space-y-2 text-sm">
              {quote.scope_of_work && (
                <div>
                  <p className="text-slate-500 text-xs mb-1">Scope of Work</p>
                  <p className="text-slate-700 whitespace-pre-wrap">{quote.scope_of_work}</p>
                </div>
              )}
              {quote.customer_notes && (
                <p className="text-slate-500 italic">{quote.customer_notes}</p>
              )}
              <div className="border-t pt-3 mt-3 space-y-1">
                <div className="flex justify-between">
                  <span className="text-slate-500">Subtotal</span>
                  <span>${Number(quote.customer_price_before_gst || 0).toFixed(2)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">GST ({quote.gst_rate || 5}%)</span>
                  <span>${Number(quote.gst || 0).toFixed(2)}</span>
                </div>
                <div className="flex justify-between font-bold text-slate-800 pt-1 border-t">
                  <span>Total</span>
                  <span>${Number(quote.customer_total || 0).toFixed(2)}</span>
                </div>
              </div>
              <div className="mt-2">
                <span className={`text-xs px-2 py-1 rounded-full font-medium ${
                  quote.status === 'approved' ? 'bg-green-100 text-green-700'
                    : ['sent', 'viewed'].includes(quote.status) ? 'bg-blue-100 text-blue-700'
                      : 'bg-slate-100 text-slate-600'
                }`}>
                  {quote.status === 'approved' ? '✓ Accepted'
                    : ['sent', 'viewed'].includes(quote.status) ? 'Awaiting your approval'
                      : quote.status}
                </span>
              </div>
              {['sent', 'viewed'].includes(quote.status) && (
                <div className="flex gap-2 mt-4">
                  <button type="button" onClick={acceptQuote} disabled={submitting}
                    className="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                    Accept Estimate
                  </button>
                  <button type="button" onClick={() => setShowReject(true)} disabled={submitting}
                    className="flex-1 border border-red-300 text-red-600 rounded-lg py-2.5 text-sm font-medium hover:bg-red-50">
                    Decline
                  </button>
                </div>
              )}
            </div>
          </section>
        )}

        {updates.length > 0 && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-4">📸 Progress Updates</h2>
            <div className="space-y-4">
              {updates.map((u, i) => (
                <div key={i} className="border-b border-slate-100 pb-4 last:border-0 last:pb-0">
                  <p className="text-xs text-slate-500 mb-2">
                    {new Date(u.created_at).toLocaleDateString('en-CA', {
                      weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
                    })}
                  </p>
                  <p className="text-sm text-slate-700">{u.text}</p>
                  {u.photos?.length > 0 && (
                    <div className="grid grid-cols-3 gap-2 mt-3">
                      {u.photos.map((url, j) => (
                        <button key={j} type="button" onClick={() => setSelectedPhoto(storageUrl(url))}>
                          <img
                            src={storageUrl(url)}
                            alt="Progress"
                            className="w-full h-24 object-cover rounded-lg border hover:opacity-80"
                            onError={(e) => { e.target.style.display = 'none'; }}
                          />
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </section>
        )}

        {updates.length === 0 && job && ['in_progress', 'scheduled', 'progress_updated'].includes(jobStatus) && (
          <section className="bg-white rounded-xl border border-slate-200 p-5 text-center">
            <p className="text-slate-400 text-sm">No progress updates yet. Check back once work begins.</p>
          </section>
        )}

        {jobStatus === 'pending_customer_approval' && (
          <section className="bg-white rounded-xl border-2 border-orange-300 p-5">
            <h2 className="font-semibold text-slate-800 mb-2">✅ Work Complete — Your Review</h2>
            <p className="text-sm text-slate-600 mb-4">
              The contractor has marked the work as complete. Please review the progress updates
              and photos above, then accept or request changes.
            </p>
            <div className="flex gap-2">
              <button type="button" onClick={acceptCompletion} disabled={submitting}
                className="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                Accept Completion
              </button>
              <button type="button" onClick={() => setShowRevision(true)} disabled={submitting}
                className="flex-1 border border-orange-400 text-orange-600 rounded-lg py-2.5 text-sm font-medium hover:bg-orange-50">
                Request Changes
              </button>
            </div>
          </section>
        )}

        {jobStatus === 'revision_requested' && job?.revision_description && (
          <section className="bg-orange-50 border border-orange-200 rounded-xl p-5">
            <h2 className="font-semibold text-orange-800 mb-2">Revision Requested</h2>
            <p className="text-sm text-orange-700">{job.revision_description}</p>
            <p className="text-xs text-orange-600 mt-2">Your contractor is addressing your feedback.</p>
          </section>
        )}

        {['payment_pending', 'etransfer_pending_confirmation'].includes(jobStatus) && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-3">💳 Payment</h2>
            {invoice && (
              <div className="space-y-2 text-sm mb-4">
                <div className="flex justify-between font-bold text-slate-800">
                  <span>Amount Due</span>
                  <span>${Number(invoice.balance || invoice.amount || 0).toFixed(2)}</span>
                </div>
              </div>
            )}
            {jobStatus === 'etransfer_pending_confirmation' ? (
              <div className="bg-blue-50 rounded-lg p-3 text-sm text-blue-700">
                ✓ E-transfer sent — waiting for confirmation from the team.
              </div>
            ) : (
              <div className="space-y-3">
                <div className="bg-slate-50 rounded-lg p-4 border border-slate-200">
                  <p className="font-medium text-slate-800 text-sm mb-1">Pay by E-Transfer</p>
                  <p className="text-xs text-slate-600">
                    Send to: <strong>{data.payment?.company_email || 'notifications@serviceop.ca'}</strong>
                  </p>
                  <p className="text-xs text-slate-600 mt-1">Reference: your name + job address</p>
                </div>
                <Link to={`/payment/${job.id}?token=${token}`}
                  className="block w-full text-center bg-blue-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-blue-700">
                  Go to Payment Page
                </Link>
                <button type="button" onClick={notifyPayment} disabled={submitting}
                  className="w-full border border-slate-300 rounded-lg py-2.5 text-sm font-medium hover:bg-slate-50 disabled:opacity-50">
                  I Have Sent the E-Transfer
                </button>
              </div>
            )}
          </section>
        )}

        {['paid_completed', 'paid', 'completed'].includes(jobStatus) && (
          <section className="bg-green-50 border border-green-200 rounded-xl p-5 text-center">
            <p className="text-2xl mb-2">🎉</p>
            <p className="font-semibold text-green-800">Project Complete</p>
            <p className="text-sm text-green-700 mt-1">
              Thank you for choosing ServiceOP. Your project is finished and payment confirmed.
            </p>
          </section>
        )}
      </main>

      {showReject && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 className="font-semibold mb-3">Decline Estimate</h3>
            <textarea value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} rows={3}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4" placeholder="Reason (optional feedback)..." />
            <div className="flex gap-2">
              <button type="button" onClick={() => setShowReject(false)} className="flex-1 border rounded-lg py-2 text-sm">Cancel</button>
              <button type="button" onClick={rejectQuote} disabled={submitting} className="flex-1 bg-red-600 text-white rounded-lg py-2 text-sm">Decline</button>
            </div>
          </div>
        </div>
      )}

      {showRevision && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 className="font-semibold mb-3">Request Revision</h3>
            <textarea value={revisionText} onChange={(e) => setRevisionText(e.target.value)} rows={4}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4" placeholder="Describe what needs to be changed..." />
            <div className="flex gap-2">
              <button type="button" onClick={() => setShowRevision(false)} className="flex-1 border rounded-lg py-2 text-sm">Cancel</button>
              <button type="button" onClick={requestRevision} disabled={submitting || !revisionText.trim()}
                className="flex-1 bg-orange-600 text-white rounded-lg py-2 text-sm disabled:opacity-50">Submit</button>
            </div>
          </div>
        </div>
      )}

      {selectedPhoto && (
        <div className="fixed inset-0 bg-black/80 flex items-center justify-center z-50 p-4" onClick={() => setSelectedPhoto(null)}>
          <img src={selectedPhoto} alt="" className="max-w-full max-h-full rounded-lg" />
        </div>
      )}
    </div>
  );
}
