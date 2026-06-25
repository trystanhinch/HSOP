import { Link, useParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';

export default function CustomerDetail() {
  const { id } = useParams();

  return (
    <div>
      <Link to="/customers" className="inline-flex items-center gap-2 text-sm text-[#64748B] hover:text-[#0F172A] mb-6">
        <ArrowLeft size={16} /> Back to Customers
      </Link>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6 mb-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Customer Info</h3>
        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div><dt className="text-[#64748B]">Customer #</dt><dd className="font-medium mt-0.5">#{id}</dd></div>
          <div><dt className="text-[#64748B]">Name</dt><dd className="font-medium mt-0.5">—</dd></div>
          <div><dt className="text-[#64748B]">Phone</dt><dd className="font-medium mt-0.5">—</dd></div>
          <div><dt className="text-[#64748B]">Email</dt><dd className="font-medium mt-0.5">—</dd></div>
          <div className="sm:col-span-2"><dt className="text-[#64748B]">Address</dt><dd className="font-medium mt-0.5">—</dd></div>
        </dl>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-[#E2E8F0] p-6">
        <h3 className="text-sm font-semibold text-[#0F172A] mb-4">Job History</h3>
        <p className="text-sm text-[#64748B] text-center py-8">No jobs yet</p>
      </div>
    </div>
  );
}
