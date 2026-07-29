import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
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
  const [search, setSearch] = useState('');
  const [savedQueries, setSavedQueries] = useState([]);
  const [killSwitch, setKillSwitch] = useState(false);
  const [mode, setMode] = useState('suggestion');
  const bottomRef = useRef(null);

  const loadSessions = (q = search) => {
    api.get('/command-center/sessions', { params: q ? { q } : {} })
      .then(({ data }) => {
        setSessions(data.data || []);
        setKillSwitch(Boolean(data.ai_kill_switch));
        setMode(data.mode || 'suggestion');
      })
      .catch(() => setSessions([]));
  };

  const loadSaved = () => {
    api.get('/command-center/saved-queries')
      .then(({ data }) => setSavedQueries(data.data || []))
      .catch(() => setSavedQueries([]));
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

  useEffect(() => { loadSessions(); loadSaved(); }, []);

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

  const renameSession = async (s) => {
    const title = window.prompt('Rename conversation', s.title || '');
    if (!title) return;
    try {
      await api.patch(`/command-center/sessions/${s.id}`, { title });
      loadSessions();
    } catch (e) {
      await showError(e.response?.data?.message || 'Rename failed');
    }
  };

  const deleteSession = async (s) => {
    const ok = await confirmAction({ title: 'Delete conversation?', text: s.title || `Chat #${s.id}`, confirmText: 'Delete' });
    if (!ok) return;
    try {
      await api.delete(`/command-center/sessions/${s.id}`);
      if (sessionId === s.id) {
        setSessionId(null);
        setMessages([]);
        setPending(null);
      }
      loadSessions();
    } catch (e) {
      await showError(e.response?.data?.message || 'Delete failed');
    }
  };

  const saveQuery = async () => {
    if (!input.trim()) return;
    const name = window.prompt('Name this saved query', input.slice(0, 40));
    if (!name) return;
    try {
      await api.post('/command-center/saved-queries', { name, query_text: input.trim() });
      loadSaved();
      await showSuccess('Saved');
    } catch (e) {
      await showError(e.response?.data?.message || 'Could not save query');
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
      title: 'Confirm sensitive AI action?',
      text: `${pending.consequences || 'This will send a live message.'}\n\nTo: ${pending.pm_name}\n${pending.message}`,
      confirmText: 'Yes, execute',
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
      await showSuccess(data.result?.status === 'executed' || data.result?.audit ? 'Action executed + audited' : 'Done');
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
      <PageHeader title="AI Command Center" subtitle="Cited ops answers — Owner / PM (brand-scoped actions)" />

      {killSwitch && (
        <div className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900" role="status">
          <strong>AI kill switch is ON.</strong> Actions are blocked; queries remain read-only.
        </div>
      )}

      <div className="flex flex-col md:flex-row gap-4" style={{ height: 'calc(100vh - 220px)' }}>
        <div className="md:w-64 bg-white rounded-xl border border-slate-200 overflow-y-auto flex-shrink-0 flex flex-col">
          <div className="px-3 py-3 border-b border-slate-100 flex items-center justify-between">
            <p className="text-xs font-semibold text-slate-400 uppercase">Chats</p>
            <button type="button" onClick={newChat} className="text-blue-600 hover:bg-blue-50 rounded p-1" title="New chat">
              <Plus className="w-4 h-4" />
            </button>
          </div>
          <div className="px-3 py-2 border-b border-slate-100">
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') loadSessions(search); }}
              placeholder="Search conversations…"
              className="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5"
            />
          </div>
          <div className="flex-1 overflow-y-auto">
            {sessions.map((s) => (
              <div
                key={s.id}
                className={`w-full text-left px-3 py-2 border-b border-slate-100 hover:bg-slate-50 ${sessionId === s.id ? 'bg-blue-50 border-l-2 border-l-blue-600' : ''}`}
              >
                <button type="button" onClick={() => loadSession(s.id)} className="w-full text-left">
                  <p className="text-sm font-medium text-slate-800 truncate">{s.title || `Chat #${s.id}`}</p>
                  <p className="text-xs text-slate-400">{s.last_message_at ? new Date(s.last_message_at).toLocaleString() : ''}</p>
                </button>
                <div className="flex gap-2 mt-1">
                  <button type="button" className="text-[10px] text-slate-500 hover:text-blue-600" onClick={() => renameSession(s)}>Rename</button>
                  <button type="button" className="text-[10px] text-slate-500 hover:text-red-600" onClick={() => deleteSession(s)}>Delete</button>
                </div>
              </div>
            ))}
            {sessions.length === 0 && (
              <p className="text-sm text-slate-500 px-4 py-8 text-center">No chats yet.</p>
            )}
          </div>
          {savedQueries.length > 0 && (
            <div className="border-t border-slate-100 p-3">
              <p className="text-[10px] uppercase text-slate-400 font-semibold mb-1">Saved queries</p>
              {savedQueries.map((q) => (
                <button
                  key={q.id}
                  type="button"
                  className="block w-full text-left text-xs text-blue-700 hover:underline truncate py-0.5"
                  onClick={() => setInput(q.query_text)}
                >
                  {q.name}
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="flex-1 bg-white rounded-xl border border-slate-200 flex flex-col min-w-0">
          <div className="px-4 py-3 border-b border-slate-200 flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center">
              <Bot className="w-4 h-4 text-white" />
            </div>
            <div>
              <p className="font-medium text-slate-800 text-sm">ServiceOP Ops Assistant</p>
              <p className="text-xs text-slate-500">
                {user?.name} ({user?.role}) · mode {mode}
              </p>
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
              const citations = m.meta?.citations || [];
              return (
                <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                  <div className={`max-w-[85%] rounded-xl px-3 py-2 text-sm whitespace-pre-wrap ${
                    mine ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'
                  }`}>
                    {m.content}
                    {!mine && (
                      <div className="mt-2 space-y-1">
                        <p className="text-[10px] text-slate-500">
                          {m.meta?.response_kind || 'read-only'}
                          {m.meta?.model ? ` · ${m.meta.model}` : ''}
                          {m.meta?.data_refreshed_at ? ` · refreshed ${new Date(m.meta.data_refreshed_at).toLocaleTimeString()}` : ''}
                          {m.meta?.brand_scope ? ` · scope ${m.meta.brand_scope}` : ''}
                          {m.meta?.kill_switch ? ' · kill switch' : ''}
                        </p>
                        {citations.length > 0 && (
                          <div className="flex flex-wrap gap-1">
                            {citations.map((c) => (
                              <Link
                                key={`${c.type}-${c.id}`}
                                to={c.path}
                                className="text-[10px] px-1.5 py-0.5 rounded bg-white border border-slate-200 text-blue-700"
                              >
                                {c.type} #{c.id}: {c.label}
                              </Link>
                            ))}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
            {pending && (
              <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 space-y-2">
                <p className="text-sm font-medium text-amber-900">Action preview — confirmation required</p>
                <p className="text-xs text-amber-800">{pending.consequences}</p>
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
            <button type="button" onClick={saveQuery} className="px-2 text-slate-500 hover:text-blue-600" title="Save query">
              <Plus className="w-4 h-4" />
            </button>
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
