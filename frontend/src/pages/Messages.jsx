import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';

export default function Messages() {
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
    <div>
      <PageHeader title="Messages" />
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
            <p className="text-sm text-slate-500 px-4 py-8 text-center">
              {jobsError || 'No jobs found.'}
            </p>
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
    </div>
  );
}
