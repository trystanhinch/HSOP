import { useEffect, useState } from 'react';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';

const emptyForm = {
  contact_name: '', phone: '', email: '', address: '',
  service_category: 'drywall_paint', source: 'manual',
  company_id: '', assigned_pm_id: '', project_description: '', internal_notes: '',
  site_visit_date: '', site_visit_time: '',
};

export default function LeadForm({ initial, onSubmit, onCancel, saving }) {
  const { user } = useAuth();
  const [form, setForm] = useState({ ...emptyForm, ...initial });
  const [companies, setCompanies] = useState([]);
  const [pms, setPms] = useState([]);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    api.get('/companies').then(({ data }) => setCompanies(data)).catch(() => {});
    if (user?.role === 'owner') {
      api.get('/users/pms').then(({ data }) => setPms(data)).catch(() => {});
    }
  }, [user?.role]);

  useEffect(() => {
    if (initial) setForm({ ...emptyForm, ...initial });
  }, [initial]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    try {
      await onSubmit(form);
    } catch (err) {
      if (err.response?.status === 422) setErrors(err.response.data.errors || {});
      throw err;
    }
  };

  const field = (name, label, type = 'text', required = false) => (
    <div key={name}>
      <label className="text-xs font-medium text-slate-600 block mb-1">{label}{required ? ' *' : ''}</label>
      <input type={type} value={form[name] || ''} onChange={(e) => setForm({ ...form, [name]: e.target.value })}
        required={required}
        className={`w-full border rounded-lg px-3 py-2 text-sm ${errors[name] ? 'border-red-400' : 'border-slate-300'}`} />
      {errors[name] && <p className="text-red-500 text-xs mt-1">{errors[name][0]}</p>}
    </div>
  );

  return (
    <form onSubmit={handleSubmit} className="space-y-4 pb-6">
      {field('contact_name', 'Contact Name', 'text', true)}
      {field('phone', 'Phone')}
      {field('email', 'Email', 'email')}
      {field('address', 'Address', 'text', true)}

      <div>
        <label className="text-xs font-medium text-slate-600 block mb-1">Service Category *</label>
        <select value={form.service_category} onChange={(e) => setForm({ ...form, service_category: e.target.value })}
          className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
          <option value="drywall_paint">Drywall & Paint</option>
          <option value="insulation">Insulation</option>
        </select>
      </div>

      <div>
        <label className="text-xs font-medium text-slate-600 block mb-1">Source</label>
        <select value={form.source} onChange={(e) => setForm({ ...form, source: e.target.value })}
          className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
          {['google', 'referral', 'website', 'manual', 'other'].map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>
      </div>

      {companies.length > 0 && (
        <div>
          <label className="text-xs font-medium text-slate-600 block mb-1">Company</label>
          <select value={form.company_id} onChange={(e) => setForm({ ...form, company_id: e.target.value })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <option value="">Select company...</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
      )}

      {user?.role === 'owner' && pms.length > 0 && (
        <div>
          <label className="text-xs font-medium text-slate-600 block mb-1">Assign PM</label>
          <select value={form.assigned_pm_id} onChange={(e) => setForm({ ...form, assigned_pm_id: e.target.value })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <option value="">Select PM...</option>
            {pms.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
      )}

      <div>
        <label className="text-xs font-medium text-slate-600 block mb-1">Project Description</label>
        <textarea value={form.project_description} onChange={(e) => setForm({ ...form, project_description: e.target.value })}
          rows={3} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
      </div>

      <div>
        <label className="text-xs font-medium text-slate-600 block mb-1">Internal Notes</label>
        <textarea value={form.internal_notes} onChange={(e) => setForm({ ...form, internal_notes: e.target.value })}
          rows={2} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
      </div>

      {initial?.id && (
        <>
          {field('site_visit_date', 'Site Visit Date', 'date')}
          {field('site_visit_time', 'Site Visit Time', 'time')}
        </>
      )}

      <div className="flex gap-3 pt-2">
        <button type="button" onClick={onCancel} className="flex-1 px-4 py-2.5 border border-slate-300 rounded-lg text-sm text-slate-600 hover:bg-slate-50">Cancel</button>
        <button type="submit" disabled={saving} className="flex-1 px-4 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 disabled:opacity-60">
          {saving ? 'Saving...' : initial?.id ? 'Save Changes' : 'Create Lead'}
        </button>
      </div>
    </form>
  );
}
