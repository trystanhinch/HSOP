import { useEffect, useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import api from '../api/axios';
import { showError, showSuccess } from '../utils/swal';

export default function PaymentPage() {
  const { jobId } = useParams();
  const [searchParams] = useSearchParams();
  const portalToken = searchParams.get('token');
  const paidFlag = searchParams.get('paid');
  const cancelledFlag = searchParams.get('cancelled');
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [checkoutBusy, setCheckoutBusy] = useState(false);

  useEffect(() => {
    if (paidFlag === '1') {
      showSuccess('Payment received. Thank you! It may take a moment for your portal to update.');
    } else if (cancelledFlag === '1') {
      showError('Checkout was cancelled. You can try again or pay by e-transfer.');
    }
  }, [paidFlag, cancelledFlag]);

  useEffect(() => {
    const fetchData = portalToken
      ? api.get(`/portal/${portalToken}/payment-details`)
      : api.get(`/jobs/${jobId}/payment-details`);

    fetchData
      .then(({ data: d }) => setData(d))
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  }, [jobId, portalToken]);

  const notifyEtransferSent = async () => {
    setSubmitting(true);
    try {
      if (portalToken) {
        await api.post(`/portal/${portalToken}/notify-payment`);
      } else {
        await api.post(`/jobs/${jobId}/notify-etransfer-sent`);
      }
      await showSuccess('Thank you. The team has been notified to confirm your payment.');
      if (portalToken) {
        navigate(`/portal/${portalToken}`);
      } else {
        navigate('/');
      }
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to notify payment.');
    } finally {
      setSubmitting(false);
    }
  };

  const payByCard = async () => {
    setCheckoutBusy(true);
    try {
      const { data: res } = portalToken
        ? await api.post(`/portal/${portalToken}/stripe/checkout`)
        : await api.post(`/jobs/${jobId}/stripe/checkout`);
      if (!res.checkout_url) {
        throw new Error('No checkout URL returned');
      }
      window.location.href = res.checkout_url;
    } catch (e) {
      await showError(e.response?.data?.message || e.message || 'Unable to start card checkout');
      setCheckoutBusy(false);
    }
  };

  if (loading) return <div className="min-h-screen flex items-center justify-center text-slate-500">Loading...</div>;
  if (!data) return <div className="min-h-screen flex items-center justify-center text-red-600">Payment details not available.</div>;

  const invoice = data.invoice || {};
  const total = parseFloat(invoice.amount || 0).toFixed(2);
  const subtotal = parseFloat(invoice.subtotal || 0).toFixed(2);
  const gst = parseFloat(invoice.gst || 0).toFixed(2);
  const alreadyPaid = invoice.status === 'paid';
  const cardEnabled = !!data.card_payments_enabled && !alreadyPaid;

  return (
    <div className="min-h-screen bg-slate-50 py-12 px-4">
      <div className="max-w-lg mx-auto">
        <div className="text-center mb-8">
          <h1 className="text-2xl font-bold text-slate-900">Payment</h1>
          <p className="text-sm text-slate-500 mt-1">{data.job?.address}</p>
        </div>

        <div className="bg-white rounded-xl border border-slate-200 p-6 mb-4">
          <h2 className="font-semibold text-slate-800 mb-3">Invoice Summary</h2>
          {data.job?.scope_of_work && (
            <p className="text-sm text-slate-600 mb-4">{data.job.scope_of_work}</p>
          )}
          <div className="space-y-2 text-sm">
            <div className="flex justify-between"><span className="text-slate-500">Subtotal</span><span>${subtotal}</span></div>
            <div className="flex justify-between"><span className="text-slate-500">GST</span><span>${gst}</span></div>
            <div className="flex justify-between font-bold text-base border-t pt-2"><span>Total Due</span><span>${total}</span></div>
          </div>
          {alreadyPaid && (
            <p className="mt-3 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-3 py-2">
              This invoice is marked paid.
            </p>
          )}
        </div>

        {cardEnabled && (
          <div className="border-2 border-blue-200 rounded-xl p-5 bg-white mb-4">
            <h3 className="font-semibold text-slate-800 mb-2">Pay by Card</h3>
            <p className="text-sm text-slate-600 mb-3">
              Secure checkout powered by Stripe. You will be redirected to complete payment.
            </p>
            <button
              type="button"
              onClick={payByCard}
              disabled={checkoutBusy}
              className="w-full bg-blue-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
            >
              {checkoutBusy ? 'Redirecting…' : `Pay $${total} by card`}
            </button>
          </div>
        )}

        {!alreadyPaid && (
          <div className="border-2 border-slate-200 rounded-xl p-5 bg-white mb-4">
            <h3 className="font-semibold text-slate-800 mb-2">Pay by E-Transfer</h3>
            <p className="text-sm text-slate-600 mb-3">
              Send your e-transfer to: <strong>{data.company_email}</strong>
            </p>
            <p className="text-sm text-slate-600 mb-3">
              Amount: <strong>${total}</strong>
            </p>
            {data.payment_instructions && (
              <p className="text-xs text-slate-500 mb-3">{data.payment_instructions}</p>
            )}
            <p className="text-xs text-slate-400">
              Please use your name and job address as the message reference.
              Once we receive your payment, we will confirm it and your job will be marked complete.
            </p>
            <button type="button" onClick={notifyEtransferSent} disabled={submitting}
              className="mt-4 w-full bg-slate-800 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-slate-900 disabled:opacity-50">
              {submitting ? 'Submitting...' : 'I Have Sent the E-Transfer'}
            </button>
          </div>
        )}

        {!cardEnabled && !alreadyPaid && data.payment_provider !== 'stripe' && (
          <div className="border-2 border-dashed border-slate-200 rounded-xl p-5 bg-white">
            <h3 className="font-semibold text-slate-600 mb-2">Pay by Card</h3>
            <p className="text-sm text-slate-400">
              Card payments are not enabled in this environment yet.
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
