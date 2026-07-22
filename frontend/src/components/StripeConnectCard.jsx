import { useEffect, useState } from 'react';
import api from '../api/axios';
import { showError, showSuccess } from '../utils/swal';

/**
 * Stripe Connect Express onboarding for contractors / PMs.
 */
export default function StripeConnectCard() {
  const [status, setStatus] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = () => {
    api.get('/stripe/connect/status')
      .then(({ data }) => setStatus(data))
      .catch(() => setStatus(null));
  };

  const syncFromStripe = async ({ silent = false } = {}) => {
    setBusy(true);
    try {
      const { data } = await api.post('/stripe/connect/sync');
      setStatus((prev) => ({
        ...(prev || {}),
        provider: 'stripe',
        stripe_account_id: data.stripe_account_id,
        onboarding_status: data.onboarding_status,
        payout_ready: data.payout_ready,
        requirements_due: data.requirements_due || [],
      }));
      if (!silent) {
        await showSuccess(
          data.payout_ready
            ? 'Stripe payouts are ready.'
            : `Stripe status: ${data.onboarding_status || 'pending'}`
        );
      }
      return data;
    } catch (e) {
      if (!silent) {
        await showError(e.response?.data?.message || e.message || 'Unable to refresh Stripe status');
      }
      load();
      return null;
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => { load(); }, []);

  const start = async () => {
    setBusy(true);
    try {
      const returnUrl = `${window.location.origin}${window.location.pathname}?stripe=return`;
      const refreshUrl = `${window.location.origin}${window.location.pathname}?stripe=refresh`;
      const { data } = await api.post('/stripe/connect/start', {
        return_url: returnUrl,
        refresh_url: refreshUrl,
      });
      if (!data.onboarding_url) {
        throw new Error('No onboarding URL');
      }
      window.location.href = data.onboarding_url;
    } catch (e) {
      await showError(e.response?.data?.message || e.message || 'Unable to start Stripe Connect');
      setBusy(false);
    }
  };

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('stripe') === 'return') {
      (async () => {
        const data = await syncFromStripe({ silent: true });
        if (data?.payout_ready) {
          await showSuccess('Stripe onboarding complete — ready for payouts.');
        } else {
          await showSuccess('Stripe onboarding returned — status refreshed from Stripe.');
        }
        // Clean query so refresh doesn't re-toast
        window.history.replaceState({}, '', window.location.pathname);
      })();
    }
  }, []);

  if (!status) return null;
  if (status.provider !== 'stripe') {
    return (
      <div className="bg-white rounded-xl border border-dashed border-slate-300 p-5">
        <h3 className="font-semibold text-slate-800 mb-1">Payout account (Stripe Connect)</h3>
        <p className="text-sm text-slate-500">Stripe Connect is not enabled in this environment.</p>
      </div>
    );
  }

  const ready = !!status.payout_ready;
  const due = status.requirements_due || [];

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
      <h3 className="font-semibold text-slate-800">Payout account (Stripe Connect)</h3>
      <p className="text-sm text-slate-600">
        Connect your bank account through Stripe. ServiceOP never stores your bank details.
      </p>
      <div className="text-sm space-y-1">
        <p>
          Status:{' '}
          <span className={`font-medium ${ready ? 'text-green-700' : 'text-amber-700'}`}>
            {ready ? 'Ready for payouts' : (status.onboarding_status || 'Not started')}
          </span>
        </p>
        {status.stripe_account_id && (
          <p className="text-xs text-slate-400 font-mono">Account: {status.stripe_account_id}</p>
        )}
        {due.length > 0 && (
          <p className="text-xs text-amber-700">Requirements due: {due.slice(0, 5).join(', ')}{due.length > 5 ? '…' : ''}</p>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        {!ready && (
          <button
            type="button"
            onClick={start}
            disabled={busy}
            className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
          >
            {busy ? 'Opening Stripe…' : (status.stripe_account_id ? 'Continue Stripe setup' : 'Connect Stripe')}
          </button>
        )}
        {status.stripe_account_id && (
          <button
            type="button"
            onClick={() => syncFromStripe()}
            disabled={busy}
            className="px-4 py-2 border border-slate-300 text-slate-700 rounded-lg text-sm font-medium hover:bg-slate-50 disabled:opacity-50"
          >
            {busy ? 'Refreshing…' : 'Refresh Stripe status'}
          </button>
        )}
      </div>
    </div>
  );
}
