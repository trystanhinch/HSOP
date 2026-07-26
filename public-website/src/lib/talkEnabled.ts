"use client";

import { useEffect, useState } from "react";

const TALK_SESSION_KEY = "serviceop_enable_talk";

/**
 * Talk (live STT) enablement:
 * 1. Global build flag NEXT_PUBLIC_ENABLE_INTAKE_TALK=true (full rollout), OR
 * 2. Per-browser session via ?enableTalk=1 (stores sessionStorage for that tab only).
 *
 * Normal visitors without the flag or query param never see Talk.
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
      return true;
    }
    return window.sessionStorage.getItem(TALK_SESSION_KEY) === "1";
  } catch {
    return false;
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
