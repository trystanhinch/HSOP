import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Lock, MessageSquare } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { useAuth } from '../context/AuthContext';
import { channelLabel, looksLikeInternalContent } from '../utils/messageSafety';
import { confirmAction, showError, showSuccess } from '../utils/swal';

/**
 * CT-02: site-visit + job threads for contractors (lead messages path).
 * PM-04: separate Customer Chat / Internal Notes drafts + sensitive-content warning.
 */
function JobMessagesPanel() {
  const { user } = useAuth();
  const [jobs, setJobs] = useState([]);
  const [selectedJob, setSelectedJob] = useState(null);
  const [messages, setMessages] = useState([]);
  const [msgTab, setMsgTab] = useState('customer_visible');
  const [customerDraft, setCustomerDraft] = useState('');
  const [internalDraft, setInternalDraft] = useState('');
  const [pmDraft, setPmDraft] = useState('');
  const [jobsError, setJobsError] = useState(null);

  const isContractor = user?.role === 'contractor';
  const isCustomer = user?.role === 'customer';
  const isAssignmentThread = isContractor || selectedJob?.type === 'site_visit';

  const activeDraft = isAssignmentThread
    ? pmDraft
    : (msgTab === 'internal' ? internalDraft : customerDraft);
  const setActiveDraft = isAssignmentThread
    ? setPmDraft
    : (msgTab === 'internal' ? setInternalDraft : setCustomerDraft);

  const messagesPath = (item) => {
    if (!item) return null;
    if (item.type === 'site_visit' && item.lead_id) return `/leads/${item.lead_id}/messages`;
    if (item.messages_path) return item.messages_path;
    return `/jobs/${item.id}/messages`;
  };

  const itemKey = (item) => (item ? `${item.type || 'job'}-${item.lead_id || item.id}` : '');

  useEffect(() => {
    api.get('/jobs', { params: { per_page: 50 } })
      .then(({ data }) => {
        const list = data.data || data;
        setJobs(list);
        if (list.length > 0) setSelectedJob(list[0]);
      })
      .catch((err) => {
        setJobs([]);
        setJobsError(err.response?.data?.message || 'Failed to load jobs');
      });
  }, []);

  useEffect(() => {
    if (!selectedJob?.id || selectedJob.type === 'site_visit') return;
    api.get(`/jobs/${selectedJob.id}`)
      .then(({ data }) => setSelectedJob((prev) => (prev?.id === data.id ? { ...prev, ...data } : prev)))
      .catch(() => {});
  }, [selectedJob?.id, selectedJob?.type]);

  useEffect(() => {
    if (!selectedJob) return;
    const path = messagesPath(selectedJob);
    if (!path) return;
    const params = isAssignmentThread ? {} : { visibility: msgTab };
    api.get(path, { params })
      .then(({ data }) => setMessages(data.messages || data))
      .catch(() => setMessages([]));
  }, [selectedJob, msgTab, isAssignmentThread]);

  const switchTab = async (next) => {
    if (next === msgTab || isAssignmentThread) return;
    const leaving = msgTab;
    const draft = leaving === 'internal' ? internalDraft : customerDraft;
    if (draft.trim()) {
      const ok = await confirmAction({
        title: 'Discard unsent draft?',
        text: `You have an unsent ${channelLabel(leaving)} — discard it?`,
        confirmText: 'Discard draft',
        icon: 'warning',
      });
      if (!ok) return;
      if (leaving === 'internal') setInternalDraft('');
      else setCustomerDraft('');
    }
    setMsgTab(next);
  };

  const sendMessage = async (e) => {
    e.preventDefault();
    if (!activeDraft.trim() || !selectedJob) return;

    if (!isAssignmentThread && msgTab === 'customer_visible' && looksLikeInternalContent(activeDraft)) {
      const proceed = await confirmAction({
        title: 'Possible internal information',
        text: 'This looks like it might contain internal information — send to customer anyway?',
        confirmText: 'Send to customer',
        icon: 'warning',
      });
      if (!proceed) return;
    }

    const ok = await confirmAction({
      title: 'Send message?',
      text: isAssignmentThread
        ? 'Send this message to your project manager?'
        : (msgTab === 'internal'
          ? 'Send this internal note? (never shown to customer)'
          : 'Send this message to the customer?'),
      confirmText: 'Yes, send',
    });
    if (!ok) return;

    try {
      const path = messagesPath(selectedJob);
      if (isAssignmentThread) {
        await api.post(path, { content: activeDraft });
        setPmDraft('');
        const { data } = await api.get(path);
        setMessages(data.messages || data);
      } else {
        await api.post(path, { content: activeDraft, visibility: msgTab });
        setActiveDraft('');
        const { data } = await api.get(path, { params: { visibility: msgTab } });
        setMessages(data.messages || data);
      }
      await showSuccess('Message sent.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to send message.');
    }
  };

  const customerName = selectedJob?.customer?.name
    || selectedJob?.customer_name
    || selectedJob?.lead?.contact_name
    || 'Customer';
  const deliveryChannel = 'In-app portal'
    + (selectedJob?.customer?.email || selectedJob?.lead?.email ? ' · Email notify' : '')
    + (selectedJob?.customer?.phone || selectedJob?.lead?.phone ? ' · SMS notify' : '');

  const panelTone = isAssignmentThread
    ? 'bg-white border-slate-200'
    : (msgTab === 'internal' ? 'bg-amber-50/80 border-amber-200' : 'bg-sky-50/50 border-sky-200');
  const threadTone = isAssignmentThread
    ? 'bg-white'
    : (msgTab === 'internal' ? 'bg-amber-50/40' : 'bg-white');
  const composerTone = isAssignmentThread
    ? 'bg-slate-50 border-slate-200'
    : (msgTab === 'internal' ? 'bg-amber-100/70 border-amber-200' : 'bg-sky-100/60 border-sky-200');

  return (
    <div className="flex flex-col md:flex-row gap-4" style={{ height: 'calc(100vh - 200px)' }}>
      <div className="md:w-72 bg-white rounded-xl border border-slate-200 overflow-y-auto flex-shrink-0">
        <p className="text-xs font-semibold text-slate-400 uppercase px-4 py-3">
          {isContractor ? 'Jobs & Site Visits' : 'Jobs'}
        </p>
        {jobs.map((job) => (
          <button
            key={itemKey(job)}
            type="button"
            onClick={() => setSelectedJob(job)}
            className={`w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50 ${itemKey(selectedJob) === itemKey(job) ? 'bg-blue-50 border-l-2 border-l-blue-600' : ''}`}
          >
            <p className="text-sm font-medium text-slate-800 truncate">
              {job.type === 'site_visit' ? (job.job_title || 'Site Visit') : `Job #${job.id}`}
            </p>
            <p className="text-xs text-slate-500 truncate">{job.address}</p>
            {job.type === 'site_visit' && (
              <span className="inline-block mt-1 text-[10px] uppercase tracking-wide text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded">Site visit</span>
            )}
          </button>
        ))}
        {jobs.length === 0 && (
          <p className="text-sm text-slate-500 px-4 py-8 text-center">{jobsError || 'No jobs found.'}</p>
        )}
      </div>

      <div className={`flex-1 rounded-xl border flex flex-col min-w-0 ${panelTone}`}>
        {selectedJob ? (
          <>
            <div className="px-4 py-3 border-b border-slate-200/80 flex items-center justify-between bg-white/70 rounded-t-xl">
              <div>
                <p className="font-medium text-slate-800">
                  {selectedJob.type === 'site_visit'
                    ? (selectedJob.job_title || selectedJob.customer?.name || 'Site Visit')
                    : `Job #${selectedJob.id}`}
                </p>
                <p className="text-xs text-slate-500">{selectedJob.address}</p>
                {selectedJob.pm?.name && (
                  <p className="text-xs text-slate-500 mt-0.5">PM: {selectedJob.pm.name}</p>
                )}
              </div>
              <Link to={selectedJob.url || `/jobs/${selectedJob.id}`} className="text-xs text-blue-600 hover:underline">
                View Details
              </Link>
            </div>

            {!isCustomer && !isAssignmentThread && (
              <div className="flex border-b border-slate-200/80 bg-white/50">
                <button type="button" onClick={() => switchTab('customer_visible')}
                  className={`flex-1 px-4 py-2.5 text-sm inline-flex items-center justify-center gap-2 ${msgTab === 'customer_visible' ? 'border-b-2 border-sky-600 text-sky-800 font-semibold bg-sky-50' : 'text-slate-500'}`}>
                  <MessageSquare className="w-4 h-4" /> Customer Chat
                </button>
                <button type="button" onClick={() => switchTab('internal')}
                  className={`flex-1 px-4 py-2.5 text-sm inline-flex items-center justify-center gap-2 ${msgTab === 'internal' ? 'border-b-2 border-amber-700 text-amber-900 font-semibold bg-amber-50' : 'text-slate-500'}`}>
                  <Lock className="w-4 h-4" /> Internal Notes
                </button>
              </div>
            )}

            {isAssignmentThread && (
              <div className="px-4 py-2 border-b border-slate-100 bg-slate-50">
                <p className="text-xs text-slate-600">Contractor ↔ PM thread (owner/PM internal notes are not visible here)</p>
              </div>
            )}

            <div className={`flex-1 overflow-y-auto p-4 space-y-3 ${threadTone}`}>
              {messages.map((m) => {
                const mine = m.sender_id === user?.id;
                const bubble = isAssignmentThread
                  ? (mine ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800')
                  : (msgTab === 'internal'
                    ? (mine ? 'bg-amber-700 text-white' : 'bg-amber-100 text-amber-950 border border-amber-200')
                    : (mine ? 'bg-sky-700 text-white' : 'bg-white text-slate-800 border border-slate-200'));
                return (
                  <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                    <div className={`max-w-[75%] rounded-xl px-4 py-2 text-sm ${bubble}`}>
                      {!mine && <p className="text-xs opacity-70 mb-1">{m.sender?.name}</p>}
                      {m.content}
                    </div>
                  </div>
                );
              })}
              {messages.length === 0 && (
                <p className="text-sm text-slate-500 text-center py-8">
                  {isAssignmentThread
                    ? 'No messages yet. Ask your PM a question.'
                    : (msgTab === 'internal' ? 'No internal notes yet.' : 'No customer messages yet.')}
                </p>
              )}
            </div>

            <form onSubmit={sendMessage} className={`border-t p-3 space-y-2 rounded-b-xl ${composerTone}`}>
              {isAssignmentThread ? (
                <p className="text-xs font-semibold text-slate-700">To: Project Manager</p>
              ) : msgTab === 'internal' ? (
                <p className="text-xs font-semibold text-amber-900 flex items-center gap-1.5">
                  <Lock className="w-3.5 h-3.5" /> Internal — never shown to customer
                </p>
              ) : (
                <p className="text-xs font-semibold text-sky-900">
                  To: {customerName} · Delivery: {deliveryChannel}
                </p>
              )}
              <div className="flex gap-2">
                <input
                  value={activeDraft}
                  onChange={(e) => setActiveDraft(e.target.value)}
                  placeholder={
                    isAssignmentThread
                      ? 'Message your PM…'
                      : (msgTab === 'internal' ? 'Write an internal note…' : 'Message the customer…')
                  }
                  className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white"
                />
                <button
                  type="submit"
                  className={`px-4 py-2 text-white text-sm rounded-lg ${
                    isAssignmentThread
                      ? 'bg-blue-600 hover:bg-blue-700'
                      : (msgTab === 'internal' ? 'bg-amber-800 hover:bg-amber-900' : 'bg-sky-700 hover:bg-sky-800')
                  }`}
                >
                  Send
                </button>
              </div>
            </form>
          </>
        ) : (
          <div className="flex-1 flex items-center justify-center text-slate-500 text-sm">Select a job to view messages</div>
        )}
      </div>
    </div>
  );
}

function AdminPmMessagesPanel() {
  const { user } = useAuth();
  const [conversations, setConversations] = useState([]);
  const [selectedUser, setSelectedUser] = useState(null);
  const [messages, setMessages] = useState([]);
  const [newMsg, setNewMsg] = useState('');

  const loadConversations = () => {
    api.get('/admin-pm-messages/conversations')
      .then(({ data }) => {
        setConversations(data);
        if (!selectedUser && data.length > 0) setSelectedUser(data[0]);
      })
      .catch(() => setConversations([]));
  };

  useEffect(() => { loadConversations(); }, []);

  useEffect(() => {
    if (!selectedUser) return;
    api.get(`/admin-pm-messages/with/${selectedUser.user_id}`)
      .then(({ data }) => setMessages(data))
      .catch(() => setMessages([]));
  }, [selectedUser]);

  const sendMessage = async (e) => {
    e.preventDefault();
    if (!newMsg.trim() || !selectedUser) return;

    try {
      await api.post(`/admin-pm-messages/with/${selectedUser.user_id}`, { content: newMsg });
      setNewMsg('');
      const { data } = await api.get(`/admin-pm-messages/with/${selectedUser.user_id}`);
      setMessages(data);
      loadConversations();
      await showSuccess('Message sent.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to send message.');
    }
  };

  const listLabel = user?.role === 'owner' ? 'Project Managers' : 'Admin';

  return (
    <div className="flex flex-col md:flex-row gap-4" style={{ height: 'calc(100vh - 200px)' }}>
      <div className="md:w-72 bg-white rounded-xl border border-slate-200 overflow-y-auto flex-shrink-0">
        <p className="text-xs font-semibold text-slate-400 uppercase px-4 py-3">{listLabel}</p>
        {conversations.map((c) => (
          <button key={c.user_id} onClick={() => setSelectedUser(c)}
            className={`w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50 ${selectedUser?.user_id === c.user_id ? 'bg-blue-50 border-l-2 border-l-blue-600' : ''}`}>
            <p className="text-sm font-medium text-slate-800 truncate">{c.name}</p>
            {c.last_message && <p className="text-xs text-slate-500 truncate">{c.last_message}</p>}
            {c.unread_count > 0 && (
              <span className="inline-block mt-1 text-xs bg-blue-600 text-white rounded-full px-2 py-0.5">{c.unread_count}</span>
            )}
          </button>
        ))}
        {conversations.length === 0 && (
          <p className="text-sm text-slate-500 px-4 py-8 text-center">No conversations yet.</p>
        )}
      </div>

      <div className="flex-1 bg-white rounded-xl border border-slate-200 flex flex-col min-w-0">
        {selectedUser ? (
          <>
            <div className="px-4 py-3 border-b border-slate-200">
              <p className="font-medium text-slate-800">{selectedUser.name}</p>
              <p className="text-xs text-slate-500">{selectedUser.email}</p>
            </div>
            <div className="flex-1 overflow-y-auto p-4 space-y-3">
              {messages.map((m) => {
                const mine = m.sender_id === user?.id;
                return (
                  <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                    <div className={`max-w-[75%] rounded-xl px-4 py-2 text-sm ${mine ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'}`}>
                      {!mine && <p className="text-xs opacity-70 mb-1">{m.sender?.name}</p>}
                      {m.content}
                    </div>
                  </div>
                );
              })}
            </div>
            <form onSubmit={sendMessage} className="border-t border-slate-200 p-3 flex gap-2">
              <input value={newMsg} onChange={(e) => setNewMsg(e.target.value)} placeholder="Message your PM..."
                className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <button type="submit" className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg">Send</button>
            </form>
          </>
        ) : (
          <div className="flex-1 flex items-center justify-center text-slate-500 text-sm">Select a conversation</div>
        )}
      </div>
    </div>
  );
}

function PmContractorMessagesPanel() {
  const { user } = useAuth();
  const [conversations, setConversations] = useState([]);
  const [selectedUser, setSelectedUser] = useState(null);
  const [messages, setMessages] = useState([]);
  const [newMsg, setNewMsg] = useState('');

  const loadConversations = () => {
    api.get('/pm-contractor-messages/conversations')
      .then(({ data }) => {
        setConversations(data);
        if (!selectedUser && data.length > 0) setSelectedUser(data[0]);
      })
      .catch(() => setConversations([]));
  };

  useEffect(() => { loadConversations(); }, []);

  useEffect(() => {
    if (!selectedUser) return;
    api.get(`/pm-contractor-messages/with/${selectedUser.user_id}`)
      .then(({ data }) => setMessages(data))
      .catch(() => setMessages([]));
  }, [selectedUser]);

  const sendMessage = async (e) => {
    e.preventDefault();
    if (!newMsg.trim() || !selectedUser) return;

    try {
      await api.post(`/pm-contractor-messages/with/${selectedUser.user_id}`, { content: newMsg });
      setNewMsg('');
      const { data } = await api.get(`/pm-contractor-messages/with/${selectedUser.user_id}`);
      setMessages(data);
      loadConversations();
      await showSuccess('Message sent.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to send message.');
    }
  };

  const listLabel = user?.role === 'pm' ? 'Contractors' : 'Project Managers';

  return (
    <div className="flex flex-col md:flex-row gap-4" style={{ height: 'calc(100vh - 200px)' }}>
      <div className="md:w-72 bg-white rounded-xl border border-slate-200 overflow-y-auto flex-shrink-0">
        <p className="text-xs font-semibold text-slate-400 uppercase px-4 py-3">{listLabel}</p>
        {conversations.map((c) => (
          <button key={c.user_id} onClick={() => setSelectedUser(c)}
            className={`w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50 ${selectedUser?.user_id === c.user_id ? 'bg-blue-50 border-l-2 border-l-blue-600' : ''}`}>
            <p className="text-sm font-medium text-slate-800 truncate">{c.name}</p>
            {c.last_message && <p className="text-xs text-slate-500 truncate">{c.last_message}</p>}
            {c.unread_count > 0 && (
              <span className="inline-block mt-1 text-xs bg-blue-600 text-white rounded-full px-2 py-0.5">{c.unread_count}</span>
            )}
          </button>
        ))}
        {conversations.length === 0 && (
          <p className="text-sm text-slate-500 px-4 py-8 text-center">No conversations yet.</p>
        )}
      </div>

      <div className="flex-1 bg-white rounded-xl border border-slate-200 flex flex-col min-w-0">
        {selectedUser ? (
          <>
            <div className="px-4 py-3 border-b border-slate-200">
              <p className="font-medium text-slate-800">{selectedUser.name}</p>
              <p className="text-xs text-slate-500">{selectedUser.email}</p>
            </div>
            <div className="flex-1 overflow-y-auto p-4 space-y-3">
              {messages.map((m) => {
                const mine = m.sender_id === user?.id;
                return (
                  <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                    <div className={`max-w-[75%] rounded-xl px-4 py-2 text-sm ${mine ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'}`}>
                      {!mine && <p className="text-xs opacity-70 mb-1">{m.sender?.name}</p>}
                      {m.content}
                    </div>
                  </div>
                );
              })}
            </div>
            <form onSubmit={sendMessage} className="border-t border-slate-200 p-3 flex gap-2">
              <input value={newMsg} onChange={(e) => setNewMsg(e.target.value)}
                placeholder={user?.role === 'pm' ? 'Message contractor...' : 'Message your PM...'}
                className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <button type="submit" className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg">Send</button>
            </form>
          </>
        ) : (
          <div className="flex-1 flex items-center justify-center text-slate-500 text-sm">Select a conversation</div>
        )}
      </div>
    </div>
  );
}

export default function Messages() {
  const { user } = useAuth();
  const isAdmin = user?.role === 'owner';
  const isPm = user?.role === 'pm';
  const [pmTab, setPmTab] = useState('jobs');
  const [contractorTab, setContractorTab] = useState('jobs');

  if (isAdmin) {
    return (
      <div>
        <PageHeader title="Messages" subtitle="Communicate with your project managers" />
        <AdminPmMessagesPanel />
      </div>
    );
  }

  if (isPm) {
    return (
      <div>
        <PageHeader title="Messages" />
        <div className="flex gap-2 mb-4">
          <button type="button" onClick={() => setPmTab('jobs')}
            className={`px-4 py-2 text-sm rounded-lg ${pmTab === 'jobs' ? 'bg-blue-600 text-white' : 'bg-white border border-slate-200 text-slate-600'}`}>
            Job Messages
          </button>
          <button type="button" onClick={() => setPmTab('contractors')}
            className={`px-4 py-2 text-sm rounded-lg ${pmTab === 'contractors' ? 'bg-blue-600 text-white' : 'bg-white border border-slate-200 text-slate-600'}`}>
            Contractor Messages
          </button>
          <button type="button" onClick={() => setPmTab('admin')}
            className={`px-4 py-2 text-sm rounded-lg ${pmTab === 'admin' ? 'bg-blue-600 text-white' : 'bg-white border border-slate-200 text-slate-600'}`}>
            Admin Messages
          </button>
        </div>
        {pmTab === 'jobs' && <JobMessagesPanel />}
        {pmTab === 'contractors' && <PmContractorMessagesPanel />}
        {pmTab === 'admin' && <AdminPmMessagesPanel />}
      </div>
    );
  }

  return (
    <div>
      <PageHeader title="Messages" />
      <div className="flex gap-2 mb-4">
        <button type="button" onClick={() => setContractorTab('jobs')}
          className={`px-4 py-2 text-sm rounded-lg ${contractorTab === 'jobs' ? 'bg-blue-600 text-white' : 'bg-white border border-slate-200 text-slate-600'}`}>
          Job Messages
        </button>
        <button type="button" onClick={() => setContractorTab('pm')}
          className={`px-4 py-2 text-sm rounded-lg ${contractorTab === 'pm' ? 'bg-blue-600 text-white' : 'bg-white border border-slate-200 text-slate-600'}`}>
          PM Messages
        </button>
      </div>
      {contractorTab === 'jobs' ? <JobMessagesPanel /> : <PmContractorMessagesPanel />}
    </div>
  );
}
