import { useEffect, useState } from 'react';
import { Database, Table2, Lock } from 'lucide-react';
import api from '../api/axios';
import { showError, showSuccess, confirmAction } from '../utils/swal';
import Swal from 'sweetalert2';

/**
 * A-23 — Developer-only diagnostics. Requires is_developer + password unlock.
 * Default view: health/structure metadata only (no raw customer samples).
 */
export default function DatabaseStructure() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [active, setActive] = useState(0);
  const [unlocked, setUnlocked] = useState(false);
  const [isDeveloper, setIsDeveloper] = useState(false);
  const [reauthBusy, setReauthBusy] = useState(false);
  const [gateCode, setGateCode] = useState(null);

  const loadStatus = async () => {
    try {
      const { data: st } = await api.get('/admin/developer/status');
      setIsDeveloper(Boolean(st.is_developer));
      setUnlocked(Boolean(st.unlocked));
      return st;
    } catch {
      setIsDeveloper(false);
      setUnlocked(false);
      return null;
    }
  };

  const loadOverview = async (includeSamples = false) => {
    setLoading(true);
    setGateCode(null);
    try {
      const { data: overview } = await api.get('/admin/database-overview', {
        params: includeSamples ? { include_samples: 1 } : undefined,
      });
      setData(overview);
      setUnlocked(true);
    } catch (err) {
      const code = err.response?.data?.code;
      setGateCode(code || 'error');
      setData(null);
      if (code === 'developer_reauth_required') {
        setUnlocked(false);
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    (async () => {
      const st = await loadStatus();
      if (st?.is_developer && st?.unlocked) {
        await loadOverview(false);
      } else {
        setLoading(false);
      }
    })();
  }, []);

  const unlock = async () => {
    const { value: password } = await Swal.fire({
      title: 'Re-authenticate',
      text: 'Enter your password to unlock developer diagnostics (15 minutes).',
      input: 'password',
      inputPlaceholder: 'Password',
      showCancelButton: true,
      confirmButtonText: 'Unlock',
      confirmButtonColor: '#2563eb',
    });
    if (!password) return;
    setReauthBusy(true);
    try {
      await api.post('/admin/developer/unlock', { password });
      setUnlocked(true);
      await showSuccess('Diagnostics unlocked.');
      await loadOverview(false);
    } catch (err) {
      await showError(err.response?.data?.errors?.password?.[0] || err.response?.data?.message || 'Unlock failed.');
    } finally {
      setReauthBusy(false);
    }
  };

  const requestSamples = async () => {
    const ok = await confirmAction({
      title: 'Load redacted samples?',
      text: 'Samples are PII-redacted and still logged. Prefer health metadata when possible.',
      confirmText: 'Yes, load redacted samples',
    });
    if (!ok) return;
    await loadOverview(true);
  };

  if (!isDeveloper && !loading) {
    return (
      <div className="max-w-xl mx-auto bg-white border border-slate-200 rounded-xl p-8 text-center">
        <Lock className="w-8 h-8 text-slate-400 mx-auto mb-3" />
        <h2 className="text-lg font-semibold text-slate-800">Developer permission required</h2>
        <p className="text-sm text-slate-500 mt-2">
          Database Structure is a developer diagnostics tool. Standard owners cannot browse raw schema or samples without an elevated developer flag.
        </p>
      </div>
    );
  }

  if (!unlocked || gateCode === 'developer_reauth_required') {
    return (
      <div className="max-w-xl mx-auto bg-white border border-slate-200 rounded-xl p-8 text-center">
        <Lock className="w-8 h-8 text-amber-500 mx-auto mb-3" />
        <h2 className="text-lg font-semibold text-slate-800">Re-authentication required</h2>
        <p className="text-sm text-slate-500 mt-2 mb-4">
          Even with developer permission, password re-entry is required before viewing diagnostics.
        </p>
        <button
          type="button"
          disabled={reauthBusy}
          onClick={unlock}
          className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg disabled:opacity-60"
        >
          {reauthBusy ? 'Unlocking…' : 'Re-enter password'}
        </button>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
      </div>
    );
  }

  const tables = data?.tables || [];

  return (
    <div className="max-w-6xl mx-auto">
      <div className="mb-6">
        <div className="flex items-center gap-3 mb-1">
          <Database className="w-6 h-6 text-blue-600" />
          <h1 className="text-2xl font-bold text-slate-900">Database Structure</h1>
        </div>
        <p className="text-slate-500 text-sm">
          {tables.length} tables · mode: {data?.mode || 'health'} · engine: {data?.schema_engine || '—'}
        </p>
        <p className="text-xs text-amber-700 mt-1">{data?.samples_note}</p>
        {!data?.include_samples && (
          <button
            type="button"
            onClick={requestSamples}
            className="mt-3 text-sm text-blue-600 hover:text-blue-800"
          >
            Request redacted sample rows…
          </button>
        )}
      </div>

      <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
        {tables.map((t, i) => (
          <button
            key={t.name}
            type="button"
            onClick={() => setActive(i)}
            className={`p-3 rounded-xl border text-left transition-all ${
              active === i ? 'border-blue-500 bg-blue-50' : 'border-slate-200 bg-white hover:border-slate-300'
            }`}
          >
            <div className="flex items-center gap-2 mb-1">
              <Table2 className={`w-4 h-4 ${active === i ? 'text-blue-600' : 'text-slate-400'}`} />
              <span className={`text-xs font-semibold truncate ${active === i ? 'text-blue-700' : 'text-slate-600'}`}>
                {t.name}
              </span>
            </div>
            <p className="text-lg font-bold text-slate-800">{t.count ?? 0}</p>
            <p className="text-xs text-slate-400">rows · {t.health || 'ok'}</p>
          </button>
        ))}
      </div>

      {tables[active] && (
        <div className="bg-white rounded-2xl border border-slate-200 p-6">
          <div className="mb-4">
            <h2 className="text-lg font-bold text-slate-800 flex items-center gap-2 flex-wrap">
              <Table2 className="w-5 h-5 text-blue-600" />
              {tables[active].name}
              <span className="text-sm font-normal bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">
                {tables[active].count} rows
              </span>
            </h2>
            <p className="text-sm text-slate-500 mt-1">{tables[active].purpose}</p>
          </div>

          <div className="mb-4">
            <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Columns (metadata)</p>
            <div className="flex flex-wrap gap-2">
              {(tables[active].columns || []).map((col) => (
                <span
                  key={col}
                  className="text-xs px-2.5 py-1 rounded-full font-mono border bg-slate-50 border-slate-200 text-slate-700"
                >
                  {col}
                </span>
              ))}
            </div>
          </div>

          {tables[active].statuses && (
            <div className="mt-4">
              <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Status Breakdown</p>
              <div className="flex flex-wrap gap-2">
                {tables[active].statuses.map((s) => (
                  <span key={s.status} className="text-xs px-3 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200">
                    {s.status}: <strong>{s.total}</strong>
                  </span>
                ))}
              </div>
            </div>
          )}

          {tables[active].roles && (
            <div className="mt-4">
              <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Users by Role</p>
              <div className="flex flex-wrap gap-2">
                {tables[active].roles.map((r) => (
                  <span key={r.role} className="text-xs px-3 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200">
                    {r.role}: <strong>{r.total}</strong>
                  </span>
                ))}
              </div>
            </div>
          )}

          {tables[active].sample && (
            <div className="mt-4">
              <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">
                Sample Record {tables[active].sample_redacted ? '(PII redacted)' : ''}
              </p>
              <pre className="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-700 overflow-x-auto">
                {JSON.stringify(tables[active].sample, null, 2)}
              </pre>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
