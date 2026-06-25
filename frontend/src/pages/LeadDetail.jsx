import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Pencil } from 'lucide-react';
import api from '../api/axios';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import LeadForm from '../components/LeadForm';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const statuses = ['new', 'contacted', 'site_visit_scheduled', 'quote_needed', 'converted', 'lost'];

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

export default function LeadDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [lead, setLead] = useState(null);
  const [panelOpen, setPanelOpen] = useState(false);
  const [converting, setConverting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [siteVisit, setSiteVisit] = useState({ site_visit_date: '', site_visit_time: '' });

  const load = () => api.get(`/leads/${id}`).then(({ data }) => {
    setLead(data);
    setSiteVisit({
      site_visit_date: data.site_visit_date?.split('T')[0] || '',
      site_visit_time: data.site_visit_time?.slice(0, 5) || '',
    });
  }).catch(() => {});

  useEffect(() => { load(); }, [id]);

  const updateStatus = async (status) => {
    if (status === lead.status) return;
    const ok = await confirmAction({
      title: 'Change lead status?',
      text: `Update status to "${status.replace(/_/g, ' ')}"?`,
      confirmText: 'Yes, update',
    });
    if (!ok) return;

    try {
      await api.put(`/leads/${id}`, { status });
      await showSuccess('Status updated.');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to update status.');
    }
  };

  const saveSiteVisit = async () => {
    const ok = await confirmAction({
      title: 'Save site visit?',
      text: 'Save the scheduled site visit date and time?',
      confirmText: 'Yes, save',
    });
    if (!ok) return;

    try {
      await api.put(`/leads/${id}`, siteVisit);
      await showSuccess('Site visit saved.');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to save site visit.');
    }
  };

  const handleEdit = async (form) => {
    const ok = await confirmAction({
      title: 'Save changes?',
      text: 'Update this lead with your changes?',
      confirmText: 'Yes, save',
    });
    if (!ok) return;

    setSaving(true);
    try {
      await api.put(`/leads/${id}`, form);
      setPanelOpen(false);
      await showSuccess('Lead updated.');
      load();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to update lead.');
    } finally {
      setSaving(false);
    }
  };

  const convertToJob = async () => {
    const ok = await confirmAction({
      title: 'Convert to job?',
      text: 'This will create a new job from this lead. This action cannot be undone.',
      confirmText: 'Yes, convert',
      icon: 'warning',
    });
    if (!ok) return;

    setConverting(true);
    try {
      const { data } = await api.post(`/leads/${id}/convert-to-job`);
      await showSuccess('Lead converted to job.');
      navigate(`/jobs/${data.job_id}`);
    } catch (e) {
      await showError(e.response?.data?.message || 'Conversion failed.');
    } finally {
      setConverting(false);
    }
  };

  if (!lead) return <div className="text-center py-12 text-slate-500">Loading lead...</div>;

  const formInitial = {
    id: lead.id,
    contact_name: lead.contact_name,
    phone: lead.phone,
    email: lead.email,
    address: lead.address,
    service_category: lead.service_category,
    source: lead.source,
    company_id: lead.company_id,
    assigned_pm_id: lead.assigned_pm_id,
    project_description: lead.project_description || lead.notes,
    internal_notes: lead.internal_notes,
    site_visit_date: siteVisit.site_visit_date,
    site_visit_time: siteVisit.site_visit_time,
  };

  return (
    <div>
      <Link to="/leads" className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900 mb-6">
        <ArrowLeft size={16} /> Back to Leads
      </Link>

      <div className="flex flex-wrap items-center gap-3 mb-6">
        <h2 className="text-xl font-bold text-slate-900">Lead #{lead.id}</h2>
        <StatusBadge status={lead.status} />
        <button onClick={() => setPanelOpen(true)} className="ml-auto flex items-center gap-1 px-3 py-1.5 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">
          <Pencil className="w-4 h-4" /> Edit
        </button>
        {lead.status !== 'converted' && (
          <button onClick={convertToJob} disabled={converting}
            className="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 disabled:opacity-60">
            {converting ? 'Converting...' : 'Convert to Job'}
          </button>
        )}
        {lead.job && <Link to={`/jobs/${lead.job.id}`} className="text-sm text-blue-600 hover:underline">View Job →</Link>}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 text-sm">
          <h3 className="font-semibold text-slate-800">Contact Info</h3>
          <div className="flex justify-between"><span className="text-slate-500">Name</span><span>{lead.contact_name}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Phone</span><span>{lead.phone || '—'}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Email</span><span>{lead.email || '—'}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Address</span><span className="text-right max-w-[60%]">{lead.address}</span></div>
        </div>

        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 text-sm">
          <h3 className="font-semibold text-slate-800">Lead Details</h3>
          <div className="flex justify-between"><span className="text-slate-500">Category</span><span className="capitalize">{formatCategory(lead.service_category)}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Source</span><span>{lead.source || '—'}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">PM</span><span>{lead.assigned_pm?.name || '—'}</span></div>
          <div>
            <label className="text-slate-500 text-xs block mb-1">Status</label>
            <select value={lead.status} onChange={(e) => updateStatus(e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
              {statuses.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
          </div>
        </div>

        <div className="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-6">
          <h3 className="font-semibold text-slate-800 mb-2">Project Description</h3>
          <p className="text-sm text-slate-600 whitespace-pre-wrap">{lead.project_description || lead.notes || '—'}</p>
        </div>

        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <h3 className="font-semibold text-slate-800 mb-3">Site Visit</h3>
          <div className="grid grid-cols-2 gap-3">
            <input type="date" value={siteVisit.site_visit_date} onChange={(e) => setSiteVisit({ ...siteVisit, site_visit_date: e.target.value })}
              className="border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            <input type="time" value={siteVisit.site_visit_time} onChange={(e) => setSiteVisit({ ...siteVisit, site_visit_time: e.target.value })}
              className="border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <button onClick={saveSiteVisit} className="mt-3 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">Save Site Visit</button>
        </div>

        {lead.activity?.length > 0 && (
          <div className="bg-white rounded-xl border border-slate-200 p-6">
            <h3 className="font-semibold text-slate-800 mb-3">Activity Log</h3>
            <div className="space-y-2">
              {lead.activity.map((a) => (
                <div key={a.id} className="text-xs text-slate-600 flex gap-2">
                  <span className="text-slate-400">{new Date(a.created_at).toLocaleString()}</span>
                  <span className="font-medium">{a.user?.name}</span>
                  <span>{a.action_type}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      <SlideOverPanel isOpen={panelOpen} onClose={() => setPanelOpen(false)} title="Edit Lead">
        <LeadForm initial={formInitial} onSubmit={handleEdit} onCancel={() => setPanelOpen(false)} saving={saving} />
      </SlideOverPanel>
    </div>
  );
}
