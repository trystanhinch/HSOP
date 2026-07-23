import { useEffect, useState } from 'react';
import { Plus } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import { showError, showSuccess } from '../utils/swal';

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
};

const dowLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function Availability() {
  const [windows, setWindows] = useState([]);
  const [brands, setBrands] = useState([]);
  const [bookings, setBookings] = useState([]);
  const [holds, setHolds] = useState([]);
  const [brandFilter, setBrandFilter] = useState('');
  const [panelOpen, setPanelOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [tab, setTab] = useState('windows');

  const loadWindows = () => {
    const q = brandFilter ? `?brand_id=${brandFilter}` : '';
    api.get(`/availability/windows${q}`).then(({ data }) => setWindows(data || [])).catch(() => setWindows([]));
  };

  const loadBookings = () => {
    const q = brandFilter ? `?brand_id=${brandFilter}` : '';
    api.get(`/availability/bookings${q}`).then(({ data }) => {
      setBookings(data.bookings || []);
      setHolds(data.holds || []);
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
    setForm({
      ...emptyForm,
      brand_id: brandFilter || (brands[0]?.id ? String(brands[0].id) : ''),
    });
    setPanelOpen(true);
  };

  const save = async () => {
    setSaving(true);
    try {
      const payload = {
        brand_id: Number(form.brand_id),
        day_of_week: form.specific_date === '' ? Number(form.day_of_week) : null,
        specific_date: form.specific_date || null,
        start_time: form.start_time,
        end_time: form.end_time,
        slot_duration_minutes: Number(form.slot_duration_minutes) || 60,
        service_category: form.service_category || null,
        timezone: form.timezone || 'America/Vancouver',
        status: form.status,
      };
      await api.post('/availability/windows', payload);
      await showSuccess('Availability window saved.');
      setPanelOpen(false);
      loadWindows();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save window.');
    } finally {
      setSaving(false);
    }
  };

  const deactivate = async (id) => {
    try {
      await api.delete(`/availability/windows/${id}`);
      loadWindows();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to deactivate.');
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
        Per-brand booking windows, soft holds during intake, and confirmed appointments.
      </p>

      <div className="mb-4 flex flex-wrap gap-3 items-center">
        <select
          value={brandFilter}
          onChange={(e) => setBrandFilter(e.target.value)}
          className="border border-slate-300 rounded-lg px-3 py-2 text-sm"
        >
          <option value="">All brands</option>
          {brands.map((b) => (
            <option key={b.id} value={b.id}>{b.company_name} ({b.domain})</option>
          ))}
        </select>
        <div className="flex gap-2">
          {['windows', 'bookings', 'holds'].map((t) => (
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
                <th className="px-3 py-2">Hours</th>
                <th className="px-3 py-2">Slot</th>
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
                  <td className="px-3 py-2">{String(w.start_time).slice(0, 5)}–{String(w.end_time).slice(0, 5)}</td>
                  <td className="px-3 py-2">{w.slot_duration_minutes}m</td>
                  <td className="px-3 py-2"><StatusBadge status={w.status} /></td>
                  <td className="px-3 py-2 text-right">
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

      {tab === 'bookings' && (
        <div className="bg-white border border-slate-200 rounded-xl overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-slate-600">
              <tr>
                <th className="px-3 py-2">Brand</th>
                <th className="px-3 py-2">Lead</th>
                <th className="px-3 py-2">Slot (UTC)</th>
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

      <SlideOverPanel isOpen={panelOpen} onClose={() => setPanelOpen(false)} title="New availability window">
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
          <label className="block text-sm">
            Slot minutes
            <input type="number" className="mt-1 w-full border rounded-lg px-3 py-2" value={form.slot_duration_minutes} onChange={(e) => setForm({ ...form, slot_duration_minutes: e.target.value })} />
          </label>
          <label className="block text-sm">
            Service (optional)
            <input className="mt-1 w-full border rounded-lg px-3 py-2" value={form.service_category} onChange={(e) => setForm({ ...form, service_category: e.target.value })} placeholder="e.g. drywall_paint" />
          </label>
          <button type="button" disabled={saving} onClick={save} className="w-full bg-slate-800 text-white rounded-lg py-2.5 text-sm disabled:opacity-50">
            {saving ? 'Saving…' : 'Save window'}
          </button>
        </div>
      </SlideOverPanel>
    </div>
  );
}
