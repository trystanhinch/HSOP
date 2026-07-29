import { useEffect, useState } from 'react';
import { Plus } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const emptyForm = {
  brand_id: '',
  day_of_week: '1',
  specific_date: '',
  start_time: '09:00',
  end_time: '12:00',
  slot_duration_minutes: '60',
  service_category: '',
  timezone: 'America/Vancouver',
  status: 'active',
  travel_buffer_minutes: '0',
  capacity: '1',
  effective_from: '',
  effective_to: '',
  blackout_dates: '',
  temporary_override: false,
  notes: '',
};

const dowLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function Availability() {
  const { user } = useAuth();
  const isPm = user?.role === 'pm';
  const [windows, setWindows] = useState([]);
  const [brands, setBrands] = useState([]);
  const [bookings, setBookings] = useState([]);
  const [holds, setHolds] = useState([]);
  const [timezone, setTimezone] = useState('America/Vancouver');
  const [brandFilter, setBrandFilter] = useState('');
  const [panelOpen, setPanelOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [tab, setTab] = useState('windows');
  const [deactivateInfo, setDeactivateInfo] = useState(null);

  const loadWindows = () => {
    const q = brandFilter ? `?brand_id=${brandFilter}` : '';
    api.get(`/availability/windows${q}`).then(({ data }) => setWindows(data || [])).catch(() => setWindows([]));
  };

  const loadBookings = () => {
    const q = brandFilter ? `?brand_id=${brandFilter}` : '';
    api.get(`/availability/bookings${q}`).then(({ data }) => {
      setBookings(data.bookings || []);
      setHolds(data.holds || []);
      if (data.timezone) setTimezone(data.timezone);
    }).catch(() => {
      setBookings([]);
      setHolds([]);
    });
  };

  useEffect(() => {
    api.get('/availability/brands').then(({ data }) => setBrands(data || [])).catch(() => setBrands([]));
  }, []);

  useEffect(() => {
    loadWindows();
    loadBookings();
  }, [brandFilter]);

  const openCreate = () => {
    setEditingId(null);
    setForm({
      ...emptyForm,
      brand_id: brandFilter || (brands[0]?.id ? String(brands[0].id) : ''),
    });
    setPanelOpen(true);
  };

  const openEdit = (w) => {
    setEditingId(w.id);
    setForm({
      brand_id: String(w.brand_id),
      day_of_week: w.day_of_week != null ? String(w.day_of_week) : '1',
      specific_date: w.specific_date ? String(w.specific_date).slice(0, 10) : '',
      start_time: String(w.start_time).slice(0, 5),
      end_time: String(w.end_time).slice(0, 5),
      slot_duration_minutes: String(w.slot_duration_minutes || 60),
      service_category: w.service_category || '',
      timezone: w.timezone || 'America/Vancouver',
      status: w.status || 'active',
      travel_buffer_minutes: String(w.travel_buffer_minutes ?? 0),
      capacity: String(w.capacity ?? 1),
      effective_from: w.effective_from ? String(w.effective_from).slice(0, 10) : '',
      effective_to: w.effective_to ? String(w.effective_to).slice(0, 10) : '',
      blackout_dates: Array.isArray(w.blackout_dates) ? w.blackout_dates.join(', ') : '',
      temporary_override: !!w.temporary_override,
      notes: w.notes || '',
    });
    setPanelOpen(true);
  };

  const payloadFromForm = () => ({
    brand_id: Number(form.brand_id),
    day_of_week: form.specific_date === '' ? Number(form.day_of_week) : null,
    specific_date: form.specific_date || null,
    start_time: form.start_time,
    end_time: form.end_time,
    slot_duration_minutes: Number(form.slot_duration_minutes) || 60,
    service_category: form.service_category || null,
    timezone: form.timezone || 'America/Vancouver',
    status: form.status,
    travel_buffer_minutes: Number(form.travel_buffer_minutes) || 0,
    capacity: Number(form.capacity) || 1,
    effective_from: form.effective_from || null,
    effective_to: form.effective_to || null,
    blackout_dates: form.blackout_dates
      ? form.blackout_dates.split(/[\s,]+/).map((s) => s.trim()).filter(Boolean)
      : [],
    temporary_override: !!form.temporary_override,
    notes: form.notes || null,
  });

  const save = async () => {
    setSaving(true);
    try {
      const payload = payloadFromForm();
      if (editingId) {
        await api.put(`/availability/windows/${editingId}`, payload);
        await showSuccess('Availability window updated.');
      } else {
        await api.post('/availability/windows', payload);
        await showSuccess('Availability window saved.');
      }
      setPanelOpen(false);
      loadWindows();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save window.');
    } finally {
      setSaving(false);
    }
  };

  const duplicate = async (id) => {
    try {
      await api.post(`/availability/windows/${id}/duplicate`);
      await showSuccess('Window duplicated.');
      loadWindows();
    } catch (err) {
      await showError(err.response?.data?.message || 'Duplicate failed.');
    }
  };

  const deactivate = async (id) => {
    try {
      const { data: preview } = await api.get(`/availability/windows/${id}/deactivation-preview`);
      if (preview.blocked) {
        setDeactivateInfo({ id, ...preview });
        return;
      }
      const ok = await confirmAction({
        title: 'Deactivate this window?',
        text: 'No active bookings or holds are tied to it.',
        confirmText: 'Deactivate',
      });
      if (!ok) return;
      await api.delete(`/availability/windows/${id}`);
      loadWindows();
    } catch (err) {
      const data = err.response?.data;
      if (err.response?.status === 422 && data?.resolution_options) {
        setDeactivateInfo({ id, ...data, blocked: true });
        return;
      }
      await showError(data?.message || 'Failed to deactivate.');
    }
  };

  const resolveDeactivate = async (resolution) => {
    if (!deactivateInfo?.id) return;
    if (resolution === 'reschedule') {
      setDeactivateInfo(null);
      setTab('bookings');
      await showSuccess('Reschedule the listed bookings/holds, then try deactivate again.');
      return;
    }
    try {
      await api.post(`/availability/windows/${deactivateInfo.id}/resolve-deactivate`, {
        resolution: 'cancel_then_deactivate',
        confirm: true,
      });
      setDeactivateInfo(null);
      await showSuccess('Bookings/holds cancelled and window deactivated.');
      loadWindows();
      loadBookings();
    } catch (err) {
      await showError(err.response?.data?.message || 'Resolution failed.');
    }
  };

  return (
    <div>
      <PageHeader title="Availability & Bookings">
        <button type="button" onClick={openCreate} className="inline-flex items-center gap-2 bg-slate-800 text-white px-3 py-2 rounded-lg text-sm">
          <Plus size={16} /> Add window
        </button>
      </PageHeader>
      <p className="text-sm text-slate-500 mb-4">
        Brand-scoped booking windows (timezone: {timezone}). Edit, duplicate, blackout, buffer, and capacity controls included.
      </p>

      <div className="mb-4 flex flex-wrap gap-3 items-center">
        <select
          value={brandFilter}
          onChange={(e) => setBrandFilter(e.target.value)}
          className="border border-slate-300 rounded-lg px-3 py-2 text-sm"
        >
          <option value="">{isPm ? 'My brands' : 'All brands'}</option>
          {brands.map((b) => (
            <option key={b.id} value={b.id}>{b.company_name} ({b.domain})</option>
          ))}
        </select>
        <div className="flex gap-2">
          {['windows', 'calendar', 'bookings', 'holds'].map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setTab(t)}
              className={`px-3 py-1.5 rounded-lg text-sm ${tab === t ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700'}`}
            >
              {t}
            </button>
          ))}
        </div>
      </div>

      {tab === 'windows' && (
        <div className="bg-white border border-slate-200 rounded-xl overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-slate-600">
              <tr>
                <th className="px-3 py-2">Brand</th>
                <th className="px-3 py-2">When</th>
                <th className="px-3 py-2">Hours ({timezone})</th>
                <th className="px-3 py-2">Capacity</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {windows.map((w) => (
                <tr key={w.id} className="border-t border-slate-100">
                  <td className="px-3 py-2">{w.brand?.company_name || w.brand_id}</td>
                  <td className="px-3 py-2">
                    {w.specific_date || (w.day_of_week != null ? dowLabels[w.day_of_week] : '—')}
                    {w.service_category ? ` · ${w.service_category}` : ''}
                  </td>
                  <td className="px-3 py-2">
                    {String(w.start_time).slice(0, 5)}–{String(w.end_time).slice(0, 5)}
                    <span className="text-xs text-slate-400 block">{w.timezone || timezone}</span>
                  </td>
                  <td className="px-3 py-2">{w.capacity ?? 1} · buf {w.travel_buffer_minutes ?? 0}m</td>
                  <td className="px-3 py-2"><StatusBadge status={w.status} /></td>
                  <td className="px-3 py-2 text-right space-x-2">
                    <button type="button" className="text-xs text-blue-600" onClick={() => openEdit(w)}>Edit</button>
                    <button type="button" className="text-xs text-slate-600" onClick={() => duplicate(w.id)}>Duplicate</button>
                    {w.status === 'active' && (
                      <button type="button" className="text-xs text-red-600" onClick={() => deactivate(w.id)}>Deactivate</button>
                    )}
                  </td>
                </tr>
              ))}
              {windows.length === 0 && (
                <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">No windows yet.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'calendar' && (
        <div className="bg-white border border-slate-200 rounded-xl p-4 space-y-2">
          <p className="text-xs text-slate-500 mb-2">List view by day-of-week / date · timezone {timezone}</p>
          {windows.filter((w) => w.status === 'active').map((w) => (
            <div key={w.id} className="flex flex-wrap justify-between gap-2 border border-slate-100 rounded-lg px-3 py-2 text-sm">
              <div>
                <p className="font-medium text-slate-800">{w.brand?.company_name}</p>
                <p className="text-slate-600">
                  {w.specific_date || (w.day_of_week != null ? dowLabels[w.day_of_week] : '—')}
                  {' '}{String(w.start_time).slice(0, 5)}–{String(w.end_time).slice(0, 5)} ({w.timezone || timezone})
                </p>
              </div>
              <button type="button" className="text-xs text-blue-600" onClick={() => openEdit(w)}>Edit</button>
            </div>
          ))}
          {windows.filter((w) => w.status === 'active').length === 0 && (
            <p className="text-sm text-slate-500 text-center py-6">No active windows.</p>
          )}
        </div>
      )}

      {tab === 'bookings' && (
        <div className="bg-white border border-slate-200 rounded-xl overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-slate-600">
              <tr>
                <th className="px-3 py-2">Brand</th>
                <th className="px-3 py-2">Lead</th>
                <th className="px-3 py-2">Slot ({timezone})</th>
                <th className="px-3 py-2">Status</th>
              </tr>
            </thead>
            <tbody>
              {bookings.map((b) => (
                <tr key={b.id} className="border-t border-slate-100">
                  <td className="px-3 py-2">{b.brand?.company_name}</td>
                  <td className="px-3 py-2">#{b.lead_id} {b.lead?.contact_name}</td>
                  <td className="px-3 py-2">{b.slot_start} → {b.slot_end}</td>
                  <td className="px-3 py-2"><StatusBadge status={b.status} /></td>
                </tr>
              ))}
              {bookings.length === 0 && (
                <tr><td colSpan={4} className="px-3 py-6 text-center text-slate-500">No confirmed bookings.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'holds' && (
        <div className="bg-white border border-slate-200 rounded-xl overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-slate-600">
              <tr>
                <th className="px-3 py-2">Brand</th>
                <th className="px-3 py-2">Slot</th>
                <th className="px-3 py-2">Held until</th>
                <th className="px-3 py-2">Status</th>
              </tr>
            </thead>
            <tbody>
              {holds.map((h) => (
                <tr key={h.id} className="border-t border-slate-100">
                  <td className="px-3 py-2">{h.brand?.company_name}</td>
                  <td className="px-3 py-2">{h.slot_start}</td>
                  <td className="px-3 py-2">{h.held_until}</td>
                  <td className="px-3 py-2"><StatusBadge status={h.status} /></td>
                </tr>
              ))}
              {holds.length === 0 && (
                <tr><td colSpan={4} className="px-3 py-6 text-center text-slate-500">No holds.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {deactivateInfo && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 space-y-4">
            <h3 className="text-lg font-semibold text-slate-800">Cannot deactivate yet</h3>
            <p className="text-sm text-slate-600">{deactivateInfo.message}</p>
            <div className="max-h-40 overflow-y-auto text-sm space-y-1">
              {(Array.isArray(deactivateInfo.booking_details) ? deactivateInfo.booking_details : []).map((b) => (
                <p key={`b-${b.id}`}>Booking #{b.id} · lead {b.contact_name || b.lead_id} · {b.slot_start}</p>
              ))}
              {(Array.isArray(deactivateInfo.hold_details) ? deactivateInfo.hold_details : []).map((h) => (
                <p key={`h-${h.id}`}>Hold #{h.id} · {h.slot_start}</p>
              ))}
              {!deactivateInfo.booking_details?.length && !deactivateInfo.hold_details?.length && (
                <p>{deactivateInfo.active_bookings || 0} booking(s), {deactivateInfo.active_holds || 0} hold(s)</p>
              )}
            </div>
            <div className="flex flex-col gap-2">
              <button type="button" className="px-4 py-2 bg-slate-800 text-white text-sm rounded-lg" onClick={() => resolveDeactivate('reschedule')}>
                Reschedule first
              </button>
              <button type="button" className="px-4 py-2 bg-red-600 text-white text-sm rounded-lg" onClick={() => resolveDeactivate('cancel_then_deactivate')}>
                Cancel bookings/holds, then deactivate
              </button>
              <button type="button" className="px-4 py-2 text-sm text-slate-600" onClick={() => setDeactivateInfo(null)}>Close</button>
            </div>
          </div>
        </div>
      )}

      <SlideOverPanel isOpen={panelOpen} onClose={() => setPanelOpen(false)} title={editingId ? 'Edit availability window' : 'New availability window'}>
        <div className="space-y-3 p-1">
          <label className="block text-sm">
            Brand
            <select className="mt-1 w-full border rounded-lg px-3 py-2" value={form.brand_id} onChange={(e) => setForm({ ...form, brand_id: e.target.value })}>
              {brands.map((b) => <option key={b.id} value={b.id}>{b.company_name}</option>)}
            </select>
          </label>
          <label className="block text-sm">
            Day of week
            <select className="mt-1 w-full border rounded-lg px-3 py-2" value={form.day_of_week} onChange={(e) => setForm({ ...form, day_of_week: e.target.value, specific_date: '' })}>
              {dowLabels.map((d, i) => <option key={d} value={i}>{d}</option>)}
            </select>
          </label>
          <label className="block text-sm">
            Or specific date
            <input type="date" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.specific_date} onChange={(e) => setForm({ ...form, specific_date: e.target.value })} />
          </label>
          <div className="grid grid-cols-2 gap-2">
            <label className="block text-sm">Start<input type="time" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} /></label>
            <label className="block text-sm">End<input type="time" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} /></label>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <label className="block text-sm">Slot minutes<input type="number" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.slot_duration_minutes} onChange={(e) => setForm({ ...form, slot_duration_minutes: e.target.value })} /></label>
            <label className="block text-sm">Capacity<input type="number" min="1" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} /></label>
          </div>
          <label className="block text-sm">Travel buffer (minutes)
            <input type="number" min="0" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.travel_buffer_minutes} onChange={(e) => setForm({ ...form, travel_buffer_minutes: e.target.value })} />
          </label>
          <div className="grid grid-cols-2 gap-2">
            <label className="block text-sm">Effective from<input type="date" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} /></label>
            <label className="block text-sm">Effective to<input type="date" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.effective_to} onChange={(e) => setForm({ ...form, effective_to: e.target.value })} /></label>
          </div>
          <label className="block text-sm">Blackout dates (comma-separated YYYY-MM-DD)
            <input className="mt-1 w-full border rounded-lg px-3 py-2" value={form.blackout_dates} onChange={(e) => setForm({ ...form, blackout_dates: e.target.value })} />
          </label>
          <label className="block text-sm">Service (optional)
            <input className="mt-1 w-full border rounded-lg px-3 py-2" value={form.service_category} onChange={(e) => setForm({ ...form, service_category: e.target.value })} placeholder="e.g. drywall_paint" />
          </label>
          <label className="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.temporary_override} onChange={(e) => setForm({ ...form, temporary_override: e.target.checked })} />
            Temporary override
          </label>
          <button type="button" disabled={saving} onClick={save} className="w-full bg-slate-800 text-white rounded-lg py-2.5 text-sm disabled:opacity-50">
            {saving ? 'Saving…' : (editingId ? 'Update window' : 'Save window')}
          </button>
        </div>
      </SlideOverPanel>
    </div>
  );
}
