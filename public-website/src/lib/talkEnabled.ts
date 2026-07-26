"use client";

import { useEffect, useState } from "react";

const TALK_SESSION_KEY = "serviceop_enable_talk";
const INTAKE_TOKEN_KEY = "serviceop_intake_token";
/** Set after the one-shot clear for this page load of ?enableTalk=1. */
const FRESH_CLEARED_KEY = "serviceop_enable_talk_cleared";

/**
 * Talk (live STT) enablement:
 * 1. Global build flag NEXT_PUBLIC_ENABLE_INTAKE_TALK=true (full rollout), OR
 * 2. Per-browser session via ?enableTalk=1 (stores sessionStorage for that tab only).
 *
 * Visiting with ?enableTalk=1 clears any prior intake token ONCE per page load
 * so private test links start clean — subsequent calls must not wipe a session
 * the homepage just created and messaged into.
 */
export function talkIntakeEnabled(): boolean {
  if (process.env.NEXT_PUBLIC_ENABLE_INTAKE_TALK === "true") {
    return true;
  }
  if (typeof window === "undefined") {
    return false;
  }
  try {
    const params = new URLSearchParams(window.location.search);
    if (params.get("enableTalk") === "1") {
      window.sessionStorage.setItem(TALK_SESSION_KEY, "1");
      // One-shot per full page load (sessionStorage survives SPA remounts;
      // cleared on hard refresh automatically when tab closes — use a
      // page-load marker tied to navigation timing).
      const navKey = `${FRESH_CLEARED_KEY}:${performance.timeOrigin || "0"}`;
      if (!window.sessionStorage.getItem(navKey)) {
        window.localStorage.removeItem(INTAKE_TOKEN_KEY);
        window.sessionStorage.setItem(navKey, "1");
      }
      return true;
    }
    return window.sessionStorage.getItem(TALK_SESSION_KEY) === "1";
  } catch {
    return false;
  }
}

export function clearIntakeSessionToken(): void {
  try {
    window.localStorage.removeItem(INTAKE_TOKEN_KEY);
  } catch {
    /* ignore */
  }
}

/** Client hook so ?enableTalk=1 can flip UI after hydration without a global flag. */
export function useTalkIntakeEnabled(): boolean {
  const [enabled, setEnabled] = useState(
    () => process.env.NEXT_PUBLIC_ENABLE_INTAKE_TALK === "true"
  );

  useEffect(() => {
    setEnabled(talkIntakeEnabled());
  }, []);

  return enabled;
}
