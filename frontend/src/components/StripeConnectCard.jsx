import { useEffect, useState } from 'react';
import api from '../api/axios';
import { showError, showSuccess } from '../utils/swal';

/**
 * Stripe Connect Express onboarding for contractors / PMs.
 * PM-05: one authoritative live/test + onboarding status; account ID masked to last 4.
 */
export default function StripeConnectCard() {
  const [status, setStatus] = useState(null);
  const [busy, setBusy] = useState(false);

  const applyStatus = (data) => {
    if (!data) return;
    setStatus({
      provider: data.provider,
      mode: data.mode,
      livemode: data.livemode,
      has_stripe_account: !!data.has_stripe_account,
      stripe_account_ref: data.stripe_account_ref || null,
      onboarding_status: data.onboarding_status,
      payout_ready: !!data.payout_ready,
      requirements_due: data.requirements_due || [],
      requirements_plain: data.requirements_plain || [],
      support_guidance: data.support_guidance,
      status_label: data.status_label,
      synced_at: data.synced_at,
    });
  };

  const load = () => {
    api.get('/stripe/connect/status')
      .then(({ data }) => applyStatus(data))
      .catch(() => setStatus(null));
  };

  const syncFromStripe = async ({ silent = false } = {}) => {
    setBusy(true);
    try {
      const { data } = await api.post('/stripe/connect/sync');
      applyStatus(data);
      if (!silent) {
        await showSuccess(
          data.payout_ready
            ? 'Stripe payouts are ready.'
            : `Stripe status: ${data.status_label || data.onboarding_status || 'pending'}`
        );
      }
      return data;
    } catch (e) {
      if (!silent) {
        await showError(e.response?.data?.message || e.message || 'Unable to refresh Stripe status');
      }
      // Still refresh local cached flags when sync fails (e.g. mock provider)
      load();
      return null;
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const { data } = await api.get('/stripe/connect/status');
        if (cancelled) return;
        applyStatus(data);
        // Auto-sync from Stripe when an account exists and provider is live Stripe
        if (data?.provider === 'stripe' && data?.has_stripe_account) {
          const synced = await api.post('/stripe/connect/sync').catch(() => null);
          if (!cancelled && synced?.data) applyStatus(synced.data);
        }
      } catch {
        if (!cancelled) setStatus(null);
      }
    })();
    return () => { cancelled = true; };
  }, []);

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
        window.history.replaceState({}, '', window.location.pathname);
      })();
    }
  }, []);

  if (!status) return null;
  if (status.provider !== 'stripe') {
    return (
      <div className="bg-white rounded-xl border border-dashed border-slate-300 p-5">
        <h3 className="font-semibold text-slate-800 mb-1">Payout account (Stripe Connect)</h3>
        <p className="text-sm text-slate-500">
          Stripe Connect is not enabled in this environment (payment provider: {status.provider || 'mock'}).
          Mode: <span className="font-medium">{status.mode || 'TEST'}</span>.
        </p>
      </div>
    );
  }

  const ready = !!status.payout_ready;
  const duePlain = status.requirements_plain || [];
  const mode = status.mode || (status.livemode ? 'LIVE' : 'TEST');

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-semibold text-slate-800">Payout account (Stripe Connect)</h3>
        <span
          className={`text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded ${
            mode === 'LIVE' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
          }`}
        >
          {mode} mode
        </span>
      </div>
      <p className="text-sm text-slate-600">
        Connect your bank account through Stripe. ServiceOP never stores your bank details.
      </p>
      <div className="text-sm space-y-1">
        <p>
          Status:{' '}
          <span className={`font-medium ${ready ? 'text-green-700' : 'text-amber-700'}`}>
            {status.status_label || (ready ? 'Ready for payouts' : (status.onboarding_status || 'Not started'))}
          </span>
        </p>
        {status.stripe_account_ref && (
          <p className="text-xs text-slate-400 font-mono">Account ref: {status.stripe_account_ref}</p>
        )}
        {duePlain.length > 0 && (
          <ul className="text-xs text-amber-800 list-disc pl-4 space-y-0.5">
            {duePlain.slice(0, 5).map((step) => (
              <li key={step}>{step}</li>
            ))}
          </ul>
        )}
        {status.support_guidance && (
          <p className="text-xs text-slate-500">{status.support_guidance}</p>
        )}
        {status.synced_at && (
          <p className="text-xs text-slate-400">Synced {new Date(status.synced_at).toLocaleString()}</p>
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
            {busy ? 'Opening Stripe…' : (status.has_stripe_account ? 'Continue Stripe setup' : 'Connect Stripe')}
          </button>
        )}
        {status.has_stripe_account && (
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
