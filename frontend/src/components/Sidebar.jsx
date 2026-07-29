import { useEffect, useState } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { getRoleDashboard } from '../utils/getRoleDashboard';
import api from '../api/axios';
import {
  ROLE_LABEL,
  ROLE_BG,
  navItemsForRole,
} from '../nav/navConfig';

export function resolveNavPath(item, user, contractorId) {
  if (item.dashboard) return getRoleDashboard(user?.role);
  if (item.path === '__contractor_profile__' && contractorId) return `/contractors/${contractorId}`;
  return item.path;
}

export function useContractorId() {
  const { user } = useAuth();
  const [contractorId, setContractorId] = useState(null);
  useEffect(() => {
    if (user?.role === 'contractor') {
      api.get('/me/contractor').then(({ data }) => setContractorId(data.id)).catch(() => {});
    }
  }, [user?.role]);
  return contractorId;
}

export function useLeadReviewCount() {
  const { user } = useAuth();
  const [reviewCount, setReviewCount] = useState(0);
  useEffect(() => {
    if (user?.role === 'owner') {
      api.get('/leads/review-count').then(({ data }) => setReviewCount(data.count || 0)).catch(() => setReviewCount(0));
    }
  }, [user?.role]);
  return reviewCount;
}

export default function Sidebar({ onNavClick, items }) {
  const { user } = useAuth();
  const contractorId = useContractorId();
  const reviewCount = useLeadReviewCount();
  const navItems = items || navItemsForRole(user?.role);

  return (
    <div className="flex flex-col h-full flex-1 min-h-0">
      <nav className="flex-1 overflow-y-auto py-4 px-3 space-y-1 overscroll-contain">
        {navItems.map((item) => {
          if (item.path === '__contractor_profile__' && !contractorId) return null;
          const Icon = item.icon;
          const path = resolveNavPath(item, user, contractorId);
          return (
            <NavLink
              key={item.id || item.label}
              to={path}
              end={item.dashboard === true}
              onClick={onNavClick}
              className={({ isActive }) =>
                `flex items-center gap-3 px-3 py-2.5 min-h-[44px] rounded-md text-sm font-medium transition-colors cursor-pointer ${
                  isActive ? 'bg-[#334155] text-white' : 'text-[#F1F5F9] hover:bg-slate-700/60'
                }`
              }
            >
              <Icon size={18} className="flex-shrink-0" />
              <span className="flex-1 truncate">{item.label}</span>
              {item.id === 'leads' && reviewCount > 0 && (
                <span
                  title="Needs Review: production leads with needs_manual_review=true (same as Admin Dashboard)"
                  className="bg-amber-500 text-white text-xs font-bold px-1.5 py-0.5 rounded-full min-w-[1.25rem] text-center"
                >
                  {reviewCount}
                </span>
              )}
            </NavLink>
          );
        })}
      </nav>

      {user && (
        <div className="p-4 border-t border-slate-700 mt-auto flex-shrink-0 pb-[max(1rem,env(safe-area-inset-bottom))]">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-full bg-slate-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
              {user.name?.charAt(0)}
            </div>
            <div className="min-w-0">
              <p className="text-sm text-white font-medium truncate">{user.name}</p>
              <span className={`text-xs px-2 py-0.5 rounded-full text-white font-medium ${ROLE_BG[user.role] || 'bg-slate-600'}`}>
                {ROLE_LABEL[user.role]}
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
