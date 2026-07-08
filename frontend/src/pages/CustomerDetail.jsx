import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Trash2 } from 'lucide-react';
import api from '../api/axios';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../context/AuthContext';
import { confirmDanger, showError, showSuccess } from '../utils/swal';
import { formatDate } from '../utils/formatDate';

export default function CustomerDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const isAdmin = user?.role === 'owner';
  const [customer, setCustomer] = useState(null);
  const [loadError, setLoadError] = useState(null);

  const load = () => {
    setLoadError(null);
    api.get(`/customers/${id}`)
      .then(({ data }) => setCustomer(data))
      .catch((e) => setLoadError(e.response?.data?.message || 'Failed to load customer.'));
  };

  useEffect(() => { load(); }, [id]);

  const handleDelete = async () => {
    const ok = await confirmDanger({
      title: 'Delete this customer?',
      text: 'This cannot be undone.',
      confirmText: 'Yes, delete',
    });
    if (!ok) return;

    try {
      await api.delete(`/customers/${id}`);
      await showSuccess('Customer deleted.');
      navigate('/customers');
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to delete customer.');
    }
  };

  if (loadError) {
    return <div className="text-center py-12 text-red-600">{loadError}</div>;
  }

  if (!customer) {
    return <div className="text-center py-12 text-slate-500">Loading customer...</div>;
  }

  return (
    <div>
      <Link to="/customers" className="inline-flex items-center gap-2 text-sm text-[#64748B] hover:text-[#0F172A] mb-6">
        <ArrowLeft size={16} /> Back to Customers
      </Link>

      <div className="flex flex-wrap items-center gap-3 mb-6">
        <h2 className="text-xl font-bold text-slate-900">{customer.name}</h2>
        {isAdmin && (
          <button
            type="button"
            onClick={handleDelete}
            className="ml-auto inline-flex items-center gap-1 px-3 py-1.5 text-sm text-red-600 border border-red-200 rounded-lg hover:bg-red-50"
          >
            <Trash2 size={14} /> Delete Customer
          </button>
        )}
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6 mb-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Customer Info</h3>
        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div><dt className="text-[#64748B]">Customer #</dt><dd className="font-medium mt-0.5">#{customer.id}</dd></div>
          <div><dt className="text-[#64748B]">Name</dt><dd className="font-medium mt-0.5">{customer.name || '—'}</dd></div>
          <div><dt className="text-[#64748B]">Phone</dt><dd className="font-medium mt-0.5">{customer.phone || '—'}</dd></div>
          <div><dt className="text-[#64748B]">Email</dt><dd className="font-medium mt-0.5">{customer.email || '—'}</dd></div>
          <div className="sm:col-span-2"><dt className="text-[#64748B]">Address</dt><dd className="font-medium mt-0.5">{customer.address || '—'}</dd></div>
        </dl>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Job History</h3>
        {(customer.jobs || []).length === 0 ? (
          <p className="text-sm text-[#64748B] text-center py-8">No jobs yet</p>
        ) : (
          <div className="space-y-3">
            {customer.jobs.map((job) => (
              <Link
                key={job.id}
                to={`/jobs/${job.id}`}
                className="flex items-center justify-between p-3 border border-slate-200 rounded-lg hover:bg-slate-50"
              >
                <div>
                  <p className="font-medium text-slate-800">{job.job_title || `Job #${job.id}`}</p>
                  <p className="text-xs text-slate-500">{job.address} · {formatDate(job.created_at)}</p>
                </div>
                <StatusBadge status={job.status} />
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
