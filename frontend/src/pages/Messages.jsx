import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';

function JobMessagesPanel() {
  const { user } = useAuth();
  const [jobs, setJobs] = useState([]);
  const [selectedJob, setSelectedJob] = useState(null);
  const [messages, setMessages] = useState([]);
  const [msgTab, setMsgTab] = useState('customer_visible');
  const [newMsg, setNewMsg] = useState('');
  const [jobsError, setJobsError] = useState(null);

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
    if (!selectedJob) return;
    api.get(`/jobs/${selectedJob.id}/messages`, { params: { visibility: msgTab } })
      .then(({ data }) => setMessages(data)).catch(() => setMessages([]));
  }, [selectedJob, msgTab]);

  const sendMessage = async (e) => {
    e.preventDefault();
    if (!newMsg.trim() || !selectedJob) return;

    const ok = await confirmAction({
      title: 'Send message?',
      text: msgTab === 'internal' ? 'Send this internal note?' : 'Send this message to the customer?',
      confirmText: 'Yes, send',
    });
    if (!ok) return;

    try {
      await api.post(`/jobs/${selectedJob.id}/messages`, { content: newMsg, visibility: msgTab });
      setNewMsg('');
      const { data } = await api.get(`/jobs/${selectedJob.id}/messages`, { params: { visibility: msgTab } });
      setMessages(data);
      await showSuccess('Message sent.');
    } catch (err) {
      await showError(err.response?.data?.message || 'Failed to send message.');
    }
  };

  const isCustomer = user?.role === 'customer';

  return (
    <div className="flex flex-col md:flex-row gap-4" style={{ height: 'calc(100vh - 200px)' }}>
      <div className="md:w-72 bg-white rounded-xl border border-slate-200 overflow-y-auto flex-shrink-0">
        <p className="text-xs font-semibold text-slate-400 uppercase px-4 py-3">Jobs</p>
        {jobs.map((job) => (
          <button key={job.id} onClick={() => setSelectedJob(job)}
            className={`w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50 ${selectedJob?.id === job.id ? 'bg-blue-50 border-l-2 border-l-blue-600' : ''}`}>
            <p className="text-sm font-medium text-slate-800 truncate">Job #{job.id}</p>
            <p className="text-xs text-slate-500 truncate">{job.address}</p>
          </button>
        ))}
        {jobs.length === 0 && (
          <p className="text-sm text-slate-500 px-4 py-8 text-center">{jobsError || 'No jobs found.'}</p>
        )}
      </div>

      <div className="flex-1 bg-white rounded-xl border border-slate-200 flex flex-col min-w-0">
        {selectedJob ? (
          <>
            <div className="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
              <div>
                <p className="font-medium text-slate-800">Job #{selectedJob.id}</p>
                <p className="text-xs text-slate-500">{selectedJob.address}</p>
              </div>
              <Link to={`/jobs/${selectedJob.id}`} className="text-xs text-blue-600 hover:underline">View Job</Link>
            </div>
            {!isCustomer && (
              <div className="flex border-b border-slate-200">
                {['customer_visible', 'internal'].map((v) => (
                  <button key={v} onClick={() => setMsgTab(v)}
                    className={`px-4 py-2 text-sm ${msgTab === v ? 'border-b-2 border-blue-600 text-blue-600 font-medium' : 'text-slate-500'}`}>
                    {v === 'customer_visible' ? 'Customer Chat' : 'Internal Notes'}
                  </button>
                ))}
              </div>
            )}
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
              <input value={newMsg} onChange={(e) => setNewMsg(e.target.value)} placeholder="Type a message..."
                className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm" />
              <button type="submit" className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg">Send</button>
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
