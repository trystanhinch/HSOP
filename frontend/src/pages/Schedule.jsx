import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { ChevronLeft, ChevronRight, Plus, Trash2 } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import FieldQuickActions from '../components/FieldQuickActions';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmDanger, showError, showSuccess } from '../utils/swal';
import { formatTime } from '../utils/formatDate';

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function eventClass(item) {
  if (item.type === 'pm_meeting' || item.color === 'purple') {
    return 'bg-purple-100 text-purple-700 border-purple-200';
  }
  if (item.color === 'indigo' || item.type === 'site_visit') {
    return 'bg-indigo-100 text-indigo-700 border-indigo-200';
  }
  if (item.type === 'booking_hold' || item.color === 'orange') {
    return 'bg-orange-100 text-orange-800 border-orange-200';
  }
  if (item.type === 'booking' || item.color === 'teal') {
    return 'bg-teal-100 text-teal-800 border-teal-200';
  }
  if (item.status === 'in_progress' || item.status === 'progress_updated' || item.color === 'blue') {
    return 'bg-blue-100 text-blue-700 border-blue-200';
  }
  if (item.status === 'completed' || item.status === 'paid_completed') {
    return 'bg-green-100 text-green-700 border-green-200';
  }
  return 'bg-yellow-100 text-yellow-700 border-yellow-200';
}

