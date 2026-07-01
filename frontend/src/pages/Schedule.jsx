import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function eventClass(item) {
  if (item.type === 'site_visit') {
    return 'bg-indigo-100 text-indigo-700 border-indigo-200';
  }
  if (item.status === 'in_progress' || item.status === 'progress_updated') {
    return 'bg-blue-100 text-blue-700 border-blue-200';
  }
  if (item.status === 'completed' || item.status === 'paid_completed') {
    return 'bg-green-100 text-green-700 border-green-200';
  }
  return 'bg-yellow-100 text-yellow-700 border-yellow-200';
}

export default function Schedule() {
  const [month, setMonth] = useState(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  });
  const [events, setEvents] = useState([]);

  useEffect(() => {
    api.get('/schedule', { params: { month } })
      .then(({ data }) => setEvents(data.all || data.jobs || []))
      .catch(() => setEvents([]));
  }, [month]);

  const [year, mon] = month.split('-').map(Number);
  const firstDay = new Date(year, mon - 1, 1).getDay();
  const daysInMonth = new Date(year, mon, 0).getDate();

  const prevMonth = () => {
    const d = new Date(year, mon - 2, 1);
    setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
  };

  const nextMonth = () => {
    const d = new Date(year, mon, 1);
    setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
  };

  const eventsOnDay = (day) => {
    const dateStr = `${year}-${String(mon).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    return events.filter((e) => (e.date || '').startsWith(dateStr));
  };

  const cells = [];
  for (let i = 0; i < firstDay; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);

  const monthLabel = new Date(year, mon - 1).toLocaleString('default', { month: 'long', year: 'numeric' });

  return (
    <div>
      <PageHeader title="Schedule" />
      <div className="flex gap-4 mb-4 text-xs">
        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-indigo-200 border border-indigo-300" /> Site Visit</span>
        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-yellow-200 border border-yellow-300" /> Scheduled Job</span>
        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-blue-200 border border-blue-300" /> In Progress</span>
        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-green-200 border border-green-300" /> Completed</span>
      </div>
      <div className="bg-white rounded-xl border border-slate-200 p-4">
        <div className="flex items-center justify-between mb-4">
          <button onClick={prevMonth} className="p-2 hover:bg-slate-100 rounded-lg"><ChevronLeft className="w-5 h-5" /></button>
          <h2 className="font-semibold text-slate-800">{monthLabel}</h2>
          <button onClick={nextMonth} className="p-2 hover:bg-slate-100 rounded-lg"><ChevronRight className="w-5 h-5" /></button>
        </div>

        <div className="grid grid-cols-7 gap-1">
          {DAYS.map((d) => (
            <div key={d} className="text-center text-xs font-medium text-slate-500 py-2">{d}</div>
          ))}
          {cells.map((day, i) => (
            <div key={i} className={`min-h-[80px] border border-slate-100 rounded-lg p-1 ${day ? 'bg-white' : 'bg-slate-50'}`}>
              {day && (
                <>
                  <p className="text-xs font-medium text-slate-600 mb-1">{day}</p>
                  {eventsOnDay(day).map((item) => (
                    <Link key={`${item.type}-${item.id}`} to={item.url}
                      className={`block text-xs border rounded px-1 py-0.5 mb-0.5 hover:opacity-80 truncate ${eventClass(item)}`}>
                      {item.type === 'site_visit' ? 'Site Visit' : item.customer_name?.split(' ')[0] || item.title}
                    </Link>
                  ))}
                </>
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
