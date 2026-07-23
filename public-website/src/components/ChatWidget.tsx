"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { BrandConfig } from "@/lib/brand";
import { apiBaseUrl, brandHeaders } from "@/lib/brand";

type ChatMessage = { role: "user" | "assistant"; content: string };

type Props = {
  brand: BrandConfig;
  hostHint?: string;
};

export function ChatWidget({ brand, hostHint }: Props) {
  const [token, setToken] = useState<string | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState("");
  const [streaming, setStreaming] = useState(false);
  const [collected, setCollected] = useState<Record<string, unknown>>({});
  const [ready, setReady] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submittedLeadId, setSubmittedLeadId] = useState<number | null>(null);
  const [priceEstimate, setPriceEstimate] = useState<{
    available?: boolean;
    low?: number;
    high?: number;
    currency?: string;
    message?: string;
    disclaimer?: string;
    is_placeholder?: boolean;
  } | null>(null);
  const [attachments, setAttachments] = useState<
    Array<{ url: string; file_name: string }>
  >([]);
  const [mounted, setMounted] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setMounted(true);
  }, []);

  const headers = useCallback(() => {
    const h = brandHeaders(hostHint || brand.domain) as Record<string, string>;
    if (token) h["X-Intake-Token"] = token;
    return h;
  }, [brand.domain, hostHint, token]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, streaming]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const existing =
          typeof window !== "undefined"
            ? window.localStorage.getItem("serviceop_intake_token")
            : null;
        if (existing) {
          const resume = await fetch(
            `${apiBaseUrl()}/api/public/intake/session?session_token=${encodeURIComponent(existing)}`,
            { headers: brandHeaders(hostHint || brand.domain), credentials: "include" }
          );
          if (resume.ok) {
            const data = await resume.json();
            if (cancelled) return;
            setToken(data.session_token);
            setMessages(
              (data.messages || []).map((m: { role: string; content: string }) => ({
                role: m.role === "assistant" ? "assistant" : "user",
                content: m.content,
              }))
            );
            setCollected(data.collected || {});
            setReady(Boolean(data.ready_to_submit));
            setAttachments(data.attachments || []);
            setPriceEstimate(data.price_estimate || null);
            return;
          }
        }

        const start = await fetch(`${apiBaseUrl()}/api/public/intake/start`, {
          method: "POST",
          headers: brandHeaders(hostHint || brand.domain),
          credentials: "include",
        });
        if (!start.ok) throw new Error("Could not start chat session");
        const data = await start.json();
        if (cancelled) return;
        setToken(data.session_token);
        window.localStorage.setItem("serviceop_intake_token", data.session_token);
      } catch (e) {
        if (!cancelled) setError(e instanceof Error ? e.message : "Startup failed");
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [brand.domain, hostHint]);

  async function sendMessage() {
    const text = input.trim();
    if (!text || !token || streaming) return;
    setInput("");
    setError(null);
    setMessages((m) => [...m, { role: "user", content: text }]);
    setStreaming(true);
    setMessages((m) => [...m, { role: "assistant", content: "" }]);

    try {
      const res = await fetch(`${apiBaseUrl()}/api/public/intake/message`, {
        method: "POST",
        headers: {
          ...headers(),
          Accept: "text/event-stream",
        },
        credentials: "include",
        body: JSON.stringify({
          session_token: token,
          message: text,
          stream: true,
        }),
      });

      if (!res.ok || !res.body) {
        throw new Error(`Chat failed (${res.status})`);
      }

      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = "";
      let assistant = "";

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const parts = buffer.split("\n\n");
        buffer = parts.pop() || "";
        for (const part of parts) {
          const lines = part.split("\n");
          let event = "message";
          let dataLine = "";
          for (const line of lines) {
            if (line.startsWith("event:")) event = line.slice(6).trim();
            if (line.startsWith("data:")) dataLine += line.slice(5).trim();
          }
          if (!dataLine) continue;
          const payload = JSON.parse(dataLine);
          if (event === "delta" && payload.text) {
            assistant += payload.text;
            const snapshot = assistant;
            setMessages((m) => {
              const copy = [...m];
              copy[copy.length - 1] = { role: "assistant", content: snapshot };
              return copy;
            });
          }
          if (event === "collected" && payload.collected) {
            setCollected(payload.collected);
          }
          if (event === "done") {
            if (payload.reply) {
              assistant = payload.reply;
              setMessages((m) => {
                const copy = [...m];
                copy[copy.length - 1] = { role: "assistant", content: assistant };
                return copy;
              });
            }
            if (payload.collected) setCollected(payload.collected);
            setReady(Boolean(payload.ready_to_submit));
            if (payload.price_estimate) setPriceEstimate(payload.price_estimate);
          }
          if (event === "error") {
            setError(payload.message || "Assistant error");
          }
        }
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Send failed");
    } finally {
      setStreaming(false);
    }
  }

  async function uploadPhotos(files: FileList | null) {
    if (!files?.length || !token) return;
    const fd = new FormData();
    fd.append("session_token", token);
    for (const f of Array.from(files)) {
      fd.append("photos[]", f);
    }

    const h = brandHeaders(hostHint || brand.domain) as Record<string, string>;
    delete h["Content-Type"];
    if (token) h["X-Intake-Token"] = token;

    const res = await fetch(`${apiBaseUrl()}/api/public/intake/media`, {
      method: "POST",
      headers: h,
      credentials: "include",
      body: fd,
    });
    if (!res.ok) {
      setError("Photo upload failed");
      return;
    }
    const data = await res.json();
    setAttachments(data.attachments || []);
  }

  async function submitLead() {
    if (!token) return;
    setError(null);
    const res = await fetch(`${apiBaseUrl()}/api/public/intake/submit`, {
      method: "POST",
      headers: headers(),
      credentials: "include",
      body: JSON.stringify({ session_token: token }),
    });
    const data = await res.json();
    if (!res.ok) {
      setError(data.message || "Submit failed");
      return;
    }
    setSubmittedLeadId(data.lead_id);
    window.localStorage.removeItem("serviceop_intake_token");
  }

  if (!mounted) {
    return (
      <div className="chat">
        <p className="muted">Loading chat…</p>
      </div>
    );
  }

  return (
    <div className="chat">
      <div className="chat-log">
        {messages.length === 0 && (
          <p className="muted">
            Chat with {brand.company_name} to start your project request.
          </p>
        )}
        {messages.map((m, i) => (
          <div key={i} className={`bubble ${m.role}`}>
            {m.content || (streaming && i === messages.length - 1 ? "…" : "")}
          </div>
        ))}
        <div ref={bottomRef} />
      </div>

      {Object.keys(collected).length > 0 && (
        <div className="collected muted">
          Captured:{" "}
          {Object.entries(collected)
            .map(([k, v]) => `${k}=${String(v)}`)
            .join(" · ")}
        </div>
      )}

      {priceEstimate?.available && (
        <div className="estimate">
          <strong>
            Estimate: ${Number(priceEstimate.low).toLocaleString()} – $
            {Number(priceEstimate.high).toLocaleString()}{" "}
            {priceEstimate.currency || "CAD"}
          </strong>
          <p className="muted">{priceEstimate.disclaimer || priceEstimate.message}</p>
          {priceEstimate.is_placeholder ? (
            <p className="muted">Rates are provisional placeholders pending review.</p>
          ) : null}
        </div>
      )}

      {attachments.length > 0 && (
        <div className="muted">
          Photos: {attachments.map((a) => a.file_name).join(", ")}
        </div>
      )}

      {error && <p className="error">{error}</p>}

      {submittedLeadId ? (
        <p className="success">
          Request received{typeof submittedLeadId === "number" ? ` (#${submittedLeadId})` : ""}.{" "}
          {brand.company_name} will follow up soon.
        </p>
      ) : (
        <>
          <div className="chat-compose">
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => e.key === "Enter" && sendMessage()}
              placeholder="Type a message…"
              disabled={streaming || !token}
            />
            <button type="button" onClick={sendMessage} disabled={streaming || !token}>
              Send
            </button>
          </div>
          <div className="chat-actions">
            <label className="file-btn">
              Add photos
              <input
                type="file"
                accept="image/*"
                multiple
                hidden
                onChange={(e) => uploadPhotos(e.target.files)}
              />
            </label>
            <button
              type="button"
              className="primary"
              onClick={submitLead}
              disabled={!token || (!ready && Object.keys(collected).length === 0)}
            >
              Submit request
            </button>
          </div>
        </>
      )}
    </div>
  );
}
