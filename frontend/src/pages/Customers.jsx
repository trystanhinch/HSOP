import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmDanger, showError, showSuccess } from '../utils/swal';

export default function Customers() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const isAdmin = user?.role === 'owner';
  const [customers, setCustomers] = useState([]);

  const load = () => {
    api.get('/customers').then(({ data }) => setCustomers(data.data || data)).catch(() => setCustomers([]));
  };

  useEffect(() => { load(); }, []);

  const handleDelete = async (e, customer) => {
    e.stopPropagation();
    const ok = await confirmDanger({
      title: 'Delete this customer?',
      text: 'This cannot be undone.',
      confirmText: 'Yes, delete',
    });
    if (!ok) return;

    try {
      await api.delete(`/customers/${customer.id}`);
      await showSuccess('Customer deleted.');
      load();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to delete customer.');
    }
  };

  return (
    <div>
      <PageHeader title="Customers" />
      <div className="overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
        <table className="w-full min-w-[640px] text-sm divide-y divide-[#E2E8F0]">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">#</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Name</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Phone</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Email</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Jobs</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Portal</th>
              {isAdmin && <th className="text-right px-4 py-3 font-medium text-[#64748B]">Actions</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {customers.map((c) => (
              <tr
                key={c.id}
                className="hover:bg-slate-50 cursor-pointer transition-colors"
                onClick={() => navigate(`/customers/${c.id}`)}
              >
                <td className="px-4 py-3 font-medium text-[#3B82F6]">#{c.id}</td>
                <td className="px-4 py-3">{c.name}</td>
                <td className="px-4 py-3">{c.phone || '—'}</td>
                <td className="px-4 py-3 hidden md:table-cell">{c.email || '—'}</td>
                <td className="px-4 py-3 hidden lg:table-cell">{c.job_count ?? 0}</td>
                <td className="px-4 py-3"><StatusBadge status={c.portal_link_status ? 'active' : 'inactive'} /></td>
                {isAdmin && (
                  <td className="px-4 py-3 text-right">
                    <button
                      type="button"
                      onClick={(e) => handleDelete(e, c)}
                      className="inline-flex items-center gap-1 text-red-600 hover:text-red-700 text-xs font-medium"
                    >
                      <Trash2 size={14} /> Delete
                    </button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
