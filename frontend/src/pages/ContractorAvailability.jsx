import { useEffect, useState } from 'react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import ListStatePanel from '../components/ListStatePanel';
import { showError, showSuccess } from '../utils/swal';

const DAYS = [
  { key: 'mon', label: 'Monday' },
  { key: 'tue', label: 'Tuesday' },
  { key: 'wed', label: 'Wednesday' },
  { key: 'thu', label: 'Thursday' },
  { key: 'fri', label: 'Friday' },
  { key: 'sat', label: 'Saturday' },
  { key: 'sun', label: 'Sunday' },
];

export default function ContractorAvailability() {
  const [data, setData] = useState(null);
  const [loadState, setLoadState] = useState('loading');
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState(null);

  const load = () => {
    setLoadState('loading');
    setError(null);
    api.get('/me/contractor/availability')
      .then(({ data: d }) => {
        setData(d);
        setForm({
          working_hours: d.working_hours || {},
          blackout_ranges: d.blackout_ranges || [],
          min_notice_hours: d.min_notice_hours ?? 24,
          daily_capacity: d.daily_capacity ?? 3,
          availability_paused: !!d.availability_paused,
          availability_paused_until: d.availability_paused_until || '',
          availability_notes: d.availability_notes || '',
          services: (d.services || []).join(', '),
          cities: (d.cities || []).join(', '),
        });
        setLoadState('ready');
      })
      .catch((err) => {
        setLoadState(err.response?.status === 403 ? 'permission' : 'error');
        setError(err.response?.data?.message || 'Could not load availability.');
      });
  };

  useEffect(() => { load(); }, []);

  const save = async () => {
    setSaving(true);
    try {
      const payload = {
        ...form,
        services: form.services.split(',').map((s) => s.trim()).filter(Boolean),
        cities: form.cities.split(',').map((s) => s.trim()).filter(Boolean),
        availability_paused_until: form.availability_paused_until || null,
      };
      const { data: res } = await api.put('/me/contractor/availability', payload);
      setData(res.availability);
      await showSuccess(res.message || 'Saved.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  if (loadState === 'loading' || !form) {
    return <div className="text-center py-12 text-slate-500">Loading availability…</div>;
  }
  if (loadState === 'error' || loadState === 'permission') {
    return (
      <ListStatePanel
        state={loadState}
        title={loadState === 'permission' ? 'Permission required' : 'Unable to load availability'}
        body={error}
        actionLabel="Retry"
        onAction={load}
      />
    );
  }

  const setDay = (key, patch) => {
    setForm((f) => ({
      ...f,
      working_hours: {
        ...f.working_hours,
        [key]: { ...(f.working_hours[key] || {}), ...patch },
      },
    }));
  };

  return (
    <div className="space-y-6 max-w-3xl">
      <PageHeader title="My Availability" subtitle="Controls new offers only — accepted visits and jobs stay scheduled." />
      {data?.note && <p className="text-sm text-slate-500">{data.note}</p>}

      <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
        <h3 className="font-semibold text-slate-800">Working hours</h3>
        {DAYS.map(({ key, label }) => {
          const day = form.working_hours[key] || { start: '08:00', end: '17:00', closed: false };
          return (
            <div key={key} className="flex flex-wrap items-center gap-2 text-sm">
              <span className="w-24 text-slate-600">{label}</span>
              <label className="flex items-center gap-1 text-xs">
                <input type="checkbox" checked={!!day.closed} onChange={(e) => setDay(key, { closed: e.target.checked })} />
                Closed
              </label>
              {!day.closed && (
                <>
                  <input type="time" value={day.start || ''} onChange={(e) => setDay(key, { start: e.target.value })}
                    className="border border-slate-200 rounded px-2 py-1" />
                  <span>to</span>
                  <input type="time" value={day.end || ''} onChange={(e) => setDay(key, { end: e.target.value })}
                    className="border border-slate-200 rounded px-2 py-1" />
                </>
              )}
            </div>
          );
        })}
      </div>

      <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
        <h3 className="font-semibold text-slate-800">Blackout / vacation</h3>
        {(form.blackout_ranges || []).map((r, i) => (
          <div key={i} className="flex flex-wrap gap-2 items-center text-sm">
            <input type="date" value={r.start || ''} onChange={(e) => {
              const next = [...form.blackout_ranges];
              next[i] = { ...next[i], start: e.target.value };
              setForm({ ...form, blackout_ranges: next });
            }} className="border rounded px-2 py-1" />
            <span>to</span>
            <input type="date" value={r.end || ''} onChange={(e) => {
              const next = [...form.blackout_ranges];
              next[i] = { ...next[i], end: e.target.value };
              setForm({ ...form, blackout_ranges: next });
            }} className="border rounded px-2 py-1" />
            <button type="button" className="text-red-600 text-xs" onClick={() => {
              setForm({ ...form, blackout_ranges: form.blackout_ranges.filter((_, j) => j !== i) });
            }}>Remove</button>
          </div>
        ))}
        <button type="button" className="text-sm text-blue-600" onClick={() => {
          setForm({ ...form, blackout_ranges: [...(form.blackout_ranges || []), { start: '', end: '' }] });
        }}>+ Add range</button>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-3 text-sm">
        <h3 className="font-semibold text-slate-800">Preferences</h3>
        <label className="block">Services offered (comma-separated)
          <input className="mt-1 w-full border rounded-lg px-3 py-2" value={form.services}
            onChange={(e) => setForm({ ...form, services: e.target.value })} />
        </label>
        <label className="block">Service area / cities (comma-separated)
          <input className="mt-1 w-full border rounded-lg px-3 py-2" value={form.cities}
            onChange={(e) => setForm({ ...form, cities: e.target.value })} />
        </label>
        <div className="flex flex-wrap gap-4">
          <label>Min notice (hours)
            <input type="number" min={0} className="ml-2 border rounded px-2 py-1 w-20"
              value={form.min_notice_hours} onChange={(e) => setForm({ ...form, min_notice_hours: Number(e.target.value) })} />
          </label>
          <label>Daily capacity
            <input type="number" min={1} className="ml-2 border rounded px-2 py-1 w-20"
              value={form.daily_capacity} onChange={(e) => setForm({ ...form, daily_capacity: Number(e.target.value) })} />
          </label>
        </div>
        <label className="flex items-center gap-2">
          <input type="checkbox" checked={form.availability_paused}
            onChange={(e) => setForm({ ...form, availability_paused: e.target.checked })} />
          Temporarily pause new offers
        </label>
        {form.availability_paused && (
          <label>Paused until
            <input type="date" className="ml-2 border rounded px-2 py-1"
              value={form.availability_paused_until || ''}
              onChange={(e) => setForm({ ...form, availability_paused_until: e.target.value })} />
          </label>
        )}
        <label className="block">Notes
          <textarea className="mt-1 w-full border rounded-lg px-3 py-2" rows={2} value={form.availability_notes}
            onChange={(e) => setForm({ ...form, availability_notes: e.target.value })} />
        </label>
      </div>

      <button type="button" disabled={saving} onClick={save}
        className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">
        {saving ? 'Saving…' : 'Save availability'}
      </button>
    </div>
  );
}
