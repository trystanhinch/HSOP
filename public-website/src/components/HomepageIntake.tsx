"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import type { BrandConfig } from "@/lib/brand";
import { apiBaseUrl, brandHeaders, brandUploadHeaders } from "@/lib/brand";
import { useTalkIntakeEnabled } from "@/lib/talkEnabled";
import { ChatWidget } from "@/components/ChatWidget";

type Mode = "type" | "talk" | "upload";

type Props = {
  brand: BrandConfig;
  hostHint?: string;
};

function mentionsVoice(text: string): boolean {
  return /\b(voice|talk|mic|speak|recording)\b/i.test(text);
}

function homeCopy(brand: BrandConfig, talkEnabled: boolean) {
  const home = brand.content?.home || {};
  const defaultLede = talkEnabled
    ? "Type a short description, talk it through, or upload photos — we will walk you through the next step."
    : "Type a short description or upload photos — we will walk you through the next step.";
  const configuredLede = home.intake_lede || "";
  const defaultSteps = talkEnabled
    ? [
        {
          eyebrow: "1",
          title: "Describe project",
          description:
            "Tell us what you see — in text, by talking, or with photos.",
        },
        {
          eyebrow: "2",
          title: "Add photos",
          description:
            "Optional photos help us give a clearer range before a visit.",
        },
        {
          eyebrow: "3",
          title: "Get next step",
          description:
            "See a ballpark range and hold a site-visit time when you are ready.",
        },
      ]
    : [
        {
          eyebrow: "1",
          title: "Describe project",
          description: "Tell us what you see — in text or with photos.",
        },
        {
          eyebrow: "2",
          title: "Add photos",
          description:
            "Optional photos help us give a clearer range before a visit.",
        },
        {
          eyebrow: "3",
          title: "Get next step",
          description:
            "See a ballpark range and hold a site-visit time when you are ready.",
        },
      ];
  const configuredSteps =
    Array.isArray(home.steps) && home.steps.length === 3 ? home.steps : null;
  const steps =
    configuredSteps &&
    (talkEnabled ||
      !configuredSteps.some(
        (s) => mentionsVoice(s.title || "") || mentionsVoice(s.description || "")
      ))
      ? configuredSteps
      : defaultSteps;

  return {
    headline: home.intake_headline || "Tell us about your project.",
    lede:
      configuredLede && (talkEnabled || !mentionsVoice(configuredLede))
        ? configuredLede
        : defaultLede,
    typeLabel: home.mode_type_label || "Type",
    talkLabel: home.mode_talk_label || "Talk",
    uploadLabel: home.mode_upload_label || "Upload Photos",
    goLabel: home.go_label || "Go",
    manualLabel:
      home.manual_quote_label || "Prefer a form? Request a quote manually",
    reassurance: talkEnabled
      ? home.reassurance ||
        "Talk never starts automatically. You can switch to typing or request a person at any time."
      : home.reassurance && !mentionsVoice(home.reassurance)
        ? home.reassurance
        : "You can request a person at any time.",
    placeholder: home.composer_placeholder || "Describe what you see…",
    steps,
  };
}

