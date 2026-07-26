"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import {
  StreamingTalkSession,
  whisperFallbackTranscribe,
} from "@/lib/streamingTalk";

type TalkStatus = "idle" | "connecting" | "listening" | "stopping" | "fallback";

type Options = {
  hostHint: string;
  enabled: boolean;
  /** Live caption text while listening (and finalized text on stop before clear). */
  onCaption: (text: string) => void;
  /** Called when a Talk turn is ready to send into chat (explicit stop). */
  onCommitTurn?: (text: string) => void | Promise<void>;
  /** When true, stop only fills caption / input — caller sends manually (homepage Go). */
  deferCommit?: boolean;
};

export function useTalkInput({
  hostHint,
  enabled,
  onCaption,
  onCommitTurn,
  deferCommit = false,
}: Options) {
  const [status, setStatus] = useState<TalkStatus>("idle");
  const [error, setError] = useState<string | null>(null);
  const sessionRef = useRef<StreamingTalkSession | null>(null);
  const mediaFallbackRef = useRef<MediaRecorder | null>(null);
  const fallbackChunksRef = useRef<Blob[]>([]);
  const fallbackStreamRef = useRef<MediaStream | null>(null);
  const usingFallbackRef = useRef(false);

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
  }, [cleanupFallbackRecorder]);

  useEffect(() => {
    return () => {
      void stopAll();
    };
  }, [stopAll]);

  // Pause cleanly when tab backgrounds (Safari iOS often suspends mic).
  useEffect(() => {
    if (!enabled) return;
    const onVisibility = () => {
      if (document.visibilityState === "hidden" && status === "listening") {
        setError("Microphone paused when the tab was backgrounded. Tap Talk to continue, or type.");
        void stopAll();
      }
    };
    document.addEventListener("visibilitychange", onVisibility);
    return () => document.removeEventListener("visibilitychange", onVisibility);
  }, [enabled, status, stopAll]);

  const startFallbackRecording = useCallback(async () => {
    usingFallbackRef.current = true;
    setStatus("fallback");
    setError("Live transcription unavailable — using short voice-note fallback. Tap Stop when done.");
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

  const start = useCallback(async () => {
    if (!enabled || status === "connecting" || status === "listening" || status === "fallback") {
      return;
    }
    setError(null);
    setStatus("connecting");
    try {
      const session = new StreamingTalkSession({
        onPartial: (text) => onCaption(text),
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
    } catch (e) {
      sessionRef.current?.cleanup();
      sessionRef.current = null;
      const msg = e instanceof Error ? e.message : "Could not start Talk.";
      try {
        await startFallbackRecording();
      } catch (fallbackErr) {
        setStatus("idle");
        setError(
          fallbackErr instanceof Error
            ? fallbackErr.message
            : msg + " Please type instead."
        );
      }
    }
  }, [enabled, hostHint, onCaption, startFallbackRecording, status]);

  const stop = useCallback(
    async (opts?: { commit?: boolean; keepCaption?: boolean }) => {
      const shouldCommit = opts?.commit !== false;
      const keepCaption = opts?.keepCaption === true;
      if (status === "idle" || status === "stopping") return;
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
                "Transcription failed. Please type instead."
            );
          }
          const text = String((data as { text?: string }).text || "").trim();
          if (!text) throw new Error("No speech detected. Please type instead.");
          onCaption(text);
          if (shouldCommit && !deferCommit && onCommitTurn) await onCommitTurn(text);
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
        onCaption(text);
        if (shouldCommit && !deferCommit && onCommitTurn) await onCommitTurn(text);
        setStatus("idle");
      } catch (e) {
        cleanupFallbackRecorder();
        sessionRef.current?.cleanup();
        sessionRef.current = null;
        setStatus("idle");
        setError(e instanceof Error ? e.message : "Talk failed. Please type instead.");
      }
    },
    [
      cleanupFallbackRecorder,
      deferCommit,
      hostHint,
      onCaption,
      onCommitTurn,
      status,
    ]
  );

  const toggle = useCallback(async () => {
    if (status === "listening" || status === "fallback" || status === "connecting") {
      await stop({ commit: true });
    } else {
      await start();
    }
  }, [start, status, stop]);

  return {
    status,
    error,
    setError,
    listening: status === "listening" || status === "fallback" || status === "connecting",
    start,
    stop,
    toggle,
  };
}

export async function runWhisperOneShot(hostHint: string): Promise<string> {
  return whisperFallbackTranscribe(hostHint);
}
