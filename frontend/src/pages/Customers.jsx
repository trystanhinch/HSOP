import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Download, GitMerge, Trash2 } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import { useAuth } from '../context/AuthContext';
import { confirmDanger, showError, showSuccess } from '../utils/swal';
import { formatDate } from '../utils/formatDate';

const VIEWS = [
  { id: 'primary', label: 'Active' },
  { id: 'needs_review', label: 'Needs Review' },
  { id: 'duplicates', label: 'Possible Duplicates' },
  { id: 'all', label: 'All' },
];

function flagSummary(flags) {
  if (!Array.isArray(flags) || flags.length === 0) return '—';
  return flags.join(', ');
}

export default function Customers() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const isOwner = user?.role === 'owner';
  const isPm = user?.role === 'pm';
  const customerViews = isOwner ? VIEWS : [{ id: 'primary', label: 'My customers' }];
  const [customers, setCustomers] = useState([]);
  const [view, setView] = useState('primary');
  const [search, setSearch] = useState('');
  const [mergeOpen, setMergeOpen] = useState(false);
  const [mergeGroup, setMergeGroup] = useState([]);
  const [primaryId, setPrimaryId] = useState(null);
  const [fieldChoices, setFieldChoices] = useState({});
  const [merging, setMerging] = useState(false);

  const load = () => {
    const params = { view };
    if (search.trim()) params.search = search.trim();
    api.get('/customers', { params })
      .then(({ data }) => setCustomers(data.data || []))
      .catch(() => setCustomers([]));
  };

  useEffect(() => { load(); }, [view]);

  const openMergeGroup = async (groupId) => {
    try {
      const { data } = await api.get(`/customers/duplicate-groups/${groupId}`);
      const members = data.members || [];
      setMergeGroup(members);
      const primary = members.find((m) => m.is_duplicate_primary) || members[0];
      setPrimaryId(primary?.id ?? null);
      setFieldChoices({});
      setMergeOpen(true);
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to load duplicate group.');
    }
  };

  const runMerge = async () => {
    if (!primaryId || mergeGroup.length < 2) return;
    const ok = await confirmDanger({
      title: 'Merge customers?',
      text: 'All jobs, quotes, invoices, and messages will move to the primary record. This cannot be undone automatically.',
      confirmText: 'Merge',
    });
    if (!ok) return;
    setMerging(true);
    try {
      await api.post('/customers/merge', {
        customer_ids: mergeGroup.map((m) => m.id),
        primary_customer_id: primaryId,
        field_choices: fieldChoices,
      });
      await showSuccess('Customers merged.');
      setMergeOpen(false);
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Merge failed.');
    } finally {
      setMerging(false);
    }
  };

  const handleDelete = async (e, customer) => {
    e.stopPropagation();
    const typed = window.prompt('Type DELETE to permanently remove this customer record:');
    if (typed !== 'DELETE') return;
    try {
      await api.delete(`/customers/${customer.id}`, { data: { confirmation: 'delete' } });
      await showSuccess('Customer deleted.');
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Delete failed.');
    }
  };

  const handleExport = async (e, customer) => {
    e.stopPropagation();
    try {
      const { data } = await api.get(`/customers/${customer.id}/export`);
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `customer-${customer.id}-export.json`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      await showError(e.response?.data?.message || 'Export failed.');
    }
  };

  return (
    <div>
      <PageHeader title={isPm ? 'My Customers' : 'Customers'} />

      <div className="bg-white rounded-lg border border-[#E2E8F0] p-4 mb-4 flex flex-col sm:flex-row gap-3">
        <div className="flex flex-wrap gap-2">
          {customerViews.map((v) => (
            <button
              key={v.id}
              type="button"
              onClick={() => setView(v.id)}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium ${view === v.id ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700'}`}
            >
              {v.label}
            </button>
          ))}
        </div>
        <div className="flex flex-1 gap-2">
          <input
            type="text"
            placeholder="Search name, phone, email, address..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && load()}
            className="flex-1 px-3 py-2 border border-slate-200 rounded-lg text-sm"
          />
          <button type="button" onClick={load} className="px-4 py-2 bg-slate-100 rounded-lg text-sm">Search</button>
        </div>
      </div>

      <div className="overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
        <table className="w-full min-w-[900px] text-sm divide-y divide-[#E2E8F0]">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">#</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Name</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Phone</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Email</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Flags</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">DNC</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Last contact</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Active</th>
              {isOwner && <th className="text-right px-4 py-3 font-medium text-[#64748B]">Actions</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {customers.length === 0 ? (
              <tr><td colSpan={isOwner ? 9 : 8} className="px-4 py-12 text-center text-slate-500">No customers in this view.</td></tr>
            ) : customers.map((c) => (
              <tr
                key={c.id}
                className="hover:bg-slate-50 cursor-pointer"
                onClick={() => navigate(`/customers/${c.id}`)}
              >
                <td className="px-4 py-3 font-medium text-[#3B82F6]">#{c.id}</td>
                <td className="px-4 py-3">{c.name}</td>
                <td className="px-4 py-3">{c.phone || '—'}</td>
                <td className="px-4 py-3 hidden md:table-cell">{c.email || '—'}</td>
                <td className="px-4 py-3 text-xs text-amber-800">{flagSummary(c.data_quality_flags)}</td>
                <td className="px-4 py-3">
                  {c.do_not_contact ? (
                    <span className="text-xs font-medium bg-red-100 text-red-800 px-2 py-0.5 rounded">DNC</span>
                  ) : '—'}
                </td>
                <td className="px-4 py-3 hidden lg:table-cell text-xs text-slate-500">{c.last_contact_at ? formatDate(c.last_contact_at) : '—'}</td>
                <td className="px-4 py-3 hidden lg:table-cell">
                  {c.has_active_work ? <StatusBadge status="in_progress" /> : '—'}
                </td>
                {isOwner && (
                  <td className="px-4 py-3 text-right space-x-1">
                    {c.duplicate_group_id && (
                      <button
                        type="button"
                        title="Review merge"
                        onClick={(e) => { e.stopPropagation(); openMergeGroup(c.duplicate_group_id); }}
                        className="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 p-1"
                      >
                        <GitMerge size={14} />
                      </button>
                    )}
                    <button type="button" onClick={(e) => handleExport(e, c)} className="inline-flex p-1 text-slate-600 hover:text-slate-800" title="Export">
                      <Download size={14} />
                    </button>
                    <button type="button" onClick={(e) => handleDelete(e, c)} className="inline-flex p-1 text-red-600 hover:text-red-800" title="Delete">
                      <Trash2 size={14} />
                    </button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <SlideOverPanel isOpen={mergeOpen} onClose={() => setMergeOpen(false)} title="Merge duplicate customers">
        {mergeGroup.length < 2 ? (
          <p className="text-sm text-slate-500">Select a duplicate group with at least two members.</p>
        ) : (
          <div className="space-y-4 text-sm">
            <p className="text-slate-600">Choose the primary record and which field values to keep. Related jobs, quotes, invoices, and messages will be reassigned.</p>
            <label className="block">
              <span className="text-xs text-slate-500">Primary record</span>
              <select
                value={primaryId || ''}
                onChange={(e) => setPrimaryId(Number(e.target.value))}
                className="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2"
              >
                {mergeGroup.map((m) => (
                  <option key={m.id} value={m.id}>#{m.id} — {m.name} ({m.phone || 'no phone'})</option>
                ))}
              </select>
            </label>
            {['name', 'phone', 'email', 'address'].map((field) => (
              <label key={field} className="block">
                <span className="text-xs text-slate-500 capitalize">{field} from</span>
                <select
                  value={fieldChoices[field] ?? primaryId}
                  onChange={(e) => setFieldChoices((f) => ({ ...f, [field]: Number(e.target.value) }))}
                  className="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2"
                >
                  {mergeGroup.map((m) => (
                    <option key={m.id} value={m.id}>#{m.id}: {m[field] || '—'}</option>
                  ))}
                </select>
              </label>
            ))}
            <div className="border border-slate-100 rounded-lg overflow-hidden">
              <table className="w-full text-xs">
                <thead className="bg-slate-50"><tr><th className="px-2 py-1 text-left">ID</th><th className="px-2 py-1 text-left">Name</th><th className="px-2 py-1 text-left">Phone</th><th className="px-2 py-1 text-left">Jobs</th></tr></thead>
                <tbody>
                  {mergeGroup.map((m) => (
                    <tr key={m.id} className="border-t border-slate-100">
                      <td className="px-2 py-1">#{m.id}{m.id === primaryId ? ' ★' : ''}</td>
                      <td className="px-2 py-1">{m.name}</td>
                      <td className="px-2 py-1">{m.phone || '—'}</td>
                      <td className="px-2 py-1">{m.job_count ?? 0}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <button
              type="button"
              disabled={merging}
              onClick={runMerge}
              className="w-full py-2 bg-blue-600 text-white rounded-lg font-medium disabled:opacity-50"
            >
              Confirm merge
            </button>
          </div>
        )}
      </SlideOverPanel>
    </div>
  );
}
