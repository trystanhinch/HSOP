import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

export default function Schedule() {
  const [month, setMonth] = useState(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  });
  const [jobs, setJobs] = useState([]);

  useEffect(() => {
    api.get('/schedule', { params: { month } }).then(({ data }) => setJobs(data.jobs || [])).catch(() => setJobs([]));
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

  const jobsOnDay = (day) => {
    const dateStr = `${year}-${String(mon).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    return jobs.filter((j) => (j.scheduled_start_date || '').startsWith(dateStr));
  };

  const cells = [];
  for (let i = 0; i < firstDay; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);

  const monthLabel = new Date(year, mon - 1).toLocaleString('default', { month: 'long', year: 'numeric' });

  return (
    <div>
      <PageHeader title="Schedule" />
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
                  {jobsOnDay(day).map((job) => (
                    <Link key={job.id} to={`/jobs/${job.id}`}
                      className="block text-xs bg-blue-50 border border-blue-200 rounded px-1 py-0.5 mb-0.5 hover:bg-blue-100 truncate">
                      <span className="font-medium">{job.customer?.name?.split(' ')[0]}</span>
                      <span className="text-slate-500"> · {formatCategory(job.service_category)}</span>
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
