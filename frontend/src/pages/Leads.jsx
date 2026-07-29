import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { Plus, Trash2 } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import SlideOverPanel from '../components/SlideOverPanel';
import LeadForm from '../components/LeadForm';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';

import { formatDate } from '../utils/formatDate';

function formatCategory(cat) {
  return (cat || '').replace(/_/g, ' ');
}

function ConfidenceList({ items }) {
  if (!Array.isArray(items) || items.length === 0) {
    return <p className="text-xs text-slate-500">No field confidence recorded.</p>;
  }
  return (
    <ul className="space-y-2">
      {items.map((row) => (
        <li key={row.field} className="text-xs border border-slate-100 rounded-lg p-2 bg-slate-50">
          <div className="flex justify-between gap-2 font-medium text-slate-700">
            <span>{row.field}</span>
            <span className={row.valid ? 'text-emerald-700' : 'text-amber-700'}>
              {row.score ?? 0}%{row.valid ? '' : ' · invalid'}
            </span>
          </div>
          {row.source_text ? (
            <p className="mt-1 text-slate-500 break-words whitespace-pre-wrap">{row.source_text}</p>
          ) : null}
        </li>
      ))}
    </ul>
  );
}

/** PM-10 / PM-15 — context-specific empty / error copy */
function leadsEmptyCopy({ isPm, view, status, search, category, loadState, loadError }) {
  if (loadState === 'permission') {
    return {
      title: 'Permission required',
      body: loadError || 'You do not have permission to view these leads.',
      links: [],
    };
  }
  if (loadState === 'error') {
    return {
      title: 'Unable to load leads',
      body: loadError || 'Something went wrong loading this list. Try refreshing the page.',
      links: [],
    };
  }
  if (search) {
    return {
      title: 'No matching leads',
      body: `No leads match “${search}” in your current filters.`,
      links: isPm
        ? [{ to: '/leads?view=active', label: 'Clear search — active leads' }]
        : [{ to: '/leads', label: 'Clear search' }],
    };
  }
  if (view === 'converted' || status === 'converted') {
    return {
      title: 'No converted leads',
      body: isPm
        ? 'None of your assigned leads have been converted yet.'
        : 'No converted leads in this view.',
      links: [{ to: '/leads?view=active', label: 'View active leads' }],
    };
  }
  if (view === 'lost' || status === 'lost') {
    return {
      title: 'No lost / disqualified leads',
      body: isPm
        ? 'You have no lost or disqualified leads in your assignment history.'
        : 'No lost leads in this view.',
      links: [{ to: '/leads?view=active', label: 'View active leads' }],
    };
  }
  if (view === 'ignored') {
    return {
      title: 'No ignored leads',
      body: 'No leads have been marked ignored in this filter.',
      links: [{ to: '/leads?view=active', label: 'View active leads' }],
    };
  }
  if (view === 'needs_review' || status === 'needs_review') {
    return {
      title: 'Nothing needs review',
      body: 'No leads are currently flagged for manual review.',
      links: [{ to: '/leads?view=active', label: 'View active leads' }],
    };
  }
  if (category) {
    return {
      title: 'No leads in this category',
      body: `No ${category.replace(/_/g, ' ')} leads match your current filters.`,
      links: [{ to: '/leads?view=active', label: 'View all active leads' }],
    };
  }
  if (isPm) {
    return {
      title: 'No active leads assigned to you',
      body: 'When a lead is assigned to you it will appear here. Converted and archived leads are available via the tabs above when authorized.',
      links: [
        { to: '/leads?view=converted&status=converted', label: 'Converted leads' },
        { to: '/leads?view=lost&status=lost', label: 'Lost / archived' },
      ],
    };
  }
  return {
    title: 'No leads in this view',
    body: 'Try another tab or clear filters. Converted and lost leads are under their own tabs.',
    links: [
      { to: '/leads?view=converted&status=converted', label: 'Converted' },
      { to: '/leads?view=lost&status=lost', label: 'Lost' },
    ],
  };
}

function LeadsEmptyState(props) {
  const copy = leadsEmptyCopy(props);
  return (
    <div className="text-center py-10 px-4 space-y-2">
      <p className="text-sm font-medium text-slate-700">{copy.title}</p>
      <p className="text-sm text-slate-500 max-w-md mx-auto">{copy.body}</p>
      {copy.links?.length > 0 && (
        <div className="flex flex-wrap justify-center gap-3 pt-2">
          {copy.links.map((l) => (
            <Link key={l.to} to={l.to} className="text-sm text-blue-600 hover:underline font-medium">{l.label}</Link>
          ))}
        </div>
      )}
    </div>
  );
}

