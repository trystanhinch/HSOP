import { usesBottomTabs } from '../nav/navConfig';
import { useAuth } from '../context/AuthContext';

/**
 * Sticky bottom action bar — keeps primary actions above iOS/Android browser chrome.
 * Uses env(safe-area-inset-bottom). Accounts for field-role bottom tab bar height.
 */
export default function StickyActionBar({ children, className = '' }) {
  const { user } = useAuth();
  const fieldTabs = usesBottomTabs(user?.role);

  return (
    <div
      className={`fixed left-0 right-0 z-30 border-t border-slate-200 bg-white/95 backdrop-blur-sm shadow-[0_-4px_12px_rgba(15,23,42,0.06)] ${className}`}
      style={{
        bottom: fieldTabs ? 'calc(3.5rem + env(safe-area-inset-bottom, 0px))' : 'env(safe-area-inset-bottom, 0px)',
        paddingBottom: fieldTabs ? 0 : undefined,
      }}
    >
      <div className="max-w-3xl mx-auto px-4 py-3 flex flex-wrap gap-2 justify-stretch sm:justify-end">
        {children}
      </div>
    </div>
  );
}
