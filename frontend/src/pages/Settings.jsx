import { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Plus } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { formatDate } from '../utils/formatDate';
import AddUserModal from '../components/AddUserModal';
import DatabaseStructure from './DatabaseStructure';
import TestDataPanel from '../components/TestDataPanel';
import AiActivityLogViewer from '../components/AiActivityLogViewer';
import { confirmAction, confirmDanger, showError, showSuccess } from '../utils/swal';
import { useAuth } from '../context/AuthContext';
import Swal from 'sweetalert2';

const ALL_TABS = ['Company', 'Users & Roles', 'Lead Inbox', 'Workflow', 'Message Templates', 'AI Settings', 'AI Activity Log', 'Notifications', 'GST & Markup', 'Payouts & Split', 'Payment', 'SMS Log', 'Email Log', 'Branding', 'Database Structure', 'Test Data'];

const COMPANY_PUBLIC_FIELDS = [
  { key: 'operating_name', label: 'Operating name (internal label)' },
  { key: 'name', label: 'Legacy display name' },
  { key: 'email', label: 'Internal company email' },
  { key: 'phone', label: 'Internal company phone' },
  { key: 'address', label: 'Business address' },
  { key: 'province', label: 'Province' },
  { key: 'timezone', label: 'Timezone (e.g. America/Vancouver)' },
  { key: 'currency', label: 'Currency (CAD / USD)' },
  { key: 'invoice_prefix', label: 'Invoice prefix (e.g. ACU)' },
  { key: 'public_contact_email', label: 'Public contact email' },
  { key: 'public_contact_phone', label: 'Public contact phone' },
];

const COMPANY_SENSITIVE_FIELDS = [
  { key: 'legal_name', label: 'Legal name (tax / contracts)' },
  { key: 'gst_number', label: 'GST / HST number' },
  { key: 'gst_verification_status', label: 'GST verification status' },
  { key: 'remittance_address', label: 'Remittance address' },
];

const smsStatusColor = { sent: 'bg-green-100 text-green-700', failed: 'bg-red-100 text-red-700', disabled: 'bg-slate-100 text-slate-600' };

/**
 * A-06/A-22: Brand identity preview tab.
 * Shows read-only resolved values for each active brand.
 * Company name is managed via Brand Content — this tab is intentionally read-only.
 */
function BrandingTab() {
  const [preview, setPreview] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/brand-preview')
      .then(({ data }) => setPreview(data))
      .catch(() => setPreview(null))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-6 max-w-3xl">
      <div className="bg-amber-50 border border-amber-200 rounded-xl p-4">
        <p className="text-sm font-medium text-amber-800">⚠ This tab is read-only</p>
        <p className="text-sm text-amber-700 mt-1">
          Company name and branding are managed under <strong>Brand Content</strong>. Changing them there
          updates all customer-facing channels (quotes, invoices, SMS, email, portals, Stripe payments).
        </p>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 p-5">
        <h3 className="font-semibold text-slate-800 mb-1">Platform Identity</h3>
        <p className="text-sm text-slate-500 mb-3">
          The platform name is shown only on internal pages (admin portal, contractor/PM accounts, Stripe Connect).
          It is never shown to customers.
        </p>
        <div className="text-sm bg-slate-50 border border-slate-100 rounded-lg px-4 py-3 font-mono text-slate-700">
          {preview?.platform_name ?? 'ServiceOP'}
        </div>
      </div>

      {loading && <p className="text-slate-500 text-sm">Loading brand preview…</p>}

      {!loading && preview?.brands?.map((brand) => (
        <div key={brand.id} className="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="font-semibold text-slate-800">{brand.operating_name}</h3>
            <span className="text-xs text-slate-400 font-mono">{brand.domain}</span>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div>
              <p className="text-xs text-slate-400 uppercase tracking-wide mb-1">Invoice / Quote Header</p>
              <p className="text-slate-700 font-medium">{brand.invoice_name}</p>
            </div>
            <div>
              <p className="text-xs text-slate-400 uppercase tracking-wide mb-1">SMS / Email Sender</p>
              <p className="text-slate-700 font-medium">{brand.sender_name}</p>
            </div>
            <div>
              <p className="text-xs text-slate-400 uppercase tracking-wide mb-1">Portal Header</p>
              <p className="text-slate-700 font-medium">{brand.portal_brand}</p>
            </div>
            <div>
              <p className="text-xs text-slate-400 uppercase tracking-wide mb-1">Stripe Payment Descriptor</p>
              <p className="text-slate-700 font-medium font-mono">{brand.payment_descriptor}</p>
            </div>
            <div className="sm:col-span-2">
              <p className="text-xs text-slate-400 uppercase tracking-wide mb-1">Review Request Destination</p>
              <p className="text-slate-700 font-medium">{brand.review_destination}</p>
            </div>
          </div>
          <p className="text-xs text-slate-400 italic">{brand.platform_note}</p>
        </div>
      ))}

      {!loading && preview?.brands?.length === 0 && (
        <p className="text-sm text-slate-500">No active brands found. Add brands under Brand Content.</p>
      )}
    </div>
  );
}

