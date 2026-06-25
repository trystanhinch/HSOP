import { useState, useEffect, useRef } from 'react';
import { Upload, FileCheck, Clock, XCircle, CheckCircle, AlertCircle, Eye } from 'lucide-react';
import api, { storageUrl } from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { confirmAction, confirmDanger, showError, showSuccess } from '../utils/swal';

const docTypes = [
  { key: 'wcb', label: 'WCB Certificate', required: true },
  { key: 'liability_insurance', label: 'Liability Insurance', required: true },
  { key: 'business_license', label: 'Business License', required: false },
  { key: 'other', label: 'Other Document', required: false },
];

const statusConfig = {
  pending_review: { label: 'Pending Review', color: 'bg-yellow-100 text-yellow-700 border-yellow-200', icon: Clock },
  approved: { label: 'Approved', color: 'bg-green-100 text-green-700 border-green-200', icon: CheckCircle },
  rejected: { label: 'Rejected', color: 'bg-red-100 text-red-700 border-red-200', icon: XCircle },
  expired: { label: 'Expired', color: 'bg-orange-100 text-orange-700 border-orange-200', icon: AlertCircle },
  not_uploaded: { label: 'Not Uploaded', color: 'bg-slate-100 text-slate-500 border-slate-200', icon: Upload },
};

