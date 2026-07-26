"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { StreamingTalkSession } from "@/lib/streamingTalk";

type TalkStatus = "idle" | "connecting" | "listening" | "stopping" | "fallback";

type Options = {
  hostHint: string;
  enabled: boolean;
  onCaption: (text: string) => void;
  onCommitTurn?: (text: string) => void | Promise<void>;
  deferCommit?: boolean;
  /** After a completed speech segment + quiet gap, auto stop+send. */
  autoSendOnSilence?: boolean;
  silenceCommitMs?: number;
};

export function useTalkInput({
  hostHint,
  enabled,
  onCaption,
  onCommitTurn,
  deferCommit = false,
  autoSendOnSilence = false,
  silenceCommitMs = 1400,
}: Options) {
  const [status, setStatus] = useState<TalkStatus>("idle");
  const [error, setError] = useState<string | null>(null);
  const sessionRef = useRef<StreamingTalkSession | null>(null);
  const mediaFallbackRef = useRef<MediaRecorder | null>(null);
  const fallbackChunksRef = useRef<Blob[]>([]);
  const fallbackStreamRef = useRef<MediaStream | null>(null);
  const usingFallbackRef = useRef(false);
  const silenceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const statusRef = useRef(status);
  const onCommitRef = useRef(onCommitTurn);
  const onCaptionRef = useRef(onCaption);

  statusRef.current = status;
  onCommitRef.current = onCommitTurn;
  onCaptionRef.current = onCaption;

  const clearSilenceTimer = useCallback(() => {
    if (silenceTimerRef.current) {
      clearTimeout(silenceTimerRef.current);
      silenceTimerRef.current = null;
    }
  }, []);

  const cleanupFallbackRecorder = useCallback(() => {
    try {
      if (mediaFallbackRef.current && mediaFallbackRef.current.state !== "inactive") {
        mediaFallbackRef.current.stop();
      }
    } catch {
      /* ignore */
    }
    mediaFallbackRef.current = null;
    fallbackStreamRef.current?.getTracks().forEach((t) => t.stop());
    fallbackStreamRef.current = null;
    fallbackChunksRef.current = [];
    usingFallbackRef.current = false;
  }, []);

  const stopAll = useCallback(async () => {
    clearSilenceTimer();
    const live = sessionRef.current;
    sessionRef.current = null;
    if (live) {
      try {
        await live.stop();
      } catch {
        live.cleanup();
      }
    }
    cleanupFallbackRecorder();
    setStatus("idle");
  }, [cleanupFallbackRecorder, clearSilenceTimer]);

  useEffect(() => {
    return () => {
      void stopAll();
    };
  }, [stopAll]);

  useEffect(() => {
    if (!enabled) return;
    const onVisibility = () => {
      if (document.visibilityState === "hidden" && statusRef.current === "listening") {
        setError("Microphone paused when the tab was backgrounded. Tap Talk to continue, or type.");
        void stopAll();
      }
    };
    document.addEventListener("visibilitychange", onVisibility);
    return () => document.removeEventListener("visibilitychange", onVisibility);
  }, [enabled, stopAll]);

  const startFallbackRecording = useCallback(async () => {
    usingFallbackRef.current = true;
    setStatus("fallback");
    // Quiet fallback — no scary "voice note" copy; customer still taps Send.
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    fallbackStreamRef.current = stream;
    const mime = MediaRecorder.isTypeSupported("audio/webm")
      ? "audio/webm"
      : "audio/mp4";
    const recorder = new MediaRecorder(stream, { mimeType: mime });
    fallbackChunksRef.current = [];
    recorder.ondataavailable = (e) => {
      if (e.data.size > 0) fallbackChunksRef.current.push(e.data);
    };
    mediaFallbackRef.current = recorder;
    recorder.start();
  }, []);

  const stop = useCallback(
    async (opts?: { commit?: boolean; keepCaption?: boolean }) => {
      const shouldCommit = opts?.commit !== false;
      const keepCaption = opts?.keepCaption === true;
      clearSilenceTimer();
      if (statusRef.current === "idle" || statusRef.current === "stopping") return;
      setStatus("stopping");

      try {
        if (usingFallbackRef.current && mediaFallbackRef.current) {
          const recorder = mediaFallbackRef.current;
          const mime = recorder.mimeType || "audio/webm";
          const blob = await new Promise<Blob>((resolve, reject) => {
            recorder.onstop = () =>
              resolve(new Blob(fallbackChunksRef.current, { type: mime }));
            recorder.onerror = () => reject(new Error("Recording failed."));
            if (recorder.state !== "inactive") recorder.stop();
            else resolve(new Blob(fallbackChunksRef.current, { type: mime }));
          });
          fallbackStreamRef.current?.getTracks().forEach((t) => t.stop());
          mediaFallbackRef.current = null;
          usingFallbackRef.current = false;

          if (keepCaption) {
            setStatus("idle");
            return;
          }

          if (blob.size < 200) {
            throw new Error("Recording was too short. Please type instead.");
          }

          const fd = new FormData();
          fd.append(
            "audio",
            blob,
            mime.includes("webm") ? "voice-note.webm" : "voice-note.m4a"
          );
          const { apiBaseUrl, brandHeaders } = await import("@/lib/brand");
          const h = brandHeaders(hostHint) as Record<string, string>;
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
                "Couldn’t catch that — try typing instead."
            );
          }
          const text = String((data as { text?: string }).text || "").trim();
          if (!text) throw new Error("No speech detected. Please type instead.");
          onCaptionRef.current(text);
          if (shouldCommit && !deferCommit && onCommitRef.current) {
            await onCommitRef.current(text);
          }
          setStatus("idle");
          return;
        }

        const live = sessionRef.current;
        sessionRef.current = null;
        if (keepCaption) {
          live?.cleanup();
          setStatus("idle");
          return;
        }
        const text = live ? (await live.stop()).trim() : "";
        if (!text) {
          if (shouldCommit) {
            setError("No speech captured. Tap Talk again or type your message.");
          }
          setStatus("idle");
          return;
        }
        onCaptionRef.current(text);
        if (shouldCommit && !deferCommit && onCommitRef.current) {
          await onCommitRef.current(text);
        }
        setStatus("idle");
      } catch (e) {
        cleanupFallbackRecorder();
        sessionRef.current?.cleanup();
        sessionRef.current = null;
        setStatus("idle");
        setError(e instanceof Error ? e.message : "Talk failed. Please type instead.");
      }
    },
    [cleanupFallbackRecorder, clearSilenceTimer, deferCommit, hostHint]
  );

  const scheduleSilenceCommit = useCallback(() => {
    if (!autoSendOnSilence || deferCommit) return;
    clearSilenceTimer();
    silenceTimerRef.current = setTimeout(() => {
      if (
        statusRef.current === "listening" ||
        statusRef.current === "fallback"
      ) {
        void stop({ commit: true });
      }
    }, silenceCommitMs);
  }, [autoSendOnSilence, clearSilenceTimer, deferCommit, silenceCommitMs, stop]);

  const start = useCallback(async () => {
    if (
      !enabled ||
      statusRef.current === "connecting" ||
      statusRef.current === "listening" ||
      statusRef.current === "fallback"
    ) {
      return;
    }
    setError(null);
    clearSilenceTimer();
    setStatus("connecting");
    try {
      const session = new StreamingTalkSession({
        onPartial: (text) => {
          clearSilenceTimer();
          onCaptionRef.current(text);
        },
        onSegment: () => {
          // VAD completed an utterance — after a quiet gap, auto-send.
          scheduleSilenceCommit();
        },
        onStatus: (s) => {
          if (s === "listening") setStatus("listening");
          if (s === "connecting") setStatus("connecting");
          if (s === "idle") setStatus("idle");
        },
        onError: (msg) => setError(msg),
      });
      sessionRef.current = session;
      await session.start(hostHint);
      setStatus("listening");
    } catch {
      sessionRef.current?.cleanup();
      sessionRef.current = null;
      try {
        await startFallbackRecording();
      } catch (fallbackErr) {
        setStatus("idle");
        setError(
          fallbackErr instanceof Error
            ? fallbackErr.message
            : "Microphone unavailable. Please type instead."
        );
      }
    }
  }, [
    clearSilenceTimer,
    enabled,
    hostHint,
    scheduleSilenceCommit,
    startFallbackRecording,
  ]);

  const toggle = useCallback(async () => {
    if (
      statusRef.current === "listening" ||
      statusRef.current === "fallback" ||
      statusRef.current === "connecting"
    ) {
      await stop({ commit: true });
    } else {
      await start();
    }
  }, [start, stop]);

  return {
    status,
    error,
    setError,
    listening:
      status === "listening" || status === "fallback" || status === "connecting",
    start,
    stop,
    toggle,
  };
}
