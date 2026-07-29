import { NavLink, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import {
  bottomTabsForRole,
  MORE_TAB,
} from '../nav/navConfig';
import { resolveNavPath, useContractorId } from './Sidebar';

/**
 * PM-12 / CT-06 — bottom tab bar for field roles below md breakpoint.
 * Primary daily destinations + "More" which opens the drawer.
 */
export default function BottomTabBar({ onMoreClick }) {
  const { user } = useAuth();
  const { pathname } = useLocation();
  const contractorId = useContractorId();
  const tabs = bottomTabsForRole(user?.role);
  if (!tabs) return null;

  return (
    <nav
      className="md:hidden fixed bottom-0 inset-x-0 z-40 bg-slate-900 border-t border-slate-700 safe-bottom"
      style={{ paddingBottom: 'env(safe-area-inset-bottom, 0px)' }}
      aria-label="Primary"
    >
      <div className="flex items-stretch h-14">
        {tabs.map((item) => {
          const Icon = item.icon;
          if (item.id === MORE_TAB.id) {
            return (
              <button
                key="more"
                type="button"
                onClick={onMoreClick}
                className="flex-1 flex flex-col items-center justify-center gap-0.5 text-slate-400 hover:text-white min-w-0"
              >
                <Icon className="w-5 h-5" />
                <span className="text-[10px] font-medium truncate max-w-full px-1">More</span>
              </button>
            );
          }
          const path = resolveNavPath(item, user, contractorId);
          return (
            <NavLink
              key={item.id}
              to={path}
              end={item.dashboard === true}
              className={({ isActive }) => {
                const active = isActive
                  || (item.path === '/jobs' && pathname.startsWith('/jobs'))
                  || (item.path === '/leads' && pathname.startsWith('/leads'))
                  || (item.path === '/messages' && pathname.startsWith('/messages'))
                  || (item.path === '/schedule' && pathname.startsWith('/schedule'));
                return `flex-1 flex flex-col items-center justify-center gap-0.5 min-w-0 ${
                  active ? 'text-white' : 'text-slate-400 hover:text-white'
                }`;
              }}
            >
              <Icon className="w-5 h-5" />
              <span className="text-[10px] font-medium truncate max-w-full px-1">{item.label}</span>
            </NavLink>
          );
        })}
      </div>
    </nav>
  );
}
