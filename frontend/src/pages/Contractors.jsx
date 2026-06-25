import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';

export default function Contractors() {
  const [contractors, setContractors] = useState([]);

  useEffect(() => {
    api.get('/contractors').then(({ data }) => setContractors(data.data || data)).catch(() => setContractors([]));
  }, []);

  return (
    <div>
      <PageHeader title="Contractors" />
      <div className="overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
        <table className="w-full min-w-[640px] text-sm divide-y divide-[#E2E8F0]">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">#</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Name</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Services</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Cities</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">WCB</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden sm:table-cell">Insurance</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Approval</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {contractors.map((c) => (
              <tr key={c.id} className="hover:bg-slate-50">
                <td className="px-4 py-3">
                  <Link to={`/contractors/${c.id}`} className="text-[#3B82F6] hover:underline font-medium">#{c.id}</Link>
                </td>
                <td className="px-4 py-3">{c.legal_name || c.operating_name || '—'}</td>
                <td className="px-4 py-3 hidden md:table-cell">{(c.services || []).join(', ')}</td>
                <td className="px-4 py-3 hidden lg:table-cell">{(c.cities || []).join(', ')}</td>
                <td className="px-4 py-3 capitalize">{c.wcb_status || '—'}</td>
                <td className="px-4 py-3 hidden sm:table-cell capitalize">{c.liability_insurance_status || '—'}</td>
                <td className="px-4 py-3"><StatusBadge status={c.approval_status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
