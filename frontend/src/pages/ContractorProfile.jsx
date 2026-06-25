import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import ContractorDocuments from '../components/ContractorDocuments';
import { getRoleDashboard } from '../utils/getRoleDashboard';

export default function ContractorProfile() {
  const { id } = useParams();
  const { user } = useAuth();
  const [contractor, setContractor] = useState(null);

  useEffect(() => {
    api.get(`/contractors/${id}`).then(({ data }) => setContractor(data)).catch(() => {});
  }, [id]);

  if (!contractor) return <div className="text-center py-12 text-slate-500">Loading...</div>;

  const backLink = user?.role === 'contractor' ? getRoleDashboard('contractor') : '/contractors';
  const backLabel = user?.role === 'contractor' ? 'Back to Dashboard' : 'Back to Contractors';

  return (
    <div>
      <Link to={backLink} className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-800 mb-6">
        <ArrowLeft size={16} /> {backLabel}
      </Link>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <h3 className="text-sm font-semibold mb-4">Company Info</h3>
          <dl className="space-y-3 text-sm">
            <div className="flex justify-between"><dt className="text-slate-500">Legal Name</dt><dd>{contractor.legal_name}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Operating Name</dt><dd>{contractor.operating_name}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Contact</dt><dd>{contractor.contact_name}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Phone</dt><dd>{contractor.phone}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Email</dt><dd>{contractor.email}</dd></div>
          </dl>
        </div>

        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <h3 className="text-sm font-semibold mb-4">Services & Cities</h3>
          <div className="flex flex-wrap gap-2 mb-3">
            {(contractor.services || []).map((s) => (
              <span key={s} className="px-2 py-1 bg-slate-100 text-slate-600 rounded text-xs">{s}</span>
            ))}
          </div>
          <div className="flex flex-wrap gap-2">
            {(contractor.cities || []).map((c) => (
              <span key={c} className="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs">{c}</span>
            ))}
          </div>
        </div>

        <ContractorDocuments contractorId={contractor.id} />
      </div>
    </div>
  );
}
