"use client";

import { useEffect, useState } from "react";

const TALK_SESSION_KEY = "serviceop_enable_talk";
const INTAKE_TOKEN_KEY = "serviceop_intake_token";
const FORCE_FRESH_KEY = "serviceop_force_fresh_intake";

/**
 * Talk (live STT) enablement:
 * 1. Global build flag NEXT_PUBLIC_ENABLE_INTAKE_TALK=true (full rollout), OR
 * 2. Per-browser session via ?enableTalk=1 (stores sessionStorage for that tab only).
 *
 * Visiting with ?enableTalk=1 clears any prior intake token once per page load
 * so private test links start a clean conversation (Talk stays enabled).
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
      // One-shot per page load: drop resumed conversation/price state.
      window.localStorage.removeItem(INTAKE_TOKEN_KEY);
      window.sessionStorage.setItem(FORCE_FRESH_KEY, "1");
      return true;
    }
    return window.sessionStorage.getItem(TALK_SESSION_KEY) === "1";
  } catch {
    return false;
  }
}

/**
 * Whether ChatWidget should refuse to resume a stored session.
 * Consumes the one-shot flag set by ?enableTalk=1 / ?fresh=1.
 */
export function consumeForceFreshIntakeSession(): boolean {
  if (typeof window === "undefined") return false;
  try {
    const params = new URLSearchParams(window.location.search);
    if (params.get("fresh") === "1" || params.get("new") === "1") {
      window.localStorage.removeItem(INTAKE_TOKEN_KEY);
      return true;
    }
    if (window.sessionStorage.getItem(FORCE_FRESH_KEY) === "1") {
      // Consumed after homepage may have created a brand-new session for this visit.
      window.sessionStorage.removeItem(FORCE_FRESH_KEY);
      // Do not delete the token here — ensureSession/openChat may have just set it.
      return false;
    }
    return false;
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
