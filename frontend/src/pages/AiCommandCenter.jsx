import { useEffect, useRef, useState } from 'react';
import { Bot, Send, Plus } from 'lucide-react';
import api from '../api/axios';
import PageHeader from '../components/PageHeader';
import { useAuth } from '../context/AuthContext';
import { confirmAction, showError, showSuccess } from '../utils/swal';

export default function AiCommandCenter() {
  const { user } = useAuth();
  const [sessions, setSessions] = useState([]);
  const [sessionId, setSessionId] = useState(null);
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [pending, setPending] = useState(null);
  const bottomRef = useRef(null);

  const loadSessions = () => {
    api.get('/command-center/sessions')
      .then(({ data }) => setSessions(data.data || []))
      .catch(() => setSessions([]));
  };

  const loadSession = (id) => {
    if (!id) return;
    api.get(`/command-center/sessions/${id}`)
      .then(({ data }) => {
        setSessionId(data.session.id);
        setMessages(data.messages || []);
        const last = [...(data.messages || [])].reverse().find((m) => m.role === 'assistant');
        setPending(last?.meta?.pending_action || null);
      })
      .catch(async (e) => {
        await showError(e.response?.data?.message || 'Failed to load session');
      });
  };

  useEffect(() => { loadSessions(); }, []);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, pending]);

  const newChat = async () => {
    try {
      const { data } = await api.post('/command-center/sessions');
      setSessionId(data.session.id);
      setMessages([]);
      setPending(null);
      loadSessions();
    } catch (e) {
      await showError(e.response?.data?.message || 'Could not start chat');
    }
  };

  const send = async (e) => {
    e?.preventDefault();
    if (!input.trim() || busy) return;
    setBusy(true);
    const text = input.trim();
    setInput('');
    try {
      const { data } = await api.post('/command-center/ask', {
        message: text,
        session_id: sessionId,
      });
      setSessionId(data.session.id);
      setMessages((prev) => [...prev, data.user_message, data.assistant_message]);
      setPending(data.pending_action || null);
      loadSessions();
    } catch (err) {
      await showError(err.response?.data?.message || 'Command failed');
    } finally {
      setBusy(false);
    }
  };

  const confirmPending = async () => {
    if (!pending || !sessionId) return;
    const ok = await confirmAction({
      title: 'Send this message?',
      text: pending.message,
      confirmText: 'Yes, send',
    });
    if (!ok) return;
    setBusy(true);
    try {
      const { data } = await api.post('/command-center/confirm', {
        session_id: sessionId,
        pending_action: pending,
      });
      setPending(null);
      if (data.message) setMessages((prev) => [...prev, data.message]);
      await showSuccess(data.result?.status === 'executed' ? 'Action executed' : 'Done');
    } catch (err) {
      await showError(err.response?.data?.message || 'Confirm failed');
    } finally {
      setBusy(false);
    }
  };

  const suggestions = [
    'How are things going today?',
    'Any leads stuck?',
    'Which PMs need follow-up?',
    'What jobs are ready for payout?',
    'Show me anything that needs my attention',
  ];

  return (
    <div className="space-y-4">
      <PageHeader title="AI Command Center" subtitle="Ask about live ops data — Owner only" />

      <div className="flex flex-col md:flex-row gap-4" style={{ height: 'calc(100vh - 200px)' }}>
        <div className="md:w-64 bg-white rounded-xl border border-slate-200 overflow-y-auto flex-shrink-0">
          <div className="px-3 py-3 border-b border-slate-100 flex items-center justify-between">
            <p className="text-xs font-semibold text-slate-400 uppercase">Chats</p>
            <button type="button" onClick={newChat} className="text-blue-600 hover:bg-blue-50 rounded p-1" title="New chat">
              <Plus className="w-4 h-4" />
            </button>
          </div>
          {sessions.map((s) => (
            <button
              key={s.id}
              type="button"
              onClick={() => loadSession(s.id)}
              className={`w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50 ${sessionId === s.id ? 'bg-blue-50 border-l-2 border-l-blue-600' : ''}`}
            >
              <p className="text-sm font-medium text-slate-800 truncate">{s.title || `Chat #${s.id}`}</p>
              <p className="text-xs text-slate-400">{s.last_message_at ? new Date(s.last_message_at).toLocaleString() : ''}</p>
            </button>
          ))}
          {sessions.length === 0 && (
            <p className="text-sm text-slate-500 px-4 py-8 text-center">No chats yet.</p>
          )}
        </div>

        <div className="flex-1 bg-white rounded-xl border border-slate-200 flex flex-col min-w-0">
          <div className="px-4 py-3 border-b border-slate-200 flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center">
              <Bot className="w-4 h-4 text-white" />
            </div>
            <div>
              <p className="font-medium text-slate-800 text-sm">ServiceOP Ops Assistant</p>
              <p className="text-xs text-slate-500">Signed in as {user?.name} (owner)</p>
            </div>
          </div>

          <div className="flex-1 overflow-y-auto p-4 space-y-3">
            {messages.length === 0 && (
              <div className="space-y-3">
                <p className="text-sm text-slate-500">Try one of these:</p>
                <div className="flex flex-wrap gap-2">
                  {suggestions.map((s) => (
                    <button
                      key={s}
                      type="button"
                      onClick={() => setInput(s)}
                      className="text-xs px-3 py-1.5 rounded-full border border-slate-200 text-slate-600 hover:bg-slate-50"
                    >
                      {s}
                    </button>
                  ))}
                </div>
              </div>
            )}
            {messages.map((m) => {
              const mine = m.role === 'user';
              return (
                <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                  <div className={`max-w-[85%] rounded-xl px-3 py-2 text-sm whitespace-pre-wrap ${
                    mine ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'
                  }`}>
                    {m.content}
                    {!mine && m.meta?.usage?.total_tokens != null && (
                      <p className={`text-[10px] mt-1 ${mine ? 'text-blue-100' : 'text-slate-400'}`}>
                        ~{m.meta.usage.total_tokens} tokens
                        {m.meta.usage.estimated_cost_usd != null ? ` · ~$${m.meta.usage.estimated_cost_usd}` : ''}
                        {m.meta.kill_switch ? ' · kill switch (read-only actions)' : ''}
                      </p>
                    )}
                  </div>
                </div>
              );
            })}
            {pending && (
              <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 space-y-2">
                <p className="text-sm font-medium text-amber-900">Draft pending confirmation</p>
                <p className="text-sm text-amber-800 whitespace-pre-wrap">To: {pending.pm_name}<br />{pending.message}</p>
                <button
                  type="button"
                  disabled={busy}
                  onClick={confirmPending}
                  className="px-3 py-1.5 bg-amber-600 text-white text-sm rounded-lg disabled:opacity-50"
                >
                  Confirm &amp; send
                </button>
              </div>
            )}
            <div ref={bottomRef} />
          </div>

          <form onSubmit={send} className="p-3 border-t border-slate-200 flex gap-2">
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Ask about leads, jobs, payouts…"
              className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              disabled={busy}
            />
            <button
              type="submit"
              disabled={busy || !input.trim()}
              className="inline-flex items-center gap-1 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium disabled:opacity-50"
            >
              <Send className="w-4 h-4" />
              {busy ? '…' : 'Send'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
