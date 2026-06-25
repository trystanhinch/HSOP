import { useEffect, useState } from 'react';
import { Database, Table2 } from 'lucide-react';
import api from '../api/axios';

export default function DatabaseStructure() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [active, setActive] = useState(0);

  useEffect(() => {
    api.get('/admin/database-overview').then((r) => {
      setData(r.data);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
      </div>
    );
  }

  const tables = data?.tables || [];

  return (
    <div className="max-w-6xl mx-auto">
      <div className="mb-6">
        <div className="flex items-center gap-3 mb-1">
          <Database className="w-6 h-6 text-blue-600" />
          <h1 className="text-2xl font-bold text-slate-900">Database Structure</h1>
        </div>
        <p className="text-slate-500 text-sm">
          {tables.length} tables · Multi-company ready · Role-based data access
        </p>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
        {tables.map((t, i) => (
          <button
            key={t.name}
            type="button"
            onClick={() => setActive(i)}
            className={`p-3 rounded-xl border text-left transition-all ${
              active === i ? 'border-blue-500 bg-blue-50' : 'border-slate-200 bg-white hover:border-slate-300'
            }`}
          >
            <div className="flex items-center gap-2 mb-1">
              <Table2 className={`w-4 h-4 ${active === i ? 'text-blue-600' : 'text-slate-400'}`} />
              <span className={`text-xs font-semibold truncate ${active === i ? 'text-blue-700' : 'text-slate-600'}`}>
                {t.name}
              </span>
            </div>
            <p className="text-lg font-bold text-slate-800">{t.count ?? 0}</p>
            <p className="text-xs text-slate-400">rows</p>
          </button>
        ))}
      </div>

      {tables[active] && (
        <div className="bg-white rounded-2xl border border-slate-200 p-6">
          <div className="mb-4">
            <h2 className="text-lg font-bold text-slate-800 flex items-center gap-2 flex-wrap">
              <Table2 className="w-5 h-5 text-blue-600" />
              {tables[active].name}
              <span className="text-sm font-normal bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">
                {tables[active].count} rows
              </span>
            </h2>
            <p className="text-sm text-slate-500 mt-1">{tables[active].purpose}</p>
          </div>

          <div className="mb-4">
            <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Columns</p>
            <div className="flex flex-wrap gap-2">
              {tables[active].columns.map((col) => (
                <span
                  key={col}
                  className={`text-xs px-2.5 py-1 rounded-full font-mono border ${
                    col.includes('hidden') ? 'bg-red-50 border-red-200 text-red-700'
                    : col === 'id' ? 'bg-slate-100 border-slate-300 text-slate-600'
                    : 'bg-slate-50 border-slate-200 text-slate-700'
                  }`}
                >
                  {col}
                </span>
              ))}
            </div>
          </div>

          {tables[active].statuses && (
            <div className="mt-4">
              <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Status Breakdown</p>
              <div className="flex flex-wrap gap-2">
                {tables[active].statuses.map((s) => (
                  <span key={s.status} className="text-xs px-3 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200">
                    {s.status}: <strong>{s.total}</strong>
                  </span>
                ))}
              </div>
            </div>
          )}

          {tables[active].roles && (
            <div className="mt-4">
              <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Users by Role</p>
              <div className="flex flex-wrap gap-2">
                {tables[active].roles.map((r) => (
                  <span key={r.role} className="text-xs px-3 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200">
                    {r.role}: <strong>{r.total}</strong>
                  </span>
                ))}
              </div>
            </div>
          )}

          {tables[active].sample && (
            <div className="mt-4">
              <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Sample Record</p>
              <pre className="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-700 overflow-x-auto">
                {JSON.stringify(tables[active].sample, null, 2)}
              </pre>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
