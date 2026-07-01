import { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Plus } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import DatabaseStructure from './DatabaseStructure';
import { confirmAction, showError, showSuccess } from '../utils/swal';

const tabs = ['Company', 'Users & Roles', 'Notifications', 'GST & Markup', 'Payouts & Split', 'Payment', 'SMS Log', 'Email Log', 'Branding', 'Database Structure'];

const smsStatusColor = { sent: 'bg-green-100 text-green-700', failed: 'bg-red-100 text-red-700', disabled: 'bg-slate-100 text-slate-600' };

export default function Settings() {
  const [searchParams, setSearchParams] = useSearchParams();
  const tabParam = searchParams.get('tab');
  const [activeTab, setActiveTab] = useState(tabParam === 'database' ? 'Database Structure' : 'Company');
  const [settings, setSettings] = useState(null);
  const [users, setUsers] = useState([]);
  const [companyForm, setCompanyForm] = useState({});
  const [notifForm, setNotifForm] = useState({ sms_globally_enabled: false, email_globally_enabled: false });
  const [pricingForm, setPricingForm] = useState({ gst_rate: '5', markup_divisor: '0.80' });
  const [splitForm, setSplitForm] = useState({ split_contractor_pct: '80', split_pm_pct: '10', split_company_pct: '10' });
  const [paymentForm, setPaymentForm] = useState({ payment_instructions: '' });
  const [smsLogs, setSmsLogs] = useState([]);
  const [emailLogs, setEmailLogs] = useState([]);
  const [userForm, setUserForm] = useState({ name: '', email: '', role: 'pm' });
  const [showAddUser, setShowAddUser] = useState(false);
  const [saving, setSaving] = useState(false);

  const loadSettings = () => {
    api.get('/settings').then(({ data }) => {
      setSettings(data);
      setCompanyForm(data.company || {});
      setNotifForm({
        sms_globally_enabled: data.notifications?.sms_globally_enabled ?? data.notifications?.sms_enabled ?? false,
        email_globally_enabled: data.notifications?.email_globally_enabled ?? data.notifications?.email_enabled ?? false,
      });
      setPricingForm({
        gst_rate: data.gst_rate || '5',
        markup_divisor: data.markup_divisor || '0.80',
      });
      setSplitForm({
        split_contractor_pct: data.split_contractor_pct || '80',
        split_pm_pct: data.split_pm_pct || '10',
        split_company_pct: data.split_company_pct || '10',
      });
      setPaymentForm({ payment_instructions: data.payment?.instructions || '' });
    }).catch(() => {});
  };

  useEffect(() => {
    loadSettings();
    api.get('/users').then(({ data }) => setUsers(data)).catch(() => {});
  }, []);

  useEffect(() => {
    if (activeTab === 'SMS Log') {
      api.get('/sms-logs').then(({ data }) => setSmsLogs(data.data || data)).catch(() => setSmsLogs([]));
    }
    if (activeTab === 'Email Log') {
      api.get('/email-logs').then(({ data }) => setEmailLogs(data.data || data)).catch(() => setEmailLogs([]));
    }
  }, [activeTab]);

  useEffect(() => {
    if (tabParam === 'database') setActiveTab('Database Structure');
  }, [tabParam]);

  const handleTab = (tab) => {
    setActiveTab(tab);
    setSearchParams(tab === 'Database Structure' ? { tab: 'database' } : {});
  };

  const saveSettings = async (payload, successMsg) => {
    const ok = await confirmAction({ title: 'Save settings?', text: 'Update these settings?', confirmText: 'Yes, save' });
    if (!ok) return;
    setSaving(true);
    try {
      await api.post('/settings', payload);
      await showSuccess(successMsg);
      loadSettings();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save settings.');
    } finally {
      setSaving(false);
    }
  };

  const saveCompany = (e) => {
    e.preventDefault();
    saveSettings(companyForm, 'Company settings saved.');
  };

  const saveNotifications = (e) => {
    e.preventDefault();
    saveSettings(notifForm, 'Notification settings saved.');
  };

  const savePricing = (e) => {
    e.preventDefault();
    saveSettings(pricingForm, 'GST and markup settings saved.');
  };

  const splitTotal = parseFloat(splitForm.split_contractor_pct || 0) + parseFloat(splitForm.split_pm_pct || 0) + parseFloat(splitForm.split_company_pct || 0);
  const splitValid = Math.abs(splitTotal - 100) < 0.01;

  const saveSplit = (e) => {
    e.preventDefault();
    if (!splitValid) return;
    saveSettings(splitForm, 'Payout split settings saved.');
  };

  const savePayment = (e) => {
    e.preventDefault();
    saveSettings(paymentForm, 'Payment settings saved.');
  };

  const toggleUserSms = async (user) => {
    try {
      const { data } = await api.put(`/users/${user.id}/toggle-sms`);
      setUsers((prev) => prev.map((u) => (u.id === user.id ? { ...u, sms_enabled: data.sms_enabled } : u)));
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to toggle SMS.');
    }
  };

  const addUser = async (e) => {
    e.preventDefault();
    const ok = await confirmAction({
      title: 'Create user?',
      text: `Create a new ${userForm.role === 'pm' ? 'Project Manager' : 'Contractor'} account for ${userForm.name}?`,
      confirmText: 'Yes, create user',
    });
    if (!ok) return;

    setSaving(true);
    try {
      const { data } = await api.post('/users', userForm);
      setUsers((p) => [...p, data]);
      setShowAddUser(false);
      setUserForm({ name: '', email: '', role: 'pm' });
      await showSuccess('User created. Default password: password');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to create user');
    } finally {
      setSaving(false);
    }
  };

  const roleBadge = (role) => {
    const colors = { owner: 'bg-purple-100 text-purple-700', pm: 'bg-blue-100 text-blue-700', contractor: 'bg-orange-100 text-orange-700', customer: 'bg-green-100 text-green-700' };
    return <span className={`text-xs px-2 py-0.5 rounded-full font-medium capitalize ${colors[role] || 'bg-slate-100'}`}>{role}</span>;
  };

  return (
    <div>
      <PageHeader title="Settings" />

      <div className="flex flex-wrap gap-2 mb-6 border-b border-slate-200 pb-2">
        {tabs.map((tab) => (
          <button key={tab} type="button" onClick={() => handleTab(tab)}
            className={`px-4 py-2 rounded-lg text-sm font-medium ${activeTab === tab ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-100'}`}>
            {tab}
          </button>
        ))}
      </div>

      {activeTab === 'Database Structure' && <DatabaseStructure />}

      {activeTab === 'Company' && (
        <form onSubmit={saveCompany} className="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl space-y-4">
          {['name', 'email', 'phone', 'address', 'gst_number'].map((f) => (
            <div key={f}>
              <label className="block text-sm font-medium text-slate-700 mb-1 capitalize">{f.replace('_', ' ')}</label>
              <input value={companyForm[f] || ''} onChange={(e) => setCompanyForm({ ...companyForm, [f]: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
          ))}
          <button type="submit" disabled={saving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
            {saving ? 'Saving...' : 'Save Company Settings'}
          </button>
        </form>
      )}

      {activeTab === 'Users & Roles' && (
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-semibold text-slate-800">Users</h3>
            <button onClick={() => setShowAddUser(true)} className="flex items-center gap-1 px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">
              <Plus className="w-4 h-4" /> Add User
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm divide-y divide-slate-200">
              <thead>
                <tr className="text-slate-500">
                  <th className="text-left py-2">Name</th>
                  <th className="text-left py-2">Email</th>
                  <th className="text-left py-2">Role</th>
                  <th className="text-left py-2">SMS</th>
                  <th className="text-left py-2">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.map((u) => (
                  <tr key={u.id}>
                    <td className="py-2">{u.name}</td>
                    <td className="py-2">{u.email}</td>
                    <td className="py-2">{roleBadge(u.role)}</td>
                    <td className="py-2">
                      {u.role !== 'customer' && (
                        <button type="button" onClick={() => toggleUserSms(u)}
                          className={`text-xs px-2 py-1 rounded-full ${u.sms_enabled !== false ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                          {u.sms_enabled !== false ? 'On' : 'Off'}
                        </button>
                      )}
                    </td>
                    <td className="py-2 capitalize">{u.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {showAddUser && (
            <form onSubmit={addUser} className="mt-6 border-t border-slate-200 pt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
              <input placeholder="Name" value={userForm.name} onChange={(e) => setUserForm({ ...userForm, name: e.target.value })} required className="border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <input placeholder="Email" type="email" value={userForm.email} onChange={(e) => setUserForm({ ...userForm, email: e.target.value })} required className="border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <select value={userForm.role} onChange={(e) => setUserForm({ ...userForm, role: e.target.value })} className="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                <option value="pm">Project Manager</option>
                <option value="contractor">Contractor</option>
              </select>
              <button type="submit" disabled={saving} className="bg-blue-600 text-white rounded-lg text-sm py-2 hover:bg-blue-700">Create User</button>
            </form>
          )}
        </div>
      )}

      {activeTab === 'Notifications' && (
        <form onSubmit={saveNotifications} className="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl space-y-4">
          <h3 className="font-semibold text-slate-800">Notification Channels</h3>
          <label className="flex items-center gap-3">
            <input type="checkbox" checked={notifForm.sms_globally_enabled} onChange={(e) => setNotifForm({ ...notifForm, sms_globally_enabled: e.target.checked })} className="rounded" />
            <span className="text-sm text-slate-700">SMS notifications enabled (global)</span>
          </label>
          <label className="flex items-center gap-3">
            <input type="checkbox" checked={notifForm.email_globally_enabled} onChange={(e) => setNotifForm({ ...notifForm, email_globally_enabled: e.target.checked })} className="rounded" />
            <span className="text-sm text-slate-700">Email notifications enabled (global)</span>
          </label>
          <p className="text-xs text-slate-500">Keep disabled until Twilio and SMTP credentials are configured in the server .env file.</p>
          <button type="submit" disabled={saving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
            {saving ? 'Saving...' : 'Save Notification Settings'}
          </button>
        </form>
      )}

      {activeTab === 'GST & Markup' && (
        <form onSubmit={savePricing} className="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl space-y-4">
          <h3 className="font-semibold text-slate-800">Pricing Formula</h3>
          <p className="text-sm text-slate-500">Customer subtotal = contractor price ÷ markup divisor (default 0.80 = 80/20 split)</p>
          <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Changes apply to all new quotes going forward. Existing quotes keep their original amounts.</p>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">GST Rate (%)</label>
            <input type="number" step="0.01" value={pricingForm.gst_rate} onChange={(e) => setPricingForm({ ...pricingForm, gst_rate: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Markup Divisor</label>
            <input type="number" step="0.01" value={pricingForm.markup_divisor} onChange={(e) => setPricingForm({ ...pricingForm, markup_divisor: e.target.value })}
              className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <button type="submit" disabled={saving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
            {saving ? 'Saving...' : 'Save Pricing Settings'}
          </button>
        </form>
      )}

      {activeTab === 'Payouts & Split' && (
        <form onSubmit={saveSplit} className="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl space-y-4">
          <h3 className="font-semibold text-slate-800">Default Payout Split (80/10/10)</h3>
          <p className="text-sm text-slate-500">Customer price = contractor price ÷ contractor %. PM and company shares are calculated from customer subtotal.</p>
          <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Changing these values only affects NEW jobs. Existing jobs keep their saved split.
          </p>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Contractor %</label>
              <input type="number" step="0.01" value={splitForm.split_contractor_pct}
                onChange={(e) => setSplitForm({ ...splitForm, split_contractor_pct: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">PM %</label>
              <input type="number" step="0.01" value={splitForm.split_pm_pct}
                onChange={(e) => setSplitForm({ ...splitForm, split_pm_pct: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Company %</label>
              <input type="number" step="0.01" value={splitForm.split_company_pct}
                onChange={(e) => setSplitForm({ ...splitForm, split_company_pct: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
          </div>
          {!splitValid && (
            <p className="text-sm text-red-600">Split must add up to 100. Current total: {splitTotal.toFixed(1)}</p>
          )}
          <button type="submit" disabled={saving || !splitValid} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
            {saving ? 'Saving...' : 'Save Split Settings'}
          </button>
        </form>
      )}

      {activeTab === 'Payment' && (
        <form onSubmit={savePayment} className="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl space-y-4">
          <h3 className="font-semibold text-slate-800">Payment Instructions</h3>
          <textarea value={paymentForm.payment_instructions} onChange={(e) => setPaymentForm({ payment_instructions: e.target.value })}
            rows={4} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
          <button type="submit" disabled={saving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
            {saving ? 'Saving...' : 'Save Payment Settings'}
          </button>
        </form>
      )}

      {activeTab === 'SMS Log' && (
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm divide-y divide-slate-200">
              <thead className="bg-slate-50">
                <tr>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">To</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Trigger</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Job</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Date</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {smsLogs.length === 0 ? (
                  <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">No SMS logs yet.</td></tr>
                ) : smsLogs.map((log) => (
                  <tr key={log.id} className="hover:bg-slate-50" title={log.error_message || ''}>
                    <td className="px-4 py-3">{log.to_phone}</td>
                    <td className="px-4 py-3">{log.trigger_event?.replace(/_/g, ' ')}</td>
                    <td className="px-4 py-3">
                      <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${smsStatusColor[log.status] || 'bg-slate-100'}`}>
                        {log.status}
                      </span>
                    </td>
                    <td className="px-4 py-3">{log.job?.address || log.related_job_id || '—'}</td>
                    <td className="px-4 py-3 whitespace-nowrap">{log.created_at?.split('T')[0] || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {activeTab === 'Email Log' && (
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm divide-y divide-slate-200">
              <thead className="bg-slate-50">
                <tr>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">To</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Trigger</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Status</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Job</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Date</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {emailLogs.length === 0 ? (
                  <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">No email logs yet.</td></tr>
                ) : emailLogs.map((log) => (
                  <tr key={log.id} className="hover:bg-slate-50" title={log.error_message || ''}>
                    <td className="px-4 py-3">{log.to_email}</td>
                    <td className="px-4 py-3">{log.trigger_event?.replace(/_/g, ' ')}</td>
                    <td className="px-4 py-3">
                      <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${log.status === 'sent' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                        {log.status}
                      </span>
                    </td>
                    <td className="px-4 py-3">{log.job?.address || log.related_job_id || '—'}</td>
                    <td className="px-4 py-3 whitespace-nowrap">{log.created_at?.split('T')[0] || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {activeTab === 'Branding' && (
        <div className="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl">
          <h3 className="font-semibold text-slate-800 mb-2">Branding</h3>
          <p className="text-sm text-slate-500">Primary color: {settings?.branding?.primary_color || '#3B82F6'}</p>
          <p className="text-sm text-slate-500 mt-1">Company name: {settings?.branding?.company_name || 'HSOP'}</p>
        </div>
      )}
    </div>
  );
}
