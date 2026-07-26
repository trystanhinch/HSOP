"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import type { BrandConfig } from "@/lib/brand";
import { apiBaseUrl, brandHeaders } from "@/lib/brand";

type Mode = "type" | "talk" | "upload";

type Props = {
  brand: BrandConfig;
  hostHint?: string;
};

/** Record-and-transcribe Talk is held pending live/streaming direction. Opt in locally only. */
function talkIntakeEnabled(): boolean {
  return process.env.NEXT_PUBLIC_ENABLE_INTAKE_TALK === "true";
}

function mentionsVoice(text: string): boolean {
  return /\b(voice|talk|mic|speak|recording)\b/i.test(text);
}

function homeCopy(brand: BrandConfig, talkEnabled: boolean) {
  const home = brand.content?.home || {};
  const defaultLede = talkEnabled
    ? "Type a short description, leave a voice note, or upload photos — we will walk you through the next step."
    : "Type a short description or upload photos — we will walk you through the next step.";
  const configuredLede = home.intake_lede || "";
  const defaultSteps = talkEnabled
    ? [
        {
          eyebrow: "1",
          title: "Describe project",
          description:
            "Tell us what you see — in text, a voice note, or with photos.",
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
        "Voice never starts automatically. You can switch to typing or request a person at any time."
      : home.reassurance && !mentionsVoice(home.reassurance)
        ? home.reassurance
        : "You can request a person at any time.",
    placeholder: home.composer_placeholder || "Describe what you see…",
    steps,
  };
}

export function HomepageIntake({ brand, hostHint }: Props) {
  const router = useRouter();
  const talkEnabled = talkIntakeEnabled();
  const c = homeCopy(brand, talkEnabled);
  const [mode, setMode] = useState<Mode>("type");
  const [text, setText] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [recording, setRecording] = useState(false);
  const [transcribing, setTranscribing] = useState(false);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const stopRecording = useCallback(() => {
    const rec = mediaRecorderRef.current;
    if (rec && rec.state !== "inactive") {
      rec.stop();
    }
    setRecording(false);
  }, []);

  useEffect(() => {
    return () => {
      stopRecording();
      mediaRecorderRef.current?.stream.getTracks().forEach((t) => t.stop());
    };
  }, [stopRecording]);

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

  async function startRecording() {
    setError(null);
    if (!navigator.mediaDevices?.getUserMedia) {
      setError("Microphone is not available in this browser. Please type instead.");
      setMode("type");
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mime = MediaRecorder.isTypeSupported("audio/webm")
        ? "audio/webm"
        : "audio/mp4";
      const recorder = new MediaRecorder(stream, { mimeType: mime });
      chunksRef.current = [];
      recorder.ondataavailable = (e) => {
        if (e.data.size > 0) chunksRef.current.push(e.data);
      };
      recorder.onstop = async () => {
        stream.getTracks().forEach((t) => t.stop());
        const blob = new Blob(chunksRef.current, { type: mime });
        if (blob.size < 200) {
          setError("Recording was too short. Please try again or type instead.");
          return;
        }
        setTranscribing(true);
        try {
          const fd = new FormData();
          fd.append(
            "audio",
            blob,
            mime.includes("webm") ? "voice-note.webm" : "voice-note.m4a"
          );
          const h = brandHeaders(hostHint || brand.domain) as Record<string, string>;
          delete h["Content-Type"];
          const res = await fetch(`${apiBaseUrl()}/api/public/intake/transcribe`, {
            method: "POST",
            headers: h,
            credentials: "include",
            body: fd,
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) {
            throw new Error(
              (data as { message?: string }).message ||
                "Transcription failed. Please type instead."
            );
          }
          setText(String((data as { text?: string }).text || "").trim());
          setMode("type");
        } catch (e) {
          setError(e instanceof Error ? e.message : "Transcription failed.");
          setMode("type");
        } finally {
          setTranscribing(false);
        }
      };
      mediaRecorderRef.current = recorder;
      recorder.start();
      setRecording(true);
    } catch {
      setError(
        "Microphone permission was denied. You can type your message instead."
      );
      setMode("type");
    }
  }

  async function onGo() {
    setError(null);
    setBusy(true);
    try {
      if (mode === "talk" && recording) {
        stopRecording();
        setBusy(false);
        return;
      }
      const token = await ensureSession();
      if (mode === "upload" && files.length > 0) {
        const fd = new FormData();
        fd.append("session_token", token);
        files.forEach((f) => fd.append("photos[]", f));
        const h = brandHeaders(hostHint || brand.domain) as Record<string, string>;
        delete h["Content-Type"];
        h["X-Intake-Token"] = token;
        const res = await fetch(`${apiBaseUrl()}/api/public/intake/media`, {
          method: "POST",
          headers: h,
          credentials: "include",
          body: fd,
        });
        if (!res.ok) {
          throw new Error("Photo upload failed. Please try again from the quote page.");
        }
      }
      if ((mode === "type" || mode === "talk") && text.trim()) {
        const res = await fetch(`${apiBaseUrl()}/api/public/intake/message`, {
          method: "POST",
          headers: {
            ...brandHeaders(hostHint || brand.domain),
            "X-Intake-Token": token,
          },
          credentials: "include",
          body: JSON.stringify({
            session_token: token,
            message: text.trim(),
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
                stopRecording();
                setMode(id);
                setError(null);
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

        {mode === "type" || (mode === "talk" && text && !recording) ? (
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

        {talkEnabled && mode === "talk" && !text ? (
          <div className="intake-talk">
            <p className="muted">
              Record a short voice note. We transcribe it to text and continue in
              the same chat — this is not a live voice conversation.
            </p>
            <button
              type="button"
              className={recording ? "btn intake-talk__stop" : "btn"}
              onClick={() => (recording ? stopRecording() : void startRecording())}
              disabled={transcribing || busy}
            >
              {transcribing
                ? "Transcribing…"
                : recording
                  ? "Stop recording"
                  : "Start recording"}
            </button>
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
            disabled={busy || recording || transcribing}
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
              <span className="intake-steps__num">{step.eyebrow || String(i + 1)}</span>
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
