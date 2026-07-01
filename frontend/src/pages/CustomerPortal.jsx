import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import api, { storageUrl } from '../api/axios';
import { showError, showSuccess } from '../utils/swal';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
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

  const jobStatus = data?.job?.status;
  const quoteStatus = data?.quote?.status;

  const acceptQuote = async () => {
    setSubmitting(true);
    try {
      await api.post(`/portal/${token}/accept-quote`);
      await showSuccess('Quote approved. Thank you!');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to approve quote.');
    } finally {
      setSubmitting(false);
    }
  };

  const rejectQuote = async () => {
    if (!rejectReason.trim()) return;
    setSubmitting(true);
    try {
      await api.post(`/portal/${token}/reject-quote`, { rejection_reason: rejectReason });
      await showSuccess('Quote rejected. The team has been notified.');
      setShowReject(false);
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to reject quote.');
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

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="bg-white border-b border-slate-200 px-6 py-4">
        <h1 className="text-xl font-bold text-slate-900">Your Project Portal</h1>
        <p className="text-sm text-slate-500">Hi {data.lead.contact_name} · {data.lead.address}</p>
      </header>

      <main className="max-w-2xl mx-auto p-6 space-y-6">
        {/* Site visit */}
        {data.lead.status === 'site_visit_scheduled' && data.lead.site_visit_date && (
          <section className="bg-white rounded-xl border border-slate-200 p-6">
            <h2 className="font-semibold text-slate-800 mb-2">Site Visit Scheduled</h2>
            <p className="text-sm text-slate-600">
              Your appointment is scheduled for <strong>{data.lead.site_visit_date?.split('T')[0]}</strong>
              {data.lead.site_visit_time && <> at <strong>{data.lead.site_visit_time?.slice(0, 5)}</strong></>}
              {' '}at <strong>{data.lead.address}</strong>.
            </p>
          </section>
        )}

        {/* Quote */}
        {quote && ['sent', 'viewed'].includes(quote.status) && (
          <section className="bg-white rounded-xl border border-slate-200 p-6">
            <h2 className="font-semibold text-slate-800 mb-3">Your Estimate</h2>
            <p className="text-sm text-slate-600 mb-4 whitespace-pre-wrap">{quote.scope_of_work}</p>
            {quote.customer_notes && <p className="text-sm text-slate-500 mb-4 italic">{quote.customer_notes}</p>}
            <div className="space-y-1 text-sm mb-4">
              <div className="flex justify-between"><span>Subtotal</span><span>${parseFloat(quote.customer_price_before_gst || 0).toFixed(2)}</span></div>
              <div className="flex justify-between"><span>GST</span><span>${parseFloat(quote.gst || 0).toFixed(2)}</span></div>
              <div className="flex justify-between font-bold"><span>Total</span><span>${parseFloat(quote.customer_total || 0).toFixed(2)}</span></div>
            </div>
            <div className="flex gap-3">
              <button onClick={acceptQuote} disabled={submitting}
                className="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                Accept Estimate
              </button>
              <button onClick={() => setShowReject(true)} disabled={submitting}
                className="flex-1 border border-slate-300 rounded-lg py-2.5 text-sm font-medium hover:bg-slate-50">
                Decline
              </button>
            </div>
          </section>
        )}

        {quote?.status === 'approved' && job && ['quote_approved', 'scheduled', 'start_date_scheduled'].includes(jobStatus) && (
          <section className="bg-green-50 border border-green-200 rounded-xl p-6">
            <h2 className="font-semibold text-green-800 mb-2">Estimate Accepted</h2>
            <p className="text-sm text-green-700">
              {job.scheduled_start_date
                ? `Your project starts on ${job.scheduled_start_date?.split('T')[0]}. We'll keep you updated on progress.`
                : "Your estimate has been accepted. We'll be in touch to schedule your project start date."}
            </p>
          </section>
        )}

        {/* Progress updates */}
        {data.updates?.length > 0 && (
          <section className="bg-white rounded-xl border border-slate-200 p-6">
            <h2 className="font-semibold text-slate-800 mb-4">Progress Updates</h2>
            <div className="space-y-4">
              {data.updates.map((u, i) => (
                <div key={i} className="border-b border-slate-100 pb-4 last:border-0">
                  <p className="text-xs text-slate-400 mb-1">{new Date(u.created_at).toLocaleString()}</p>
                  <p className="text-sm text-slate-700">{u.text}</p>
                  {u.photos?.length > 0 && (
                    <div className="flex flex-wrap gap-2 mt-2">
                      {u.photos.map((url, j) => (
                        <button key={j} type="button" onClick={() => setSelectedPhoto(storageUrl(url))}>
                          <img src={storageUrl(url)} alt="" className="w-20 h-20 object-cover rounded-lg border hover:opacity-80" />
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </section>
        )}

        {/* Completion review */}
        {jobStatus === 'pending_customer_approval' && (
          <section className="bg-white rounded-xl border border-slate-200 p-6">
            <h2 className="font-semibold text-slate-800 mb-2">Work Complete — Please Review</h2>
            <p className="text-sm text-slate-600 mb-4">
              Your contractor has completed the work. Please accept the completion or request changes.
            </p>
            <div className="flex gap-3">
              <button onClick={acceptCompletion} disabled={submitting}
                className="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                Accept Completion
              </button>
              <button onClick={() => setShowRevision(true)} disabled={submitting}
                className="flex-1 border border-orange-300 text-orange-700 rounded-lg py-2.5 text-sm font-medium hover:bg-orange-50">
                Request Revision
              </button>
            </div>
          </section>
        )}

        {jobStatus === 'revision_requested' && job?.revision_description && (
          <section className="bg-orange-50 border border-orange-200 rounded-xl p-6">
            <h2 className="font-semibold text-orange-800 mb-2">Revision Requested</h2>
            <p className="text-sm text-orange-700">{job.revision_description}</p>
            <p className="text-xs text-orange-600 mt-2">Your contractor is addressing your feedback.</p>
          </section>
        )}

        {/* Payment */}
        {['payment_pending', 'etransfer_pending_confirmation'].includes(jobStatus) && (
          <section className="bg-white rounded-xl border border-slate-200 p-6">
            <h2 className="font-semibold text-slate-800 mb-3">Payment</h2>
            {invoice && (
              <div className="space-y-1 text-sm mb-4">
                <div className="flex justify-between"><span>Total Due</span><span className="font-bold">${parseFloat(invoice.amount || 0).toFixed(2)}</span></div>
              </div>
            )}
            <p className="text-sm text-slate-600 mb-3">
              Send e-transfer to: <strong>{data.payment?.company_email || 'payments@hsop.ca'}</strong>
            </p>
            {jobStatus === 'payment_pending' ? (
              <>
                <Link to={`/payment/${job.id}?token=${token}`}
                  className="block w-full text-center bg-blue-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-blue-700 mb-3">
                  Go to Payment Page
                </Link>
                <button onClick={notifyPayment} disabled={submitting}
                  className="w-full border border-slate-300 rounded-lg py-2.5 text-sm font-medium hover:bg-slate-50 disabled:opacity-50">
                  I Have Sent the E-Transfer
                </button>
              </>
            ) : (
              <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-700">
                Payment notification received. We will confirm once your e-transfer is processed.
              </div>
            )}
          </section>
        )}

        {jobStatus === 'paid_completed' && (
          <section className="bg-green-50 border border-green-200 rounded-xl p-6 text-center">
            <h2 className="font-semibold text-green-800 mb-2">Project Complete</h2>
            <p className="text-sm text-green-700">Your project is complete and payment has been confirmed. Thank you!</p>
          </section>
        )}
      </main>

      {/* Reject quote modal */}
      {showReject && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 className="font-semibold mb-3">Decline Estimate</h3>
            <textarea value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} rows={3}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4" placeholder="Reason (optional feedback)..." />
            <div className="flex gap-2">
              <button onClick={() => setShowReject(false)} className="flex-1 border rounded-lg py-2 text-sm">Cancel</button>
              <button onClick={rejectQuote} disabled={submitting} className="flex-1 bg-red-600 text-white rounded-lg py-2 text-sm">Decline</button>
            </div>
          </div>
        </div>
      )}

      {/* Revision modal */}
      {showRevision && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 className="font-semibold mb-3">Request Revision</h3>
            <textarea value={revisionText} onChange={(e) => setRevisionText(e.target.value)} rows={4}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4" placeholder="Describe what needs to be changed..." />
            <div className="flex gap-2">
              <button onClick={() => setShowRevision(false)} className="flex-1 border rounded-lg py-2 text-sm">Cancel</button>
              <button onClick={requestRevision} disabled={submitting || !revisionText.trim()}
                className="flex-1 bg-orange-600 text-white rounded-lg py-2 text-sm disabled:opacity-50">Submit</button>
            </div>
          </div>
        </div>
      )}

      {/* Photo lightbox */}
      {selectedPhoto && (
        <div className="fixed inset-0 bg-black/80 flex items-center justify-center z-50 p-4" onClick={() => setSelectedPhoto(null)}>
          <img src={selectedPhoto} alt="" className="max-w-full max-h-full rounded-lg" />
        </div>
      )}
    </div>
  );
}
