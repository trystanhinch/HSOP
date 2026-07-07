import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';

export default function Customers() {
  const navigate = useNavigate();
  const [customers, setCustomers] = useState([]);

  useEffect(() => {
    api.get('/customers').then(({ data }) => setCustomers(data.data || data)).catch(() => setCustomers([]));
  }, []);

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
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Address</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Portal</th>
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
                <td className="px-4 py-3 hidden lg:table-cell">{c.address || '—'}</td>
                <td className="px-4 py-3"><StatusBadge status={c.portal_link_status ? 'active' : 'inactive'} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
