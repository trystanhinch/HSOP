import { useEffect, useState } from 'react';
import api from '../api/axios';
import { confirmAction, showError, showSuccess } from '../utils/swal';

export default function AssignUserModal({ jobId, type, currentName, onClose, onAssigned }) {
  const [users, setUsers] = useState([]);
  const [selected, setSelected] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const endpoint = type === 'pm' ? '/users/pms' : '/users/contractors';
    api.get(endpoint).then(({ data }) => setUsers(data)).catch(() => {});
  }, [type]);

  const handleAssign = async () => {
    if (!selected) return;
    const label = type === 'pm' ? 'Project Manager' : 'Contractor';
    const name = users.find((u) => String(u.id) === String(selected))?.name;
    const ok = await confirmAction({
      title: `Assign ${label}?`,
      text: `Assign ${name || 'this user'} to this job?`,
      confirmText: 'Yes, assign',
    });
    if (!ok) return;

    setLoading(true);
    try {
      const endpoint = type === 'pm' ? `/jobs/${jobId}/assign-pm` : `/jobs/${jobId}/assign-contractor`;
      const body = type === 'pm' ? { pm_id: selected } : { contractor_id: selected };
      await api.post(endpoint, body);
      await showSuccess(`${label} assigned successfully.`);
      onAssigned?.();
      onClose();
    } catch (e) {
      await showError(e.response?.data?.message || 'Assignment failed.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
        <h3 className="text-lg font-semibold text-slate-800 mb-1">
          {type === 'pm' ? 'Assign Project Manager' : 'Assign Contractor'}
        </h3>
        {currentName && <p className="text-sm text-slate-500 mb-4">Current: {currentName}</p>}

        <select
          value={selected}
          onChange={(e) => setSelected(e.target.value)}
          className="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm mb-4"
        >
          <option value="">Select {type === 'pm' ? 'PM' : 'contractor'}...</option>
          {users.map((u) => (
            <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
          ))}
        </select>

        <div className="flex gap-3 justify-end">
          <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:bg-slate-100 rounded-lg">Cancel</button>
          <button type="button" onClick={handleAssign} disabled={!selected || loading}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-60">
            {loading ? 'Assigning...' : 'Confirm'}
          </button>
        </div>
      </div>
    </div>
  );
}
