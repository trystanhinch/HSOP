import { useEffect } from 'react';

/**
 * Milestone 6A.2 — persistent top banner when VITE_STAGING_MODE=true.
 * Independent of login / EnvironmentBadge so public portal pages also show it.
 */
export default function StagingBanner() {
  const enabled = String(import.meta.env.VITE_STAGING_MODE || '').toLowerCase() === 'true';

  useEffect(() => {
    if (!enabled) return undefined;
    const meta = document.createElement('meta');
    meta.name = 'robots';
    meta.content = 'noindex, nofollow, noarchive';
    meta.setAttribute('data-staging-noindex', '1');
    document.head.appendChild(meta);
    return () => {
      document.querySelectorAll('meta[data-staging-noindex="1"]').forEach((el) => el.remove());
    };
  }, [enabled]);

  if (!enabled) return null;

  return (
    <div
      role="status"
      data-testid="staging-banner"
      className="sticky top-0 z-[100] w-full bg-amber-400 text-amber-950 border-b-2 border-amber-700 px-3 py-2 text-center text-sm font-bold tracking-wide"
    >
      STAGING — Not Production. Data may be reset at any time.
    </div>
  );
}
