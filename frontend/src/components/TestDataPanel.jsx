import { useEffect, useState } from 'react';
import api from '../api/axios';
import { confirmDanger, showError, showSuccess } from '../utils/swal';

export default function TestDataPanel() {
  const [summary, setSummary] = useState(null);
  const [dryRun, setDryRun] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/admin/test-data');
      setSummary(data);
    } catch {
      setSummary(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const runDryRun = async () => {
    setBusy(true);
    try {
      const { data } = await api.post('/admin/test-data/dry-run');
      setDryRun(data);
      showSuccess('Dry-run complete', 'No records were modified.');
    } catch (e) {
      showError('Dry-run failed', e?.response?.data?.message || e.message);
    } finally {
      setBusy(false);
    }
  };

  const runApply = async () => {
    const ok = await confirmDanger({
      title: 'Flag matched test records?',
      text: 'This sets is_test_data=true on matched QA/placeholder rows. Nothing is deleted. Production dashboards will hide them afterward.',
      confirmText: 'Yes, flag test data',
    });
    if (!ok) return;
    setBusy(true);
    try {
      await api.post('/admin/test-data/apply', { confirm: true });
      showSuccess('Job queued', 'Refresh in a moment to see updated counts.');
      setTimeout(load, 1500);
    } catch (e) {
      showError('Apply failed', e?.response?.data?.message || e.message);
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return <p className="text-slate-500 text-sm">Loading test-data summary…</p>;
  }

  const counts = summary?.counts || {};
  const totalFlagged = Object.values(counts).reduce((a, b) => a + Number(b || 0), 0);

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <h3 className="text-base font-semibold text-slate-900">Test data separation (A-05)</h3>
        <p className="mt-1 text-sm text-slate-600">
          Flagged records stay in the database but are excluded from dashboards, reports, accounting,
          AI Command Center, and outbound SMS/email. Owner-only diagnostics.
        </p>
        <p className="mt-2 text-sm text-slate-700">
          Currently flagged: <strong>{totalFlagged}</strong> rows across {Object.keys(counts).length} tables
          {summary?.app_env ? <> · env <code className="text-xs bg-slate-100 px-1 rounded">{summary.app_env}</code></> : null}
        </p>
        <div className="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            disabled={busy}
            onClick={runDryRun}
            className="px-3 py-2 rounded-lg bg-slate-800 text-white text-sm font-medium hover:bg-slate-700 disabled:opacity-50"
          >
            Run dry-run report
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={runApply}
            className="px-3 py-2 rounded-lg bg-amber-500 text-amber-950 text-sm font-semibold hover:bg-amber-400 disabled:opacity-50"
          >
            Apply flags (queued)
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={load}
            className="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Refresh counts
          </button>
        </div>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <div className="px-5 py-3 border-b border-slate-100">
          <h4 className="text-sm font-semibold text-slate-800">Flagged counts by table</h4>
        </div>
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50 text-slate-500 text-left">
            <tr>
              <th className="px-5 py-2 font-medium">Table</th>
              <th className="px-5 py-2 font-medium">Flagged</th>
            </tr>
          </thead>
          <tbody>
            {Object.entries(counts).map(([table, count]) => (
              <tr key={table} className="border-t border-slate-100">
                <td className="px-5 py-2 font-mono text-xs">{table}</td>
                <td className="px-5 py-2">{count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {summary?.last_run && (
        <div className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-700">
          <h4 className="font-semibold text-slate-900 mb-2">Last job result</h4>
          <pre className="text-xs bg-slate-50 rounded-lg p-3 overflow-auto max-h-64">
            {JSON.stringify(summary.last_run, null, 2)}
          </pre>
        </div>
      )}

      {dryRun && (
        <div className="rounded-xl border border-amber-200 bg-amber-50/40 p-5 text-sm">
          <h4 className="font-semibold text-slate-900 mb-2">
            Dry-run report — would flag {dryRun.totals?.would_flag ?? 0}
            {dryRun.totals?.review ? ` · ${dryRun.totals.review} need manual review` : ''}
          </h4>
          <pre className="text-xs bg-white rounded-lg p-3 overflow-auto max-h-96 border border-amber-100">
            {JSON.stringify(
              {
                totals: dryRun.totals,
                before: dryRun.before,
                after: dryRun.after,
                flagged: dryRun.flagged,
                needs_manual_review: dryRun.needs_manual_review,
              },
              null,
              2
            )}
          </pre>
        </div>
      )}
    </div>
  );
}