export default function Settings() {
  const { user: authUser } = useAuth();
  const isDeveloper = Boolean(authUser?.is_developer);
  const tabs = ALL_TABS.filter((t) => t !== 'Database Structure' || isDeveloper);
  const [searchParams, setSearchParams] = useSearchParams();
  const tabParam = searchParams.get('tab');
  const [activeTab, setActiveTab] = useState(
    tabParam === 'database' && isDeveloper
      ? 'Database Structure'
      : tabParam === 'test-data'
        ? 'Test Data'
        : 'Company'
  );
  const [settings, setSettings] = useState(null);
  const [identityReadiness, setIdentityReadiness] = useState(null);
  const [companyForm, setCompanyForm] = useState({});
  const [companyBaseline, setCompanyBaseline] = useState({});
  const [notifForm, setNotifForm] = useState({ sms_globally_enabled: false, email_globally_enabled: false });
  const [channelHealth, setChannelHealth] = useState(null);
  const [channelTestBusy, setChannelTestBusy] = useState(null);
  const [smsLogFilters, setSmsLogFilters] = useState({ status: '', trigger_event: '' });
  const [emailLogFilters, setEmailLogFilters] = useState({ status: '', trigger_event: '' });
  const [smsMetrics, setSmsMetrics] = useState(null);
  const [emailMetrics, setEmailMetrics] = useState(null);
  const [templatePreview, setTemplatePreview] = useState({});
  const [templateVersions, setTemplateVersions] = useState({});
  const [pricingForm, setPricingForm] = useState({ gst_rate: '5', markup_divisor: '0.80' });
  const [splitForm, setSplitForm] = useState({ split_contractor_pct: '80', split_pm_pct: '10', split_company_pct: '10' });
  const [calcExamplePrice, setCalcExamplePrice] = useState('800');
  const [pricingPreview, setPricingPreview] = useState(null);
  const [paymentDestinations, setPaymentDestinations] = useState([]);
  const [paymentBrands, setPaymentBrands] = useState([]);
  const [paymentMode, setPaymentMode] = useState('TEST');
  const [paymentLegacy, setPaymentLegacy] = useState(null);
  const [destForm, setDestForm] = useState({
    brand_id: '',
    payment_method: 'e_transfer',
    destination_value: '',
    owner_override: false,
    override_reason: '',
  });
  const [smsLogs, setSmsLogs] = useState([]);
  const [emailLogs, setEmailLogs] = useState([]);
  const [users, setUsers] = useState([]);
  const [pmBrandAssignments, setPmBrandAssignments] = useState([]);
  const [assignableBrands, setAssignableBrands] = useState([]);
  const [brandEditUserId, setBrandEditUserId] = useState(null);
  const [brandEditIds, setBrandEditIds] = useState([]);
  const [brandSaving, setBrandSaving] = useState(false);
  const [showAddModal, setShowAddModal] = useState(false);
  const [roleFilter, setRoleFilter] = useState('all');
  const [saving, setSaving] = useState(false);
  const [aiSettings, setAiSettings] = useState(null);
  const [aiForm, setAiForm] = useState({
    ai_kill_switch: false,
    ai_simulation_mode: false,
    ai_daily_action_limit: 200,
    ai_daily_cost_usd_limit: 25,
    ai_conversation_retention_days: 365,
    module_modes: {},
  });
  const [aiSaving, setAiSaving] = useState(false);
  const [gmailStatus, setGmailStatus] = useState(null);
  const [gmailBusy, setGmailBusy] = useState(false);
  const [workflowThresholds, setWorkflowThresholds] = useState(null);
  const [workflowSaving, setWorkflowSaving] = useState(false);
  const [messageTemplates, setMessageTemplates] = useState([]);
  const [templateSavingId, setTemplateSavingId] = useState(null);
  const [opsReports, setOpsReports] = useState([]);
  const [opsReportBusy, setOpsReportBusy] = useState(false);

  const loadAdminUsers = () => {
    api.get('/admin/users').then(({ data }) => setUsers(data)).catch(() => setUsers([]));
    api.get('/admin/pm-brand-assignments').then(({ data }) => {
      setPmBrandAssignments(data.assignments || []);
      setAssignableBrands(data.brands || []);
    }).catch(() => {
      setPmBrandAssignments([]);
      setAssignableBrands([]);
    });
  };

  const openBrandEditor = (pmUser) => {
    const row = pmBrandAssignments.find((a) => a.user_id === pmUser.id);
    setBrandEditUserId(pmUser.id);
    setBrandEditIds([...(row?.brand_ids || [])]);
  };

  const toggleBrandId = (brandId) => {
    const id = Number(brandId);
    setBrandEditIds((prev) => (
      prev.map(Number).includes(id) ? prev.filter((x) => Number(x) !== id) : [...prev.map(Number), id]
    ));
  };

  const savePmBrands = async () => {
    if (!brandEditUserId) return;
    setBrandSaving(true);
    try {
      await api.put(`/admin/pm-brand-assignments/${brandEditUserId}`, { brand_ids: brandEditIds });
      await showSuccess('PM brand assignments updated.');
      setBrandEditUserId(null);
      loadAdminUsers();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to update brand assignments.');
    } finally {
      setBrandSaving(false);
    }
  };

  const loadSettings = () => {
    api.get('/settings').then(({ data }) => {
      setSettings(data);
      setCompanyForm(data.company || {});
      setCompanyBaseline(data.company || {});
      setIdentityReadiness(data.identity_readiness || null);
      setChannelHealth(data.channel_health || null);
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
      if (data.pricing_preview_example) setPricingPreview(data.pricing_preview_example);
      setPaymentLegacy({
        company_email: data.payment?.legacy_company_email || data.settings?.company_email || null,
        instructions: data.payment?.legacy_instructions || null,
      });
    }).catch(() => {});
  };

  const loadPaymentDestinations = () => {
    api.get('/payment-destinations')
      .then(({ data }) => {
        setPaymentDestinations(data.destinations || []);
        setPaymentBrands(data.brands || []);
        setPaymentMode(data.payment_mode || 'TEST');
        if ((data.brands || []).length && !destForm.brand_id) {
          setDestForm((f) => ({ ...f, brand_id: String(data.brands[0].id) }));
        }
      })
      .catch(() => {
        setPaymentDestinations([]);
        setPaymentBrands([]);
      });
  };

  useEffect(() => {
    loadSettings();
  }, []);

  useEffect(() => {
    if (activeTab === 'Payment') {
      loadPaymentDestinations();
    }
  }, [activeTab]);

  useEffect(() => {
    if (activeTab === 'Users & Roles') {
      loadAdminUsers();
    }
  }, [activeTab]);

  useEffect(() => {
    if (!['GST & Markup', 'Payouts & Split'].includes(activeTab)) return undefined;
    const t = setTimeout(() => {
      api.get('/settings/pricing-preview', {
        params: {
          contractor_price: calcExamplePrice || 800,
          gst_rate: pricingForm.gst_rate,
          split_contractor_pct: splitForm.split_contractor_pct,
          split_pm_pct: splitForm.split_pm_pct,
          split_company_pct: splitForm.split_company_pct,
        },
      }).then(({ data }) => setPricingPreview(data)).catch(() => setPricingPreview(null));
    }, 250);
    return () => clearTimeout(t);
  }, [activeTab, calcExamplePrice, pricingForm, splitForm]);

  useEffect(() => {
    if (activeTab === 'AI Settings') {
      api.get('/ai/settings').then(({ data }) => {
        setAiSettings(data);
        setAiForm({
          ai_kill_switch: data.ai_kill_switch ?? false,
          ai_simulation_mode: data.ai_simulation_mode ?? false,
          ai_daily_action_limit: data.ai_daily_action_limit ?? 200,
          ai_daily_cost_usd_limit: data.ai_daily_cost_usd_limit ?? 25,
          ai_conversation_retention_days: data.ai_conversation_retention_days ?? 365,
          module_modes: data.module_modes || {},
        });
      }).catch(() => setAiSettings(null));
      api.get('/ops-reports').then(({ data }) => setOpsReports(data.data || data || [])).catch(() => setOpsReports([]));
    }
  }, [activeTab]);

  const generateOpsReport = async () => {
    setOpsReportBusy(true);
    try {
      await api.post('/ops-reports/generate', { period: 'daily' });
      const { data } = await api.get('/ops-reports');
      setOpsReports(data.data || data || []);
      await showSuccess('Daily ops report generated');
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to generate report');
    } finally {
      setOpsReportBusy(false);
    }
  };
  useEffect(() => {
    if (activeTab === 'SMS Log') {
      const params = {};
      if (smsLogFilters.status) params.status = smsLogFilters.status;
      if (smsLogFilters.trigger_event) params.trigger_event = smsLogFilters.trigger_event;
      api.get('/sms-logs', { params }).then(({ data }) => {
        setSmsLogs(data.data?.data || data.data || data);
        setSmsMetrics(data.metrics || null);
      }).catch(() => setSmsLogs([]));
    }
    if (activeTab === 'Email Log') {
      const params = {};
      if (emailLogFilters.status) params.status = emailLogFilters.status;
      if (emailLogFilters.trigger_event) params.trigger_event = emailLogFilters.trigger_event;
      api.get('/email-logs', { params }).then(({ data }) => {
        setEmailLogs(data.data?.data || data.data || data);
        setEmailMetrics(data.metrics || null);
      }).catch(() => setEmailLogs([]));
    }
  }, [activeTab, smsLogFilters, emailLogFilters]);

  useEffect(() => {
    if (tabParam === 'database') setActiveTab('Database Structure');
    if (tabParam === 'test-data') setActiveTab('Test Data');
    if (tabParam === 'lead-inbox') setActiveTab('Lead Inbox');
    const gmail = searchParams.get('gmail');
    if (gmail === 'connected') {
      showSuccess(`Gmail connected${searchParams.get('mailbox') ? `: ${searchParams.get('mailbox')}` : ''}`);
      setActiveTab('Lead Inbox');
      setSearchParams({ tab: 'lead-inbox' });
    } else if (gmail === 'error') {
      showError(searchParams.get('reason') || 'Gmail connection failed');
      setActiveTab('Lead Inbox');
      setSearchParams({ tab: 'lead-inbox' });
    }
  }, [tabParam, searchParams]);

  useEffect(() => {
    if (activeTab === 'Lead Inbox') {
      api.get('/oauth/gmail/status').then(({ data }) => setGmailStatus(data)).catch(() => setGmailStatus(null));
    }
    if (activeTab === 'Workflow') {
      api.get('/workflow/thresholds').then(({ data }) => setWorkflowThresholds(data)).catch(() => setWorkflowThresholds(null));
    }
    if (activeTab === 'Message Templates') {
      api.get('/message-templates').then(({ data }) => setMessageTemplates(data || [])).catch(() => setMessageTemplates([]));
    }
  }, [activeTab]);

  const saveWorkflowThresholds = async (e) => {
    e.preventDefault();
    setWorkflowSaving(true);
    try {
      const { data } = await api.put('/workflow/thresholds', {
        pm_contact_lead_hours: workflowThresholds.pm_contact_lead_hours,
        pm_contact_escalation_hours: workflowThresholds.pm_contact_escalation_hours,
        contractor_pricing_deadline_hours: workflowThresholds.contractor_pricing_deadline_hours,
        quote_follow_up_hours: workflowThresholds.quote_follow_up_hours,
        job_missing_update_days: workflowThresholds.job_missing_update_days,
      });
      setWorkflowThresholds(data);
      await showSuccess('Workflow thresholds saved.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save thresholds.');
    } finally {
      setWorkflowSaving(false);
    }
  };

  const saveTemplate = async (tpl) => {
    setTemplateSavingId(tpl.id);
    try {
      const { data } = await api.put(`/message-templates/${tpl.id}`, {
        body: tpl.body,
        label: tpl.label,
        is_active: tpl.is_active,
      });
      setMessageTemplates((prev) => prev.map((t) => (t.id === data.id ? { ...t, ...data } : t)));
      await showSuccess('Template saved.');
      refreshTemplateMeta(data.id);
    } catch (err) {
      const unresolved = err.response?.data?.errors?.unresolved;
      await showError(
        unresolved
          ? `Unresolved placeholders: {{${(unresolved || []).join('}}, {{')}}}`
          : (err.response?.data?.errors?.body?.[0] || err.response?.data?.message || 'Failed to save template.')
      );
    } finally {
      setTemplateSavingId(null);
    }
  };

  const refreshTemplateMeta = async (id) => {
    try {
      const [{ data: preview }, { data: versions }] = await Promise.all([
        api.post(`/message-templates/${id}/preview`, {}),
        api.get(`/message-templates/${id}/versions`),
      ]);
      setTemplatePreview((p) => ({ ...p, [id]: preview }));
      setTemplateVersions((p) => ({ ...p, [id]: versions.versions || [] }));
    } catch {
      // ignore
    }
  };

  const previewTemplate = async (tpl) => {
    try {
      const { data } = await api.post(`/message-templates/${tpl.id}/preview`, { body: tpl.body });
      setTemplatePreview((p) => ({ ...p, [tpl.id]: data }));
    } catch (err) {
      await showError(err.response?.data?.message || 'Preview failed.');
    }
  };

  const testSendTemplate = async (tpl) => {
    const ok = await confirmAction({
      title: 'Send test to yourself?',
      text: 'Uses BrandResolver sample brand name and your owner phone/email.',
      confirmText: 'Send test',
    });
    if (!ok) return;
    try {
      const { data } = await api.post(`/message-templates/${tpl.id}/test-send`, { body: tpl.body });
      await showSuccess(
        data.provider_response?.success
          ? `Test sent via ${data.channel}. Brand: ${data.brand_name}`
          : `Test attempted: ${data.provider_response?.plain || data.provider_response?.reason || 'see response'}`
      );
    } catch (err) {
      await showError(err.response?.data?.errors?.body?.[0] || err.response?.data?.message || 'Test send failed.');
    }
  };

  const restoreTemplateVersion = async (tpl, versionId) => {
    const ok = await confirmAction({ title: 'Restore this version?', confirmText: 'Restore' });
    if (!ok) return;
    try {
      const { data } = await api.post(`/message-templates/${tpl.id}/versions/${versionId}/restore`);
      setMessageTemplates((prev) => prev.map((t) => (t.id === data.id ? { ...t, ...data } : t)));
      await showSuccess('Version restored.');
      refreshTemplateMeta(tpl.id);
    } catch (err) {
      await showError(err.response?.data?.message || 'Restore failed.');
    }
  };

  const runChannelTest = async (channel) => {
    setChannelTestBusy(channel);
    try {
      const { data } = await api.post(`/notification-channels/test-${channel}`);
      setChannelHealth((h) => ({ ...h, [channel]: data.health }));
      await showSuccess(
        data.provider_response?.success
          ? `Test ${channel.toUpperCase()} succeeded.`
          : `Test ${channel.toUpperCase()}: ${data.provider_response?.plain || data.provider_response?.blocking_error || data.provider_response?.reason || 'failed'}`
      );
    } catch (err) {
      await showError(err.response?.data?.message || `Test ${channel} failed.`);
    } finally {
      setChannelTestBusy(null);
    }
  };

  const retrySmsLog = async (log) => {
    const ok = await confirmAction({ title: 'Retry this SMS?', text: log.correction_path || 'Idempotent retry — will not duplicate a prior success.', confirmText: 'Retry' });
    if (!ok) return;
    try {
      const { data } = await api.post(`/sms-logs/${log.id}/retry`, {});
      await showSuccess(data.deduplicated ? 'Already retried successfully (no duplicate).' : (data.success ? 'Retry sent.' : (data.provider_response?.plain || 'Retry failed.')));
      setActiveTab('SMS Log');
    } catch (err) {
      await showError(err.response?.data?.errors?.phone?.[0] || err.response?.data?.message || 'Retry failed.');
    }
  };

  const retryEmailLog = async (log) => {
    const ok = await confirmAction({ title: 'Retry this email?', text: log.correction_path || 'Idempotent retry.', confirmText: 'Retry' });
    if (!ok) return;
    try {
      const { data } = await api.post(`/email-logs/${log.id}/retry`, {});
      await showSuccess(data.deduplicated ? 'Already retried successfully (no duplicate).' : (data.success ? 'Retry sent.' : (data.provider_response?.plain || 'Retry failed.')));
    } catch (err) {
      await showError(err.response?.data?.message || 'Retry failed.');
    }
  };

  const handleTab = (tab) => {
    setActiveTab(tab);
    if (tab === 'Database Structure') setSearchParams({ tab: 'database' });
    else if (tab === 'Test Data') setSearchParams({ tab: 'test-data' });
    else if (tab === 'Lead Inbox') setSearchParams({ tab: 'lead-inbox' });
    else if (tab === 'Workflow') setSearchParams({ tab: 'workflow' });
    else if (tab === 'Message Templates') setSearchParams({ tab: 'message-templates' });
    else setSearchParams({});
  };

  const connectGmail = async () => {
    setGmailBusy(true);
    try {
      const { data } = await api.get('/oauth/gmail/initiate');
      if (data.auth_url) {
        window.location.href = data.auth_url;
        return;
      }
      await showError('No auth URL returned.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to start Gmail OAuth.');
    } finally {
      setGmailBusy(false);
    }
  };

  const disconnectGmail = async () => {
    const ok = await confirmDanger({
      title: 'Disconnect Gmail?',
      text: 'Lead email polling will stop until you reconnect.',
      confirmText: 'Disconnect',
    });
    if (!ok) return;
    setGmailBusy(true);
    try {
      await api.post('/oauth/gmail/disconnect');
      await showSuccess('Gmail disconnected.');
      const { data } = await api.get('/oauth/gmail/status');
      setGmailStatus(data);
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to disconnect.');
    } finally {
      setGmailBusy(false);
    }
  };

  const fetchGmailNow = async () => {
    setGmailBusy(true);
    try {
      const { data } = await api.post('/oauth/gmail/fetch-now');
      const s = data.stats || {};
      await showSuccess(`Fetched ${s.fetched ?? 0}, processed ${s.processed ?? 0}, skipped ${s.skipped ?? 0}, failed ${s.failed ?? 0}`);
      const status = await api.get('/oauth/gmail/status');
      setGmailStatus(status.data);
    } catch (err) {
      await showError(err.response?.data?.message || 'Fetch failed.');
    } finally {
      setGmailBusy(false);
    }
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

  const saveCompany = async (e) => {
    e.preventDefault();
    const sensitiveKeys = COMPANY_SENSITIVE_FIELDS.map((f) => f.key);
    const touchingSensitive = sensitiveKeys.some(
      (k) => String(companyForm[k] ?? '') !== String(companyBaseline[k] ?? '')
    );

    const payload = { ...companyForm };
    if (touchingSensitive) {
      const ok = await confirmAction({
        title: 'Confirm tax / remittance change?',
        text: 'Legal name, GST number, and remittance address are higher-risk identity fields. You must re-enter your password.',
        confirmText: 'Continue',
      });
      if (!ok) return;
      const { value: password } = await Swal.fire({
        title: 'Re-enter password',
        input: 'password',
        inputPlaceholder: 'Your password',
        showCancelButton: true,
        confirmButtonText: 'Confirm change',
        confirmButtonColor: '#2563eb',
      });
      if (!password) return;
      payload.confirm_sensitive_change = true;
      payload.current_password = password;
    }

    setSaving(true);
    try {
      const { data } = await api.post('/settings', payload);
      setIdentityReadiness(data.identity_readiness || null);
      await showSuccess('Company identity saved.');
      loadSettings();
    } catch (err) {
      const errors = err.response?.data?.errors;
      await showError(
        errors?.current_password?.[0]
        || errors?.confirm_sensitive_change?.[0]
        || errors?.gst_number?.[0]
        || errors?.public_contact_email?.[0]
        || err.response?.data?.message
        || 'Failed to save company identity.'
      );
    } finally {
      setSaving(false);
    }
  };

  const suspendUser = async (userId) => {
    const ok = await confirmDanger({
      title: 'Suspend account?',
      text: 'This immediately revokes their active sessions and API tokens.',
      confirmText: 'Yes, suspend',
    });
    if (!ok) return;
    try {
      await api.post(`/admin/users/${userId}/suspend`);
      await showSuccess('Account suspended; sessions revoked.');
      loadAdminUsers();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to suspend account');
    }
  };

  const reactivateUser = async (userId) => {
    const ok = await confirmAction({
      title: 'Reactivate account?',
      text: 'This user will be able to log in again.',
      confirmText: 'Yes, reactivate',
    });
    if (!ok) return;
    try {
      await api.post(`/admin/users/${userId}/reactivate`);
      await showSuccess('Account reactivated.');
      loadAdminUsers();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to reactivate');
    }
  };

  const resendInvite = async (userId) => {
    const ok = await confirmAction({
      title: 'Resend invite?',
      text: 'Generates a new temporary password and revokes existing sessions.',
      confirmText: 'Yes, resend',
    });
    if (!ok) return;
    try {
      const { data } = await api.post(`/admin/users/${userId}/resend-invite`);
      await showSuccess(data.password ? `Invite resent. Temp password: ${data.password}` : 'Invite resent.');
      loadAdminUsers();
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to resend invite');
    }
  };

  const saveNotifications = (e) => {
    e.preventDefault();
    saveSettings(notifForm, 'Notification settings saved.');
  };

  const savePricing = async (e) => {
    e.preventDefault();
    const ok = await confirmAction({
      title: 'Apply GST / markup changes?',
      text: 'These defaults only affect FUTURE quotes and jobs. Existing jobs keep their saved split unless an authorized override is recorded.',
      confirmText: 'Yes, update future pricing',
    });
    if (!ok) return;
    setSaving(true);
    try {
      const payload = {
        ...pricingForm,
        ...splitForm,
        confirm_pricing_change: true,
        example_contractor_price: calcExamplePrice,
      };
      await api.post('/settings', payload);
      await showSuccess('GST and markup settings saved.');
      loadSettings();
    } catch (err) {
      await showError(err.response?.data?.message || err.response?.data?.errors?.split?.[0] || 'Failed to save settings.');
    } finally {
      setSaving(false);
    }
  };

  const splitTotal = parseFloat(splitForm.split_contractor_pct || 0) + parseFloat(splitForm.split_pm_pct || 0) + parseFloat(splitForm.split_company_pct || 0);
  const splitValid = Math.abs(splitTotal - 100) < 0.01;

  const saveSplit = async (e) => {
    e.preventDefault();
    if (!splitValid) return;
    const ok = await confirmAction({
      title: 'Apply payout split changes?',
      text: 'These defaults only affect FUTURE jobs. Existing jobs keep their original contractor/PM/company split.',
      confirmText: 'Yes, update future splits',
    });
    if (!ok) return;
    setSaving(true);
    try {
      const payload = {
        ...pricingForm,
        ...splitForm,
        confirm_pricing_change: true,
        example_contractor_price: calcExamplePrice,
      };
      // Keep markup divisor in sync with contractor %
      payload.markup_divisor = (parseFloat(splitForm.split_contractor_pct || 80) / 100).toFixed(4);
      await api.post('/settings', payload);
      await showSuccess('Payout split settings saved.');
      loadSettings();
    } catch (err) {
      await showError(err.response?.data?.message || err.response?.data?.errors?.split?.[0] || 'Failed to save settings.');
    } finally {
      setSaving(false);
    }
  };

  const savePayment = async (e) => {
    e.preventDefault();
    if (!destForm.brand_id) {
      await showError('Select a brand.');
      return;
    }
    const payload = {
      brand_id: Number(destForm.brand_id),
      payment_method: destForm.payment_method,
      destination_value: destForm.payment_method === 'stripe' ? 'platform' : destForm.destination_value,
      is_verified: true,
      is_active: true,
      owner_override: destForm.owner_override,
      override_reason: destForm.override_reason || undefined,
    };

    const existing = paymentDestinations.find(
      (d) => String(d.brand_id) === String(destForm.brand_id) && d.payment_method === destForm.payment_method
    );
    const changingLive = existing?.customer_ready
      && String(existing.destination_value || '') !== String(payload.destination_value || '');

    if (changingLive) {
      const ok = await confirmAction({
        title: 'Change live payment destination?',
        text: `Current: ${existing.destination_value || '(empty)'} → New: ${payload.destination_value || '(empty)'}. This affects customer invoices and portals.`,
        confirmText: 'Yes, change live destination',
      });
      if (!ok) return;
      payload.confirm_live_change = true;
      payload.reason = destForm.override_reason || 'Owner confirmed live destination change';
    }

    setSaving(true);
    try {
      if (existing?.id) {
        await api.put(`/payment-destinations/${existing.id}`, payload);
      } else {
        await api.post('/payment-destinations', payload);
      }
      await showSuccess('Payment destination saved.');
      setDestForm((f) => ({ ...f, destination_value: '', owner_override: false, override_reason: '' }));
      loadPaymentDestinations();
    } catch (err) {
      const errors = err.response?.data?.errors;
      const needsOverride = errors?.requires_owner_override || String(err.response?.data?.message || '').includes('contractor');
      if (needsOverride && !destForm.owner_override) {
        await showError(errors?.destination_value?.[0] || err.response?.data?.message || 'Blocked: contractor email.');
        setDestForm((f) => ({ ...f, owner_override: true }));
      } else {
        await showError(errors?.destination_value?.[0] || errors?.override_reason?.[0] || errors?.confirm_live_change?.[0] || err.response?.data?.message || 'Failed to save.');
      }
    } finally {
      setSaving(false);
    }
  };

  const saveAiSettings = async (e) => {
    e.preventDefault();
    const ok = await confirmAction({ title: 'Save AI settings?', text: 'Update AI kill switch, retention, and module modes?', confirmText: 'Yes, save' });
    if (!ok) return;
    setAiSaving(true);
    try {
      await api.put('/ai/settings', aiForm);
      await showSuccess('AI settings saved.');
      api.get('/ai/settings').then(({ data }) => {
        setAiSettings(data);
        setAiForm({
          ai_kill_switch: data.ai_kill_switch ?? false,
          ai_simulation_mode: data.ai_simulation_mode ?? false,
          ai_daily_action_limit: data.ai_daily_action_limit ?? 200,
          ai_daily_cost_usd_limit: data.ai_daily_cost_usd_limit ?? 25,
          ai_conversation_retention_days: data.ai_conversation_retention_days ?? 365,
          module_modes: data.module_modes || {},
        });
      });
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to save AI settings.');
    } finally {
      setAiSaving(false);
    }
  };

  const createTestAiLog = async () => {
    try {
      await api.post('/ai/action-logs/test', {
        trigger_event: 'phase1_verification',
        action_taken: 'test_action',
        decision: 'Manual test entry from Settings UI.',
      });
      await showSuccess('Test AI action log created.');
      api.get('/ai/settings').then(({ data }) => setAiSettings(data));
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to create test log.');
    }
  };

  const filteredUsers = users.filter((u) => roleFilter === 'all' || u.role === roleFilter);

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

      {activeTab === 'Database Structure' && isDeveloper && <DatabaseStructure />}
      {activeTab === 'Test Data' && <TestDataPanel />}

      {activeTab === 'Company' && (
        <div className="space-y-4 max-w-3xl">
          {identityReadiness?.blocking && (
            <div className="bg-amber-50 border border-amber-200 rounded-xl p-4">
              <p className="text-sm font-medium text-amber-900">Production identity incomplete</p>
              <p className="text-sm text-amber-800 mt-1">
                Missing: {(identityReadiness.missing || []).join(', ')}. This is a readiness flag — it does not block the app.
              </p>
            </div>
          )}
          <div className="flex flex-wrap gap-2 text-xs">
            <span className={`px-2 py-1 rounded-full font-medium ${
              (identityReadiness?.environment || '').toLowerCase() === 'production'
                ? 'bg-emerald-100 text-emerald-800'
                : 'bg-amber-100 text-amber-800'
            }`}
            >
              Env: {(identityReadiness?.environment || 'unknown').toUpperCase()}
            </span>
            <span className={`px-2 py-1 rounded-full font-medium ${
              identityReadiness?.is_test_data ? 'bg-violet-100 text-violet-800' : 'bg-slate-100 text-slate-700'
            }`}
            >
              {identityReadiness?.is_test_data ? 'Company row: TEST DATA' : 'Company row: PRODUCTION'}
            </span>
            <span className={`px-2 py-1 rounded-full font-medium ${
              identityReadiness?.complete ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
            }`}
            >
              {identityReadiness?.complete ? 'Identity ready' : 'Identity incomplete'}
            </span>
          </div>

          <form onSubmit={saveCompany} className="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
            <div>
              <h3 className="font-semibold text-slate-800">Public &amp; operating identity</h3>
              <p className="text-xs text-slate-500 mt-1">
                Customer-facing brand name stays in Brand Content. These fields are legal entity / remittance / public contact.
              </p>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                {COMPANY_PUBLIC_FIELDS.map((f) => (
                  <div key={f.key} className={f.key === 'address' ? 'sm:col-span-2' : ''}>
                    <label className="block text-sm font-medium text-slate-700 mb-1">{f.label}</label>
                    <input
                      value={companyForm[f.key] || ''}
                      onChange={(e) => setCompanyForm({ ...companyForm, [f.key]: e.target.value })}
                      className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                    />
                  </div>
                ))}
              </div>
            </div>

            <div className="border-t border-slate-100 pt-4">
              <h3 className="font-semibold text-slate-800">Tax identity &amp; remittance</h3>
              <p className="text-xs text-amber-700 mt-1">Changes here require password re-confirmation and are audited.</p>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                {COMPANY_SENSITIVE_FIELDS.map((f) => (
                  <div key={f.key} className={f.key === 'remittance_address' ? 'sm:col-span-2' : ''}>
                    <label className="block text-sm font-medium text-slate-700 mb-1">{f.label}</label>
                    {f.key === 'gst_verification_status' ? (
                      <select
                        value={companyForm[f.key] || 'unverified'}
                        onChange={(e) => setCompanyForm({ ...companyForm, [f.key]: e.target.value })}
                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                      >
                        <option value="unverified">unverified</option>
                        <option value="pending">pending</option>
                        <option value="verified">verified</option>
                        <option value="failed">failed</option>
                      </select>
                    ) : (
                      <input
                        value={companyForm[f.key] || ''}
                        onChange={(e) => setCompanyForm({ ...companyForm, [f.key]: e.target.value })}
                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                      />
                    )}
                  </div>
                ))}
              </div>
            </div>

            <button type="submit" disabled={saving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
              {saving ? 'Saving...' : 'Save Company Identity'}
            </button>
          </form>
        </div>
      )}

      {activeTab === 'Users & Roles' && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex gap-2 flex-wrap">
              {['all', 'pm', 'contractor', 'owner'].map((role) => (
                <button
                  key={role}
                  type="button"
                  onClick={() => setRoleFilter(role)}
                  className={`px-3 py-1.5 rounded-lg text-sm font-medium ${
                    roleFilter === role ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600'
                  }`}
                >
                  {role === 'all' ? 'All' : role === 'pm' ? 'Project Managers' : role === 'contractor' ? 'Contractors' : 'Owners'}
                </button>
              ))}
            </div>
            <button
              type="button"
              onClick={() => setShowAddModal(true)}
              className="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium flex items-center gap-2 hover:bg-blue-700"
            >
              <Plus className="w-4 h-4" />
              Invite User
            </button>
          </div>

          <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[960px] text-sm">
                <thead className="bg-slate-50 border-b border-slate-200">
                  <tr>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">Name</th>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">Role</th>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">Brand scope</th>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">Invite</th>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">Last active</th>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">Status</th>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">2FA</th>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">Linked</th>
                    <th className="px-3 py-3 text-left font-medium text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {filteredUsers.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-slate-500">No users found.</td>
                    </tr>
                  ) : filteredUsers.map((user) => {
                    const scope = user.brand_scope?.length
                      ? user.brand_scope.map((b) => b.company_name).filter(Boolean).join(', ')
                      : (user.role === 'pm'
                        ? (() => {
                          const row = pmBrandAssignments.find((a) => a.user_id === user.id);
                          const names = (row?.brands || []).map((b) => b.company_name);
                          return names.length ? names.join(', ') : 'No brands (no access)';
                        })()
                        : '—');
                    const linked = [];
                    if (user.linked_profiles?.contractor?.id) linked.push(`Contractor #${user.linked_profiles.contractor.id}`);
                    if (user.linked_profiles?.stripe_account_id) linked.push('Stripe Connect');
                    if (user.is_developer) linked.push('Developer');
                    return (
                      <tr key={user.id} className="hover:bg-slate-50">
                        <td className="px-3 py-3">
                          <div className="font-medium text-slate-800">{user.name}</div>
                          <div className="text-xs text-slate-500">{user.email}</div>
                        </td>
                        <td className="px-3 py-3">
                          <span className="text-xs px-2 py-1 rounded-full font-medium bg-slate-100 text-slate-700">
                            {user.role}
                          </span>
                        </td>
                        <td className="px-3 py-3 text-xs text-slate-600 max-w-[160px]">
                          {user.role === 'pm' ? (
                            <button type="button" onClick={() => openBrandEditor(user)} className="text-left text-blue-600 hover:text-blue-800">
                              {scope}
                            </button>
                          ) : scope}
                        </td>
                        <td className="px-3 py-3 text-xs text-slate-600">{user.invitation_status || '—'}</td>
                        <td className="px-3 py-3 text-xs text-slate-600">
                          {user.last_active_at || user.last_login_at
                            ? formatDate(user.last_active_at || user.last_login_at)
                            : 'Never'}
                        </td>
                        <td className="px-3 py-3">
                          <span className={`text-xs px-2 py-1 rounded-full ${
                            user.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                          }`}
                          >
                            {user.account_status || user.status}
                          </span>
                        </td>
                        <td className="px-3 py-3 text-xs text-slate-500">{user.two_factor_status || 'not_yet_implemented'}</td>
                        <td className="px-3 py-3 text-xs text-slate-600">{linked.length ? linked.join(' · ') : '—'}</td>
                        <td className="px-3 py-3">
                          <div className="flex flex-col gap-1 items-start">
                            {user.role !== 'owner' && (
                              <>
                                <button type="button" onClick={() => resendInvite(user.id)} className="text-blue-600 hover:text-blue-800 text-xs">
                                  Resend invite
                                </button>
                                {user.status === 'active' ? (
                                  <button type="button" onClick={() => suspendUser(user.id)} className="text-red-500 hover:text-red-700 text-xs">
                                    Suspend
                                  </button>
                                ) : (
                                  <button type="button" onClick={() => reactivateUser(user.id)} className="text-green-600 hover:text-green-800 text-xs">
                                    Reactivate
                                  </button>
                                )}
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>

          {brandEditUserId && (
            <div className="bg-white rounded-xl border border-slate-200 p-4 max-w-xl space-y-3">
              <h3 className="font-semibold text-slate-800">Assign brands</h3>
              <p className="text-xs text-slate-500">Empty selection = no brand access for this PM.</p>
              <div className="space-y-2 max-h-64 overflow-y-auto">
                {assignableBrands.map((b) => (
                  <label key={b.id} className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      checked={brandEditIds.map(Number).includes(Number(b.id))}
                      onChange={() => toggleBrandId(b.id)}
                      className="rounded"
                    />
                    {b.company_name}
                  </label>
                ))}
                {assignableBrands.length === 0 && (
                  <p className="text-sm text-slate-500">No active brands found.</p>
                )}
              </div>
              <div className="flex gap-2">
                <button
                  type="button"
                  disabled={brandSaving}
                  onClick={savePmBrands}
                  className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg disabled:opacity-60"
                >
                  {brandSaving ? 'Saving…' : 'Save brands'}
                </button>
                <button
                  type="button"
                  onClick={() => setBrandEditUserId(null)}
                  className="px-4 py-2 border border-slate-200 text-sm rounded-lg"
                >
                  Cancel
                </button>
              </div>
            </div>
          )}

          {showAddModal && (
            <AddUserModal
              onClose={() => setShowAddModal(false)}
              onSuccess={loadAdminUsers}
            />
          )}
        </div>
      )}

      {activeTab === 'Notifications' && (
        <div className="space-y-4 max-w-3xl">
          {(channelHealth?.sms?.blocking_error || channelHealth?.email?.blocking_error) && (
            <div className="bg-red-50 border border-red-200 rounded-xl p-4">
              <p className="text-sm font-medium text-red-900">Enabled-but-unavailable channel</p>
              {channelHealth?.sms?.blocking_error && <p className="text-sm text-red-800 mt-1">{channelHealth.sms.blocking_error}</p>}
              {channelHealth?.email?.blocking_error && <p className="text-sm text-red-800 mt-1">{channelHealth.email.blocking_error}</p>}
            </div>
          )}

          <form onSubmit={saveNotifications} className="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
            <h3 className="font-semibold text-slate-800">Desired policy (owner intent)</h3>
            <label className="flex items-center gap-3">
              <input type="checkbox" checked={notifForm.sms_globally_enabled} onChange={(e) => setNotifForm({ ...notifForm, sms_globally_enabled: e.target.checked })} className="rounded" />
              <span className="text-sm text-slate-700">SMS policy enabled</span>
            </label>
            <label className="flex items-center gap-3">
              <input type="checkbox" checked={notifForm.email_globally_enabled} onChange={(e) => setNotifForm({ ...notifForm, email_globally_enabled: e.target.checked })} className="rounded" />
              <span className="text-sm text-slate-700">Email policy enabled</span>
            </label>
            <p className="text-xs text-slate-500">Policy is separate from provider readiness. Turning policy on without Twilio/mail credentials shows a blocking error — sends will not fail silently.</p>
            <button type="submit" disabled={saving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
              {saving ? 'Saving...' : 'Save policy'}
            </button>
          </form>

          {['sms', 'email'].map((ch) => {
            const h = channelHealth?.[ch];
            if (!h) return null;
            return (
              <div key={ch} className="bg-white rounded-xl border border-slate-200 p-5 space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <h3 className="font-semibold text-slate-800 uppercase text-sm">{ch} provider</h3>
                  <span className={`text-xs px-2 py-1 rounded-full ${h.provider_ready ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'}`}>
                    {h.connection_status}
                  </span>
                </div>
                <p className="text-sm text-slate-600">Policy: {h.policy_enabled ? 'ON' : 'OFF'} · Ready: {h.provider_ready ? 'yes' : 'no'}</p>
                <p className="text-xs text-slate-500">Verified sender: {h.verified_sender || '—'}</p>
                <p className="text-xs text-slate-500">Last success: {h.last_successful_send_at || '—'} · Delivery rate 30d: {h.delivery_rate_30d_pct != null ? `${h.delivery_rate_30d_pct}%` : '—'}</p>
                {h.last_error && <p className="text-xs text-red-600">Last error: {h.last_error.plain}</p>}
                {h.blocking_error && <p className="text-sm text-red-700 font-medium">{h.blocking_error}</p>}
                <button
                  type="button"
                  disabled={channelTestBusy === ch}
                  onClick={() => runChannelTest(ch)}
                  className="text-sm px-3 py-1.5 bg-slate-800 text-white rounded-lg disabled:opacity-60"
                >
                  {channelTestBusy === ch ? 'Testing…' : `Test ${ch.toUpperCase()}`}
                </button>
              </div>
            );
          })}
        </div>
      )}

      {activeTab === 'GST & Markup' && (
        <form onSubmit={savePricing} className="bg-white rounded-xl border border-slate-200 p-6 max-w-3xl space-y-4">
          <h3 className="font-semibold text-slate-800">Pricing Formula</h3>
          <p className="text-sm text-slate-500">
            Customer subtotal = contractor price ÷ contractor share (markup divisor). GST is tax — labelled separately from company margin.
          </p>
          <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Changes apply to future quotes/jobs only. Existing jobs keep their saved split.
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">GST Rate (%)</label>
              <input type="number" step="0.01" value={pricingForm.gst_rate} onChange={(e) => setPricingForm({ ...pricingForm, gst_rate: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Markup Divisor (synced from contractor %)</label>
              <input type="number" step="0.01" value={(parseFloat(splitForm.split_contractor_pct || 80) / 100).toFixed(2)} readOnly
                className="w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 text-sm" />
            </div>
          </div>

          <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-2">
            <div className="flex flex-wrap items-end gap-3 justify-between">
              <h4 className="font-medium text-slate-800 text-sm">Live calculator</h4>
              <label className="text-xs text-slate-600">
                Example contractor price
                <input type="number" step="0.01" value={calcExamplePrice}
                  onChange={(e) => setCalcExamplePrice(e.target.value)}
                  className="ml-2 border border-slate-300 rounded px-2 py-1 w-28" />
              </label>
            </div>
            {pricingPreview ? (
              <dl className="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm">
                <div><dt className="text-slate-500 text-xs">Customer subtotal</dt><dd className="font-medium">${Number(pricingPreview.customer_subtotal).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">{pricingPreview.gst_label || 'GST'}</dt><dd className="font-medium">${Number(pricingPreview.gst).toFixed(2)} ({pricingPreview.gst_rate}%)</dd></div>
                <div><dt className="text-slate-500 text-xs">Customer total</dt><dd className="font-medium">${Number(pricingPreview.customer_total).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">Contractor share</dt><dd>${Number(pricingPreview.contractor_share).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">PM share</dt><dd>${Number(pricingPreview.pm_share).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">Company margin</dt><dd>${Number(pricingPreview.company_share).toFixed(2)}</dd></div>
              </dl>
            ) : (
              <p className="text-xs text-slate-500">Enter valid splits totaling 100% to preview.</p>
            )}
          </div>

          <button type="submit" disabled={saving || !splitValid} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
            {saving ? 'Saving...' : 'Save Pricing Settings'}
          </button>
        </form>
      )}

      {activeTab === 'Payouts & Split' && (
        <form onSubmit={saveSplit} className="bg-white rounded-xl border border-slate-200 p-6 max-w-3xl space-y-4">
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

          <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-2">
            <div className="flex flex-wrap items-end gap-3 justify-between">
              <h4 className="font-medium text-slate-800 text-sm">Live calculator ($800 example)</h4>
              <label className="text-xs text-slate-600">
                Contractor price
                <input type="number" step="0.01" value={calcExamplePrice}
                  onChange={(e) => setCalcExamplePrice(e.target.value)}
                  className="ml-2 border border-slate-300 rounded px-2 py-1 w-28" />
              </label>
            </div>
            {pricingPreview && splitValid ? (
              <dl className="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm">
                <div><dt className="text-slate-500 text-xs">Subtotal</dt><dd className="font-medium">${Number(pricingPreview.customer_subtotal).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">GST (tax)</dt><dd>${Number(pricingPreview.gst).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">Total</dt><dd className="font-medium">${Number(pricingPreview.customer_total).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">Contractor</dt><dd>${Number(pricingPreview.contractor_share).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">PM</dt><dd>${Number(pricingPreview.pm_share).toFixed(2)}</dd></div>
                <div><dt className="text-slate-500 text-xs">Company</dt><dd>${Number(pricingPreview.company_share).toFixed(2)}</dd></div>
              </dl>
            ) : null}
          </div>

          <button type="submit" disabled={saving || !splitValid} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
            {saving ? 'Saving...' : 'Save Split Settings'}
          </button>
        </form>
      )}

      {activeTab === 'Payment' && (
        <div className="space-y-4 max-w-3xl">
          <div className={`rounded-xl border p-4 flex flex-wrap items-center justify-between gap-2 ${
            paymentMode === 'LIVE'
              ? 'bg-slate-800 text-white border-slate-700'
              : 'bg-amber-100 border-amber-400 text-amber-950'
          }`}>
            <div>
              <p className="text-xs font-bold tracking-wider uppercase">Customer payment mode</p>
              <p className="text-lg font-semibold">{paymentMode === 'LIVE' ? 'LIVE — real money' : 'TEST — not live charges'}</p>
            </div>
            <span className={`px-3 py-1 rounded text-xs font-bold tracking-wider ${
              paymentMode === 'LIVE' ? 'bg-green-500 text-white' : 'bg-amber-500 text-amber-950 animate-pulse'
            }`}>
              {paymentMode}
            </span>
          </div>

          <div className="bg-white rounded-xl border border-slate-200 p-4 text-sm text-slate-600">
            <p className="font-medium text-slate-800 mb-1">Customer payment destinations (not contractor payouts)</p>
            <p>
              Stripe Connect / contractor payout details are managed on contractor profiles.
              This screen only controls where <em>customers</em> send money (platform Stripe or company e-transfer).
            </p>
            {paymentLegacy && (paymentLegacy.company_email || paymentLegacy.instructions) && (
              <div className="mt-3 rounded-lg bg-slate-50 border border-slate-200 p-3 text-xs">
                <p className="font-semibold text-slate-700">Legacy settings (read-only audit history — not shown to customers)</p>
                <p>company_email: {paymentLegacy.company_email || '—'}</p>
                <p>payment_instructions: {paymentLegacy.instructions || '—'}</p>
              </div>
            )}
          </div>

          <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-slate-500">
                <tr>
                  <th className="text-left px-4 py-2 font-medium">Brand</th>
                  <th className="text-left px-4 py-2 font-medium">Method</th>
                  <th className="text-left px-4 py-2 font-medium">Destination</th>
                  <th className="text-left px-4 py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {paymentDestinations.length === 0 ? (
                  <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-500">No destinations yet — save one below.</td></tr>
                ) : paymentDestinations.map((d) => (
                  <tr key={d.id}>
                    <td className="px-4 py-2">{d.brand?.company_name || d.brand_id}</td>
                    <td className="px-4 py-2">{d.payment_method}</td>
                    <td className="px-4 py-2 font-mono text-xs">{d.destination_value || '—'}</td>
                    <td className="px-4 py-2">
                      {d.customer_ready ? (
                        <span className="text-green-700 text-xs font-medium">Verified / live</span>
                      ) : d.needs_owner_review || d.blocked_if_resaved ? (
                        <span className="text-amber-700 text-xs font-medium">Needs owner review</span>
                      ) : (
                        <span className="text-slate-500 text-xs">Inactive / unverified</span>
                      )}
                      {d.blocked_if_resaved && (
                        <p className="text-[11px] text-red-600 mt-0.5">Matches contractor email — blocked unless override</p>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <form onSubmit={savePayment} className="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
            <h3 className="font-semibold text-slate-800">Save / verify destination</h3>
            <div>
              <label className="text-xs text-slate-500 block mb-1">Brand</label>
              <select
                value={destForm.brand_id}
                onChange={(e) => setDestForm({ ...destForm, brand_id: e.target.value })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
              >
                <option value="">Select brand</option>
                {paymentBrands.map((b) => (
                  <option key={b.id} value={b.id}>{b.company_name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs text-slate-500 block mb-1">Payment method</label>
              <select
                value={destForm.payment_method}
                onChange={(e) => setDestForm({ ...destForm, payment_method: e.target.value, destination_value: e.target.value === 'stripe' ? 'platform' : '' })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
              >
                <option value="stripe">Stripe (platform — default)</option>
                <option value="e_transfer">E-Transfer (company email)</option>
              </select>
            </div>
            {destForm.payment_method === 'e_transfer' ? (
              <div>
                <label className="text-xs text-slate-500 block mb-1">Company e-transfer email</label>
                <input
                  type="email"
                  value={destForm.destination_value}
                  onChange={(e) => setDestForm({ ...destForm, destination_value: e.target.value })}
                  className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                  placeholder="payments@company.com"
                  required
                />
              </div>
            ) : (
              <p className="text-sm text-slate-500">Stripe destination is <code className="text-xs bg-slate-100 px-1 rounded">platform</code> (single platform Stripe account).</p>
            )}
            {destForm.owner_override && (
              <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 space-y-2">
                <p className="text-sm text-amber-900 font-medium">Owner override required — this email matches a contractor account.</p>
                <textarea
                  value={destForm.override_reason}
                  onChange={(e) => setDestForm({ ...destForm, override_reason: e.target.value })}
                  rows={2}
                  required
                  placeholder="Required reason (e.g. sole proprietor exception)"
                  className="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm"
                />
              </div>
            )}
            <button type="submit" disabled={saving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-60">
              {saving ? 'Saving...' : 'Save & verify destination'}
            </button>
          </form>
        </div>
      )}

      {activeTab === 'SMS Log' && (
        <div className="space-y-3">
          {smsMetrics && (
            <p className="text-xs text-slate-500">
              Production 30d: {smsMetrics.production_sent_30d} sent / {smsMetrics.production_failed_30d} failed
              {smsMetrics.test_excluded ? ' · test traffic excluded from metrics' : ''}
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            <select value={smsLogFilters.status} onChange={(e) => setSmsLogFilters({ ...smsLogFilters, status: e.target.value })} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="">All statuses</option>
              {['sent', 'failed', 'disabled', 'provider_unavailable', 'blocked_test_data', 'blocked_do_not_contact'].map((s) => (
                <option key={s} value={s}>{s}</option>
              ))}
            </select>
            <input
              value={smsLogFilters.trigger_event}
              onChange={(e) => setSmsLogFilters({ ...smsLogFilters, trigger_event: e.target.value })}
              placeholder="Trigger event"
              className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm"
            />
          </div>
          <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[960px] text-sm divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Recipient</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Trigger</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Status</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Error / fix</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Linked</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Provider ID</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Attempts</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {smsLogs.length === 0 ? (
                    <tr><td colSpan={8} className="px-4 py-8 text-center text-slate-500">No SMS logs yet.</td></tr>
                  ) : smsLogs.map((log) => (
                    <tr key={log.id} className="hover:bg-slate-50 align-top">
                      <td className="px-3 py-3">
                        <div>{log.recipient_normalized || log.to_phone}</div>
                        <div className="text-xs text-slate-400">{formatDate(log.created_at)}</div>
                      </td>
                      <td className="px-3 py-3">{log.trigger_event?.replace(/_/g, ' ')}</td>
                      <td className="px-3 py-3">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${smsStatusColor[log.status] || 'bg-slate-100'}`}>
                          {log.status}
                        </span>
                      </td>
                      <td className="px-3 py-3 text-xs max-w-[220px]">
                        <div className="text-slate-700">{log.error_plain || log.error_message || '—'}</div>
                        {log.correction_path && <div className="text-amber-700 mt-1">{log.correction_path}</div>}
                      </td>
                      <td className="px-3 py-3 text-xs">
                        {log.job ? `Job #${log.related_job_id}` : ''}
                        {log.lead ? ` Lead #${log.related_lead_id}` : ''}
                        {log.user ? ` · ${log.user.name}` : ''}
                        {!log.job && !log.lead && !log.user ? '—' : ''}
                      </td>
                      <td className="px-3 py-3 text-xs font-mono">{log.provider_message_id || '—'}</td>
                      <td className="px-3 py-3">{log.attempt_count ?? 1}</td>
                      <td className="px-3 py-3">
                        {log.status !== 'sent' && (
                          <button type="button" onClick={() => retrySmsLog(log)} className="text-xs text-blue-600 hover:text-blue-800">Retry</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {activeTab === 'Email Log' && (
        <div className="space-y-3">
          {emailMetrics && (
            <p className="text-xs text-slate-500">
              Production 30d: {emailMetrics.production_sent_30d} sent / {emailMetrics.production_failed_30d} failed
              {emailMetrics.test_excluded ? ' · test traffic excluded from metrics' : ''}
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            <select value={emailLogFilters.status} onChange={(e) => setEmailLogFilters({ ...emailLogFilters, status: e.target.value })} className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
              <option value="">All statuses</option>
              {['sent', 'failed', 'provider_unavailable', 'blocked_test_data', 'blocked_do_not_contact'].map((s) => (
                <option key={s} value={s}>{s}</option>
              ))}
            </select>
            <input
              value={emailLogFilters.trigger_event}
              onChange={(e) => setEmailLogFilters({ ...emailLogFilters, trigger_event: e.target.value })}
              placeholder="Trigger event"
              className="border border-slate-300 rounded-lg px-2 py-1.5 text-sm"
            />
          </div>
          <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[960px] text-sm divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Recipient</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Trigger</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Status</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Error / fix</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Linked</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Attempts</th>
                    <th className="text-left px-3 py-3 font-medium text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {emailLogs.length === 0 ? (
                    <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-500">No email logs yet.</td></tr>
                  ) : emailLogs.map((log) => (
                    <tr key={log.id} className="hover:bg-slate-50 align-top">
                      <td className="px-3 py-3">
                        <div>{log.recipient_normalized || log.to_email}</div>
                        <div className="text-xs text-slate-400">{formatDate(log.created_at)}</div>
                      </td>
                      <td className="px-3 py-3">{log.trigger_event?.replace(/_/g, ' ')}</td>
                      <td className="px-3 py-3">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${log.status === 'sent' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                          {log.status}
                        </span>
                      </td>
                      <td className="px-3 py-3 text-xs max-w-[220px]">
                        <div>{log.error_plain || log.error_message || '—'}</div>
                        {log.correction_path && <div className="text-amber-700 mt-1">{log.correction_path}</div>}
                      </td>
                      <td className="px-3 py-3 text-xs">
                        {log.job ? `Job #${log.related_job_id}` : ''}
                        {log.lead ? ` Lead #${log.related_lead_id}` : ''}
                        {log.user ? ` · ${log.user.name}` : ''}
                        {!log.job && !log.lead && !log.user ? '—' : ''}
                      </td>
                      <td className="px-3 py-3">{log.attempt_count ?? 1}</td>
                      <td className="px-3 py-3">
                        {log.status !== 'sent' && (
                          <button type="button" onClick={() => retryEmailLog(log)} className="text-xs text-blue-600 hover:text-blue-800">Retry</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {activeTab === 'Lead Inbox' && (
        <div className="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl space-y-4">
          <h3 className="font-semibold text-slate-800">Gmail lead inbox</h3>
          <p className="text-sm text-slate-500">
            Connect <span className="font-medium text-slate-700">{gmailStatus?.expected_mailbox || 'leads@serviceop.ca'}</span> via
            Google OAuth (readonly). New inbox messages are polled every 5 minutes and run through the lead intake pipeline.
          </p>
          {!gmailStatus ? (
            <p className="text-sm text-slate-500">Loading status…</p>
          ) : (
            <dl className="text-sm space-y-2">
              <div className="flex gap-2"><dt className="text-slate-500 w-36">Google app</dt><dd>{gmailStatus.configured ? 'Configured' : 'Missing GOOGLE_CLIENT_ID / SECRET'}</dd></div>
              <div className="flex gap-2"><dt className="text-slate-500 w-36">Connection</dt><dd>{gmailStatus.connected ? 'Connected' : 'Not connected'}</dd></div>
              <div className="flex gap-2"><dt className="text-slate-500 w-36">Mailbox</dt><dd>{gmailStatus.mailbox_email || '—'}</dd></div>
              <div className="flex gap-2"><dt className="text-slate-500 w-36">Last fetch</dt><dd>{gmailStatus.last_fetched_at ? formatDate(gmailStatus.last_fetched_at) : '—'}</dd></div>
              <div className="flex gap-2"><dt className="text-slate-500 w-36">Redirect URI</dt><dd className="break-all text-xs font-mono">{gmailStatus.redirect_uri}</dd></div>
            </dl>
          )}
          <div className="flex flex-wrap gap-2 pt-2">
            {!gmailStatus?.connected ? (
              <button type="button" disabled={gmailBusy || !gmailStatus?.configured} onClick={connectGmail}
                className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg disabled:opacity-60">
                {gmailBusy ? 'Starting…' : 'Connect Gmail'}
              </button>
            ) : (
              <>
                <button type="button" disabled={gmailBusy} onClick={fetchGmailNow}
                  className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg disabled:opacity-60">
                  Fetch now
                </button>
                <button type="button" disabled={gmailBusy} onClick={disconnectGmail}
                  className="px-4 py-2 border border-slate-300 text-slate-700 text-sm rounded-lg disabled:opacity-60">
                  Disconnect
                </button>
              </>
            )}
          </div>
        </div>
      )}

      {activeTab === 'Workflow' && (
        <form onSubmit={saveWorkflowThresholds} className="bg-white rounded-xl border border-slate-200 p-6 max-w-xl space-y-4">
          <h3 className="font-semibold text-slate-800">Automation thresholds</h3>
          <p className="text-sm text-slate-500">Owner-editable defaults for reminders and escalations. Change anytime without a rebuild.</p>
          {!workflowThresholds ? (
            <p className="text-sm text-slate-400">Loading…</p>
          ) : (
            <>
              {[
                ['pm_contact_lead_hours', 'PM must contact new lead within (hours)'],
                ['pm_contact_escalation_hours', 'Escalate to Owner after reminder (hours)'],
                ['contractor_pricing_deadline_hours', 'Contractor pricing deadline after site visit (hours)'],
                ['quote_follow_up_hours', 'Quote follow-up if not approved (hours)'],
                ['job_missing_update_days', 'Flag in-progress jobs missing updates (days)'],
              ].map(([key, label]) => (
                <label key={key} className="block text-sm">
                  <span className="text-slate-600">{label}</span>
                  <input
                    type="number"
                    step="0.5"
                    min="0.5"
                    value={workflowThresholds[key] ?? ''}
                    onChange={(e) => setWorkflowThresholds({ ...workflowThresholds, [key]: e.target.value })}
                    className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
                  />
                </label>
              ))}
              <button type="submit" disabled={workflowSaving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg disabled:opacity-60">
                {workflowSaving ? 'Saving…' : 'Save thresholds'}
              </button>
            </>
          )}
        </form>
      )}

      {activeTab === 'Message Templates' && (
        <div className="space-y-4 max-w-3xl">
          <p className="text-sm text-slate-500">
            Edit automated copy. Placeholders use {'{{variable}}'}. Brand names resolve via BrandResolver (never hardcode ServiceOP).
            Inactive templates cannot be triggered — no fallback send.
          </p>
          {messageTemplates.length === 0 ? (
            <p className="text-sm text-slate-400">No templates yet — run MessageTemplateSeeder.</p>
          ) : messageTemplates.map((tpl) => {
            const preview = templatePreview[tpl.id]?.preview || tpl.sample_preview;
            const versions = templateVersions[tpl.id] || [];
            return (
              <div key={tpl.id} className="bg-white rounded-xl border border-slate-200 p-4 space-y-2">
                <div className="flex justify-between gap-2">
                  <div>
                    <p className="font-medium text-slate-800">{tpl.label}</p>
                    <p className="text-xs text-slate-400 font-mono">{tpl.event_key}</p>
                    {tpl.last_changed_by && (
                      <p className="text-xs text-slate-400 mt-1">
                        Last changed by {tpl.last_changed_by.name} {tpl.last_changed_at ? `· ${formatDate(tpl.last_changed_at)}` : ''}
                      </p>
                    )}
                  </div>
                  <label className="text-xs flex items-center gap-1">
                    <input type="checkbox" checked={!!tpl.is_active}
                      onChange={(e) => setMessageTemplates((prev) => prev.map((t) => t.id === tpl.id ? { ...t, is_active: e.target.checked } : t))} />
                    Active
                  </label>
                </div>
                <textarea
                  rows={3}
                  className="w-full border border-slate-300 rounded-lg p-2 text-sm"
                  value={tpl.body}
                  onChange={(e) => setMessageTemplates((prev) => prev.map((t) => t.id === tpl.id ? { ...t, body: e.target.value } : t))}
                />
                <p className="text-xs text-slate-400">Variables: {(tpl.variables || []).join(', ') || '—'}</p>
                {preview && (
                  <div className="bg-slate-50 border border-slate-100 rounded-lg p-3 text-sm space-y-1">
                    <p className="text-xs font-medium text-slate-500 uppercase">Sample preview</p>
                    <p className="text-slate-800 whitespace-pre-wrap">{preview.rendered}</p>
                    <p className="text-xs text-slate-500">
                      {preview.char_count} chars
                      {preview.sms_segments != null ? ` · ${preview.sms_segments} SMS segment(s)` : ''}
                    </p>
                    {preview.sms_segment_warning && (
                      <p className="text-xs text-amber-700">{preview.sms_segment_note}</p>
                    )}
                    {preview.unresolved?.length > 0 && (
                      <p className="text-xs text-red-600">Unresolved: {preview.unresolved.map((u) => `{{${u}}}`).join(', ')}</p>
                    )}
                  </div>
                )}
                <div className="flex flex-wrap gap-2">
                  <button type="button" disabled={templateSavingId === tpl.id} onClick={() => saveTemplate(tpl)}
                    className="text-sm px-3 py-1.5 bg-blue-600 text-white rounded-lg disabled:opacity-60">
                    {templateSavingId === tpl.id ? 'Saving…' : 'Save'}
                  </button>
                  <button type="button" onClick={() => previewTemplate(tpl)} className="text-sm px-3 py-1.5 border border-slate-200 rounded-lg">Preview</button>
                  <button type="button" onClick={() => testSendTemplate(tpl)} className="text-sm px-3 py-1.5 border border-slate-200 rounded-lg">Test send</button>
                  <button type="button" onClick={() => refreshTemplateMeta(tpl.id)} className="text-sm px-3 py-1.5 border border-slate-200 rounded-lg">Versions</button>
                </div>
                {versions.length > 0 && (
                  <div className="text-xs text-slate-500 space-y-1 border-t border-slate-100 pt-2">
                    {versions.slice(0, 5).map((v) => (
                      <div key={v.id} className="flex justify-between gap-2">
                        <span>v{v.version} · {v.changed_by_user?.name || '—'} · {formatDate(v.created_at)}</span>
                        <button type="button" className="text-blue-600" onClick={() => restoreTemplateVersion(tpl, v.id)}>Restore</button>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {activeTab === 'AI Settings' && (
        <div className="space-y-6 max-w-4xl">
          <form onSubmit={saveAiSettings} className="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
            <h3 className="font-semibold text-slate-800">AI Controls (Owner only)</h3>
            <label className="flex items-center gap-3 text-sm">
              <input type="checkbox" checked={aiForm.ai_kill_switch}
                onChange={(e) => setAiForm({ ...aiForm, ai_kill_switch: e.target.checked })}
                className="rounded border-slate-300" />
              <span><strong>AI Kill Switch</strong> — when on, all AI operations are paused</span>
            </label>
            {aiForm.ai_kill_switch && (
              <div className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">
                Kill switch is active — a persistent banner also appears across the app.
              </div>
            )}
            <label className="flex items-center gap-3 text-sm">
              <input type="checkbox" checked={aiForm.ai_simulation_mode}
                onChange={(e) => setAiForm({ ...aiForm, ai_simulation_mode: e.target.checked })}
                className="rounded border-slate-300" />
              <span><strong>Simulation mode</strong> — propose actions only; no live data changes</span>
            </label>
            <div className="flex flex-wrap gap-4 text-sm">
              <label className="space-y-1">
                <span className="font-medium text-slate-700">Daily action limit</span>
                <input type="number" min={1} value={aiForm.ai_daily_action_limit ?? 200}
                  onChange={(e) => setAiForm({ ...aiForm, ai_daily_action_limit: Number(e.target.value) || 200 })}
                  className="block border border-slate-300 rounded-lg px-3 py-1.5 w-32" />
              </label>
              <label className="space-y-1">
                <span className="font-medium text-slate-700">Daily cost limit (USD)</span>
                <input type="number" min={0} step="0.01" value={aiForm.ai_daily_cost_usd_limit ?? 25}
                  onChange={(e) => setAiForm({ ...aiForm, ai_daily_cost_usd_limit: Number(e.target.value) || 0 })}
                  className="block border border-slate-300 rounded-lg px-3 py-1.5 w-32" />
              </label>
            </div>
            <label className="block text-sm space-y-1">
              <span className="font-medium text-slate-700">AI conversation log retention (days)</span>
              <input
                type="number"
                min={1}
                max={3650}
                value={aiForm.ai_conversation_retention_days ?? 365}
                onChange={(e) => setAiForm({
                  ...aiForm,
                  ai_conversation_retention_days: Number(e.target.value) || 365,
                })}
                className="border border-slate-300 rounded-lg px-3 py-1.5 text-sm w-40"
              />
              <p className="text-xs text-slate-500">
                Full chat transcripts in <code>ai_conversation_logs</code> are purged after this many days
                (default {aiSettings?.ai_conversation_retention_default ?? 365}). Last purge:{' '}
                {aiSettings?.ai_conversation_last_purge_at || 'never'}
                {aiSettings?.ai_conversation_last_purge_count != null
                  ? ` (${aiSettings.ai_conversation_last_purge_count} rows)`
                  : ''}.
              </p>
            </label>
            <div className="space-y-3">
              <p className="text-sm font-medium text-slate-700">Per-module operating mode</p>
              {aiSettings?.mode_definitions && (
                <ul className="text-xs text-slate-600 space-y-1 bg-slate-50 rounded-lg p-3">
                  {Object.entries(aiSettings.mode_definitions).map(([key, def]) => (
                    <li key={key}><strong>{def.label || key}:</strong> {def.summary}</li>
                  ))}
                </ul>
              )}
              {(aiSettings?.modules || []).map((module) => (
                <div key={module} className="flex items-center gap-3">
                  <span className="text-sm text-slate-600 w-40 capitalize">{module.replace(/_/g, ' ')}</span>
                  <select value={aiForm.module_modes?.[module] || 'suggestion'}
                    onChange={(e) => setAiForm({
                      ...aiForm,
                      module_modes: { ...aiForm.module_modes, [module]: e.target.value },
                    })}
                    className="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                    {(aiSettings?.available_modes || ['suggestion', 'assisted', 'autopilot']).map((m) => (
                      <option key={m} value={m}>
                        {aiSettings?.mode_definitions?.[m]?.label || m}
                      </option>
                    ))}
                  </select>
                </div>
              ))}
            </div>
            <button type="submit" disabled={aiSaving} className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg disabled:opacity-60">
              {aiSaving ? 'Saving...' : 'Save AI Settings'}
            </button>
          </form>

          <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h3 className="font-semibold text-slate-800">AI ops reports</h3>
                <p className="text-xs text-slate-500 mt-0.5">Daily/weekly snapshots (also scheduled automatically). Same view on Accounting.</p>
              </div>
              <button
                type="button"
                onClick={generateOpsReport}
                disabled={opsReportBusy}
                className="text-sm px-3 py-1.5 bg-blue-600 text-white rounded-lg disabled:opacity-60"
              >
                {opsReportBusy ? 'Generating…' : 'Generate daily'}
              </button>
            </div>
            {opsReports.length === 0 ? (
              <p className="text-sm text-slate-500">No reports yet.</p>
            ) : (
              <ul className="space-y-3">
                {opsReports.slice(0, 5).map((r) => (
                  <li key={r.id} className="border border-slate-100 rounded-lg p-3">
                    <p className="text-xs text-slate-500 mb-1">{r.period} · {r.report_date} · {r.provider}</p>
                    <p className="text-sm text-slate-700 whitespace-pre-wrap">{r.summary_text}</p>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="bg-white rounded-xl border border-slate-200 p-6">
            <div className="flex items-center justify-between mb-3">
              <h3 className="font-semibold text-slate-800">Action Registry</h3>
              <button type="button" onClick={createTestAiLog} className="text-sm text-blue-600 font-medium">Create test log</button>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm min-w-[640px]">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="text-left px-3 py-2 font-medium text-slate-500">Action</th>
                    <th className="text-left px-3 py-2 font-medium text-slate-500">Approval</th>
                    <th className="text-left px-3 py-2 font-medium text-slate-500">Modes</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {(aiSettings?.action_registry || []).map((a) => (
                    <tr key={a.action_key}>
                      <td className="px-3 py-2">
                        <p className="font-medium">{a.label}</p>
                        <p className="text-xs text-slate-400">{a.action_key}</p>
                      </td>
                      <td className="px-3 py-2">{a.requires_human_approval ? 'Yes' : 'No'}</td>
                      <td className="px-3 py-2 text-xs">{(a.modes_available || []).join(', ')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="bg-white rounded-xl border border-slate-200 p-6">
            <div className="flex items-center justify-between mb-3">
              <h3 className="font-semibold text-slate-800">Recent AI Action Logs</h3>
              <button type="button" onClick={() => handleTab('AI Activity Log')} className="text-sm text-blue-600 font-medium">
                View full log →
              </button>
            </div>
            {(aiSettings?.recent_action_logs || []).length === 0 ? (
              <p className="text-sm text-slate-500">No AI action logs yet.</p>
            ) : (
              <div className="space-y-2">
                {aiSettings.recent_action_logs.map((log) => (
                  <div key={log.id} className="text-sm border-b border-slate-100 pb-2">
                    <span className="text-slate-400 text-xs">{formatDate(log.created_at)}</span>
                    <span className="ml-2 font-medium">{log.trigger_event}</span>
                    <span className="ml-2 text-slate-500">{log.action_taken}</span>
                    <p className="text-slate-600 text-xs mt-1">{log.decision}</p>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {activeTab === 'AI Activity Log' && (
        <div>
          <h3 className="font-semibold text-slate-800 mb-4">AI Activity Log</h3>
          <p className="text-sm text-slate-500 mb-4">
            Audit trail for AI-driven actions. Entries marked &quot;Placeholder copy&quot; used template text pending Trystan&apos;s approval.
          </p>
          <AiActivityLogViewer />
        </div>
      )}

      {activeTab === 'Branding' && (
        <BrandingTab />
      )}
    </div>
  );
}
