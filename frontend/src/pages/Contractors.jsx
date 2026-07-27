import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';

function complianceLabel(status) {
  const s = (status || '').toLowerCase();
  if (s === 'approved' || s === 'verified' || s === 'valid') return 'Approved';
  if (s === 'pending_review') return 'Pending review';
  if (s === 'rejected') return 'Rejected';
  if (s === 'expired') return 'Expired';
  if (s === 'not_uploaded' || !s) return 'Not uploaded';
  return status;
}

function stateLabel(state) {
  const map = {
    invited: 'Invited',
    profile_incomplete: 'Profile incomplete',
    approved: 'Approved',
    suspended: 'Suspended',
    deactivated: 'Deactivated',
  };
  return map[state] || state || '—';
}

export default function Contractors() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const isPm = user?.role === 'pm';
  const [contractors, setContractors] = useState([]);
  const [total, setTotal] = useState(0);

  useEffect(() => {
    api.get('/contractors').then(({ data }) => {
      setContractors(data.data || data);
      setTotal(data.total ?? (data.data || data).length);
    }).catch(() => {
      setContractors([]);
      setTotal(0);
    });
  }, []);

  return (
    <div>
      <PageHeader title="Contractors">
        <span className="text-sm text-slate-500">
          {isPm
            ? 'Contractors assigned to your jobs'
            : `Authoritative directory (${total})`}
        </span>
      </PageHeader>
      <div className="overflow-x-auto rounded-lg border border-[#E2E8F0] bg-white shadow-sm">
        <table className="w-full min-w-[640px] text-sm divide-y divide-[#E2E8F0]">
          <thead className="bg-slate-50">
            <tr>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">#</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">Name</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Contact</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden md:table-cell">Services</th>
              {!isPm && (
                <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Cities</th>
              )}
              {isPm && (
                <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Territory</th>
              )}
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">WCB</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden sm:table-cell">Insurance</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B]">State</th>
              <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden sm:table-cell">Active jobs</th>
              {!isPm && (
                <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Stripe</th>
              )}
              {isPm && (
                <th className="text-left px-4 py-3 font-medium text-[#64748B] hidden lg:table-cell">Availability</th>
              )}
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E2E8F0]">
            {contractors.length === 0 && (
              <tr>
                <td colSpan={10} className="px-4 py-8 text-center text-slate-500">
                  {isPm ? 'No contractors assigned to your jobs yet.' : 'No contractors in the directory.'}
                </td>
              </tr>
            )}
            {contractors.map((c) => (
              <tr
                key={c.id}
                className="hover:bg-slate-50 cursor-pointer transition-colors"
                onClick={() => navigate(`/contractors/${c.id}`)}
              >
                <td className="px-4 py-3 font-medium text-[#3B82F6]">#{c.id}</td>
                <td className="px-4 py-3">
                  <div className="font-medium text-slate-800">{c.name || c.legal_name || c.operating_name || '—'}</div>
                  {(c.missing_steps || []).length > 0 && (
                    <div className="text-xs text-amber-700 mt-1">
                      Missing: {(c.missing_steps || []).map((s) => s.label || s).join('; ')}
                    </div>
                  )}
                  {isPm && (c.operational_warnings || []).length > 0 && (
                    <div className="text-xs text-amber-700 mt-1">
                      {(c.operational_warnings || [])[0]}
                    </div>
                  )}
                </td>
                <td className="px-4 py-3 hidden md:table-cell text-slate-600">
                  <div>{c.phone || '—'}</div>
                  <div className="text-xs text-slate-400">{c.email || ''}</div>
                </td>
                <td className="px-4 py-3 hidden md:table-cell">{(c.services || []).join(', ') || '—'}</td>
                <td className="px-4 py-3 hidden lg:table-cell">{(c.cities || c.territory || []).join(', ') || '—'}</td>
                <td className="px-4 py-3">{complianceLabel(c.wcb_status)}</td>
                <td className="px-4 py-3 hidden sm:table-cell">{complianceLabel(c.liability_insurance_status)}</td>
                <td className="px-4 py-3">
                  <StatusBadge status={c.state || c.approval_status} />
                  <div className="text-xs text-slate-500 mt-0.5">{stateLabel(c.state)}</div>
                </td>
                <td className="px-4 py-3 hidden sm:table-cell">{c.active_job_count ?? 0}</td>
                {!isPm && (
                  <td className="px-4 py-3 hidden lg:table-cell text-slate-600">
                    {c.stripe?.status_label || '—'}
                  </td>
                )}
                {isPm && (
                  <td className="px-4 py-3 hidden lg:table-cell capitalize">
                    {(c.availability_status || 'available').replace('_', ' ')}
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
