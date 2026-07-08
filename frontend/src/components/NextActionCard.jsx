import { useEffect, useState } from 'react';
import { formatDateTime } from '../utils/formatDate';

const roleLabels = { owner: 'Admin', ai: 'AI', pm: 'PM', contractor: 'Contractor', customer: 'Customer' };

export default function NextActionCard({ nextAction, canEdit, onSave, saving }) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({
    action_description: '',
    responsible_role: 'pm',
    due_at: '',
  });

  useEffect(() => {
    if (nextAction) {
      setForm({
        action_description: nextAction.action_description || '',
        responsible_role: nextAction.responsible_role || 'pm',
        due_at: nextAction.due_at ? nextAction.due_at.split('T')[0] : '',
      });
    }
  }, [nextAction]);

  const handleSave = async (e) => {
    e.preventDefault();
    if (!form.action_description.trim()) return;
    await onSave({
      action_description: form.action_description,
      responsible_role: form.responsible_role,
      due_at: form.due_at || null,
    });
    setEditing(false);
  };

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-6">
      <div className="flex items-center justify-between mb-3">
        <h3 className="font-semibold text-slate-800">Next Action</h3>
        {canEdit && !editing && (
          <button type="button" onClick={() => setEditing(true)}
            className="text-sm text-blue-600 hover:text-blue-700 font-medium">
            {nextAction ? 'Edit' : 'Set'}
          </button>
        )}
      </div>

      {editing && canEdit ? (
        <form onSubmit={handleSave} className="space-y-3">
          <div>
            <label className="text-xs text-slate-500 block mb-1">Action *</label>
            <input value={form.action_description} onChange={(e) => setForm({ ...form, action_description: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="e.g. Follow up with customer" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs text-slate-500 block mb-1">Responsible</label>
              <select value={form.responsible_role} onChange={(e) => setForm({ ...form, responsible_role: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                {Object.entries(roleLabels).map(([k, v]) => (
                  <option key={k} value={k}>{v}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs text-slate-500 block mb-1">Due date</label>
              <input type="date" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
          </div>
          <div className="flex gap-2">
            <button type="submit" disabled={saving}
              className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg disabled:opacity-50">
              {saving ? 'Saving...' : 'Save'}
            </button>
            <button type="button" onClick={() => setEditing(false)}
              className="px-4 py-2 border border-slate-300 text-slate-600 text-sm rounded-lg">Cancel</button>
          </div>
        </form>
      ) : nextAction ? (
        <div className="text-sm space-y-1">
          <p className="text-slate-800 font-medium">{nextAction.action_description}</p>
          <p className="text-slate-500">
            {roleLabels[nextAction.responsible_role] || nextAction.responsible_role}
            {nextAction.responsible_user?.name ? ` — ${nextAction.responsible_user.name}` : ''}
          </p>
          {nextAction.due_at && <p className="text-slate-500">Due: {formatDateTime(nextAction.due_at)}</p>}
          <p className="text-xs text-slate-400 capitalize">Status: {nextAction.status}</p>
        </div>
      ) : (
        <p className="text-sm text-slate-500">No next action set.</p>
      )}
    </div>
  );
}
