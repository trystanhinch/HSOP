import { formatDateTime } from '../utils/formatDate';

const actorLabel = (entry) => {
  if (entry.actor_type === 'ai_super_admin') return 'AI Super Admin';
  return entry.actor_user?.name || 'System';
};

export default function EventTimeline({ entries = [], canAdd, onAdd, adding }) {
  return (
    <div className="bg-white rounded-xl border border-slate-200 p-6">
      <div className="flex items-center justify-between mb-3">
        <h3 className="font-semibold text-slate-800">Event Timeline</h3>
        {canAdd && onAdd && (
          <button type="button" onClick={onAdd} disabled={adding}
            className="text-sm text-blue-600 hover:text-blue-700 font-medium disabled:opacity-50">
            {adding ? 'Adding...' : 'Add note'}
          </button>
        )}
      </div>

      {entries.length === 0 ? (
        <p className="text-sm text-slate-500">No timeline events yet.</p>
      ) : (
        <div className="space-y-3">
          {entries.map((entry) => (
            <div key={entry.id} className="border-l-2 border-slate-200 pl-3 py-1">
              <div className="flex flex-wrap gap-2 text-xs text-slate-400">
                <span>{formatDateTime(entry.occurred_at)}</span>
                <span className="font-medium text-slate-500">{actorLabel(entry)}</span>
                <span className="text-slate-400">{entry.event_type}</span>
              </div>
              <p className="text-sm text-slate-700 mt-1">{entry.description}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
