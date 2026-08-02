import { useCallback, useEffect, useState } from 'react';
import { ShieldAlert, KeyRound, Activity, Ban } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { formatDateTime } from '../utils/formatDate';
import { confirmDanger, showError, showSuccess } from '../utils/swal';

/**
 * Milestone 6A Phase 3/4 — Owner Review Center for External Review AI gateway.
 * Identity: dedicated external_review_ai (never ai_super_admin).
 * Matches AI Settings / AI Activity Log visual conventions (cards + filters + tables).
 */
export default function ReviewCenter() {
  const [summary, setSummary] = useState(null);
  const [tokens, setTokens] = useState([]);
  const [logs, setLogs] = useState([]);
  const [meta, setMeta] = useState({});
  const [evalRuns, setEvalRuns] = useState([]);
  const [evalMeta, setEvalMeta] = useState({});
  const [evalPage, setEvalPage] = useState(1);
  const [evalLoading, setEvalLoading] = useState(false);
  const [selectedRunId, setSelectedRunId] = useState(null);
  const [findings, setFindings] = useState([]);
  const [findingsRun, setFindingsRun] = useState(null);
  const [findingsLoading, setFindingsLoading] = useState(false);
  const [loading, setLoading] = useState(true);
  const [logsLoading, setLogsLoading] = useState(false);
  const [filters, setFilters] = useState({
    outcome: '',
    tool: '',
    token_name: '',
    date_from: '',
    date_to: '',
    page: 1,
  });

  const loadSummary = useCallback(() => {
    return api.get('/admin/review-gateway/summary')
      .then(({ data }) => setSummary(data))
      .catch(async (e) => {
        await showError(e.response?.data?.message || 'Failed to load review gateway summary');
      });
  }, []);

  const loadTokens = useCallback(() => {
    return api.get('/admin/review-gateway/tokens')
      .then(({ data }) => setTokens(data.data || []))
      .catch(() => setTokens([]));
  }, []);

  const loadEvalRuns = useCallback(() => {
    setEvalLoading(true);
    return api.get('/admin/review-gateway/evaluation-runs', { params: { page: evalPage, per_page: 10 } })
      .then(({ data }) => {
        setEvalRuns(data.data || []);
        setEvalMeta({
          current: data.current_page,
          last: data.last_page,
          total: data.total,
        });
      })
      .catch(() => setEvalRuns([]))
      .finally(() => setEvalLoading(false));
  }, [evalPage]);

  const loadFindings = useCallback((runId) => {
    if (!runId) {
      setFindings([]);
      setFindingsRun(null);
      return Promise.resolve();
    }
    setFindingsLoading(true);
    return api.get(`/admin/review-gateway/evaluation-runs/${runId}/findings`)
      .then(({ data }) => {
        setFindings(data.data || []);
        setFindingsRun(data.run || null);
      })
      .catch(async (e) => {
        setFindings([]);
        setFindingsRun(null);
        await showError(e.response?.data?.message || 'Failed to load evaluation findings');
      })
      .finally(() => setFindingsLoading(false));
  }, []);

  const loadLogs = useCallback(() => {
    setLogsLoading(true);
    const params = { page: filters.page, per_page: 25 };
    if (filters.outcome) params.outcome = filters.outcome;
    if (filters.tool) params.tool = filters.tool;
    if (filters.token_name) params.token_name = filters.token_name;
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;

    return api.get('/admin/review-gateway/access-logs', { params })
      .then(({ data }) => {
        setLogs(data.data || []);
        setMeta({
          current: data.current_page,
          last: data.last_page,
          total: data.total,
        });
      })
      .catch(() => setLogs([]))
      .finally(() => setLogsLoading(false));
  }, [filters]);

  useEffect(() => {
    setLoading(true);
    Promise.all([loadSummary(), loadTokens()]).finally(() => setLoading(false));
  }, [loadSummary, loadTokens]);

  useEffect(() => {
    loadLogs();
  }, [loadLogs]);

  useEffect(() => {
    loadEvalRuns();
  }, [loadEvalRuns]);

  useEffect(() => {
    loadFindings(selectedRunId);
  }, [selectedRunId, loadFindings]);

  const setFilter = (key, value) => {
    setFilters((prev) => ({ ...prev, [key]: value, page: key === 'page' ? value : 1 }));
  };

  const toggleKillSwitch = async () => {
    if (!summary) return;
    const turningOn = !summary.kill_switch;
    const ok = await confirmDanger({
      title: turningOn ? 'Engage review gateway kill switch?' : 'Clear review gateway kill switch?',
      text: turningOn
        ? 'All External Review AI calls will be rejected immediately, even with valid tokens. Issued tokens are not deleted.'
        : 'External Review AI tokens will be able to call /api/review-gateway/* again (subject to their abilities).',
      confirmText: turningOn ? 'Yes, block review access' : 'Yes, allow review access',
    });
    if (!ok) return;

    try {
      const { data } = await api.patch('/admin/review-gateway/kill-switch', { enabled: turningOn });
      setSummary((prev) => (prev ? { ...prev, kill_switch: data.kill_switch } : prev));
      await showSuccess(data.message || 'Kill switch updated');
      await loadSummary();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to update kill switch');
    }
  };

  const revokeToken = async (token) => {
    const ok = await confirmDanger({
      title: `Revoke token “${token.name}”?`,
      text: 'This permanently invalidates the bearer secret. The External Review AI using this token will lose access immediately. This cannot be undone — issue a new token via artisan if needed.',
      confirmText: 'Yes, revoke token',
    });
    if (!ok) return;

    try {
      await api.post(`/admin/review-gateway/tokens/${token.id}/revoke`);
      await showSuccess('Token revoked');
      await Promise.all([loadTokens(), loadSummary()]);
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to revoke token');
    }
  };

  const outcomeBadge = (outcome) => {
    const map = {
      success: 'bg-green-100 text-green-800',
      denied: 'bg-red-100 text-red-800',
      error: 'bg-amber-100 text-amber-900',
    };
    return map[outcome] || 'bg-slate-100 text-slate-700';
  };

  if (loading && !summary) {
    return <p className="text-sm text-slate-500">Loading Review Center…</p>;
  }

  return (
    <div className="space-y-6 max-w-6xl">
      <PageHeader title="Review Center">
        <span className="text-sm text-slate-500">
          External Review AI — dedicated <code className="bg-slate-100 px-1 rounded">external_review_ai</code> identity (Owner only)
        </span>
      </PageHeader>

      {summary?.identity && (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
          <span className="font-medium">{summary.identity.label || 'External Review AI'}</span>
          {' · '}role <code className="bg-white px-1 rounded border border-slate-200">{summary.identity.role}</code>
          {summary.identity.email ? <> · {summary.identity.email}</> : null}
          <span className="text-slate-500"> — does not inherit ai_super_admin</span>
        </div>
      )}

      {/* Dashboard cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="flex items-center gap-2 text-slate-500 text-xs uppercase tracking-wide mb-2">
            <Activity className="w-4 h-4" /> Call volume
          </div>
          <p className="text-2xl font-semibold text-slate-900">{summary?.calls?.['24h'] ?? 0}</p>
          <p className="text-xs text-slate-500 mt-1">
            24h · 7d {summary?.calls?.['7d'] ?? 0} · 30d {summary?.calls?.['30d'] ?? 0}
          </p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="flex items-center gap-2 text-slate-500 text-xs uppercase tracking-wide mb-2">
            <Ban className="w-4 h-4" /> Denied
          </div>
          <p className="text-2xl font-semibold text-slate-900">{summary?.denied?.['24h'] ?? 0}</p>
          <p className="text-xs text-slate-500 mt-1">
            24h · 7d {summary?.denied?.['7d'] ?? 0} · 30d {summary?.denied?.['30d'] ?? 0}
          </p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="flex items-center gap-2 text-slate-500 text-xs uppercase tracking-wide mb-2">
            <KeyRound className="w-4 h-4" /> Active tokens
          </div>
          <p className="text-2xl font-semibold text-slate-900">{summary?.active_token_count ?? 0}</p>
          <p className="text-xs text-slate-500 mt-1 truncate">
            external_review_ai only · Last used:{' '}
            {summary?.most_recently_used_token?.name
              ? `${summary.most_recently_used_token.name} · ${formatDateTime(summary.most_recently_used_token.last_used_at) || 'never'}`
              : '—'}
          </p>
        </div>
        <div className={`rounded-xl border p-4 ${summary?.kill_switch ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white'}`}>
          <div className="flex items-center gap-2 text-slate-500 text-xs uppercase tracking-wide mb-2">
            <ShieldAlert className="w-4 h-4" /> Kill switch
          </div>
          <p className={`text-2xl font-semibold ${summary?.kill_switch ? 'text-red-800' : 'text-slate-900'}`}>
            {summary?.kill_switch ? 'ON' : 'OFF'}
          </p>
          <button
            type="button"
            onClick={toggleKillSwitch}
            className={`mt-3 text-sm font-medium px-3 py-1.5 rounded-lg border ${
              summary?.kill_switch
                ? 'border-red-400 text-red-900 bg-white hover:bg-red-100'
                : 'border-slate-300 text-slate-700 hover:bg-slate-50'
            }`}
          >
            {summary?.kill_switch ? 'Clear kill switch' : 'Engage kill switch'}
          </button>
        </div>
      </div>

      {(summary?.tokens_nearing_expiration?.length > 0 || summary?.legacy_token_count > 0) && (
        <div className="space-y-2">
          {summary?.tokens_nearing_expiration?.length > 0 && (
            <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
              {summary.tokens_nearing_expiration.length} token(s) expire within{' '}
              {summary.token_expiry_warning_days ?? 14} day(s) — not auto-revoked. Re-issue via artisan, then revoke the old token.
              <ul className="mt-1 list-disc list-inside text-xs">
                {summary.tokens_nearing_expiration.map((t) => (
                  <li key={t.id}>
                    {t.name} · expires {formatDateTime(t.expires_at) || t.expires_at}
                    {t.days_left != null ? ` (${t.days_left}d left)` : ''}
                  </li>
                ))}
              </ul>
            </div>
          )}
          {summary?.legacy_token_count > 0 && (
            <div className="rounded-lg border border-orange-300 bg-orange-50 px-3 py-2 text-sm text-orange-950">
              {summary.legacy_token_count} legacy review token(s) still attached outside{' '}
              <code className="bg-white px-1 rounded">external_review_ai</code>. Run{' '}
              <code className="bg-white px-1 rounded">php artisan review-ai:migrate-legacy-tokens</code> (add{' '}
              <code className="bg-white px-1 rounded">--revoke</code> only after re-issuing).
            </div>
          )}
        </div>
      )}

      {summary?.kill_switch && (
        <div className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">
          Kill switch is active — External Review AI access is blocked. Tokens are not deleted.
        </div>
      )}

      {/* Token management */}
      <div className="bg-white rounded-xl border border-slate-200 p-4 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="font-semibold text-slate-800">Issued external_review_ai tokens</h3>
          <p className="text-xs text-slate-500">
            Issue via <code className="bg-slate-100 px-1 rounded">php artisan review-ai:issue-token</code> (default TTL 90 days).
          </p>
        </div>
        {tokens.length === 0 ? (
          <p className="text-sm text-slate-500">No active external_review_ai tokens found.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-500 border-b border-slate-100">
                  <th className="py-2 pr-3 font-medium">Name</th>
                  <th className="py-2 pr-3 font-medium">Abilities</th>
                  <th className="py-2 pr-3 font-medium">Created</th>
                  <th className="py-2 pr-3 font-medium">Expires</th>
                  <th className="py-2 pr-3 font-medium">Last used</th>
                  <th className="py-2 font-medium"> </th>
                </tr>
              </thead>
              <tbody>
                {tokens.map((t) => (
                  <tr key={t.id} className="border-b border-slate-50">
                    <td className="py-2.5 pr-3 font-medium text-slate-800">
                      {t.name}
                      {t.nearing_expiration ? (
                        <span className="ml-2 text-xs font-medium text-amber-800 bg-amber-100 px-1.5 py-0.5 rounded">expiring soon</span>
                      ) : null}
                    </td>
                    <td className="py-2.5 pr-3 text-xs text-slate-600">
                      {(t.abilities_all || t.abilities || []).join(', ') || '—'}
                    </td>
                    <td className="py-2.5 pr-3 text-slate-600">{formatDateTime(t.created_at) || '—'}</td>
                    <td className="py-2.5 pr-3 text-slate-600">{formatDateTime(t.expires_at) || '—'}</td>
                    <td className="py-2.5 pr-3 text-slate-600">{formatDateTime(t.last_used_at) || 'never'}</td>
                    <td className="py-2.5 text-right">
                      <button
                        type="button"
                        onClick={() => revokeToken(t)}
                        className="text-sm text-red-700 hover:text-red-900 font-medium"
                      >
                        Revoke
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Phase 5 — Evaluation runs (read-only) */}
      <div className="space-y-3">
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <h3 className="font-semibold text-slate-800">Evaluation runs</h3>
          <p className="text-xs text-slate-500">
            Provider-neutral metadata · written via <code className="bg-slate-100 px-1 rounded">review:evidence-write</code>
          </p>
        </div>

        {evalLoading ? (
          <p className="text-sm text-slate-500">Loading evaluation runs…</p>
        ) : evalRuns.length === 0 ? (
          <p className="text-sm text-slate-500 bg-white rounded-xl border border-slate-200 p-6">
            No evaluation runs yet. Smoke: <code className="bg-slate-100 px-1 rounded">php artisan review-ai:smoke-evaluation</code>
          </p>
        ) : (
          <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-500 border-b border-slate-100 bg-slate-50">
                  <th className="px-3 py-2 font-medium">Run</th>
                  <th className="px-3 py-2 font-medium">Provider / Model</th>
                  <th className="px-3 py-2 font-medium">Versions</th>
                  <th className="px-3 py-2 font-medium">Type</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                  <th className="px-3 py-2 font-medium">Cost</th>
                  <th className="px-3 py-2 font-medium">Findings</th>
                </tr>
              </thead>
              <tbody>
                {evalRuns.map((run) => (
                  <tr
                    key={run.id}
                    className={`border-b border-slate-50 cursor-pointer hover:bg-slate-50/60 ${
                      selectedRunId === run.id ? 'bg-sky-50/70' : ''
                    }`}
                    onClick={() => setSelectedRunId(run.id)}
                  >
                    <td className="px-3 py-2 text-slate-800 whitespace-nowrap">
                      #{run.id}
                      <span className="block text-xs text-slate-400">{formatDateTime(run.started_at)}</span>
                    </td>
                    <td className="px-3 py-2 text-slate-700">
                      {run.provider}
                      <span className="block text-xs text-slate-500">{run.model}{run.model_version ? ` · ${run.model_version}` : ''}</span>
                    </td>
                    <td className="px-3 py-2 text-xs text-slate-600">
                      prompt {run.prompt_version}
                      <span className="block">eval {run.evaluation_version}</span>
                      {run.benchmark_set_version ? <span className="block">bench {run.benchmark_set_version}</span> : null}
                    </td>
                    <td className="px-3 py-2 text-slate-700">{run.run_type}</td>
                    <td className="px-3 py-2">
                      <span className={`text-xs px-2 py-0.5 rounded ${
                        run.status === 'completed' ? 'bg-green-100 text-green-800'
                          : run.status === 'failed' ? 'bg-red-100 text-red-800'
                            : 'bg-slate-100 text-slate-800'
                      }`}>
                        {run.status}
                      </span>
                    </td>
                    <td className="px-3 py-2 font-mono text-xs text-slate-600">{run.total_cost}</td>
                    <td className="px-3 py-2 text-slate-700">{run.findings_count ?? 0}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="flex items-center justify-between px-3 py-2 border-t border-slate-100 text-xs text-slate-500">
              <span>
                Page {evalMeta.current || 1} of {evalMeta.last || 1} · {evalMeta.total || 0} total
              </span>
              <div className="flex gap-2">
                <button
                  type="button"
                  disabled={!evalMeta.current || evalMeta.current <= 1}
                  onClick={() => setEvalPage((p) => Math.max(1, p - 1))}
                  className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40"
                >
                  Prev
                </button>
                <button
                  type="button"
                  disabled={!evalMeta.last || (evalMeta.current || 1) >= evalMeta.last}
                  onClick={() => setEvalPage((p) => p + 1)}
                  className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            </div>
          </div>
        )}

        {selectedRunId && (
          <div className="bg-white rounded-xl border border-slate-200 p-4 space-y-3">
            <div className="flex items-center justify-between gap-2 flex-wrap">
              <h4 className="font-medium text-slate-800">
                Findings for run #{selectedRunId}
                {findingsRun ? (
                  <span className="text-xs font-normal text-slate-500 ml-2">
                    {findingsRun.provider}/{findingsRun.model} · {findingsRun.status}
                  </span>
                ) : null}
              </h4>
              <button
                type="button"
                onClick={() => setSelectedRunId(null)}
                className="text-xs text-slate-500 hover:text-slate-800"
              >
                Close
              </button>
            </div>
            {findingsLoading ? (
              <p className="text-sm text-slate-500">Loading findings…</p>
            ) : findings.length === 0 ? (
              <p className="text-sm text-slate-500">No findings on this run.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-slate-500 border-b border-slate-100">
                      <th className="px-2 py-1.5 font-medium">Dimension</th>
                      <th className="px-2 py-1.5 font-medium">Score</th>
                      <th className="px-2 py-1.5 font-medium">Kind</th>
                      <th className="px-2 py-1.5 font-medium">Evidence</th>
                      <th className="px-2 py-1.5 font-medium">Critique</th>
                    </tr>
                  </thead>
                  <tbody>
                    {findings.map((f) => (
                      <tr key={f.id} className="border-b border-slate-50 align-top">
                        <td className="px-2 py-1.5 text-slate-800 whitespace-nowrap">{f.dimension}</td>
                        <td className="px-2 py-1.5 font-mono text-xs">{f.score}/{f.max_score}</td>
                        <td className="px-2 py-1.5 text-xs text-slate-600">{f.statement_kind}</td>
                        <td className="px-2 py-1.5 text-xs text-slate-600 font-mono">
                          {f.evidence_reference || `${f.subject_type}:${f.subject_id}`}
                        </td>
                        <td className="px-2 py-1.5 text-slate-600 max-w-md">{f.critique || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Access logs */}
      <div className="space-y-3">
        <h3 className="font-semibold text-slate-800">Access log</h3>
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="flex flex-col lg:flex-row gap-3 flex-wrap">
            <select
              value={filters.outcome}
              onChange={(e) => setFilter('outcome', e.target.value)}
              className="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white"
            >
              <option value="">All outcomes</option>
              <option value="success">success</option>
              <option value="denied">denied</option>
              <option value="error">error</option>
            </select>
            <input
              type="text"
              placeholder="Tool (e. for source_file)"
              value={filters.tool}
              onChange={(e) => setFilter('tool', e.target.value)}
              className="px-3 py-2 border border-slate-200 rounded-lg text-sm"
            />
            <input
              type="text"
              placeholder="Token name"
              value={filters.token_name}
              onChange={(e) => setFilter('token_name', e.target.value)}
              className="px-3 py-2 border border-slate-200 rounded-lg text-sm"
            />
            <input type="date" value={filters.date_from} onChange={(e) => setFilter('date_from', e.target.value)}
              className="px-3 py-2 border border-slate-200 rounded-lg text-sm" />
            <input type="date" value={filters.date_to} onChange={(e) => setFilter('date_to', e.target.value)}
              className="px-3 py-2 border border-slate-200 rounded-lg text-sm" />
          </div>
        </div>

        {logsLoading ? (
          <p className="text-sm text-slate-500">Loading logs…</p>
        ) : logs.length === 0 ? (
          <p className="text-sm text-slate-500 bg-white rounded-xl border border-slate-200 p-6">
            No access logs match your filters.
          </p>
        ) : (
          <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-500 border-b border-slate-100 bg-slate-50">
                  <th className="px-3 py-2 font-medium">Time</th>
                  <th className="px-3 py-2 font-medium">Tool</th>
                  <th className="px-3 py-2 font-medium">Ability</th>
                  <th className="px-3 py-2 font-medium">Outcome</th>
                  <th className="px-3 py-2 font-medium">Token</th>
                  <th className="px-3 py-2 font-medium">IP</th>
                </tr>
              </thead>
              <tbody>
                {logs.map((log) => (
                  <tr key={log.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                    <td className="px-3 py-2 text-slate-600 whitespace-nowrap">{formatDateTime(log.created_at)}</td>
                    <td className="px-3 py-2 text-slate-800">{log.tool || '—'}</td>
                    <td className="px-3 py-2 text-xs text-slate-600">{log.ability || '—'}</td>
                    <td className="px-3 py-2">
                      <span className={`text-xs px-2 py-0.5 rounded ${outcomeBadge(log.outcome)}`}>
                        {log.outcome}
                      </span>
                      {log.denial_reason && (
                        <span className="block text-xs text-slate-400 mt-0.5 truncate max-w-[12rem]" title={log.denial_reason}>
                          {log.denial_reason}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2 text-slate-700">{log.token_name || '—'}</td>
                    <td className="px-3 py-2 text-slate-500 font-mono text-xs">{log.ip || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="flex items-center justify-between px-3 py-2 border-t border-slate-100 text-xs text-slate-500">
              <span>
                Page {meta.current || 1} of {meta.last || 1} · {meta.total || 0} total
              </span>
              <div className="flex gap-2">
                <button
                  type="button"
                  disabled={!meta.current || meta.current <= 1}
                  onClick={() => setFilter('page', (meta.current || 1) - 1)}
                  className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40"
                >
                  Prev
                </button>
                <button
                  type="button"
                  disabled={!meta.last || (meta.current || 1) >= meta.last}
                  onClick={() => setFilter('page', (meta.current || 1) + 1)}
                  className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
