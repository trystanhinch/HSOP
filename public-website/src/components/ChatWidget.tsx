"use client";

import { Suspense, useCallback, useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import type { BrandConfig } from "@/lib/brand";
import { apiBaseUrl, brandHeaders, brandUploadHeaders } from "@/lib/brand";
import {
  clearIntakeSessionToken,
  useTalkIntakeEnabled,
} from "@/lib/talkEnabled";
import { useTalkInput } from "@/hooks/useTalkInput";

type ChatMessage = { role: "user" | "assistant"; content: string };

type Props = {
  brand: BrandConfig;
  hostHint?: string;
  /** Start listening as soon as the session is ready (homepage Talk). */
  autoStartTalk?: boolean;
  /** Compact chrome when embedded under the homepage headline. */
  embedded?: boolean;
};

type PriceEstimate = {
  available?: boolean;
  low?: number;
  high?: number;
  currency?: string;
  message?: string;
  disclaimer?: string;
  is_placeholder?: boolean;
};

type Slot = {
  slot_start: string;
  slot_end: string;
  slot_start_local?: string;
  resource_key: string;
  timezone?: string;
};

function flagTrue(value: unknown): boolean {
  return value === true || value === 1 || value === "1" || value === "true";
}

function ChatWidgetInner({
  brand,
  hostHint,
  autoStartTalk = false,
  embedded = false,
}: Props) {
  const searchParams = useSearchParams();
  const talkEnabled = useTalkIntakeEnabled();
  const supportPhone =
    typeof brand.contact_info?.phone === "string"
      ? brand.contact_info.phone.trim()
      : "";

  const [token, setToken] = useState<string | null>(null);
  const [sessionReady, setSessionReady] = useState(false);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState("");
  const [talkMode, setTalkMode] = useState(Boolean(autoStartTalk && talkEnabled));
  const [streaming, setStreaming] = useState(false);
  const [collected, setCollected] = useState<Record<string, unknown>>({});
  const [ready, setReady] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submittedLeadId, setSubmittedLeadId] = useState<number | null>(null);
  const [bookingConfirmed, setBookingConfirmed] = useState(false);
  const [priceEstimate, setPriceEstimate] = useState<PriceEstimate | null>(null);
  const [priceRevealed, setPriceRevealed] = useState(false);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [slotLimit, setSlotLimit] = useState(3);
  const [selectedSlot, setSelectedSlot] = useState<string | null>(null);
  const [holdToken, setHoldToken] = useState<string | null>(null);
  const [holdUntil, setHoldUntil] = useState<string | null>(null);
  const [loadingSlots, setLoadingSlots] = useState(false);
  const [attachments, setAttachments] = useState<
    Array<{ url: string; file_name: string }>
  >([]);
  const [uploading, setUploading] = useState(false);
  const [sessionEpoch, setSessionEpoch] = useState(0);

  const bottomRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const autoTalkArmed = useRef(false);
  const inputBeforeListenRef = useRef("");
  const greetingSeeded = useRef(false);
  const restartTalkAfterAi = useRef(false);
  const priceFetchArmed = useRef(false);
  const slotsFetchArmed = useRef(false);

  const headers = useCallback(() => {
    const h = brandHeaders(hostHint || brand.domain) as Record<string, string>;
    if (token) h["X-Intake-Token"] = token;
    return h;
  }, [brand.domain, hostHint, token]);

  const scopeConfirmed = flagTrue(collected.scope_confirmed);
  const wantsPrice = flagTrue(collected.wants_price);
  const wantsScheduling = flagTrue(collected.wants_scheduling);
  const showCustomerPrice =
    wantsPrice &&
    priceRevealed &&
    Boolean(priceEstimate?.available) &&
    priceEstimate?.is_placeholder !== true &&
    typeof priceEstimate?.low === "number" &&
    typeof priceEstimate?.high === "number";
  const showSubmit = ready && scopeConfirmed;

  function sanitizePriceEstimate(pe: PriceEstimate | null | undefined): PriceEstimate | null {
    if (!pe) return null;
    if (pe.is_placeholder === true || pe.available !== true) {
      return { available: false, is_placeholder: Boolean(pe.is_placeholder) };
    }
    return pe;
  }

  useEffect(() => {
    const reduce =
      typeof window !== "undefined" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    bottomRef.current?.scrollIntoView({ behavior: reduce ? "auto" : "smooth" });
  }, [messages, streaming, input, showCustomerPrice, slots, slotLimit]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setSessionReady(false);
      setError(null);
      setPriceEstimate(null);
      setPriceRevealed(false);
      setAttachments([]);
      setCollected({});
      setReady(false);
      setSlots([]);
      setSelectedSlot(null);
      setHoldToken(null);
      setHoldUntil(null);
      greetingSeeded.current = false;
      priceFetchArmed.current = false;
      slotsFetchArmed.current = false;

      try {
        // ?enableTalk=1 clears prior tokens on page load (see talkEnabled.ts).
        // Resume only if a token exists for this visit (e.g. homepage ensureSession).
        const existing = window.localStorage.getItem("serviceop_intake_token");
        if (existing) {
          const resume = await fetch(
            `${apiBaseUrl()}/api/public/intake/session?session_token=${encodeURIComponent(existing)}`,
            {
              headers: brandHeaders(hostHint || brand.domain),
              credentials: "include",
            }
          );
          if (resume.ok) {
            const data = await resume.json();
            if (cancelled) return;
            setToken(data.session_token);
            const pe = sanitizePriceEstimate(data.price_estimate || null);
            const rawMessages = (data.messages || []).map(
              (m: { role: string; content: string }) => ({
                role: (m.role === "assistant" ? "assistant" : "user") as
                  | "assistant"
                  | "user",
                content: m.content,
              })
            );
            // Drop legacy auto-appended estimate lines so placeholder $ ranges never linger.
            const hideLegacyPrice =
              !pe?.available || pe.is_placeholder === true;
            setMessages(
              hideLegacyPrice
                ? rawMessages.filter(
                    (m: ChatMessage) =>
                      !(
                        m.role === "assistant" &&
                        /ballpark|finish range|\$\s*\d/i.test(m.content)
                      )
                  )
                : rawMessages
            );
            setCollected(data.collected || {});
            setReady(Boolean(data.ready_to_submit));
            setAttachments(data.attachments || []);
            setPriceEstimate(pe);
            if (
              flagTrue(data.collected?.wants_price) &&
              pe?.available &&
              pe.is_placeholder !== true
            ) {
              setPriceRevealed(true);
            } else {
              setPriceRevealed(false);
            }
            return;
          }
          clearIntakeSessionToken();
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
        setMessages([]);
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
  }, [brand.domain, hostHint, sessionEpoch]);

  useEffect(() => {
    if (!sessionReady || !token || greetingSeeded.current) return;
    if (messages.length > 0) {
      greetingSeeded.current = true;
      return;
    }
    greetingSeeded.current = true;
    setMessages([
      {
        role: "assistant",
        content: `Hi — what’s going on at the property? A short description is enough to start.`,
      },
    ]);
  }, [sessionReady, token, messages.length]);

  const sendMessage = useCallback(
    async (overrideText?: string) => {
      const text = (overrideText ?? input).trim();
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
                  copy[copy.length - 1] = {
                    role: "assistant",
                    content: assistant,
                  };
                  return copy;
                });
              }
              if (payload.collected) setCollected(payload.collected);
              setReady(Boolean(payload.ready_to_submit));
              if (payload.price_estimate) {
                const pe = sanitizePriceEstimate(payload.price_estimate);
                setPriceEstimate(pe);
                if (!(pe?.available && pe.is_placeholder !== true)) {
                  setPriceRevealed(false);
                }
              }
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
    },
    [headers, input, streaming, token]
  );

  const talk = useTalkInput({
    hostHint: hostHint || brand.domain,
    enabled: talkEnabled,
    autoSendOnSilence: true,
    silenceCommitMs: 1400,
    onCaption: (text) => {
      const prefix = inputBeforeListenRef.current.trim();
      setInput(prefix ? `${prefix} ${text}`.trim() : text);
    },
    onCommitTurn: async (text) => {
      const prefix = inputBeforeListenRef.current.trim();
      const full = prefix ? `${prefix} ${text}`.trim() : text;
      inputBeforeListenRef.current = "";
      restartTalkAfterAi.current = talkMode;
      await sendMessage(full);
    },
  });

  useEffect(() => {
    if (!talkEnabled || streaming || !restartTalkAfterAi.current) return;
    if (!talkMode || talk.listening) return;
    restartTalkAfterAi.current = false;
    inputBeforeListenRef.current = "";
    void talk.start();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [streaming, talkMode, talkEnabled]);

  useEffect(() => {
    if (!talkEnabled || !sessionReady || !token || autoTalkArmed.current) return;
    const wantTalk = autoStartTalk || searchParams.get("talk") === "1";
    if (!wantTalk) return;
    autoTalkArmed.current = true;
    setTalkMode(true);
    inputBeforeListenRef.current = "";
    void talk.start();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [talkEnabled, sessionReady, token, autoStartTalk, searchParams]);

  // Price: only after explicit opt-in; never reveal placeholder rates.
  useEffect(() => {
    if (!token || !wantsPrice || priceFetchArmed.current) return;
    priceFetchArmed.current = true;
    (async () => {
      try {
        const res = await fetch(`${apiBaseUrl()}/api/public/intake/estimate`, {
          method: "POST",
          headers: { ...headers(), "Content-Type": "application/json" },
          credentials: "include",
          body: JSON.stringify({ session_token: token }),
        });
        if (!res.ok) return;
        const data = await res.json();
        const pe = sanitizePriceEstimate(data.price_estimate || null);
        setPriceEstimate(pe);
        if (pe?.available && pe.is_placeholder !== true) {
          setPriceRevealed(true);
        } else {
          setPriceRevealed(false);
        }
      } catch {
        /* ignore — AI copy handles follow-up */
      }
    })();
  }, [token, wantsPrice, headers]);

  async function loadSlots() {
    if (!token) return;
    setLoadingSlots(true);
    try {
      const svc =
        typeof collected.service_category === "string"
          ? collected.service_category
          : "";
      const q = svc ? `?service=${encodeURIComponent(svc)}&days=14` : "?days=14";
      const res = await fetch(`${apiBaseUrl()}/api/public/availability${q}`, {
        headers: headers(),
        credentials: "include",
      });
      if (!res.ok) return;
      const data = await res.json();
      setSlots(data.slots || []);
    } catch {
      /* ignore */
    } finally {
      setLoadingSlots(false);
    }
  }

  useEffect(() => {
    if (!token || !wantsScheduling || slotsFetchArmed.current) return;
    slotsFetchArmed.current = true;
    setSlotLimit(3);
    void loadSlots();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, wantsScheduling, collected.service_category]);

  async function uploadPhotos(fileList: FileList | File[] | null) {
    const files = fileList ? Array.from(fileList) : [];
    if (!files.length || !token || uploading) return;

    setUploading(true);
    setError(null);
    try {
      const fd = new FormData();
      fd.append("session_token", token);
      for (const f of files) {
        fd.append("photos[]", f);
      }

      const res = await fetch(`${apiBaseUrl()}/api/public/intake/media`, {
        method: "POST",
        headers: brandUploadHeaders(hostHint || brand.domain, token),
        credentials: "include",
        body: fd,
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        const msg =
          (data as { message?: string }).message ||
          (data as { errors?: { photos?: string[] } }).errors?.photos?.[0] ||
          "Photo upload failed. Please try again.";
        setError(msg);
        setMessages((m) => [
          ...m,
          {
            role: "assistant",
            content: `I couldn't attach that photo (${msg}). Try another photo, or keep describing the job.`,
          },
        ]);
        return;
      }

      const next = (data.attachments || []) as Array<{
        url: string;
        file_name: string;
      }>;
      const added = Math.max(0, next.length - attachments.length);
      const count = added > 0 ? added : Number(data.count) || files.length;
      if (count < 1 || next.length < 1) {
        setError("Upload did not attach any photos. Please try again.");
        setMessages((m) => [
          ...m,
          {
            role: "assistant",
            content:
              "I didn't receive those photos — please try uploading again.",
          },
        ]);
        return;
      }

      setAttachments(next);
      setMessages((m) => [
        ...m,
        {
          role: "assistant",
          content: `Got ${count} photo${count === 1 ? "" : "s"}. Anything else about the job?`,
        },
      ]);
    } catch (e) {
      const msg = e instanceof Error ? e.message : "Photo upload failed";
      setError(msg);
      setMessages((m) => [
        ...m,
        {
          role: "assistant",
          content:
            "Photo upload failed — please check your connection and try again.",
        },
      ]);
    } finally {
      setUploading(false);
    }
  }

  async function startOver() {
    if (talk.listening) {
      await talk.stop({ commit: false, keepCaption: true });
    }
    restartTalkAfterAi.current = false;
    setTalkMode(false);
    clearIntakeSessionToken();
    setSubmittedLeadId(null);
    setBookingConfirmed(false);
    setInput("");
    setSessionEpoch((n) => n + 1);
  }

  async function selectSlot(slot: Slot) {
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
    if (talk.listening) {
      await talk.stop({ commit: false, keepCaption: true });
    }
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
    clearIntakeSessionToken();
  }

  async function toggleTalk() {
    setError(null);
    talk.setError(null);
    if (talk.listening) {
      await talk.stop({ commit: true });
      return;
    }
    setTalkMode(true);
    inputBeforeListenRef.current = input;
    await talk.start();
  }

  async function sendTalkTurn() {
    if (talk.listening) {
      await talk.stop({ commit: true });
      return;
    }
    await sendMessage();
  }

  async function requestHumanHandoff() {
    restartTalkAfterAi.current = false;
    if (talk.listening) {
      await talk.stop({ commit: false, keepCaption: true });
    }
    setTalkMode(false);
    await sendMessage("I'd like to speak with someone from your team.");
  }

  if (submittedLeadId) {
    return (
      <div className={`chat${embedded ? " chat--embedded" : ""}`}>
        <p className="success" style={{ padding: "1.5rem" }}>
          {bookingConfirmed
            ? `Request received${typeof submittedLeadId === "number" ? ` (#${submittedLeadId})` : ""}. Your preferred visit time is confirmed — ${brand.company_name} will follow up shortly.`
            : `Request received${typeof submittedLeadId === "number" ? ` (#${submittedLeadId})` : ""}. ${brand.company_name} will follow up soon.`}
        </p>
      </div>
    );
  }

  const combinedError = error || talk.error;
  const visibleSlots = slots.slice(0, slotLimit);
  const hasMoreSlots = slots.length > slotLimit;

  return (
    <div className={`chat${embedded ? " chat--embedded" : ""}`}>
      <div className="chat-log" role="log" aria-live="polite">
        {!sessionReady && !error ? (
          <p className="chat-empty">Starting chat…</p>
        ) : (
          messages.map((m, i) => (
            <div key={i} className={`bubble ${m.role}`}>
              {m.content || (streaming && i === messages.length - 1 ? "…" : "")}
            </div>
          ))
        )}

        {showCustomerPrice ? (
          <div className="estimate estimate--inline" aria-live="polite">
            <p className="estimate__label">Ballpark range</p>
            <strong>
              ${Number(priceEstimate!.low).toLocaleString()} – $
              {Number(priceEstimate!.high).toLocaleString()}{" "}
              {priceEstimate!.currency || "CAD"}
            </strong>
            <p className="muted">
              {priceEstimate!.disclaimer ||
                "Estimate only — final pricing depends on a site visit."}
            </p>
          </div>
        ) : null}

        {wantsScheduling ? (
          <div className="slots slots--inline">
            <p className="slots-label">A few open visit times:</p>
            {loadingSlots && <p className="muted">Loading times…</p>}
            {!loadingSlots && slots.length === 0 && (
              <p className="muted">
                No online times are open right now — you can still submit and{" "}
                {brand.company_name} will contact you to schedule.
              </p>
            )}
            <div className="slot-grid">
              {visibleSlots.map((s) => {
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
            {hasMoreSlots ? (
              <button
                type="button"
                className="ghost slot-more"
                onClick={() => setSlotLimit((n) => n + 3)}
              >
                See more times
              </button>
            ) : null}
            {holdToken && (
              <p className="muted">
                Slot held
                {holdUntil
                  ? ` until ${new Date(holdUntil).toLocaleTimeString()}`
                  : ""}
                .
              </p>
            )}
          </div>
        ) : null}

        <div ref={bottomRef} />
      </div>

      {attachments.length > 0 && (
        <div className="chat-attachments" aria-label="Uploaded photos">
          {attachments.map((a, i) => (
            <figure key={`${a.url}-${i}`} className="chat-attachment">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={a.url} alt={a.file_name || `Photo ${i + 1}`} />
              <figcaption>{a.file_name}</figcaption>
            </figure>
          ))}
        </div>
      )}

      {combinedError && (
        <p className="error" style={{ padding: "0 1.5rem" }} role="alert">
          {combinedError}
        </p>
      )}

      <div className="chat-dock">
        <div className="chat-compose-actions" role="group" aria-label="Reply options">
          {talkEnabled ? (
            <button
              type="button"
              className={`chat-action${talkMode || talk.listening ? " is-active" : ""}${
                talk.listening ? " is-listening" : ""
              }`}
              onClick={() => void toggleTalk()}
              disabled={streaming || !token || talk.status === "connecting"}
            >
              {talk.listening ? "Listening…" : "Talk"}
            </button>
          ) : null}
          <button
            type="button"
            className="chat-action"
            onClick={() => fileInputRef.current?.click()}
            disabled={streaming || !token || uploading}
          >
            {uploading ? "Uploading…" : "Upload Photos"}
          </button>
        </div>

        <input
          ref={fileInputRef}
          type="file"
          accept="image/*,image/heic,image/heif,.heic,.heif"
          multiple
          hidden
          onChange={(e) => {
            const list = e.target.files ? Array.from(e.target.files) : [];
            e.target.value = "";
            if (list.length) void uploadPhotos(list);
          }}
        />

        <div className="chat-compose">
          <input
            value={input}
            onChange={(e) => {
              if (talk.listening) {
                restartTalkAfterAi.current = false;
                void talk.stop({ commit: false, keepCaption: true });
                setTalkMode(false);
              }
              setInput(e.target.value);
            }}
            onKeyDown={(e) => {
              if (e.key === "Enter") void sendTalkTurn();
            }}
            placeholder={
              talk.listening
                ? "Listening… words appear here as you speak"
                : "Type a reply…"
            }
            disabled={streaming || !token}
            aria-label="Message"
            className={talk.listening ? "is-listening" : undefined}
          />
          <button
            type="button"
            className={talk.listening ? "talk-send is-listening" : undefined}
            onClick={() => void sendTalkTurn()}
            disabled={
              streaming ||
              !token ||
              talk.status === "connecting" ||
              talk.status === "stopping" ||
              (!talk.listening && !input.trim())
            }
          >
            {talk.status === "connecting" ? "…" : "Send"}
          </button>
        </div>

        {talk.listening ? (
          <p className="chat-talk-status" aria-live="polite">
            Listening in this chat — pause briefly or tap Send to continue.
          </p>
        ) : null}

        <div className="chat-secondary">
          <button
            type="button"
            className="chat-handoff"
            onClick={() => void requestHumanHandoff()}
            disabled={streaming || !token || uploading}
          >
            Speak with someone
          </button>
          {supportPhone ? (
            <a className="chat-handoff-phone" href={`tel:${supportPhone.replace(/[^\d+]/g, "")}`}>
              Call {supportPhone}
            </a>
          ) : null}
          <button
            type="button"
            className="chat-handoff"
            onClick={() => void startOver()}
            disabled={uploading}
          >
            Start over
          </button>
          {showSubmit ? (
            <button
              type="button"
              className="primary chat-submit"
              onClick={() => void submitLead()}
              disabled={!token || uploading}
            >
              Submit request
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}

export function ChatWidget(props: Props) {
  return (
    <Suspense fallback={<p className="muted">Loading chat…</p>}>
      <ChatWidgetInner {...props} />
    </Suspense>
  );
}