export default function ContractorDocuments({ contractorId }) {
  const { user } = useAuth();
  const [docs, setDocs] = useState([]);
  const [uploading, setUploading] = useState(null);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const fileRefs = useRef({});
  const [expiryDates, setExpiryDates] = useState({});

  const canUpload = ['owner', 'pm', 'contractor'].includes(user?.role);
  const canReview = ['owner', 'pm'].includes(user?.role);

  const fetchDocs = () => {
    api.get(`/contractors/${contractorId}/documents`).then((r) => setDocs(r.data)).catch(() => setDocs([]));
  };

  useEffect(() => {
    if (contractorId) fetchDocs();
  }, [contractorId]);

  const getDocStatus = (type) => {
    const latest = docs
      .filter((d) => d.document_type === type)
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))[0];
    return latest || null;
  };

  const handleUpload = async (type) => {
    const file = fileRefs.current[type]?.files?.[0];
    if (!file) {
      setError('Please select a file first.');
      await showError('Please select a file first.');
      return;
    }

    const label = docTypes.find((d) => d.key === type)?.label || 'document';
    const ok = await confirmAction({
      title: 'Upload document?',
      text: `Upload ${label} for review?`,
      confirmText: 'Yes, upload',
    });
    if (!ok) return;

    setUploading(type);
    setError('');
    setSuccess('');
    const form = new FormData();
    form.append('document_type', type);
    form.append('document', file);
    if (expiryDates[type]) form.append('expiry_date', expiryDates[type]);
    try {
      await api.post(`/contractors/${contractorId}/documents`, form);
      setSuccess('Document uploaded successfully. Pending admin review.');
      await showSuccess('Document uploaded. Pending review.');
      fetchDocs();
      if (fileRefs.current[type]) fileRefs.current[type].value = '';
      setExpiryDates((prev) => ({ ...prev, [type]: '' }));
    } catch (e) {
      const msg = e.response?.data?.message || 'Upload failed. Please try again.';
      setError(msg);
      await showError(msg);
    } finally {
      setUploading(null);
    }
  };

  const handleReview = async (docId, status) => {
    const isApprove = status === 'approved';
    const ok = isApprove
      ? await confirmAction({
          title: 'Approve document?',
          text: 'Mark this document as approved?',
          confirmText: 'Yes, approve',
        })
      : await confirmDanger({
          title: 'Reject document?',
          text: 'Mark this document as rejected?',
          confirmText: 'Yes, reject',
        });
    if (!ok) return;

    try {
      await api.put(`/contractors/${contractorId}/documents/${docId}/review`, { status });
      setSuccess(`Document ${status}.`);
      await showSuccess(`Document ${status}.`);
      fetchDocs();
    } catch {
      setError('Review failed.');
      await showError('Review failed.');
    }
  };

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-6 lg:col-span-2">
      <h3 className="font-semibold text-slate-800 mb-1">Compliance Documents</h3>
      <p className="text-sm text-slate-500 mb-5">WCB Certificate and Liability Insurance are required before contractor can be activated.</p>

      {error && <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3 mb-4">{error}</div>}
      {success && <div className="bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg p-3 mb-4">{success}</div>}

      <div className="space-y-4">
        {docTypes.map(({ key, label, required }) => {
          const latest = getDocStatus(key);
          const status = latest?.status || 'not_uploaded';
          const cfg = statusConfig[status];
          const Icon = cfg.icon;

          return (
            <div key={key} className="border border-slate-200 rounded-xl p-4">
              <div className="flex items-center justify-between flex-wrap gap-3">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 bg-slate-100 rounded-lg flex items-center justify-center">
                    <FileCheck className="w-5 h-5 text-slate-500" />
                  </div>
                  <div>
                    <p className="font-medium text-slate-800 text-sm">
                      {label} {required && <span className="text-red-500">*</span>}
                    </p>
                    {latest && (
                      <p className="text-xs text-slate-400">
                        Uploaded: {new Date(latest.created_at).toLocaleDateString()}
                        {latest.expiry_date && ` · Expires: ${new Date(latest.expiry_date).toLocaleDateString()}`}
                        {latest.file_size && ` · ${latest.file_size}`}
                      </p>
                    )}
                  </div>
                </div>

                <div className="flex items-center gap-2 flex-wrap">
                  <span className={`text-xs px-2.5 py-1 rounded-full border font-medium flex items-center gap-1 ${cfg.color}`}>
                    <Icon className="w-3 h-3" />
                    {cfg.label}
                  </span>

                  {latest?.file_url && (
                    <a href={storageUrl(latest.file_url)} target="_blank" rel="noreferrer"
                      className="text-xs px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 flex items-center gap-1">
                      <Eye className="w-3 h-3" /> View
                    </a>
                  )}

                  {canReview && latest && latest.status === 'pending_review' && (
                    <>
                      <button type="button" onClick={() => handleReview(latest.id, 'approved')}
                        className="text-xs px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700">
                        Approve
                      </button>
                      <button type="button" onClick={() => handleReview(latest.id, 'rejected')}
                        className="text-xs px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700">
                        Reject
                      </button>
                    </>
                  )}
                </div>
              </div>

              {canUpload && (
                <div className="mt-3 pt-3 border-t border-slate-100">
                  <div className="flex flex-wrap gap-3 items-end">
                    <div>
                      <label className="text-xs text-slate-500 block mb-1">
                        {latest ? 'Replace document (PDF, JPG, PNG · max 10MB)' : 'Upload document (PDF, JPG, PNG · max 10MB)'}
                      </label>
                      <input
                        type="file"
                        ref={(el) => { fileRefs.current[key] = el; }}
                        accept=".pdf,.jpg,.jpeg,.png"
                        className="text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border file:border-slate-300 file:text-xs file:bg-white file:text-slate-700 hover:file:bg-slate-50"
                      />
                    </div>
                    <div>
                      <label className="text-xs text-slate-500 block mb-1">Expiry Date (optional)</label>
                      <input
                        type="date"
                        value={expiryDates[key] || ''}
                        onChange={(e) => setExpiryDates((prev) => ({ ...prev, [key]: e.target.value }))}
                        className="text-xs border border-slate-300 rounded-lg px-2 py-1.5"
                      />
                    </div>
                    <button
                      type="button"
                      onClick={() => handleUpload(key)}
                      disabled={uploading === key}
                      className="text-xs px-4 py-1.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white rounded-lg font-medium flex items-center gap-1"
                    >
                      <Upload className="w-3 h-3" />
                      {uploading === key ? 'Uploading...' : 'Upload'}
                    </button>
                  </div>
                </div>
              )}

              {latest?.status === 'rejected' && latest?.rejection_reason && (
                <div className="mt-2 text-xs text-red-600 bg-red-50 rounded-lg p-2">
                  Rejection reason: {latest.rejection_reason}
                </div>
              )}
            </div>
          );
        })}
      </div>

      {docs.length > 0 && (
        <div className="mt-6">
          <p className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">Upload History</p>
          <div className="space-y-2">
            {docs.map((doc) => (
              <div key={doc.id} className="flex items-center justify-between text-xs text-slate-600 bg-slate-50 rounded-lg px-3 py-2 flex-wrap gap-2">
                <span>{doc.file_name}</span>
                <div className="flex items-center gap-3">
                  <span className="text-slate-400">{new Date(doc.created_at).toLocaleDateString()}</span>
                  <span className={`px-2 py-0.5 rounded-full border text-xs ${statusConfig[doc.status]?.color}`}>
                    {statusConfig[doc.status]?.label}
                  </span>
                  {doc.file_url && (
                    <a href={storageUrl(doc.file_url)} target="_blank" rel="noreferrer" className="text-blue-600 hover:underline">View</a>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