export default function Leads() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [leads, setLeads] = useState([]);
  const [quarantine, setQuarantine] = useState([]);
  const [meta, setMeta] = useState({});
  const [qMeta, setQMeta] = useState({});
  const [panelOpen, setPanelOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [selectedQ, setSelectedQ] = useState(null);
  const [editFields, setEditFields] = useState({});
  const [acting, setActing] = useState(false);

  const status = searchParams.get('status') || '';
  const view = searchParams.get('view') || '';
  const category = searchParams.get('category') || '';
  const search = searchParams.get('search') || '';
  const page = searchParams.get('page') || '1';
  const isOwner = user?.role === 'owner';
  const isPm = user?.role === 'pm';
  const showQuarantine = isOwner && (status === 'needs_review' || view === 'quarantine');
  const [selectedIds, setSelectedIds] = useState([]);
  const [dupGroup, setDupGroup] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [loadState, setLoadState] = useState('loading'); // loading | ready | empty | permission | error

  const VIEW_TABS = [
    { label: 'Active', view: 'active' },
    { label: 'Needs Review', view: 'needs_review', status: 'needs_review' },
    { label: 'Quarantine', view: 'quarantine', status: 'needs_review' },
    { label: 'Duplicates', view: 'duplicates' },
    { label: 'Converted', view: 'converted', status: 'converted' },
    { label: 'Lost', view: 'lost', status: 'lost' },
    { label: 'Ignored', view: 'ignored' },
  ];

  const fetchLeads = () => {
    if (view === 'quarantine') {
      setLeads([]);
      setLoadState('ready');
      setLoadError(null);
      return;
    }
    setLoadState('loading');
    setLoadError(null);
    const params = { page };
    if (view) params.view = view;
    if (status === 'needs_review' || view === 'needs_review') {
      params.needs_review = 'true';
      params.view = 'needs_review';
    } else if (status) {
      params.status = status;
      if (status === 'converted') params.show_converted = 'true';
    }
    if (category) params.category = category;
    if (search) params.search = search;
    api.get('/leads', { params }).then(({ data }) => {
      const rows = data.data || [];
      setLeads(rows);
      setMeta({ current: data.current_page, last: data.last_page, total: data.total });
      setLoadState(rows.length === 0 ? 'empty' : 'ready');
    }).catch((err) => {
      setLeads([]);
      const code = err.response?.status;
      if (code === 403) {
        setLoadState('permission');
        setLoadError(err.response?.data?.message || 'You do not have permission to view these leads.');
      } else {
        setLoadState('error');
        setLoadError(err.response?.data?.message || 'Could not load leads. Try refreshing.');
      }
    });
  };

  const fetchQuarantine = () => {
    if (!showQuarantine) {
      setQuarantine([]);
      return;
    }
    const params = { page, status: 'pending' };
    if (search) params.search = search;
    api.get('/intake-quarantine', { params }).then(({ data }) => {
      setQuarantine(data.data || []);
      setQMeta({ current: data.current_page, last: data.last_page, total: data.total });
    }).catch(() => setQuarantine([]));
  };

  useEffect(() => {
    fetchLeads();
    fetchQuarantine();
  }, [status, view, category, search, page, showQuarantine]);

  const setViewTab = (tab) => {
    const next = new URLSearchParams(searchParams);
    next.delete('page');
    if (tab.view) next.set('view', tab.view); else next.delete('view');
    if (tab.status) next.set('status', tab.status); else next.delete('status');
    setSearchParams(next);
  };

  const openDupGroup = async (groupId) => {
    try {
      const { data } = await api.get(`/leads/duplicate-groups/${groupId}`);
      setDupGroup(data);
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to load duplicate group');
    }
  };

  const bulkIgnore = async () => {
    if (!selectedIds.length) return;
    const reason = window.prompt('Reason for ignoring selected leads (audit trail):');
    if (!reason?.trim()) return;
    const ok = await confirmAction({ title: 'Ignore selected leads?', text: `${selectedIds.length} lead(s) will be marked ignored.`, confirmText: 'Ignore' });
    if (!ok) return;
    try {
      await api.post('/leads/bulk-ignore', { lead_ids: selectedIds, reason: reason.trim(), confirm: true });
      await showSuccess('Leads ignored with audit trail.');
      setSelectedIds([]);
      fetchLeads();
    } catch (e) {
      await showError(e.response?.data?.message || 'Bulk ignore failed');
    }
  };

  const mergeDupGroup = async () => {
    if (!dupGroup?.leads?.length) return;
    const primary = dupGroup.recommended_primary_id;
    const ids = dupGroup.leads.map((l) => l.id);
    const ok = await confirmAction({
      title: 'Merge duplicate leads?',
      text: `Primary #${primary} will absorb ${ids.length - 1} secondary lead(s). Soft-merge with audit log.`,
      confirmText: 'Merge',
    });
    if (!ok) return;
    try {
      await api.post('/leads/merge', {
        lead_ids: ids,
        primary_lead_id: primary,
        confirm: true,
      });
      await showSuccess('Leads merged.');
      setDupGroup(null);
      fetchLeads();
    } catch (e) {
      await showError(e.response?.data?.message || 'Merge failed');
    }
  };

  const goToPage = (p) => {
    const next = new URLSearchParams(searchParams);
    next.set('page', String(p));
    setSearchParams(next);
  };

  const setFilter = (key, value) => {
    const next = new URLSearchParams(searchParams);
    if (value) next.set(key, value); else next.delete(key);
    next.delete('page');
    setSearchParams(next);
  };

  const openQuarantine = async (item) => {
    try {
      const { data } = await api.get(`/intake-quarantine/${item.id}`);
      setSelectedQ(data);
      setEditFields({
        contact_name: data.parsed_fields?.contact_name || '',
        phone: data.parsed_fields?.phone || '',
        email: data.parsed_fields?.email || '',
        address: data.parsed_fields?.address || '',
        project_description: data.parsed_fields?.project_description || '',
      });
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to load quarantine item.');
    }
  };

  const approveQuarantine = async () => {
    if (!selectedQ) return;
    const ok = await confirmAction({
      title: 'Approve and create lead?',
      text: 'This creates a customer/lead and triggers the normal PM notification flow once.',
      confirmText: 'Approve',
    });
    if (!ok) return;
    setActing(true);
    try {
      const { data } = await api.post(`/intake-quarantine/${selectedQ.id}/approve`, {
        ...editFields,
        send_notifications: true,
      });
      await showSuccess(data.message || 'Lead created.');
      setSelectedQ(null);
      fetchQuarantine();
      fetchLeads();
      if (data.lead?.id) navigate(`/leads/${data.lead.id}`);
    } catch (e) {
      await showError(e.response?.data?.message || 'Approve failed.');
    } finally {
      setActing(false);
    }
  };

  const ignoreQuarantine = async () => {
    if (!selectedQ) return;
    const reason = window.prompt('Reason for permanently ignoring this message:');
    if (!reason || !reason.trim()) return;
    setActing(true);
    try {
      await api.post(`/intake-quarantine/${selectedQ.id}/ignore`, { reason: reason.trim() });
      await showSuccess('Marked as permanently ignored (audit trail kept).');
      setSelectedQ(null);
      fetchQuarantine();
    } catch (e) {
      await showError(e.response?.data?.message || 'Ignore failed.');
    } finally {
      setActing(false);
    }
  };

  const handleCreate = async (form) => {
    const ok = await confirmAction({
      title: 'Create lead?',
      text: `Create a new lead for ${form.contact_name}?`,
      confirmText: 'Yes, create lead',
    });
    if (!ok) return;

    setSaving(true);
    try {
      await api.post('/leads', form);
      setPanelOpen(false);
      await showSuccess('Lead created successfully.');
      fetchLeads();
    } catch (e) {
      await showError(e.response?.data?.message || 'Failed to create lead.');
    } finally {
      setSaving(false);
    }
  };

  const canDelete = ['owner', 'pm'].includes(user?.role);

  const confirmDelete = async (leadId, e) => {
    e?.stopPropagation();
    e?.preventDefault();
    const ok = await confirmAction({
      title: 'Delete lead?',
      text: 'Are you sure you want to delete this lead? This cannot be undone.',
      confirmText: 'Yes, delete',
    });
    if (!ok) return;

    try {
      await api.delete(`/leads/${leadId}`);
      await showSuccess('Lead deleted.');
      fetchLeads();
    } catch (err) {
      await showError(err.response?.data?.message || 'Delete failed.');
    }
  };

  return (
    <div>
      <PageHeader title={isPm ? 'My Leads' : 'Leads'}>
        <button type="button" onClick={() => setPanelOpen(true)}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
          <Plus size={16} /> Add Lead
        </button>
      </PageHeader>

      <div className="flex flex-wrap gap-2 mb-3">
        {VIEW_TABS.map((tab) => (
          <button
            key={tab.label}
            type="button"
            onClick={() => setViewTab(tab)}
            className={`px-3 py-1.5 rounded-lg text-xs sm:text-sm font-medium ${
              (view || status || 'active') === (tab.view || tab.status || 'active')
                ? 'bg-blue-600 text-white'
                : 'bg-white text-slate-600 border border-slate-200'
            }`}
          >
            {tab.label}
          </button>
        ))}
        {isOwner && view === 'duplicates' && (
          <button
            type="button"
            className="px-3 py-1.5 rounded-lg text-xs border border-slate-200"
            onClick={async () => {
              try {
                await api.post('/leads/regroup-duplicates');
                await showSuccess('Duplicate groups refreshed');
                fetchLeads();
              } catch (e) {
                await showError(e.response?.data?.message || 'Regroup failed');
              }
            }}
          >
            Refresh groups
          </button>
        )}
        {isOwner && selectedIds.length > 0 && (
          <button type="button" onClick={bulkIgnore} className="px-3 py-1.5 rounded-lg text-xs bg-slate-800 text-white">
            Ignore selected ({selectedIds.length})
          </button>
        )}
      </div>

      <div className="bg-white rounded-xl border border-slate-200 p-4 mb-4">
        <div className="flex flex-col sm:flex-row gap-3 items-end">
          <div>
            <label className="text-xs text-slate-500 block mb-1">Status</label>
            <select value={status} onChange={(e) => setFilter('status', e.target.value)} className="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
              <option value="">All Statuses</option>
              <option value="needs_review">Needs Review</option>
              {['new', 'contacted', 'site_visit_scheduled', 'quote_needed', 'converted', 'lost'].map((s) => (
                <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs text-slate-500 block mb-1">Category</label>
            <select value={category} onChange={(e) => setFilter('category', e.target.value)} className="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
              <option value="">All Categories</option>
              <option value="drywall_paint">Drywall & Paint</option>
              <option value="insulation">Insulation</option>
            </select>
          </div>
          <div className="flex flex-1 gap-2 items-end">
            <div className="flex-1">
              <label className="text-xs text-slate-500 block mb-1">Search</label>
              <input type="text" placeholder="Search leads..." defaultValue={search}
                onKeyDown={(e) => e.key === 'Enter' && setFilter('search', e.target.value)}
                className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm outline-none" />
            </div>
            <button type="button" onClick={() => setFilter('search', document.querySelector('input[placeholder="Search leads..."]')?.value || '')}
              className="px-4 py-2 bg-slate-100 rounded-lg text-sm font-medium">Search</button>
          </div>
        </div>
      </div>

      {showQuarantine && (
        <div className="bg-white rounded-xl border border-amber-200 overflow-hidden mb-4">
          <div className="px-4 py-3 border-b border-amber-100 bg-amber-50 flex items-center justify-between gap-2">
            <div>
              <h2 className="text-sm font-semibold text-amber-900">Gmail intake quarantine</h2>
              <p className="text-xs text-amber-800 mt-0.5">
                Low-confidence or unmatched Gmail messages — no customer/lead/notification until approved.
              </p>
            </div>
            <span className="text-xs font-medium text-amber-900 bg-amber-100 px-2 py-1 rounded">
              {qMeta.total ?? quarantine.length} pending
            </span>
          </div>
          <div className="md:hidden p-3 space-y-3">
            {quarantine.length === 0 ? (
              <p className="text-center text-slate-500 py-6 text-sm">No quarantined Gmail messages pending review.</p>
            ) : quarantine.map((item) => (
              <button
                key={item.id}
                type="button"
                className="mobile-data-card w-full text-left cursor-pointer hover:border-amber-300"
                onClick={() => openQuarantine(item)}
              >
                <p className="mobile-data-card-title line-clamp-2">{item.subject || '(no subject)'}</p>
                <p className="mobile-data-card-meta line-clamp-1">{item.from_header || '—'}</p>
                <p className="mobile-data-card-meta">{item.parsed_fields?.contact_name || '—'} · {item.parsed_fields?.phone || 'no phone'}</p>
                <p className="text-xs text-amber-800">{item.quarantine_reason || '—'}</p>
                <p className="mobile-data-card-meta">{formatDate(item.created_at)}</p>
              </button>
            ))}
          </div>
          <div className="hidden md:block overflow-x-auto">
            <table className="w-full text-sm divide-y divide-slate-200">
              <thead className="bg-slate-50">
                <tr>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Subject / From</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Parsed</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Reason</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Dup</th>
                  <th className="text-left px-4 py-3 font-medium text-slate-500">Date</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {quarantine.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-8 text-center text-slate-500">
                      No quarantined Gmail messages pending review.
                    </td>
                  </tr>
                ) : quarantine.map((item) => (
                  <tr
                    key={item.id}
                    className="hover:bg-amber-50/40 cursor-pointer"
                    onClick={() => openQuarantine(item)}
                  >
                    <td className="px-4 py-3">
                      <div className="font-medium text-slate-800 line-clamp-1">{item.subject || '(no subject)'}</div>
                      <div className="text-xs text-slate-500 line-clamp-1">{item.from_header || '—'}</div>
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-600">
                      <div>{item.parsed_fields?.contact_name || '—'}</div>
                      <div>{item.parsed_fields?.phone || 'no phone'}</div>
                    </td>
                    <td className="px-4 py-3 text-xs text-amber-800">{item.quarantine_reason || '—'}</td>
                    <td className="px-4 py-3 text-xs">
                      {item.duplicate_group_key ? (
                        <span className="bg-slate-100 text-slate-700 px-2 py-0.5 rounded" title={item.duplicate_group_key}>
                          group
                        </span>
                      ) : '—'}
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-500">{formatDate(item.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        {showQuarantine && (
          <div className="px-4 py-2 border-b border-slate-100 text-xs font-medium text-slate-500 uppercase tracking-wide">
            Leads flagged needs_manual_review
          </div>
        )}

        <div className="md:hidden p-3 space-y-3">
          {leads.length === 0 ? (
            <LeadsEmptyState
              isPm={isPm}
              view={view}
              status={status}
              search={search}
              category={category}
              loadState={loadState}
              loadError={loadError}
            />
          ) : leads.map((lead) => (
            <div
              key={lead.id}
              role="button"
              tabIndex={0}
              className="mobile-data-card w-full text-left cursor-pointer hover:border-slate-300"
              onClick={() => navigate(`/leads/${lead.id}`)}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); navigate(`/leads/${lead.id}`); } }}
            >
              <div className="flex items-start justify-between gap-2">
                <span className="mobile-data-card-title text-blue-600 flex-1 min-w-0 pr-1">{lead.contact_name}</span>
                <div className="flex items-center gap-1 flex-shrink-0">
                  <StatusBadge status={lead.status} />
                  {canDelete && (
                    <button
                      type="button"
                      onClick={(e) => confirmDelete(lead.id, e)}
                      className="text-red-500 p-2 min-h-[44px] min-w-[44px] flex items-center justify-center -mr-2"
                      title="Delete lead"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  )}
                </div>
              </div>
              <p className="mobile-data-card-meta">{lead.phone || '—'}</p>
              {lead.address && <p className="mobile-data-card-meta">{lead.address}</p>}
              <p className="mobile-data-card-meta capitalize">{formatCategory(lead.service_category)} · {formatDate(lead.created_at)}</p>
              {lead.needs_manual_review && (
                <span className="text-xs font-medium bg-amber-100 text-amber-800 px-2 py-0.5 rounded w-fit">Review</span>
              )}
            </div>
          ))}
        </div>

        <div className="hidden md:block overflow-x-auto">
          <table className="w-full text-sm divide-y divide-slate-200">
            <thead className="bg-slate-50">
              <tr>
                {isOwner && <th className="px-2 py-3 w-8" />}
                <th className="text-left px-3 py-3 font-medium text-slate-500">Contact</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Source / Brand</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Confidence</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Review reason</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Dup</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">PM</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Age</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Last contact</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Next action</th>
                <th className="text-left px-3 py-3 font-medium text-slate-500">Status</th>
                {canDelete && <th className="text-right px-3 py-3 font-medium text-slate-500 w-12" />}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {leads.length === 0 ? (
                <tr>
                  <td colSpan={canDelete ? 12 : 11} className="px-4 py-4">
                    <LeadsEmptyState
                      isPm={isPm}
                      view={view}
                      status={status}
                      search={search}
                      category={category}
                      loadState={loadState}
                      loadError={loadError}
                    />
                  </td>
                </tr>
              ) : leads.map((lead) => (
                <tr key={lead.id} className="hover:bg-slate-50 cursor-pointer transition-colors" onClick={() => navigate(`/leads/${lead.id}`)}>
                  {isOwner && (
                    <td className="px-2 py-3" onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        checked={selectedIds.includes(lead.id)}
                        onChange={(e) => {
                          setSelectedIds((ids) => e.target.checked ? [...ids, lead.id] : ids.filter((id) => id !== lead.id));
                        }}
                      />
                    </td>
                  )}
                  <td className="px-3 py-3">
                    <p className="font-medium text-blue-600">{lead.contact_name}</p>
                    <p className="text-xs text-slate-500">
                      {lead.contact_clickable && lead.phone ? (
                        <a href={`tel:${lead.phone}`} onClick={(e) => e.stopPropagation()} className="underline">{lead.phone}</a>
                      ) : (lead.phone || '—')}
                      {' · '}
                      {lead.contact_clickable && lead.email ? (
                        <a href={`mailto:${lead.email}`} onClick={(e) => e.stopPropagation()} className="underline">{lead.email}</a>
                      ) : (lead.email || '')}
                    </p>
                  </td>
                  <td className="px-3 py-3 text-xs">
                    <div>{lead.source_label || lead.source || '—'}</div>
                    <div className="text-slate-500">{lead.brand_name || '—'}</div>
                  </td>
                  <td className="px-3 py-3 text-xs">
                    {lead.confidence_summary?.min_score != null
                      ? `${lead.confidence_summary.min_score}%`
                      : '—'}
                    {lead.confidence_summary?.low_fields?.length > 0 && (
                      <div className="text-amber-700">{lead.confidence_summary.low_fields.join(', ')}</div>
                    )}
                  </td>
                  <td className="px-3 py-3 text-xs text-amber-800 max-w-[200px]">
                    {lead.needs_manual_review
                      ? (lead.review_reason || lead.confidence_summary?.review_reason || 'Needs review')
                      : '—'}
                  </td>
                  <td className="px-3 py-3 text-xs" onClick={(e) => e.stopPropagation()}>
                    {lead.duplicate_group_id ? (
                      <button
                        type="button"
                        className="text-blue-600 underline"
                        onClick={() => openDupGroup(lead.duplicate_group_id)}
                      >
                        {lead.is_duplicate_primary || lead.recommended_primary ? 'Primary' : 'Group'}
                      </button>
                    ) : '—'}
                  </td>
                  <td className="px-3 py-3 text-xs">{lead.assigned_pm?.name || '—'}</td>
                  <td className="px-3 py-3 text-xs">{lead.age_days != null ? `${lead.age_days}d` : '—'}</td>
                  <td className="px-3 py-3 text-xs">{formatDate(lead.last_contact_at)}</td>
                  <td className="px-3 py-3 text-xs max-w-[160px]">{lead.next_action?.action_description || '—'}</td>
                  <td className="px-3 py-3"><StatusBadge status={lead.status} /></td>
                  {canDelete && (
                    <td className="px-3 py-3 text-right">
                      <button type="button" onClick={(e) => confirmDelete(lead.id, e)} className="text-red-500 hover:text-red-700 p-1 rounded" title="Delete lead">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {meta.last > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200 text-sm">
            <span className="text-slate-500">{meta.total} leads</span>
            <div className="flex gap-2">
              <button disabled={meta.current <= 1} onClick={() => goToPage(meta.current - 1)}
                className="px-3 py-1 border rounded-lg disabled:opacity-40">Prev</button>
              <span className="px-2 py-1">Page {meta.current} of {meta.last}</span>
              <button disabled={meta.current >= meta.last} onClick={() => goToPage(meta.current + 1)}
                className="px-3 py-1 border rounded-lg disabled:opacity-40">Next</button>
            </div>
          </div>
        )}
      </div>

      <SlideOverPanel isOpen={panelOpen} onClose={() => setPanelOpen(false)} title="Create Lead">
        <LeadForm onSubmit={handleCreate} onCancel={() => setPanelOpen(false)} saving={saving} />
      </SlideOverPanel>

      <SlideOverPanel
        isOpen={!!selectedQ}
        onClose={() => setSelectedQ(null)}
        title="Review quarantined intake"
      >
        {selectedQ && (
          <div className="space-y-4 text-sm">
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900 text-xs">
              <div className="font-semibold">{selectedQ.quarantine_reason || 'Needs review'}</div>
              {selectedQ.duplicate_group_key && (
                <div className="mt-1">Duplicate group: {selectedQ.duplicate_group_key}</div>
              )}
              {Array.isArray(selectedQ.validation_errors) && selectedQ.validation_errors.length > 0 && (
                <ul className="mt-2 list-disc pl-4">
                  {selectedQ.validation_errors.map((err) => <li key={err}>{err}</li>)}
                </ul>
              )}
            </div>

            <div>
              <h3 className="text-xs font-semibold text-slate-500 uppercase mb-2">Correct fields</h3>
              <div className="space-y-2">
                {['contact_name', 'phone', 'email', 'address', 'project_description'].map((key) => (
                  <label key={key} className="block">
                    <span className="text-xs text-slate-500 capitalize">{key.replace(/_/g, ' ')}</span>
                    {key === 'project_description' ? (
                      <textarea
                        rows={3}
                        value={editFields[key] || ''}
                        onChange={(e) => setEditFields((f) => ({ ...f, [key]: e.target.value }))}
                        className="mt-1 w-full px-3 py-2 border border-slate-200 rounded-lg text-sm"
                      />
                    ) : (
                      <input
                        type="text"
                        value={editFields[key] || ''}
                        onChange={(e) => setEditFields((f) => ({ ...f, [key]: e.target.value }))}
                        className="mt-1 w-full px-3 py-2 border border-slate-200 rounded-lg text-sm"
                      />
                    )}
                  </label>
                ))}
              </div>
            </div>

            <div>
              <h3 className="text-xs font-semibold text-slate-500 uppercase mb-2">Field confidence</h3>
              <ConfidenceList items={selectedQ.field_confidence} />
            </div>

            <div>
              <h3 className="text-xs font-semibold text-slate-500 uppercase mb-2">Raw source</h3>
              <pre className="text-xs bg-slate-50 border border-slate-200 rounded-lg p-3 max-h-56 overflow-auto whitespace-pre-wrap break-words">
                {selectedQ.raw_email}
              </pre>
            </div>

            <div className="flex flex-col gap-2 pt-2 border-t border-slate-100">
              <button
                type="button"
                disabled={acting}
                onClick={approveQuarantine}
                className="w-full px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
              >
                Approve (create lead + notify)
              </button>
              <button
                type="button"
                disabled={acting}
                onClick={ignoreQuarantine}
                className="w-full px-4 py-2 bg-slate-100 text-slate-800 rounded-lg text-sm font-medium hover:bg-slate-200 disabled:opacity-50"
              >
                Permanently ignore
              </button>
              <button
                type="button"
                onClick={() => setSelectedQ(null)}
                className="w-full px-4 py-2 text-slate-500 text-sm"
              >
                Cancel
              </button>
            </div>
          </div>
        )}
      </SlideOverPanel>
      <SlideOverPanel
        isOpen={!!dupGroup}
        onClose={() => setDupGroup(null)}
        title="Duplicate lead group"
      >
        {dupGroup && (
          <div className="space-y-3 text-sm">
            <p className="text-xs text-slate-500">
              Recommended primary: #{dupGroup.recommended_primary_id}
            </p>
            <ul className="space-y-2">
              {dupGroup.leads.map((l) => (
                <li key={l.id} className={`border rounded-lg p-2 ${l.id === dupGroup.recommended_primary_id ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200'}`}>
                  <div className="font-medium">#{l.id} {l.contact_name}</div>
                  <div className="text-xs text-slate-500">{l.phone || '—'} · {l.email || '—'}</div>
                  {l.id === dupGroup.recommended_primary_id && (
                    <div className="text-xs text-emerald-700 mt-1">Recommended primary</div>
                  )}
                </li>
              ))}
            </ul>
            {isOwner && (
              <button type="button" onClick={mergeDupGroup} className="w-full px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">
                Merge into recommended primary
              </button>
            )}
          </div>
        )}
      </SlideOverPanel>
    </div>
  );
}
