import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import api from '../api/axios';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const emptyItem = () => ({ description: '', quantity: 1, unit: 'ea', unit_price: 0 });

export default function QuoteBuilder({ job, onClose, onCreated }) {
  const [mode, setMode] = useState('lump_sum');
  const [scope, setScope] = useState(job?.scope_of_work || '');
  const [items, setItems] = useState([emptyItem()]);
  const [lumpSum, setLumpSum] = useState('');
  const [gstEnabled, setGstEnabled] = useState(true);
  const [customerNotes, setCustomerNotes] = useState('');
  const [internalNotes, setInternalNotes] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const contractorPrice = parseFloat(job?.contractor_submitted_price) || 0;
  const autoSubtotal = contractorPrice > 0 ? Math.round((contractorPrice / 0.80) * 100) / 100 : 0;

  const subtotal = mode === 'lump_sum'
    ? (parseFloat(lumpSum) || autoSubtotal || 0)
    : items.reduce((s, i) => s + (parseFloat(i.quantity) || 0) * (parseFloat(i.unit_price) || 0), 0);

  const gst = gstEnabled ? Math.round(subtotal * 0.05 * 100) / 100 : 0;
  const total = Math.round((subtotal + gst) * 100) / 100;

  const updateItem = (idx, field, val) => {
    setItems((prev) => prev.map((it, i) => (i === idx ? { ...it, [field]: val } : it)));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const ok = await confirmAction({
      title: 'Create quote?',
      text: `Create a quote for $${total.toFixed(2)} total?`,
      confirmText: 'Yes, create quote',
    });
    if (!ok) return;

    setLoading(true);
    setError('');
    try {
      const payload = {
        job_id: job.id,
        scope_of_work: scope,
        subtotal,
        gst_enabled: gstEnabled,
        customer_notes: customerNotes,
        internal_notes: internalNotes,
      };
      if (mode === 'itemized') {
        payload.items = items.map((i) => ({
          description: i.description,
          quantity: parseFloat(i.quantity),
          unit: i.unit,
          unit_price: parseFloat(i.unit_price),
        }));
      }
      const { data } = await api.post('/quotes', payload);
      await showSuccess('Quote created successfully.');
      onCreated?.(data);
      onClose();
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to create quote';
      setError(msg);
      await showError(msg);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 overflow-y-auto">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-2xl p-6 my-8">
        <h3 className="text-lg font-semibold text-slate-800 mb-4">Create Quote — Job #{job?.id}</h3>
        {error && <div className="bg-red-50 text-red-700 text-sm rounded-lg p-3 mb-4">{error}</div>}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="text-sm font-medium text-slate-700 block mb-1">Scope of Work</label>
            <textarea value={scope} onChange={(e) => setScope(e.target.value)} rows={3} required
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>

          <div className="flex gap-3 text-sm">
            <button type="button" onClick={() => setMode('lump_sum')}
              className={`px-3 py-1.5 rounded-lg border ${mode === 'lump_sum' ? 'bg-blue-50 border-blue-400 text-blue-700' : 'border-slate-200'}`}>
              Lump Sum
            </button>
            <button type="button" onClick={() => setMode('itemized')}
              className={`px-3 py-1.5 rounded-lg border ${mode === 'itemized' ? 'bg-blue-50 border-blue-400 text-blue-700' : 'border-slate-200'}`}>
              Itemized
            </button>
          </div>

          {mode === 'lump_sum' ? (
            <div>
              {contractorPrice > 0 && (
                <p className="text-xs text-slate-500 mb-2">
                  Contractor price: ${contractorPrice.toFixed(2)} → Customer subtotal (÷0.80): ${autoSubtotal.toFixed(2)}
                </p>
              )}
              <label className="text-sm font-medium text-slate-700 block mb-1">Total Price (before GST)</label>
              <input type="number" min="0" step="0.01" value={lumpSum || (autoSubtotal ? String(autoSubtotal) : '')} onChange={(e) => setLumpSum(e.target.value)} required
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
          ) : (
            <div className="space-y-2">
              {items.map((item, idx) => (
                <div key={idx} className="grid grid-cols-12 gap-2 items-end">
                  <input placeholder="Description" value={item.description} onChange={(e) => updateItem(idx, 'description', e.target.value)}
                    className="col-span-5 border border-slate-300 rounded-lg px-2 py-1.5 text-xs" required />
                  <input type="number" placeholder="Qty" value={item.quantity} onChange={(e) => updateItem(idx, 'quantity', e.target.value)}
                    className="col-span-2 border border-slate-300 rounded-lg px-2 py-1.5 text-xs" />
                  <input placeholder="Unit" value={item.unit} onChange={(e) => updateItem(idx, 'unit', e.target.value)}
                    className="col-span-1 border border-slate-300 rounded-lg px-2 py-1.5 text-xs" />
                  <input type="number" placeholder="Price" value={item.unit_price} onChange={(e) => updateItem(idx, 'unit_price', e.target.value)}
                    className="col-span-3 border border-slate-300 rounded-lg px-2 py-1.5 text-xs" />
                  <button type="button" onClick={() => setItems((p) => p.filter((_, i) => i !== idx))} className="col-span-1 text-red-500">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              ))}
              <button type="button" onClick={() => setItems((p) => [...p, emptyItem()])}
                className="flex items-center gap-1 text-xs text-blue-600 hover:underline">
                <Plus className="w-3 h-3" /> Add line item
              </button>
            </div>
          )}

          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={gstEnabled} onChange={(e) => setGstEnabled(e.target.checked)} />
            Include GST (5%)
          </label>

          <div className="bg-slate-50 rounded-lg p-4 text-sm space-y-1">
            <div className="flex justify-between"><span>Subtotal</span><span>${subtotal.toFixed(2)}</span></div>
            {gstEnabled && <div className="flex justify-between"><span>GST (5%)</span><span>${gst.toFixed(2)}</span></div>}
            <div className="flex justify-between font-bold text-base border-t border-slate-200 pt-2"><span>Total</span><span>${total.toFixed(2)}</span></div>
          </div>

          <textarea placeholder="Customer notes (visible to customer)" value={customerNotes} onChange={(e) => setCustomerNotes(e.target.value)}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" rows={2} />
          <textarea placeholder="Internal notes (hidden from customer)" value={internalNotes} onChange={(e) => setInternalNotes(e.target.value)}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" rows={2} />

          <div className="flex gap-3 justify-end">
            <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 rounded-lg hover:bg-slate-100">Cancel</button>
            <button type="submit" disabled={loading} className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-60">
              {loading ? 'Creating...' : 'Create Quote'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
