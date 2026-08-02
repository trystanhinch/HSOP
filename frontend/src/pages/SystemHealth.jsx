import { useCallback, useEffect, useState } from 'react';
import { Activity, AlertTriangle, Ban, RefreshCw, CheckCircle2 } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { formatDateTime } from '../utils/formatDate';
import { confirmDanger, showError, showSuccess } from '../utils/swal';

/**
 * Milestone 6A.4 — Owner System Health (failed jobs, signal summary, alerts).
 */
export default function SystemHealth() {
  const [summary, setSummary] = useState(null);
  const [failedJobs, setFailedJobs] = useState([]);
  const [jobMeta, setJobMeta] = useState({});
  const [alerts, setAlerts] = useState([]);
  const [alertMeta, setAlertMeta] = useState({});
  const [alertFilter, setAlertFilter] = useState('open');
  const [loading, setLoading] = useState(true);
  const [jobsLoading, setJobsLoading] = useState(false);
  const [alertsLoading, setAlertsLoading] = useState(false);
  const [jobPage, setJobPage] = useState(1);
  const [alertPage, setAlertPage] = useState(1);

  const loadSummary = useCallback(() => {
    return api.get('/admin/monitoring/summary')
      .then(({ data }) => setSummary(data))
      .catch(async (e) => {
        await showError(e.response?.data?.message || 'Failed to load monitoring summary');
      });
  }, []);

  const loadFailedJobs = useCallback(() => {
    setJobsLoading(true);
    return api.get('/admin/monitoring/failed-jobs', { params: { page: jobPage, per_page: 20 } })
      .then(({ data }) => {
        setFailedJobs(data.data || []);
        setJobMeta({ current: data.current_page, last: data.last_page, total: data.total });
      })
      .catch(() => setFailedJobs([]))
      .finally(() => setJobsLoading(false));
  }, [jobPage]);

  const loadAlerts = useCallback(() => {
    setAlertsLoading(true);
    const params = { page: alertPage, per_page: 20 };
    if (alertFilter === 'open') params.acknowledged = false;
    if (alertFilter === 'acked') params.acknowledged = true;
    return api.get('/admin/monitoring/alerts', { params })
      .then(({ data }) => {
        setAlerts(data.data || []);
        setAlertMeta({ current: data.current_page, last: data.last_page, total: data.total });
      })
      .catch(() => setAlerts([]))
      .finally(() => setAlertsLoading(false));
  }, [alertPage, alertFilter]);

  useEffect(() => {
    setLoading(true);
    Promise.all([loadSummary(), loadFailedJobs(), loadAlerts()]).finally(() => setLoading(false));
  }, [loadSummary, loadFailedJobs, loadAlerts]);

  const retryJob = async (job) => {
    const ok = await confirmDanger({
      title: 'Retry failed job?',
      text: `${job.job_name || 'Job'} #${job.id} will be pushed back onto the queue.`,
      confirmText: 'Retry',
    });
    if (!ok) return;
    try {
      await api.post(`/admin/monitoring/failed-jobs/${job.id}/retry`);
      await showSuccess('Retry queued');
      await Promise.all([loadFailedJobs(), loadSummary()]);
    } catch (e) {
      await showError(e.response?.data?.message || 'Retry failed');
    }
  };

  const dismissJob = async (job) => {
    const ok = await confirmDanger({
      title: 'Dismiss failed job?',
      text: 'Removes it from failed_jobs. This cannot be undone.',
      confirmText: 'Dismiss',
    });
    if (!ok) return;
    try {
      await api.delete(`/admin/monitoring/failed-jobs/${job.id}`);
      await showSuccess('Dismissed');
      await Promise.all([loadFailedJobs(), loadSummary()]);
    } catch (e) {
      await showError(e.response?.data?.message || 'Dismiss failed');
    }
  };

  const acknowledge = async (alert) => {
    try {
      await api.patch(`/admin/monitoring/alerts/${alert.id}/acknowledge`);
      await showSuccess('Alert acknowledged');
      await Promise.all([loadAlerts(), loadSummary()]);
    } catch (e) {
      await showError(e.response?.data?.message || 'Acknowledge failed');
    }
  };

  if (loading && !summary) {
    return <p className="text-sm text-slate-500">Loading System Health…</p>;
  }

  const cards = [
    { label: 'Failed jobs', value: summary?.failed_jobs ?? 0, icon: Ban },
    { label: 'SMS failures (window)', value: summary?.sms_delivery_failures ?? 0, icon: AlertTriangle },
    { label: 'Email failures (window)', value: summary?.email_delivery_failures ?? 0, icon: AlertTriangle },
    { label: 'AI errors (window)', value: summary?.ai_action_errors ?? 0, icon: Activity },
    { label: 'Stripe webhook fails', value: summary?.stripe_webhook_failures ?? 0, icon: AlertTriangle },
    { label: 'Overdue next actions', value: summary?.overdue_next_actions ?? 0, icon: AlertTriangle },
    { label: 'Escalations fired', value: summary?.workflow_escalations_fired ?? 0, icon: Activity },
    { label: 'Open alerts', value: summary?.alerts_unacknowledged ?? 0, icon: AlertTriangle },
  ];

  return (
    <div className="space-y-6 max-w-6xl">
      <PageHeader title="System Health">
        <span className="text-sm text-slate-500">
          Owner monitoring — {summary?.window_hours ?? 24}h window · correlation IDs on API requests
        </span>
      </PageHeader>

      <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
        Gmail last fetch:{' '}
        <span className="font-medium">
          {summary?.gmail_last_fetched_at
            ? formatDateTime(summary.gmail_last_fetched_at) || summary.gmail_last_fetched_at
            : 'never / unknown'}
        </span>
        <span className="block text-xs text-slate-500 mt-1">{summary?.gmail_last_run_note}</span>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {cards.map((c) => (
          <div key={c.label} className="bg-white rounded-xl border border-slate-200 p-4">
            <div className="flex items-center gap-2 text-slate-500 text-xs uppercase tracking-wide mb-2">
              <c.icon className="w-4 h-4" /> {c.label}
            </div>
            <p className="text-2xl font-semibold text-slate-900">{c.value}</p>
          </div>
        ))}
      </div>

      {/* Failed jobs */}
      <div className="space-y-3">
        <div className="flex items-center justify-between gap-2 flex-wrap">
          <h3 className="font-semibold text-slate-800">Failed jobs</h3>
          <button
            type="button"
            onClick={() => loadFailedJobs()}
            className="text-xs text-slate-600 flex items-center gap-1 px-2 py-1 border border-slate-200 rounded-lg hover:bg-slate-50"
          >
            <RefreshCw className="w-3.5 h-3.5" /> Refresh
          </button>
        </div>
        {jobsLoading ? (
          <p className="text-sm text-slate-500">Loading…</p>
        ) : failedJobs.length === 0 ? (
          <p className="text-sm text-slate-500 bg-white rounded-xl border border-slate-200 p-6">No failed jobs.</p>
        ) : (
          <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-500 border-b border-slate-100 bg-slate-50">
                  <th className="px-3 py-2 font-medium">Job</th>
                  <th className="px-3 py-2 font-medium">Queue</th>
                  <th className="px-3 py-2 font-medium">Failed</th>
                  <th className="px-3 py-2 font-medium">Exception</th>
                  <th className="px-3 py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {failedJobs.map((job) => (
                  <tr key={job.id} className="border-b border-slate-50 align-top">
                    <td className="px-3 py-2 text-slate-800">
                      #{job.id}
                      <span className="block text-xs text-slate-500 font-mono">{job.job_name}</span>
                    </td>
                    <td className="px-3 py-2 text-slate-600">{job.queue}</td>
                    <td className="px-3 py-2 text-slate-600 whitespace-nowrap">{formatDateTime(job.failed_at)}</td>
                    <td className="px-3 py-2 text-xs text-slate-600 max-w-md">{job.exception_summary}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">
                      <button type="button" onClick={() => retryJob(job)} className="text-sm text-sky-700 font-medium mr-3">
                        Retry
                      </button>
                      <button type="button" onClick={() => dismissJob(job)} className="text-sm text-red-700 font-medium">
                        Dismiss
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="flex items-center justify-between px-3 py-2 border-t border-slate-100 text-xs text-slate-500">
              <span>Page {jobMeta.current || 1} of {jobMeta.last || 1} · {jobMeta.total || 0} total</span>
              <div className="flex gap-2">
                <button type="button" disabled={!jobMeta.current || jobMeta.current <= 1}
                  onClick={() => setJobPage((p) => Math.max(1, p - 1))}
                  className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40">Prev</button>
                <button type="button" disabled={!jobMeta.last || (jobMeta.current || 1) >= jobMeta.last}
                  onClick={() => setJobPage((p) => p + 1)}
                  className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40">Next</button>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Alerts */}
      <div className="space-y-3">
        <div className="flex items-center justify-between gap-2 flex-wrap">
          <h3 className="font-semibold text-slate-800">Alerts</h3>
          <div className="flex gap-2">
            {[
              { id: 'open', label: 'Open' },
              { id: 'acked', label: 'Acknowledged' },
              { id: 'all', label: 'All' },
            ].map((f) => (
              <button
                key={f.id}
                type="button"
                onClick={() => { setAlertFilter(f.id); setAlertPage(1); }}
                className={`text-xs px-2.5 py-1 rounded-lg border ${
                  alertFilter === f.id ? 'bg-slate-800 text-white border-slate-800' : 'border-slate-200 text-slate-600'
                }`}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>
        {alertsLoading ? (
          <p className="text-sm text-slate-500">Loading…</p>
        ) : alerts.length === 0 ? (
          <p className="text-sm text-slate-500 bg-white rounded-xl border border-slate-200 p-6">No alerts match.</p>
        ) : (
          <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-500 border-b border-slate-100 bg-slate-50">
                  <th className="px-3 py-2 font-medium">When</th>
                  <th className="px-3 py-2 font-medium">Severity</th>
                  <th className="px-3 py-2 font-medium">Message</th>
                  <th className="px-3 py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {alerts.map((a) => (
                  <tr key={a.id} className="border-b border-slate-50 align-top">
                    <td className="px-3 py-2 text-slate-600 whitespace-nowrap">{formatDateTime(a.created_at)}</td>
                    <td className="px-3 py-2">
                      <span className={`text-xs px-2 py-0.5 rounded ${
                        a.severity === 'high' ? 'bg-red-100 text-red-800'
                          : a.severity === 'medium' ? 'bg-amber-100 text-amber-900'
                            : 'bg-slate-100 text-slate-800'
                      }`}>{a.severity}</span>
                    </td>
                    <td className="px-3 py-2 text-slate-800 max-w-lg">{a.message}</td>
                    <td className="px-3 py-2 text-right">
                      {a.acknowledged_at ? (
                        <span className="text-xs text-slate-500 inline-flex items-center gap-1">
                          <CheckCircle2 className="w-3.5 h-3.5" /> Acked
                        </span>
                      ) : (
                        <button type="button" onClick={() => acknowledge(a)} className="text-sm text-sky-700 font-medium">
                          Acknowledge
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="flex items-center justify-between px-3 py-2 border-t border-slate-100 text-xs text-slate-500">
              <span>Page {alertMeta.current || 1} of {alertMeta.last || 1} · {alertMeta.total || 0} total</span>
              <div className="flex gap-2">
                <button type="button" disabled={!alertMeta.current || alertMeta.current <= 1}
                  onClick={() => setAlertPage((p) => Math.max(1, p - 1))}
                  className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40">Prev</button>
                <button type="button" disabled={!alertMeta.last || (alertMeta.current || 1) >= alertMeta.last}
                  onClick={() => setAlertPage((p) => p + 1)}
                  className="px-2 py-1 rounded border border-slate-200 disabled:opacity-40">Next</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
