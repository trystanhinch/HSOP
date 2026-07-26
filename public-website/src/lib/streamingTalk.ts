import { apiBaseUrl, brandHeaders } from "@/lib/brand";

export type TalkSessionCreds = {
  client_secret: string;
  expires_at: number | null;
  webrtc_url: string;
  model: string;
  provider: string;
};

export type StreamingTalkHandlers = {
  onPartial?: (text: string) => void;
  onSegment?: (text: string) => void;
  onError?: (message: string) => void;
  onStatus?: (status: "connecting" | "listening" | "stopping" | "idle") => void;
};

type RealtimeEvent = {
  type?: string;
  delta?: string;
  transcript?: string;
  text?: string;
  error?: { message?: string };
};

/**
 * OpenAI Realtime transcription over WebRTC.
 * Ephemeral secret comes from Laravel — OPENAI_API_KEY never touches the browser.
 */
export class StreamingTalkSession {
  private pc: RTCPeerConnection | null = null;
  private dc: RTCDataChannel | null = null;
  private localStream: MediaStream | null = null;
  private committed: string[] = [];
  private currentPartial = "";
  private stopped = false;
  private handlers: StreamingTalkHandlers;

  constructor(handlers: StreamingTalkHandlers = {}) {
    this.handlers = handlers;
  }

  getCaption(): string {
    const parts = [...this.committed];
    if (this.currentPartial.trim()) parts.push(this.currentPartial.trim());
    return parts.join(" ").replace(/\s+/g, " ").trim();
  }

  async start(hostHint: string): Promise<void> {
    this.stopped = false;
    this.committed = [];
    this.currentPartial = "";
    this.handlers.onStatus?.("connecting");

    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error("Microphone is not available in this browser. Please type instead.");
    }

    const sessionRes = await fetch(`${apiBaseUrl()}/api/public/intake/talk-session`, {
      method: "POST",
      headers: brandHeaders(hostHint),
      credentials: "include",
    });
    const session = (await sessionRes.json().catch(() => ({}))) as TalkSessionCreds & {
      message?: string;
    };
    if (!sessionRes.ok || !session.client_secret) {
      throw new Error(session.message || "Could not start live transcription.");
    }

    this.localStream = await navigator.mediaDevices.getUserMedia({
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
      },
      video: false,
    });

    const pc = new RTCPeerConnection();
    this.pc = pc;
    this.localStream.getTracks().forEach((track) => pc.addTrack(track, this.localStream!));

    const dc = pc.createDataChannel("oai-events");
    this.dc = dc;
    dc.addEventListener("message", (ev) => this.onDataMessage(String(ev.data)));

    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);

    const sdpResponse = await fetch(session.webrtc_url || "https://api.openai.com/v1/realtime/calls", {
      method: "POST",
      body: offer.sdp || "",
      headers: {
        Authorization: `Bearer ${session.client_secret}`,
        "Content-Type": "application/sdp",
      },
    });

    if (!sdpResponse.ok) {
      const errText = await sdpResponse.text().catch(() => "");
      throw new Error(
        errText
          ? `Live transcription connect failed. Please type instead.`
          : "Live transcription connect failed. Please type instead."
      );
    }

    const answer = await sdpResponse.text();
    await pc.setRemoteDescription({ type: "answer", sdp: answer });
    this.handlers.onStatus?.("listening");
  }

  private onDataMessage(raw: string) {
    let event: RealtimeEvent;
    try {
      event = JSON.parse(raw) as RealtimeEvent;
    } catch {
      return;
    }

    if (event.type === "error") {
      this.handlers.onError?.(event.error?.message || "Live transcription error.");
      return;
    }

    if (event.type === "conversation.item.input_audio_transcription.delta" && event.delta) {
      this.currentPartial += event.delta;
      this.handlers.onPartial?.(this.getCaption());
      return;
    }

    if (event.type === "conversation.item.input_audio_transcription.completed") {
      const finalText = (event.transcript || event.text || this.currentPartial || "").trim();
      this.currentPartial = "";
      if (finalText) {
        this.committed.push(finalText);
        this.handlers.onSegment?.(finalText);
      }
      this.handlers.onPartial?.(this.getCaption());
    }
  }

  async stop(): Promise<string> {
    if (this.stopped) return this.getCaption();
    this.stopped = true;
    this.handlers.onStatus?.("stopping");

    try {
      this.dc?.send(JSON.stringify({ type: "input_audio_buffer.commit" }));
    } catch {
      /* channel may already be closed */
    }

    // Brief wait for a final completed event after commit.
    await new Promise((r) => setTimeout(r, 450));

    const text = this.getCaption();
    this.cleanup();
    this.handlers.onStatus?.("idle");
    return text;
  }

  cleanup() {
    try {
      this.dc?.close();
    } catch {
      /* ignore */
    }
    this.dc = null;
    try {
      this.pc?.getSenders().forEach((s) => s.track?.stop());
      this.pc?.close();
    } catch {
      /* ignore */
    }
    this.pc = null;
    this.localStream?.getTracks().forEach((t) => t.stop());
    this.localStream = null;
  }
}

/** Secondary fallback: MediaRecorder → Whisper batch endpoint. */
export async function whisperFallbackTranscribe(
  hostHint: string,
  onStatus?: (s: string) => void
): Promise<string> {
  if (!navigator.mediaDevices?.getUserMedia) {
    throw new Error("Microphone is not available. Please type instead.");
  }
  onStatus?.("Recording fallback…");
  const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  const mime = MediaRecorder.isTypeSupported("audio/webm")
    ? "audio/webm"
    : "audio/mp4";
  const recorder = new MediaRecorder(stream, { mimeType: mime });
  const chunks: Blob[] = [];

  const blobPromise = new Promise<Blob>((resolve, reject) => {
    recorder.ondataavailable = (e) => {
      if (e.data.size > 0) chunks.push(e.data);
    };
    recorder.onerror = () => reject(new Error("Recording failed."));
    recorder.onstop = () => resolve(new Blob(chunks, { type: mime }));
  });

  recorder.start();
  await new Promise((r) => setTimeout(r, 3500));
  if (recorder.state !== "inactive") recorder.stop();
  stream.getTracks().forEach((t) => t.stop());

  const blob = await blobPromise;
  if (blob.size < 200) {
    throw new Error("Recording was too short. Please type instead.");
  }

  onStatus?.("Transcribing…");
  const fd = new FormData();
  fd.append("audio", blob, mime.includes("webm") ? "voice-note.webm" : "voice-note.m4a");
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
  return text;
}
