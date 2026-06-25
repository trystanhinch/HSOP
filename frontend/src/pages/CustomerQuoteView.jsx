import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../api/axios';
import { confirmAction, confirmDanger, showError, showSuccess } from '../utils/swal';

function fmt(n) {
  return `$${parseFloat(n || 0).toFixed(2)}`;
}

export default function CustomerQuoteView() {
  const { token } = useParams();
  const [quote, setQuote] = useState(null);
  const [loading, setLoading] = useState(true);
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [message, setMessage] = useState('');

  const load = () => {
    api.get(`/quote/view/${token}`)
      .then(({ data }) => setQuote(data))
      .catch(() => setMessage('Quote not found or link has expired.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [token]);

  const approve = async () => {
    const ok = await confirmAction({
      title: 'Accept quote?',
      text: 'By accepting, you agree to proceed with this project at the quoted price.',
      confirmText: 'Yes, accept quote',
      icon: 'question',
    });
    if (!ok) return;

    try {
      await api.post(`/quote/view/${token}/approve`);
      setMessage('You have accepted this quote. Thank you!');
      await showSuccess('Quote accepted. Thank you!');
      load();
    } catch {
      setMessage('Unable to approve quote.');
      await showError('Unable to approve quote.');
    }
  };

  const reject = async () => {
    if (!reason.trim()) {
      await showError('Please provide a reason for declining.');
      return;
    }
    const ok = await confirmDanger({
      title: 'Decline quote?',
      text: 'Are you sure you want to decline this quote?',
      confirmText: 'Yes, decline',
    });
    if (!ok) return;

    try {
      await api.post(`/quote/view/${token}/reject`, { rejection_reason: reason });
      setMessage('You have declined this quote. The team has been notified.');
      setRejecting(false);
      await showSuccess('Quote declined. The team has been notified.');
      load();
    } catch {
      setMessage('Unable to reject quote.');
      await showError('Unable to reject quote.');
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
      </div>
    );
  }

  if (!quote) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50 p-4">
        <div className="text-center max-w-md">
          <h1 className="text-xl font-bold text-slate-800 mb-2">Link Not Found</h1>
          <p className="text-slate-500">This link is invalid or has expired. Please contact your project manager for a new link.</p>
        </div>
      </div>
    );
  }

  const done = ['approved', 'rejected'].includes(quote.status);
  const subtotal = quote.subtotal ?? 0;

  return (
    <div className="min-h-screen bg-slate-50 py-8 px-4">
      <div className="max-w-2xl mx-auto">
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-12 h-12 bg-blue-600 rounded-xl mb-3">
            <span className="text-white font-bold text-lg">JC</span>
          </div>
          <h1 className="text-2xl font-bold text-slate-900">{quote.job?.company_name || 'HSOP'}</h1>
          <p className="text-slate-500 text-sm mt-1">Quote {quote.quote_number}</p>
        </div>

        {message && (
          <div className="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6 text-sm text-center">{message}</div>
        )}

        <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-5">
          <div>
            <p className="text-xs text-slate-400 uppercase tracking-wide mb-1">Project Address</p>
            <p className="font-medium text-slate-800">{quote.job?.address}</p>
          </div>
          <div>
            <p className="text-xs text-slate-400 uppercase tracking-wide mb-1">Scope of Work</p>
            <p className="text-sm text-slate-700 whitespace-pre-wrap">{quote.scope_of_work}</p>
          </div>
          {quote.customer_notes && (
            <div>
              <p className="text-xs text-slate-400 uppercase tracking-wide mb-1">Notes</p>
              <p className="text-sm text-slate-700">{quote.customer_notes}</p>
            </div>
          )}

          {quote.items?.length > 0 && (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm divide-y divide-slate-200">
                <thead><tr className="text-left text-slate-500">
                  <th className="py-2">Description</th><th className="py-2">Qty</th><th className="py-2">Price</th><th className="py-2">Total</th>
                </tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {quote.items.map((item) => (
                    <tr key={item.id}>
                      <td className="py-2">{item.description}</td>
                      <td className="py-2">{item.quantity} {item.unit}</td>
                      <td className="py-2">{fmt(item.unit_price)}</td>
                      <td className="py-2">{fmt(item.total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <div className="bg-slate-50 rounded-xl p-4 space-y-2 text-sm">
            <div className="flex justify-between"><span>Service Amount</span><span>{fmt(subtotal)}</span></div>
            {quote.gst_enabled !== false && <div className="flex justify-between"><span>GST ({quote.gst_rate || 5}%)</span><span>{fmt(quote.gst)}</span></div>}
            <div className="flex justify-between font-bold text-lg border-t border-slate-200 pt-2"><span>Total</span><span>{fmt(quote.customer_total)}</span></div>
          </div>

          {quote.status === 'approved' && !message && (
            <div className="text-center text-green-700 font-medium py-4">✅ You have accepted this quote</div>
          )}
          {quote.status === 'rejected' && !message && (
            <div className="text-center text-red-600 font-medium py-4">❌ You have declined this quote</div>
          )}

          {!done && !message && (
            <div className="flex flex-col sm:flex-row gap-3 pt-2">
              <button onClick={approve} className="flex-1 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl py-3 text-sm">
                ✅ Accept Quote
              </button>
              <button onClick={() => setRejecting(true)} className="flex-1 bg-white border border-red-300 text-red-600 font-semibold rounded-xl py-3 text-sm hover:bg-red-50">
                ❌ Decline Quote
              </button>
            </div>
          )}

          {rejecting && (
            <div className="border-t border-slate-200 pt-4 space-y-3">
              <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3}
                placeholder="Please tell us why you're declining..."
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <div className="flex gap-3">
                <button onClick={() => setRejecting(false)} className="px-4 py-2 text-sm text-slate-600 rounded-lg hover:bg-slate-100">Cancel</button>
                <button onClick={reject} className="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Submit Decline</button>
              </div>
            </div>
          )}
        </div>

        <p className="text-center text-xs text-slate-400 mt-6">Questions? Contact {quote.job?.pm_name || 'your project manager'}.</p>
      </div>
    </div>
  );
}