function AdminMeetingSchedule() {
  const [month, setMonth] = useState(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  });
  const [events, setEvents] = useState([]);
  const [pms, setPms] = useState([]);
  const [panelOpen, setPanelOpen] = useState(false);
  const [selectedMeeting, setSelectedMeeting] = useState(null);
  const [form, setForm] = useState({ title: '', meeting_date: '', meeting_time: '', pm_id: '', notes: '' });

  const load = () => {
    api.get('/schedule', { params: { month } })
      .then(({ data }) => setEvents(data.all || data.meetings || []))
      .catch(() => setEvents([]));
  };

  useEffect(() => { load(); }, [month]);
  useEffect(() => {
    api.get('/users/pms').then(({ data }) => setPms(data)).catch(() => setPms([]));
  }, []);

  const [year, mon] = month.split('-').map(Number);
  const firstDay = new Date(year, mon - 1, 1).getDay();
  const daysInMonth = new Date(year, mon, 0).getDate();
  const monthLabel = new Date(year, mon - 1).toLocaleString('default', { month: 'long', year: 'numeric' });

  const eventsOnDay = (day) => {
    const dateStr = `${year}-${String(mon).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    return events.filter((e) => (e.date || '').startsWith(dateStr));
  };

  const openCreate = (dateStr = '') => {
    setSelectedMeeting(null);
    setForm({ title: '', meeting_date: dateStr, meeting_time: '', pm_id: pms[0]?.id ? String(pms[0].id) : '', notes: '' });
    setPanelOpen(true);
  };

  const openEdit = (meeting) => {
    setSelectedMeeting(meeting);
    setForm({
      title: meeting.title || '',
      meeting_date: meeting.date || '',
      meeting_time: meeting.time || '',
      pm_id: meeting.pm_id ? String(meeting.pm_id) : '',
      notes: meeting.notes || '',
    });
    setPanelOpen(true);
  };

  const saveMeeting = async (e) => {
    e.preventDefault();
    try {
      if (selectedMeeting) {
        await api.put(`/pm-meetings/${selectedMeeting.id}`, form);
        await showSuccess('Meeting updated.');
      } else {
        await api.post('/pm-meetings', form);
        await showSuccess('Meeting scheduled.');
      }
      setPanelOpen(false);
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save meeting.');
    }
  };

  const deleteMeeting = async () => {
    if (!selectedMeeting) return;
    const ok = await confirmDanger({ title: 'Delete this meeting?', confirmText: 'Yes, delete' });
    if (!ok) return;
    try {
      await api.delete(`/pm-meetings/${selectedMeeting.id}`);
      setPanelOpen(false);
      await showSuccess('Meeting deleted.');
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to delete meeting.');
    }
  };

  const cells = [];
  for (let i = 0; i < firstDay; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <p className="text-sm text-slate-500">Schedule meetings with your project managers</p>
        <button type="button" onClick={() => openCreate()} className="inline-flex items-center gap-1 px-3 py-2 bg-blue-600 text-white text-sm rounded-lg">
          <Plus size={16} /> New Meeting
        </button>
      </div>
      <div className="flex gap-4 mb-4 text-xs">
        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-purple-200 border border-purple-300" /> PM Meeting</span>
      </div>
      <div className="bg-white rounded-xl border border-slate-200 p-4">
        <div className="flex items-center justify-between mb-4">
          <button type="button" onClick={() => {
            const d = new Date(year, mon - 2, 1);
            setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
          }} className="p-2 hover:bg-slate-100 rounded-lg"><ChevronLeft className="w-5 h-5" /></button>
          <h2 className="font-semibold text-slate-800">{monthLabel}</h2>
          <button type="button" onClick={() => {
            const d = new Date(year, mon, 1);
            setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
          }} className="p-2 hover:bg-slate-100 rounded-lg"><ChevronRight className="w-5 h-5" /></button>
        </div>
        <div className="grid grid-cols-7 gap-1">
          {DAYS.map((d) => <div key={d} className="text-center text-xs font-medium text-slate-500 py-2">{d}</div>)}
          {cells.map((day, i) => (
            <div key={i} className={`min-h-[80px] border border-slate-100 rounded-lg p-1 ${day ? 'bg-white' : 'bg-slate-50'}`}>
              {day && (
                <>
                  <button type="button" onClick={() => openCreate(`${year}-${String(mon).padStart(2, '0')}-${String(day).padStart(2, '0')}`)}
                    className="text-xs font-medium text-slate-600 mb-1 hover:text-blue-600">{day}</button>
                  {eventsOnDay(day).map((item) => (
                    <button key={`${item.type}-${item.id}`} type="button" onClick={() => openEdit(item)}
                      className={`w-full text-left text-xs border rounded px-2 py-1 mb-1 truncate font-medium hover:opacity-80 ${eventClass(item)}`}
                      title={item.pm_name ? `With ${item.pm_name}` : item.title}>
                      {item.time && <span className="opacity-70">{formatTime(item.time)} </span>}
                      {item.title}
                    </button>
                  ))}
                </>
              )}
            </div>
          ))}
        </div>
      </div>

      {panelOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
          <form onSubmit={saveMeeting} className="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
            <h3 className="text-lg font-semibold">{selectedMeeting ? 'Edit Meeting' : 'Schedule PM Meeting'}</h3>
            <input required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })}
              placeholder="Meeting title" className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            <select required value={form.pm_id} onChange={(e) => setForm({ ...form, pm_id: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <option value="">Select PM</option>
              {pms.map((pm) => <option key={pm.id} value={pm.id}>{pm.name}</option>)}
            </select>
            <div className="grid grid-cols-2 gap-3">
              <input required type="date" value={form.meeting_date} onChange={(e) => setForm({ ...form, meeting_date: e.target.value })}
                className="border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <input type="time" value={form.meeting_time} onChange={(e) => setForm({ ...form, meeting_time: e.target.value })}
                className="border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
            <textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} rows={3}
              placeholder="Notes (optional)" className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            <div className="flex gap-2 justify-end">
              {selectedMeeting && (
                <button type="button" onClick={deleteMeeting} className="mr-auto inline-flex items-center gap-1 text-red-600 text-sm">
                  <Trash2 size={14} /> Delete
                </button>
              )}
              <button type="button" onClick={() => setPanelOpen(false)} className="px-4 py-2 text-sm text-slate-600">Cancel</button>
              <button type="submit" className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg">Save</button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}

function JobScheduleCalendar() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const isPm = user?.role === 'pm';
  const isOwner = user?.role === 'owner';
  const [month, setMonth] = useState(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  });
  const [events, setEvents] = useState([]);
  const [conflicts, setConflicts] = useState([]);
  const [timezone, setTimezone] = useState('America/Vancouver');
  // PM-13 / CT-08: agenda is the default for PMs and contractors (full field details)
  const [view, setView] = useState(() => {
    const fromUrl = searchParams.get('view');
    if (fromUrl && ['day', 'week', 'month', 'agenda', 'list'].includes(fromUrl)) return fromUrl;
    return (user?.role === 'pm' || user?.role === 'contractor') ? 'agenda' : 'month';
  });
  const [loadError, setLoadError] = useState(null);
  const [loading, setLoading] = useState(true);

  const setViewAndUrl = (v) => {
    setView(v);
    const next = new URLSearchParams(searchParams);
    next.set('view', v);
    setSearchParams(next, { replace: true });
  };

  const loadSchedule = () => {
    setLoading(true);
    setLoadError(null);
    api.get('/schedule', { params: { month, view } })
      .then(({ data }) => {
        setEvents(data.all || data.events || data.jobs || []);
        setConflicts(data.conflicts || []);
        if (data.timezone) setTimezone(data.timezone);
      })
      .catch((err) => {
        // CT-11: never present API failure as an empty calendar
        setLoadError(err?.response?.data?.message || 'Could not load schedule. Retry or contact support.');
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadSchedule();
  }, [month, view]);

  const [year, mon] = month.split('-').map(Number);
  const firstDay = new Date(year, mon - 1, 1).getDay();
  const daysInMonth = new Date(year, mon, 0).getDate();
  const monthLabel = new Date(year, mon - 1).toLocaleString('default', { month: 'long', year: 'numeric' });

  const eventsOnDay = (day) => {
    const dateStr = `${year}-${String(mon).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    return events.filter((e) => (e.date || '').startsWith(dateStr));
  };

  const cells = [];
  for (let i = 0; i < firstDay; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);

  const handleClick = (item) => {
    if (item.type === 'pm_meeting') return;
    if (item.url) navigate(item.url);
  };

  const conflictIds = new Set(
    (conflicts || []).flatMap((c) => [c.event_id, c.id, c.a_id, c.b_id].filter(Boolean).map(String))
  );

  const todayStr = new Date().toISOString().slice(0, 10);
  const weekStart = (() => {
    const d = new Date();
    d.setDate(d.getDate() - d.getDay());
    return d.toISOString().slice(0, 10);
  })();
  const weekEnd = (() => {
    const d = new Date();
    d.setDate(d.getDate() - d.getDay() + 6);
    return d.toISOString().slice(0, 10);
  })();

  const agenda = [...events]
    .filter((e) => {
      const d = (e.date || '').slice(0, 10);
      if (view === 'day') return d === todayStr;
      if (view === 'week') return d >= weekStart && d <= weekEnd;
      return true;
    })
    .sort((a, b) => `${a.date}${a.time || ''}`.localeCompare(`${b.date}${b.time || ''}`));

  const eventTypeLabel = (item) => {
    if (item.event_type_label) return item.event_type_label;
    if (item.type === 'site_visit') return 'Site visit';
    if (item.type === 'pm_meeting') return 'PM meeting';
    if (item.type === 'booking_hold') return 'Hold';
    if (item.type === 'booking') return 'Booking';
    return 'Job';
  };

  const listViews = ['day', 'week', 'agenda', 'list'];

  return (
    <div>
      <div className="flex flex-wrap gap-2 mb-3">
        {['day', 'week', 'month', 'agenda', 'list'].map((v) => (
          <button
            key={v}
            type="button"
            onClick={() => setViewAndUrl(v)}
            className={`px-3 py-1.5 text-sm rounded-lg capitalize ${view === v ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700'}`}
          >
            {v}
          </button>
        ))}
        <span className="text-xs text-slate-500 self-center ml-auto">TZ: {timezone}</span>
      </div>
      {loadError && (
        <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 flex flex-wrap items-center gap-2">
          <span>{loadError}</span>
          <button type="button" onClick={loadSchedule} className="underline font-medium">Retry</button>
        </div>
      )}
      {loading && !loadError && <p className="text-sm text-slate-500 mb-3">Loading schedule…</p>}
      <div className="flex gap-4 mb-4 text-xs flex-wrap">
        {(isPm || isOwner) && <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-purple-200 border border-purple-300" /> PM Meeting</span>}
        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-indigo-200 border border-indigo-300" /> Site Visit</span>
        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-yellow-200 border border-yellow-300" /> Scheduled Job</span>
        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-blue-200 border border-blue-300" /> In Progress</span>
        {(isPm || isOwner) && <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-orange-200 border border-orange-300" /> Hold</span>}
        {(isPm || isOwner) && <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-teal-200 border border-teal-300" /> Booking</span>}
      </div>
      {conflicts.length > 0 && (
        <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
          {conflicts.length} schedule conflict(s) detected (accepted assignments / holds).
        </div>
      )}
      {listViews.includes(view) ? (
        <div className="bg-white rounded-xl border border-slate-200 divide-y divide-slate-100">
          {agenda.map((item) => {
            const hasConflict = conflictIds.has(String(item.id))
              || (conflicts || []).some((c) => String(c.type) === String(item.type) && String(c.id) === String(item.id));
            const timeRange = [
              item.time ? formatTime(item.time) : null,
              item.end_time ? formatTime(item.end_time) : null,
            ].filter(Boolean).join(' – ');
            return (
              <div
                key={`${item.type}-${item.id}`}
                className={`px-4 py-3 ${hasConflict ? 'bg-red-50/60' : 'hover:bg-slate-50'}`}
              >
                <button
                  type="button"
                  onClick={() => handleClick(item)}
                  className="w-full text-left min-h-[44px]"
                  disabled={item.type === 'pm_meeting' && !item.url}
                >
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="text-sm font-medium text-slate-800">{item.title}</p>
                      <p className="text-xs text-slate-500 mt-0.5">
                        {item.date}{timeRange ? ` · ${timeRange}` : ''}
                        {item.end_date && item.end_date !== item.date ? ` → ${item.end_date}` : ''}
                        {' · '}{eventTypeLabel(item)}
                      </p>
                    </div>
                    {item.status && <StatusBadge status={item.status} />}
                  </div>
                  <div className="mt-2 grid sm:grid-cols-2 gap-1 text-xs text-slate-600">
                    {item.customer_name && <p><span className="text-slate-400">Customer:</span> {item.customer_name}</p>}
                    {item.address && <p><span className="text-slate-400">Address:</span> {item.address}</p>}
                    {item.contractor_name && <p><span className="text-slate-400">Contractor:</span> {item.contractor_name}</p>}
                    {item.pm_name && <p><span className="text-slate-400">PM:</span> {item.pm_name}</p>}
                    {(item.assignment_state_label || item.event_type_label) && (
                      <p>
                        <span className="text-slate-400">State:</span>{' '}
                        {item.assignment_state_label || item.event_type_label}
                        {item.is_confirmed === false && item.assignment_state ? ' (not confirmed)' : ''}
                      </p>
                    )}
                    {item.next_action && <p className="sm:col-span-2"><span className="text-slate-400">Next:</span> {item.next_action}</p>}
                  </div>
                  {hasConflict && (
                    <p className="text-xs font-medium text-red-700 mt-2">Conflict warning — overlapping accepted work</p>
                  )}
                </button>
                <FieldQuickActions
                  phone={item.customer_phone}
                  address={item.address}
                  className="mt-2"
                />
              </div>
            );
          })}
          {!loadError && agenda.length === 0 && !loading && (
            <p className="text-sm text-slate-500 text-center py-8">
              {view === 'day' ? 'Nothing scheduled today.'
                : view === 'week' ? 'Nothing scheduled this week.'
                : (isPm ? 'No events on your schedule for this month.' : 'No events this month.')}
            </p>
          )}
        </div>
      ) : (
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="flex items-center justify-between mb-4">
            <button type="button" onClick={() => {
              const d = new Date(year, mon - 2, 1);
              setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
            }} className="p-2 hover:bg-slate-100 rounded-lg"><ChevronLeft className="w-5 h-5" /></button>
            <h2 className="font-semibold text-slate-800">{monthLabel}</h2>
            <button type="button" onClick={() => {
              const d = new Date(year, mon, 1);
              setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
            }} className="p-2 hover:bg-slate-100 rounded-lg"><ChevronRight className="w-5 h-5" /></button>
          </div>
          <div className="grid grid-cols-7 gap-1">
            {DAYS.map((d) => <div key={d} className="text-center text-xs font-medium text-slate-500 py-2">{d}</div>)}
            {cells.map((day, i) => (
              <div key={i} className={`min-h-[80px] border border-slate-100 rounded-lg p-1 ${day ? 'bg-white' : 'bg-slate-50'}`}>
                {day && (
                  <>
                    <p className="text-xs font-medium text-slate-600 mb-1">{day}</p>
                    {eventsOnDay(day).map((item) => (
                      <button
                        key={`${item.type}-${item.id}`}
                        type="button"
                        onClick={() => handleClick(item)}
                        disabled={item.type === 'pm_meeting'}
                        className={`w-full text-left text-xs border rounded px-2 py-1 mb-1 truncate font-medium hover:opacity-80 ${eventClass(item)} ${item.type === 'pm_meeting' ? 'cursor-default' : ''}`}
                        title={item.type === 'pm_meeting' ? item.notes || item.title : (item.time ? `${formatTime(item.time)} · ${item.address || ''}` : item.address)}
                      >
                        {item.type === 'site_visit' ? (
                          <span>{item.customer_name || 'Site Visit'}</span>
                        ) : (
                          <>
                            {item.time && <span className="opacity-70">{formatTime(item.time)} </span>}
                            {item.title}
                          </>
                        )}
                      </button>
                    ))}
                  </>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

export default function Schedule() {
  const { user } = useAuth();
  const isAdmin = user?.role === 'owner';
  const [adminTab, setAdminTab] = useState('calendar');

  return (
    <div>
      <PageHeader
        title="Schedule"
        subtitle={isAdmin ? 'Unified calendar — PM meetings, site visits, jobs, holds, bookings' : undefined}
      />
      {isAdmin && (
        <div className="flex gap-2 mb-4">
          <button type="button" onClick={() => setAdminTab('calendar')}
            className={`px-4 py-2 text-sm rounded-lg ${adminTab === 'calendar' ? 'bg-slate-800 text-white' : 'bg-white border border-slate-200'}`}>
            Unified calendar
          </button>
          <button type="button" onClick={() => setAdminTab('meetings')}
            className={`px-4 py-2 text-sm rounded-lg ${adminTab === 'meetings' ? 'bg-slate-800 text-white' : 'bg-white border border-slate-200'}`}>
            PM meetings
          </button>
        </div>
      )}
      {isAdmin && adminTab === 'meetings' ? <AdminMeetingSchedule /> : <JobScheduleCalendar />}
    </div>
  );
}
