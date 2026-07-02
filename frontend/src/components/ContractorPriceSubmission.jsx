import { useState } from 'react';
import api from '../api/axios';

export default function ContractorPriceSubmission({ job, onSubmitted }) {
  const [price, setPrice] = useState('');
  const [duration, setDuration] = useState('');
  const [availability, setAvailability] = useState('');
  const [exclusions, setExclusions] = useState('');
  const [concerns, setConcerns] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  const handleSubmit = async () => {
    if (!price || parseFloat(price) <= 0) {
      setError('Please enter a valid price.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      await api.post(`/jobs/${job.id}/submit-price`, {
        price: parseFloat(price),
        estimated_duration: duration || null,
        availability_notes: availability || null,
        exclusions: exclusions || null,
        concerns: concerns || null,
      });
      setSuccess(true);
      onSubmitted();
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to submit price. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  if (success) {
    return (
      <div className="bg-green-50 border border-green-200 rounded-xl p-5">
        <div className="flex items-center gap-2 mb-2">
          <span className="text-green-600 text-lg">✓</span>
          <h3 className="font-semibold text-green-800">Price Submitted</h3>
        </div>
        <p className="text-sm text-green-700">
          Your price has been submitted. The project manager will review it
          and prepare the customer estimate. You will be notified when the
          estimate is sent and when the job is scheduled to begin.
        </p>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-5">
      <h3 className="font-semibold text-slate-800 mb-1">Submit Your Price</h3>
      <p className="text-sm text-slate-500 mb-4">
        After attending the site visit, enter your price for this job.
        The project manager will review it and send the estimate to the customer.
      </p>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3 mb-4">
          {error}
        </div>
      )}

      <div className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            Your Price (CAD) *
          </label>
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
          <p className="text-xs text-slate-400 mt-1">
            Enter your contractor price only. The customer estimate will be
            calculated separately by the office.
          </p>
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            Estimated Duration
          </label>
          <input
            type="text"
            value={duration}
            onChange={(e) => setDuration(e.target.value)}
            placeholder="e.g. 3 days, 1 week"
            className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            Availability / Start Date Preference
          </label>
          <input
            type="text"
            value={availability}
            onChange={(e) => setAvailability(e.target.value)}
            placeholder="e.g. Available from July 10"
            className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            Exclusions (optional)
          </label>
          <textarea
            value={exclusions}
            onChange={(e) => setExclusions(e.target.value)}
            rows={2}
            placeholder="Any work not included in your price..."
            className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            Concerns or Notes (optional)
          </label>
          <textarea
            value={concerns}
            onChange={(e) => setConcerns(e.target.value)}
            rows={2}
            placeholder="Any issues or questions about the job..."
            className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <button
          type="button"
          onClick={handleSubmit}
          disabled={submitting || !price}
          className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-semibold rounded-lg py-3 text-sm transition-colors"
        >
          {submitting ? 'Submitting...' : 'Submit My Price'}
        </button>
      </div>
    </div>
  );
}
