import { Fragment, useCallback, useEffect, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const PAYABLE_STATUSES = ['draft', 'sent', 'invoice_sent', 'awaiting_payment', 'partially_paid'];

function MarkPaidModal({ invoice, onClose, onSuccess }) {
  const [amount, setAmount] = useState(String(invoice.balance ?? ''));
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().split('T')[0]);
  const [referenceNumber, setReferenceNumber] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async () => {
    const ok = await confirmAction({
      title: 'Record payment?',
      text: `Record a payment of $${parseFloat(amount).toFixed(2)} for ${invoice.invoice_number || `invoice #${invoice.id}`}?`,
      confirmText: 'Yes, record payment',
    });
    if (!ok) return;

    setLoading(true);
    setError('');
    try {
      await api.post(`/invoices/${invoice.id}/mark-paid`, {
        amount: parseFloat(amount),
        payment_date: paymentDate,
        reference_number: referenceNumber || null,
      });
      await showSuccess('Payment recorded.');
      onSuccess();
      onClose();
    } catch (e) {
      const msg = e.response?.data?.message || 'Failed to record payment';
      setError(msg);
      await showError(msg);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl p-6 w-full max-w-sm">
        <h3 className="font-semibold text-slate-800 mb-4">Record Payment — {invoice.invoice_number || `#${invoice.id}`}</h3>
        {error && <div className="bg-red-50 text-red-700 text-sm rounded-lg p-2 mb-3">{error}</div>}
        <div className="space-y-3">
          <div>
            <label className="text-xs text-slate-500 block mb-1">Amount Received</label>
            <input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            <p className="text-xs text-slate-400 mt-1">Outstanding balance: ${Number(invoice.balance || 0).toFixed(2)}</p>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Payment Date</label>
            <input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">E-Transfer Reference (optional)</label>
            <input type="text" value={referenceNumber} onChange={(e) => setReferenceNumber(e.target.value)}
              placeholder="e.g. confirmation code"
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
        </div>
        <div className="flex gap-2 mt-5">
          <button type="button" onClick={onClose} className="flex-1 border border-slate-300 rounded-lg py-2 text-sm">Cancel</button>
          <button type="button" onClick={handleSubmit} disabled={loading}
            className="flex-1 bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white rounded-lg py-2 text-sm font-medium">
            {loading ? 'Saving...' : 'Confirm Payment'}
          </button>
        </div>
      </div>
    </div>
  );
}

function PaymentHistory({ invoiceId, balance }) {
  const [payments, setPayments] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get(`/invoices/${invoiceId}/payments`)
      .then(({ data }) => setPayments(data))
      .catch(() => setPayments([]))
      .finally(() => setLoading(false));
  }, [invoiceId]);

  if (loading) return <p className="text-xs text-slate-400 py-2">Loading payment history...</p>;
  if (payments.length === 0) return <p className="text-xs text-slate-400 py-2">No payments recorded yet.</p>;

  return (
    <div className="py-2 px-4 bg-slate-50">
      <p className="text-xs font-semibold text-slate-500 uppercase mb-2">Payment History</p>
      {balance > 0 && (
        <p className="text-xs text-orange-600 mb-2">Remaining balance: ${Number(balance).toFixed(2)}</p>
      )}
      <table className="min-w-full text-xs">
        <thead>
          <tr className="text-slate-500">
            <th className="text-left py-1 pr-3">Date</th>
            <th className="text-left py-1 pr-3">Amount</th>
            <th className="text-left py-1 pr-3">Reference</th>
            <th className="text-left py-1">Marked By</th>
          </tr>
        </thead>
        <tbody>
          {payments.map((p) => (
            <tr key={p.id} className="border-t border-slate-200">
              <td className="py-1.5 pr-3">{p.paid_date?.split('T')[0] || '—'}</td>
              <td className="py-1.5 pr-3">${Number(p.amount).toFixed(2)}</td>
              <td className="py-1.5 pr-3">{p.reference_number || '—'}</td>
              <td className="py-1.5">{p.marked_by?.name || p.markedBy?.name || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function Invoices() {
  const { user } = useAuth();
  const isOwner = user?.role === 'owner';
  const [invoices, setInvoices] = useState([]);
  const [markPaidInvoice, setMarkPaidInvoice] = useState(null);
  const [expandedId, setExpandedId] = useState(null);

  const loadInvoices = useCallback(() => {
    api.get('/invoices').then(({ data }) => setInvoices(data.data || data)).catch(() => setInvoices([]));
  }, []);

  useEffect(() => { loadInvoices(); }, [loadInvoices]);

  const canMarkPaid = (inv) => isOwner && PAYABLE_STATUSES.includes(inv.status) && Number(inv.balance) > 0;
  const canSend = (inv) => ['owner', 'pm'].includes(user?.role) && ['draft', 'awaiting_payment'].includes(inv.status);

  const sendInvoice = async (inv) => {
    const ok = await confirmAction({ title: 'Send invoice?', text: `Send ${inv.invoice_number} to the customer?`, confirmText: 'Yes, send' });
    if (!ok) return;
    try {
      await api.post(`/invoices/${inv.id}/send`);
      await showSuccess('Invoice sent.');
      loadInvoices();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to send invoice.');
    }
  };

  return (
    <div>
      <PageHeader title="Invoices" />
      <div className="md:hidden space-y-3 mb-4">
        {invoices.length === 0 ? (
          <p className="text-center text-slate-500 py-8">No invoices found.</p>
        ) : invoices.map((inv) => (
          <div key={inv.id} className="bg-white border border-slate-200 rounded-xl p-4">
            <div className="flex justify-between items-start mb-2">
              <span className="font-medium text-slate-800">{inv.invoice_number || `#${inv.id}`}</span>
              <div className="flex gap-1 flex-wrap justify-end">
                <StatusBadge status={inv.status} />
                {inv.is_overdue && <StatusBadge status="overdue" />}
              </div>
            </div>
            <p className="text-sm text-slate-500">{inv.customer?.name || '—'}</p>
            <p className="text-sm text-slate-500">Job #{inv.job_id}</p>
            <p className="text-sm font-medium mt-1">Balance: ${Number(inv.balance || 0).toFixed(2)}</p>
            {canSend(inv) && (
              <button type="button" onClick={() => sendInvoice(inv)}
                className="mt-3 w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium">
                Send Invoice
              </button>
            )}
            {canMarkPaid(inv) && (
              <button type="button" onClick={() => setMarkPaidInvoice(inv)}
                className="mt-3 w-full bg-green-600 hover:bg-green-700 text-white rounded-lg py-2 text-sm font-medium">
                Mark as Paid
              </button>
            )}
          </div>
        ))}
      </div>

      <div className="hidden md:block bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] divide-y divide-[#E2E8F0] text-sm">
          <thead className="bg-slate-50">
            <tr>
              <th className="w-8 px-2 py-3" />
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Invoice #</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Job</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Customer</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Balance</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] whitespace-nowrap">Status</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell whitespace-nowrap">Due Date</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] whitespace-nowrap sticky right-0 bg-slate-50">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {invoices.length === 0 ? (
              <tr><td colSpan={8} className="px-4 py-8 text-center text-slate-500">No invoices found.</td></tr>
            ) : invoices.map((inv) => (
              <Fragment key={inv.id}>
                <tr className="hover:bg-slate-50">
                  <td className="px-2 py-3">
                    <button type="button" onClick={() => setExpandedId(expandedId === inv.id ? null : inv.id)}
                      className="text-slate-400 hover:text-slate-600">
                      {expandedId === inv.id ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
                    </button>
                  </td>
                  <td className="px-4 py-3 whitespace-nowrap">{inv.invoice_number || `#${inv.id}`}</td>
                  <td className="px-4 py-3 whitespace-nowrap">#{inv.job_id}</td>
                  <td className="px-4 py-3 whitespace-nowrap">{inv.customer?.name || '—'}</td>
                  <td className="px-4 py-3 whitespace-nowrap">${Number(inv.balance || 0).toFixed(2)}</td>
                  <td className="px-4 py-3 whitespace-nowrap"><StatusBadge status={inv.status} />{inv.is_overdue && <span className="ml-1"><StatusBadge status="overdue" /></span>}</td>
                  <td className="px-4 py-3 hidden lg:table-cell whitespace-nowrap">{inv.due_date?.split?.('T')?.[0] || inv.due_date || '—'}</td>
                  <td className="px-4 py-3 whitespace-nowrap sticky right-0 bg-white">
                    <div className="flex flex-wrap gap-2">
                      {canSend(inv) && (
                        <button type="button" onClick={() => sendInvoice(inv)}
                          className="text-xs px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                          Send Invoice
                        </button>
                      )}
                      {canMarkPaid(inv) && (
                        <button type="button" onClick={() => setMarkPaidInvoice(inv)}
                          className="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium">
                          Mark as Paid
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
                {expandedId === inv.id && (
                  <tr>
                    <td colSpan={8}>
                      <PaymentHistory invoiceId={inv.id} balance={inv.balance} />
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
        </div>
      </div>

      {markPaidInvoice && (
        <MarkPaidModal
          invoice={markPaidInvoice}
          onClose={() => setMarkPaidInvoice(null)}
          onSuccess={loadInvoices}
        />
      )}
    </div>
  );
}
