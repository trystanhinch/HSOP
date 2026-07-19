import { useEffect, useState } from 'react';
import api from '../api/axios';
import { formatDate, formatDateTime } from '../utils/formatDate';

export default function AiActivityLogViewer() {
  const [logs, setLogs] = useState([]);
  const [meta, setMeta] = useState({});
  const [filters, setFilters] = useState({
    trigger_event: '',
    date_from: '',
    date_to: '',
    errors_only: false,
    page: 1,
  });
  const [filterOptions, setFilterOptions] = useState({ trigger_events: [], action_taken: [] });
  const [loading, setLoading] = useState(false);

  const loadFilters = () => {
    api.get('/ai/action-logs/filters').then(({ data }) => setFilterOptions(data)).catch(() => {});
  };

  const loadLogs = () => {
    setLoading(true);
    const params = { page: filters.page, per_page: 25 };
    if (filters.trigger_event) params.trigger_event = filters.trigger_event;
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    if (filters.errors_only) params.errors_only = 'true';

    api.get('/ai/action-logs', { params })
      .then(({ data }) => {
        setLogs(data.data || []);
        setMeta({
          current: data.current_page,
          last: data.last_page,
          total: data.total,
        });
      })
      .catch(() => setLogs([]))
      .finally(() => setLoading(false));
  };

  useEffect(() => { loadFilters(); }, []);
  useEffect(() => { loadLogs(); }, [filters]);

  const setFilter = (key, value) => {
    setFilters((prev) => ({ ...prev, [key]: value, page: 1 }));
  };

  return (
    <div className="space-y-4 max-w-6xl">
      <div className="bg-white rounded-xl border border-slate-200 p-4">
        <div className="flex flex-col lg:flex-row gap-3 flex-wrap">
          <select value={filters.trigger_event} onChange={(e) => setFilter('trigger_event', e.target.value)}
            className="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
            <option value="">All trigger events</option>
            {(filterOptions.trigger_events || []).map((ev) => (
              <option key={ev} value={ev}>{ev}</option>
            ))}
          </select>
          <input type="date" value={filters.date_from} onChange={(e) => setFilter('date_from', e.target.value)}
            className="px-3 py-2 border border-slate-200 rounded-lg text-sm" />
          <input type="date" value={filters.date_to} onChange={(e) => setFilter('date_to', e.target.value)}
            className="px-3 py-2 border border-slate-200 rounded-lg text-sm" />
          <label className="flex items-center gap-2 text-sm text-slate-600 px-2">
            <input type="checkbox" checked={filters.errors_only}
              onChange={(e) => setFilter('errors_only', e.target.checked)}
              className="rounded border-slate-300" />
            Errors only
          </label>
        </div>
      </div>

      {loading ? (
        <p className="text-sm text-slate-500">Loading...</p>
      ) : logs.length === 0 ? (
        <p className="text-sm text-slate-500 bg-white rounded-xl border border-slate-200 p-6">No AI action logs match your filters.</p>
      ) : (
        <div className="space-y-3">
          {logs.map((log) => {
            const isPlaceholder = log.data_viewed?.is_placeholder === true;
            return (
              <div key={log.id} className="bg-white rounded-xl border border-slate-200 p-4 text-sm">
                <div className="flex flex-wrap items-center gap-2 mb-2">
                  <span className="text-xs text-slate-400">{formatDateTime(log.created_at)}</span>
                  <span className="font-medium text-slate-800">{log.trigger_event}</span>
                  <span className="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded">{log.action_taken}</span>
                  {log.required_human_approval && (
                    <span className="text-xs bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded">Draft / approval required</span>
                  )}
                  {isPlaceholder && (
                    <span className="text-xs bg-orange-100 text-orange-800 px-2 py-0.5 rounded font-medium">Placeholder copy</span>
                  )}
                  {log.error && (
                    <span className="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded">Error</span>
                  )}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-2 text-slate-600">
                  <p><span className="text-slate-400">Actor:</span> {log.actor?.name || '—'} ({log.actor?.role || '—'})</p>
                  <p><span className="text-slate-400">Decision:</span> {log.decision || '—'}</p>
                  <p><span className="text-slate-400">Rule:</span> {log.rule_applied || '—'}</p>
                  <p><span className="text-slate-400">Status:</span> {log.status_before || '—'} → {log.status_after || '—'}</p>
                  {log.recipient && (
                    <p className="md:col-span-2"><span className="text-slate-400">Recipient:</span> {log.recipient}</p>
                  )}
                </div>

                {log.message_sent && (
                  <div className="mt-2 p-2 bg-slate-50 rounded-lg text-xs text-slate-700 whitespace-pre-wrap">
                    {log.message_sent}
                  </div>
                )}

                {log.error && (
                  <p className="mt-2 text-xs text-red-600">{log.error}</p>
                )}
              </div>
            );
          })}
        </div>
      )}

      {meta.last > 1 && (
        <div className="flex items-center justify-between text-sm">
          <span className="text-slate-500">{meta.total} entries</span>
          <div className="flex gap-2">
            <button type="button" disabled={meta.current <= 1}
              onClick={() => setFilters((p) => ({ ...p, page: p.page - 1 }))}
              className="px-3 py-1 border rounded-lg disabled:opacity-40">Prev</button>
            <span>Page {meta.current} of {meta.last}</span>
            <button type="button" disabled={meta.current >= meta.last}
              onClick={() => setFilters((p) => ({ ...p, page: p.page + 1 }))}
              className="px-3 py-1 border rounded-lg disabled:opacity-40">Next</button>
          </div>
        </div>
      )}
    </div>
  );
}
