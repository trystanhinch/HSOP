import { useEffect, useState } from 'react';
import { useParams, Link, useSearchParams } from 'react-router-dom';
import api, { storageUrl } from '../api/axios';
import { showError, showSuccess, confirmAction } from '../utils/swal';
import { formatDate, formatDateTime, formatDateLong } from '../utils/formatDate';

const ISSUE_LABELS = {
  quality: 'Quality',
  communication: 'Communication',
  scheduling: 'Scheduling',
  cleanliness: 'Cleanliness',
  payment: 'Payment',
  contractor: 'Contractor',
  pm: 'Project manager',
  other: 'Other',
};

function PortalReviewSection({ token }) {
  const [review, setReview] = useState(null);
  const [loading, setLoading] = useState(true);
  const [stars, setStars] = useState(0);
  const [comment, setComment] = useState('');
  const [issueCategory, setIssueCategory] = useState('');
  const [photo, setPhoto] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const loadReview = () => {
    setLoading(true);
    api.get(`/portal/${token}/review`)
      .then(({ data }) => setReview(data))
      .catch(() => setReview(null))
      .finally(() => setLoading(false));
  };

  useEffect(() => { loadReview(); }, [token]);

  const submit = async () => {
    if (!stars) {
      await showError('Please select a star rating.');
      return;
    }
    if (stars < 5 && !issueCategory) {
      await showError('Please select an issue category.');
      return;
    }
    setSubmitting(true);
    try {
      const form = new FormData();
      form.append('star_rating', String(stars));
      if (comment.trim()) form.append('comment', comment.trim());
      if (stars < 5) form.append('issue_category', issueCategory);
      if (photo) form.append('photo', photo);
      const { data } = await api.post(`/portal/${token}/review`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setReview(data.review);
      await showSuccess(data.message || 'Thank you for your feedback');
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to submit review.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <section className="bg-white rounded-xl border border-slate-200 p-5">
        <p className="text-sm text-slate-500">Loading review…</p>
      </section>
    );
  }

  if (!review) {
    return null;
  }

  if (review.already_submitted) {
    const five = review.feedback?.star_rating === 5;
    return (
      <section className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
        <h2 className="font-semibold text-slate-800">Your rating</h2>
        <p className="text-sm text-slate-600">
          You rated this project {review.feedback?.star_rating}★. Thank you!
        </p>
        {five && (
          <div className="rounded-lg bg-green-50 border border-green-200 p-4 text-center space-y-2">
            <p className="font-medium text-green-800">Thank you for the great feedback!</p>
            {review.show_google_button && review.google_review_url ? (
              <a
                href={review.google_review_url}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg px-4 py-2"
              >
                Leave a Google Review
              </a>
            ) : (
              <p className="text-sm text-green-700">
                We appreciate you choosing ServiceOP.
              </p>
            )}
          </div>
        )}
        {!five && review.feedback?.comment && (
          <p className="text-sm text-slate-500 italic">&ldquo;{review.feedback.comment}&rdquo;</p>
        )}
      </section>
    );
  }

  if (!review.can_submit) {
    return null;
  }

  const showDetails = stars > 0 && stars < 5;

  return (
    <section className="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      <div>
        <h2 className="font-semibold text-slate-800">Rate your experience</h2>
        <p className="text-sm text-slate-500 mt-1">
          How was your ServiceOP project{review.job?.address ? ` at ${review.job.address}` : ''}?
        </p>
      </div>
      <div className="flex gap-2 justify-center">
        {[1, 2, 3, 4, 5].map((n) => (
          <button
            key={n}
            type="button"
            onClick={() => setStars(n)}
            className={`w-11 h-11 rounded-lg text-xl border transition ${
              stars >= n
                ? 'bg-amber-400 border-amber-500 text-white'
                : 'bg-slate-50 border-slate-200 text-slate-400 hover:border-amber-300'
            }`}
            aria-label={`${n} star${n > 1 ? 's' : ''}`}
          >
            ★
          </button>
        ))}
      </div>
      {stars === 5 && (
        <p className="text-sm text-center text-green-700">Optional comment — then submit to continue.</p>
      )}
      {showDetails && (
        <div className="space-y-3">
          <div>
            <label className="text-xs font-medium text-slate-600 block mb-1">What went wrong?</label>
            <select
              value={issueCategory}
              onChange={(e) => setIssueCategory(e.target.value)}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
            >
              <option value="">Select a category…</option>
              {(review.issue_categories || Object.keys(ISSUE_LABELS)).map((c) => (
                <option key={c} value={c}>{ISSUE_LABELS[c] || c}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600 block mb-1">Photo (optional)</label>
            <input
              type="file"
              accept="image/*"
              onChange={(e) => setPhoto(e.target.files?.[0] || null)}
              className="w-full text-sm"
            />
          </div>
        </div>
      )}
      {stars > 0 && (
        <div>
          <label className="text-xs font-medium text-slate-600 block mb-1">Comment (optional)</label>
          <textarea
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            rows={3}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
            placeholder={stars < 5 ? 'Tell us more so we can make it right…' : 'Anything else you loved?'}
          />
        </div>
      )}
      {stars > 0 && (
        <button
          type="button"
          onClick={submit}
          disabled={submitting || (stars < 5 && !issueCategory)}
          className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-medium"
        >
          {submitting ? 'Submitting…' : 'Submit rating'}
        </button>
      )}
    </section>
  );
}

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function jobStatusLabel(status, statusLabel) {
  if (statusLabel) return statusLabel;
  const labels = {
    created: 'Project created',
    waiting_to_schedule: 'Waiting to schedule',
    quote_approved: 'Waiting to schedule',
    scheduled: 'Scheduled',
    in_progress: 'Your project is underway',
    progress_updated: 'Your project is underway',
    update_posted: 'Your project is underway',
    pending_customer_approval: 'Work complete — please review',
    completion_requested: 'Work complete — please review',
    revision_requested: 'Revision requested',
    revision_in_progress: 'Revision in progress',
    corrections_required: 'Revision in progress',
    payment_pending: 'Awaiting payment',
    completion_accepted: 'Completion accepted',
    completed: 'Completed',
    paid_completed: 'Completed',
    closed: 'Project closed',
  };
  return labels[status] || (status || '').replace(/_/g, ' ');
}

function statusBannerText(jobStatus, quoteStatus, statusLabel) {
  if (quoteStatus === 'approved' && ['quote_approved', 'waiting_to_schedule'].includes(jobStatus)) {
    return 'Quote approved — waiting to schedule';
  }
  switch (jobStatus) {
    case 'estimate_accepted':
    case 'quote_approved':
    case 'waiting_to_schedule':
    case 'start_date_scheduled':
      return 'Quote approved — waiting to schedule';
    case 'scheduled':
      return 'Your project is scheduled';
    case 'in_progress':
    case 'progress_updated':
    case 'update_posted':
      return 'Your project is underway';
    case 'pending_customer_approval':
    case 'completion_requested':
      return 'Work complete — please review and accept';
    case 'revision_requested':
    case 'corrections_required':
    case 'revision_in_progress':
      return 'Revision in progress';
    case 'payment_pending':
    case 'completion_accepted':
      return 'Please complete your payment';
    case 'etransfer_pending_confirmation':
      return 'E-transfer received — awaiting confirmation';
    case 'paid_completed':
    case 'paid':
    case 'completed':
    case 'closed':
      return 'Project complete — thank you';
    default:
      return statusLabel || 'Your project is active';
  }
}

function statusBannerClass(jobStatus) {
  if (['paid_completed', 'paid', 'completed', 'closed'].includes(jobStatus)) {
    return 'bg-green-50 border-green-200';
  }
  if (['pending_customer_approval', 'completion_requested'].includes(jobStatus)) return 'bg-orange-50 border-orange-200';
  if (['revision_requested', 'corrections_required', 'revision_in_progress'].includes(jobStatus)) return 'bg-yellow-50 border-yellow-200';
  if (['payment_pending', 'etransfer_pending_confirmation', 'completion_accepted'].includes(jobStatus)) return 'bg-blue-50 border-blue-200';
  return 'bg-green-50 border-green-200';
}

export default function CustomerPortal() {
  const { token } = useParams();
  const [searchParams] = useSearchParams();
  const showReviewTab = searchParams.get('tab') === 'review';
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
    const ok = await confirmAction({
      title: 'Accept quote?',
      text: 'By accepting, you agree to proceed with this project at the quoted price. Your project manager will contact you to schedule.',
      confirmText: 'Yes, accept quote',
    });
    if (!ok) return;

    setSubmitting(true);
    try {
      await api.post(`/portal/${token}/accept-quote`);
      await showSuccess('Quote accepted. Your project manager will contact you to schedule.');
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
  const pm = data.pm;
  const invoice = data.invoice;
  const jobStatus = job?.status;
  const statusLabel = job?.status_label;
  const updates = data.updates || [];
  const quoteApproved = quote?.status === 'approved';

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="bg-slate-800 px-4 py-4">
        <div className="flex items-center gap-2 mb-1">
          <div className="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
            <span className="text-white font-bold text-sm">SO</span>
          </div>
          <h1 className="text-white font-bold text-lg">ServiceOP</h1>
        </div>
        <p className="text-slate-300 text-sm">
          Hi {data.lead.contact_name} · {data.lead.address}
        </p>
      </header>

      <main className="max-w-2xl mx-auto px-4 py-6 space-y-5">
        {(showReviewTab || ['paid_completed', 'paid', 'completed', 'closed'].includes(jobStatus)) && (
          <PortalReviewSection token={token} />
        )}

        {job && (
          <section className={`rounded-xl p-4 border ${statusBannerClass(jobStatus)}`}>
            <p className="font-semibold text-slate-800 text-sm">{statusBannerText(jobStatus, quote?.status, statusLabel)}</p>
            {jobStatus && (
              <p className="text-xs text-slate-600 mt-1">Project status: {jobStatusLabel(jobStatus, statusLabel)}</p>
            )}
            {job.scheduled_start_date && ['quote_approved', 'scheduled', 'in_progress'].includes(jobStatus) && (
              <p className="text-xs text-slate-600 mt-1">
                Start date: {formatDateLong(job.scheduled_start_date)}
                {job.scheduled_start_time && ` at ${String(job.scheduled_start_time).slice(0, 5)}`}
              </p>
            )}
          </section>
        )}

        {pm && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-2">Your Project Manager</h2>
            <p className="text-sm font-medium text-slate-800">{pm.name}</p>
            {pm.phone && <p className="text-sm text-slate-600">Phone: {pm.phone}</p>}
            {pm.email && <p className="text-sm text-slate-600">Email: {pm.email}</p>}
            {quoteApproved && (
              <p className="text-xs text-slate-500 mt-2">Your PM will contact you to confirm scheduling and next steps.</p>
            )}
          </section>
        )}

        {quoteApproved && job && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-2">Your Project</h2>
            <p className="text-sm text-slate-600 mb-3">
              Your quote has been approved. Track your project status and progress updates below.
            </p>
            <div className="text-sm space-y-1">
              <div className="flex justify-between"><span className="text-slate-500">Status</span><span className="font-medium">{jobStatusLabel(jobStatus, statusLabel)}</span></div>
              {quote?.quote_number && (
                <div className="flex justify-between"><span className="text-slate-500">Quote #</span><span>{quote.quote_number}</span></div>
              )}
            </div>
          </section>
        )}

        {data.lead.status === 'site_visit_scheduled' && data.lead.site_visit_date && !job && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-2">Site Visit Scheduled</h2>
            <p className="text-sm text-slate-600">
              Your appointment is on <strong>{formatDateLong(data.lead.site_visit_date)}</strong>
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
                <span className="font-medium text-right">{formatDateLong(job.scheduled_start_date)}</span>
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
                  <span className="font-medium">{formatDateLong(job.estimated_completion)}</span>
                </div>
              )}
            </div>
          </section>
        )}

        {quote && !quoteApproved && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-3">📄 Your Quote</h2>
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
                    Accept Quote
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

        {quoteApproved && quote && (
          <section className="bg-slate-50 rounded-xl border border-slate-200 p-4 text-sm">
            <p className="font-medium text-slate-800">Quote {quote.quote_number ? `#${quote.quote_number}` : ''} — Approved</p>
            <p className="text-slate-600 mt-1">Total: ${Number(quote.customer_total || 0).toFixed(2)}</p>
          </section>
        )}

        {updates.length > 0 && (
          <section className="bg-white rounded-xl border border-slate-200 p-5">
            <h2 className="font-semibold text-slate-800 mb-4">📸 Progress Updates</h2>
            <div className="space-y-4">
              {updates.map((u, i) => (
                <div key={i} className="border-b border-slate-100 pb-4 last:border-0 last:pb-0">
                  <p className="text-xs text-slate-500 mb-2">
                    {formatDateTime(u.created_at)}
                  </p>
                  <p className="text-sm text-slate-700">{u.text}</p>
                  {u.photos?.filter(Boolean)?.length > 0 && (
                    <div className="grid grid-cols-3 gap-2 mt-3">
                      {u.photos.filter(Boolean).map((url, j) => (
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

        {(jobStatus === 'pending_customer_approval' || jobStatus === 'completion_requested') && (
          <section className="bg-white rounded-xl border-2 border-orange-300 p-5">
            <h2 className="font-semibold text-slate-800 mb-2">Work Complete — Your Review</h2>
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
            <h3 className="font-semibold mb-3">Decline Quote</h3>
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