export function HomepageIntake({ brand, hostHint }: Props) {
  const router = useRouter();
  const talkEnabled = useTalkIntakeEnabled();
  const c = homeCopy(brand, talkEnabled);
  const [mode, setMode] = useState<Mode>("type");
  const [text, setText] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  /** When true, the Project Assistant chat replaces the start form — same page. */
  const [chatOpen, setChatOpen] = useState(false);
  const [autoStartTalk, setAutoStartTalk] = useState(false);
  /** Typed homepage description handed to ChatWidget as the first turn. */
  const [seedMessage, setSeedMessage] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  async function ensureSession(): Promise<string> {
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
        return data.session_token as string;
      }
      window.localStorage.removeItem("serviceop_intake_token");
    }
    const start = await fetch(`${apiBaseUrl()}/api/public/intake/start`, {
      method: "POST",
      headers: brandHeaders(hostHint || brand.domain),
      credentials: "include",
    });
    if (!start.ok) {
      throw new Error("Could not start a chat session.");
    }
    const data = await start.json();
    window.localStorage.setItem("serviceop_intake_token", data.session_token);
    return data.session_token as string;
  }

  async function openChat(opts?: { talk?: boolean; seed?: string | null }) {
    setError(null);
    setBusy(true);
    try {
      await ensureSession();
      setAutoStartTalk(Boolean(opts?.talk && talkEnabled));
      setSeedMessage(opts?.seed?.trim() || null);
      setChatOpen(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not open chat.");
    } finally {
      setBusy(false);
    }
  }

  async function onGo() {
    setError(null);
    setBusy(true);
    try {
      if (mode === "talk" && talkEnabled) {
        await openChat({ talk: true, seed: null });
        return;
      }

      const token = await ensureSession();
      if (mode === "upload" && files.length > 0) {
        const fd = new FormData();
        fd.append("session_token", token);
        files.forEach((f) => fd.append("photos[]", f));
        const res = await fetch(`${apiBaseUrl()}/api/public/intake/media`, {
          method: "POST",
          headers: brandUploadHeaders(hostHint || brand.domain, token),
          credentials: "include",
          body: fd,
        });
        if (!res.ok) {
          throw new Error("Photo upload failed. Please try again.");
        }
      }

      const typed = mode === "type" ? text.trim() : "";

      // Talk-enabled: stay on page and let ChatWidget send the typed first turn
      // (avoids a race that used to wipe the homepage message).
      if (talkEnabled) {
        setAutoStartTalk(false);
        setSeedMessage(typed || null);
        setChatOpen(true);
        return;
      }

      if (typed) {
        const res = await fetch(`${apiBaseUrl()}/api/public/intake/message`, {
          method: "POST",
          headers: {
            ...brandHeaders(hostHint || brand.domain),
            "X-Intake-Token": token,
          },
          credentials: "include",
          body: JSON.stringify({
            session_token: token,
            message: typed,
            stream: false,
          }),
        });
        if (!res.ok) {
          throw new Error("Could not send your message. Continuing to chat…");
        }
      }

      const params = new URLSearchParams();
      if (mode === "upload") params.set("mode", "upload");
      router.push(params.toString() ? `/quote?${params}` : "/quote");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Something went wrong.");
    } finally {
      setBusy(false);
    }
  }

  if (chatOpen) {
    return (
      <section className="intake-hero intake-hero--chat" aria-label="Project assistant">
        <div className="intake-hero__inner">
          <h1 className="intake-hero__headline">{c.headline}</h1>
          <p className="intake-hero__lede">
            Same conversation — type, talk, or add photos anytime.
          </p>
          <ChatWidget
            brand={brand}
            hostHint={hostHint}
            embedded
            autoStartTalk={autoStartTalk}
            initialUserMessage={seedMessage}
          />
          <p className="intake-reassurance">{c.reassurance}</p>
        </div>
      </section>
    );
  }

  return (
    <section className="intake-hero" aria-label="Project intake">
      <div className="intake-hero__inner">
        <h1 className="intake-hero__headline">{c.headline}</h1>
        <p className="intake-hero__lede">{c.lede}</p>

        <div className="intake-modes" role="tablist" aria-label="How to start">
          {(
            [
              ["type", c.typeLabel],
              ...(talkEnabled ? [["talk", c.talkLabel] as [Mode, string]] : []),
              ["upload", c.uploadLabel],
            ] as Array<[Mode, string]>
          ).map(([id, label]) => (
            <button
              key={id}
              type="button"
              role="tab"
              aria-selected={mode === id}
              className={`intake-mode${mode === id ? " is-active" : ""}`}
              onClick={() => {
                setMode(id);
                setError(null);
                if (id === "talk" && talkEnabled) {
                  void openChat({ talk: true });
                  return;
                }
                if (id === "upload") {
                  fileInputRef.current?.click();
                }
              }}
            >
              {label}
            </button>
          ))}
        </div>

        <input
          ref={fileInputRef}
          type="file"
          accept="image/*"
          multiple
          hidden
          onChange={(e) => {
            const list = e.target.files ? Array.from(e.target.files) : [];
            setFiles(list);
            setMode("upload");
          }}
        />

        {mode === "type" ? (
          <div className="intake-compose">
            <textarea
              value={text}
              onChange={(e) => setText(e.target.value)}
              placeholder={c.placeholder}
              rows={3}
              aria-label="Project description"
            />
          </div>
        ) : null}

        {mode === "upload" ? (
          <div className="intake-upload">
            <p className="muted">
              {files.length
                ? `${files.length} photo${files.length === 1 ? "" : "s"} selected`
                : "Choose one or more photos of the problem area."}
            </p>
            <button
              type="button"
              className="ghost"
              onClick={() => fileInputRef.current?.click()}
            >
              {files.length ? "Change photos" : "Choose photos"}
            </button>
          </div>
        ) : null}

        {error ? (
          <p className="error" role="alert">
            {error}
          </p>
        ) : null}

        <div className="intake-actions">
          <button
            type="button"
            className="btn intake-go"
            onClick={() => void onGo()}
            disabled={busy || (mode === "talk" && talkEnabled)}
          >
            {busy ? "Starting…" : c.goLabel}
          </button>
          <Link href="/quote/manual" className="intake-manual">
            {c.manualLabel}
          </Link>
        </div>

        <ol className="intake-steps" aria-label="How it works">
          {c.steps.map((step, i) => (
            <li key={i}>
              <span className="intake-steps__num">
                {step.eyebrow || String(i + 1)}
              </span>
              <div>
                <strong>{step.title}</strong>
                <p>{step.description}</p>
              </div>
            </li>
          ))}
        </ol>

        <p className="intake-reassurance">{c.reassurance}</p>
      </div>
    </section>
  );
}
