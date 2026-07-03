import { useState } from 'react';
import api from '../api/axios';

export default function ContractorLeadPriceForm({ lead, onSubmitted }) {
  const [price, setPrice] = useState(lead.contractor_price || '');
  const [notes, setNotes] = useState(lead.contractor_price_notes || '');
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState(!!lead.contractor_price);
  const [error, setError] = useState('');

  if (success || lead.contractor_price) {
    return (
      <div className="bg-green-50 border border-green-200 rounded-xl p-4">
        <p className="text-sm font-semibold text-green-800">
          ✓ Price Submitted — ${Number(lead.contractor_price).toFixed(2)}
        </p>
        {lead.contractor_price_notes && (
          <p className="text-xs text-green-700 mt-1">{lead.contractor_price_notes}</p>
        )}
        <p className="text-xs text-green-600 mt-1">
          The project manager has been notified and will prepare the customer estimate.
        </p>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-5">
      <h3 className="font-semibold text-slate-800 mb-1">Submit Your Price</h3>
      <p className="text-sm text-slate-500 mb-4">
        After attending the site visit, enter your price for this project.
      </p>
      {error && (
        <div className="bg-red-50 text-red-700 text-sm rounded-lg p-3 mb-3">{error}</div>
      )}
      <div className="space-y-3">
        <div>
          <label className="block text-xs font-medium text-slate-600 mb-1">Your Price (CAD) *</label>
          <div className="relative">
            <span className="absolute left-3 top-2.5 text-slate-400 text-sm">$</span>
            <input
              type="number"
              min="1"
              step="0.01"
              value={price}
              onChange={(e) => setPrice(e.target.value)}
              placeholder="0.00"
              className="w-full border border-slate-300 rounded-lg pl-7 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-600 mb-1">
            Notes / Exclusions (optional)
          </label>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={3}
            placeholder="Any exclusions, concerns, or notes for the PM..."
            className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <button
          type="button"
          disabled={submitting || !price}
          onClick={async () => {
            if (!price || parseFloat(price) <= 0) {
              setError('Please enter a valid price.');
              return;
            }
            setSubmitting(true);
            setError('');
            try {
              await api.post(`/leads/${lead.id}/submit-price`, {
                price: parseFloat(price),
                notes: notes || null,
              });
              setSuccess(true);
              onSubmitted?.();
            } catch (e) {
              setError(e.response?.data?.message || 'Failed to submit. Please try again.');
            } finally {
              setSubmitting(false);
            }
          }}
          className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-semibold rounded-lg py-3 text-sm"
        >
          {submitting ? 'Submitting...' : 'Submit My Price'}
        </button>
      </div>
    </div>
  );
}
