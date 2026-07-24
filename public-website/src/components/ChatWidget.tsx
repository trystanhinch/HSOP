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
  const [sessionReady, setSessionReady] = useState(false);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState("");
  const [streaming, setStreaming] = useState(false);
  const [collected, setCollected] = useState<Record<string, unknown>>({});
  const [ready, setReady] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submittedLeadId, setSubmittedLeadId] = useState<number | null>(null);
  const [bookingConfirmed, setBookingConfirmed] = useState(false);
  const [priceEstimate, setPriceEstimate] = useState<{
    available?: boolean;
    low?: number;
    high?: number;
    currency?: string;
    message?: string;
    disclaimer?: string;
    is_placeholder?: boolean;
  } | null>(null);
  const [slots, setSlots] = useState<
    Array<{
      slot_start: string;
      slot_end: string;
      slot_start_local?: string;
      resource_key: string;
      timezone?: string;
    }>
  >([]);
  const [selectedSlot, setSelectedSlot] = useState<string | null>(null);
  const [holdToken, setHoldToken] = useState<string | null>(null);
  const [holdUntil, setHoldUntil] = useState<string | null>(null);
  const [loadingSlots, setLoadingSlots] = useState(false);
  const [attachments, setAttachments] = useState<
    Array<{ url: string; file_name: string }>
  >([]);
  const bottomRef = useRef<HTMLDivElement>(null);

  const headers = useCallback(() => {
    const h = brandHeaders(hostHint || brand.domain) as Record<string, string>;
    if (token) h["X-Intake-Token"] = token;
    return h;
  }, [brand.domain, hostHint, token]);

  useEffect(() => {
    const reduce =
      typeof window !== "undefined" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    bottomRef.current?.scrollIntoView({ behavior: reduce ? "auto" : "smooth" });
  }, [messages, streaming]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setSessionReady(false);
      setError(null);
      try {
        const existing = window.localStorage.getItem("serviceop_intake_token");
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
          window.localStorage.removeItem("serviceop_intake_token");
        }

        const start = await fetch(`${apiBaseUrl()}/api/public/intake/start`, {
          method: "POST",
          headers: brandHeaders(hostHint || brand.domain),
          credentials: "include",
        });
        if (!start.ok) {
          const body = await start.json().catch(() => ({}));
          throw new Error(
            (body as { message?: string }).message ||
              `Could not start chat session (${start.status})`
          );
        }
        const data = await start.json();
        if (cancelled) return;
        setToken(data.session_token);
        window.localStorage.setItem("serviceop_intake_token", data.session_token);
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "Startup failed");
        }
      } finally {
        if (!cancelled) setSessionReady(true);
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

  async function loadSlots(service?: string) {
    setLoadingSlots(true);
    try {
      const svc =
        service ||
        (typeof collected.service_category === "string"
          ? collected.service_category
          : "");
      const q = svc ? `?service=${encodeURIComponent(svc)}&days=14` : "?days=14";
      const res = await fetch(`${apiBaseUrl()}/api/public/availability${q}`, {
        headers: headers(),
        credentials: "include",
      });
      if (!res.ok) return;
      const data = await res.json();
      setSlots((data.slots || []).slice(0, 12));
    } catch {
      /* ignore */
    } finally {
      setLoadingSlots(false);
    }
  }

  useEffect(() => {
    if (priceEstimate?.available && token) {
      void loadSlots();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [priceEstimate?.available, token, collected.service_category]);

  async function selectSlot(slot: {
    slot_start: string;
    slot_end: string;
    resource_key: string;
  }) {
    if (!token) return;
    setError(null);
    const res = await fetch(`${apiBaseUrl()}/api/public/availability/hold`, {
      method: "POST",
      headers: { ...headers(), "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({
        session_token: token,
        slot_start: slot.slot_start,
        slot_end: slot.slot_end,
        resource_key: slot.resource_key,
        service: collected.service_category || undefined,
      }),
    });
    const data = await res.json();
    if (!res.ok) {
      setError(data.message || "That slot is no longer available.");
      void loadSlots();
      return;
    }
    setSelectedSlot(slot.slot_start);
    setHoldToken(data.hold_token);
    setHoldUntil(data.held_until || null);
  }

  async function submitLead() {
    if (!token) return;
    setError(null);
    const res = await fetch(`${apiBaseUrl()}/api/public/intake/submit`, {
      method: "POST",
      headers: { ...headers(), "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ session_token: token }),
    });
    const data = await res.json();
    if (!res.ok) {
      setError(data.message || "Submit failed");
      return;
    }
    setSubmittedLeadId(data.lead_id);
    setBookingConfirmed(Boolean(data.booking?.confirmed));
    window.localStorage.removeItem("serviceop_intake_token");
  }

  if (submittedLeadId) {
    return (
      <div className="chat">
        <p className="success" style={{ padding: "1.5rem" }}>
          {bookingConfirmed
            ? `Request received${typeof submittedLeadId === "number" ? ` (#${submittedLeadId})` : ""}. Your preferred visit time is confirmed — ${brand.company_name} will follow up shortly.`
            : `Request received${typeof submittedLeadId === "number" ? ` (#${submittedLeadId})` : ""}. ${brand.company_name} will follow up soon.`}
        </p>
      </div>
    );
  }

  return (
    <div className="chat">
      <div className="chat-log" role="log" aria-live="polite">
        {!sessionReady && !error ? (
          <p className="chat-empty">Starting chat…</p>
        ) : messages.length === 0 ? (
          <p className="chat-empty">
            Start with what you see on site — a stained ceiling, open drywall, cold
            room, anything. {brand.company_name} will ask only what is needed.
          </p>
        ) : (
          messages.map((m, i) => (
            <div key={i} className={`bubble ${m.role}`}>
              {m.content || (streaming && i === messages.length - 1 ? "…" : "")}
            </div>
          ))
        )}
        <div ref={bottomRef} />
      </div>

      {Object.keys(collected).length > 0 && (
        <div className="collected" style={{ padding: "0 1.5rem" }}>
          Noted:{" "}
          {Object.entries(collected)
            .map(([k, v]) => `${k.replace(/_/g, " ")} ${String(v)}`)
            .join(" · ")}
        </div>
      )}

      {priceEstimate?.available && (
        <div className="estimate" aria-live="polite">
          <p className="estimate__label">Your finish range</p>
          <strong>
            ${Number(priceEstimate.low).toLocaleString()} – $
            {Number(priceEstimate.high).toLocaleString()}{" "}
            {priceEstimate.currency || "CAD"}
          </strong>
          <p className="muted">{priceEstimate.disclaimer || priceEstimate.message}</p>
          {priceEstimate.is_placeholder ? (
            <p className="muted">Rates are provisional placeholders pending review.</p>
          ) : null}
        </div>
      )}

      {priceEstimate?.available && (
        <div className="slots">
          <p className="slots-label">Pick a site-visit time (held briefly while you finish):</p>
          {loadingSlots && <p className="muted">Loading times…</p>}
          {!loadingSlots && slots.length === 0 && (
            <p className="muted">
              No online times are open right now — you can still submit your request and{" "}
              {brand.company_name} will contact you to schedule.
            </p>
          )}
          <div className="slot-grid">
            {slots.map((s) => {
              const label = new Date(
                s.slot_start_local || s.slot_start
              ).toLocaleString(undefined, {
                weekday: "short",
                month: "short",
                day: "numeric",
                hour: "numeric",
                minute: "2-digit",
                timeZone: s.timezone || "America/Vancouver",
              });
              const active = selectedSlot === s.slot_start;
              return (
                <button
                  key={s.slot_start + s.resource_key}
                  type="button"
                  className={`slot-btn${active ? " active" : ""}`}
                  onClick={() => selectSlot(s)}
                  aria-pressed={active}
                >
                  {label}
                </button>
              );
            })}
          </div>
          {holdToken && (
            <p className="muted">
              Slot held{holdUntil ? ` until ${new Date(holdUntil).toLocaleTimeString()}` : ""}.
            </p>
          )}
        </div>
      )}

      {attachments.length > 0 && (
        <div className="muted" style={{ padding: "0 1.5rem" }}>
          Photos: {attachments.map((a) => a.file_name).join(", ")}
        </div>
      )}

      {error && (
        <p className="error" style={{ padding: "0 1.5rem" }} role="alert">
          {error}
        </p>
      )}

      <div className="chat-dock">
        <div className="chat-compose">
          <input
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => e.key === "Enter" && sendMessage()}
            placeholder="Describe the problem…"
            disabled={streaming || !token}
            aria-label="Message"
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
      </div>
    </div>
  );
}
