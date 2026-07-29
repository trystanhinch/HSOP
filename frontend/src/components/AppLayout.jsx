import { useState, useEffect } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Menu, X } from 'lucide-react';
import Sidebar from './Sidebar';
import Header from './Header';
import EnvironmentBadge from './EnvironmentBadge';
import BottomTabBar from './BottomTabBar';
import { useAuth } from '../context/AuthContext';
import {
  usesBottomTabs,
  moreItemsForRole,
  navItemsForRole,
} from '../nav/navConfig';

/**
 * A-07 / PM-12 / CT-06 — shared responsive application shell.
 *
 * Desktop (md+): permanent sidebar for all roles.
 * Mobile:
 *   - Owner / content_editor / customer: hamburger → slide-out drawer
 *   - PM / Contractor: bottom tab bar for daily workflow + More → drawer
 *
 * Safe-area insets keep primary chrome above iOS Safari / Android Chrome toolbars.
 */
function getPageTitle(pathname, search) {
  if (search.includes('tab=database')) return 'Database Structure';
  if (search.includes('tab=test-data')) return 'Test Data';
  if (pathname.includes('/dashboard/admin')) return 'Admin Dashboard';
  if (pathname.includes('/dashboard/pm')) return 'PM Dashboard';
  if (pathname.includes('/dashboard/contractor')) return 'Dashboard';
  if (pathname.includes('/dashboard/customer')) return 'Customer Dashboard';
  if (pathname.startsWith('/site-visits/')) return 'Site Visit';
  if (pathname.startsWith('/leads/')) return 'Lead Detail';
  if (pathname.startsWith('/jobs/')) return 'Job Detail';
  if (pathname.startsWith('/contractors/')) return 'Contractor Profile';
  const titles = {
    '/leads': 'Leads', '/jobs': 'Jobs', '/contractors': 'Contractors',
    '/customers': 'Customers', '/quotes': 'Quotes', '/schedule': 'Schedule',
    '/messages': 'Messages', '/invoices': 'Invoices', '/payouts': 'Payouts',
    '/accounting': 'Accounting', '/ai-command-center': 'AI Command Center',
    '/reports': 'Reports', '/company-sources': 'Company Sources', '/pricing-rules': 'Pricing Rules',
    '/availability': 'Availability', '/brand-content': 'Brand Content', '/settings': 'Settings',
    '/my-leads': 'My Leads', '/unauthorized': 'Access Denied',
  };
  return titles[pathname] || 'ServiceOP';
}

export default function AppLayout() {
  const [drawerOpen, setDrawerOpen] = useState(false);
  const { pathname, search } = useLocation();
  const { user } = useAuth();
  const title = getPageTitle(pathname, search);
  const fieldRole = usesBottomTabs(user?.role);
  const drawerItems = fieldRole ? moreItemsForRole(user?.role) : navItemsForRole(user?.role);

  // Close drawer on route change
  useEffect(() => {
    setDrawerOpen(false);
  }, [pathname, search]);

  // Lock body scroll when drawer is open
  useEffect(() => {
    if (!drawerOpen) return undefined;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = prev; };
  }, [drawerOpen]);

  return (
    <div className="flex h-[100dvh] overflow-hidden bg-slate-50">
      {/* Mobile drawer overlay */}
      {drawerOpen && (
        <div className="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <button
            type="button"
            className="absolute inset-0 bg-black/50 border-0 cursor-pointer"
            aria-label="Close menu"
            onClick={() => setDrawerOpen(false)}
          />
          <div
            className="absolute left-0 top-0 h-full w-[min(18rem,88vw)] bg-slate-800 z-50 shadow-xl flex flex-col"
            style={{ paddingTop: 'env(safe-area-inset-top, 0px)' }}
          >
            <div className="flex items-center justify-between px-4 pt-4 pb-2">
              <span className="text-white font-bold text-lg">ServiceOP</span>
              <button type="button" onClick={() => setDrawerOpen(false)} className="text-slate-400 hover:text-white p-2 min-h-[44px] min-w-[44px] flex items-center justify-center">
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="px-4 pb-2">
              <EnvironmentBadge />
            </div>
            {fieldRole && (
              <p className="px-4 pb-2 text-[10px] uppercase tracking-wide text-slate-400 font-semibold">More</p>
            )}
            <Sidebar onNavClick={() => setDrawerOpen(false)} items={drawerItems} />
          </div>
        </div>
      )}

      {/* Desktop permanent sidebar */}
      <div className="hidden md:flex md:flex-shrink-0">
        <div className="flex flex-col w-64 bg-slate-800 h-full">
          <div className="px-6 py-5 border-b border-slate-700">
            <h1 className="text-white text-lg font-bold tracking-tight">ServiceOP</h1>
            <div className="mt-2">
              <EnvironmentBadge />
            </div>
          </div>
          <Sidebar />
        </div>
      </div>

      {/* Main column */}
      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        <header
          className="flex items-center bg-white border-b border-slate-200 px-3 sm:px-4 h-14 sm:h-16 flex-shrink-0 gap-2 sm:gap-3"
          style={{ paddingTop: 'env(safe-area-inset-top, 0px)' }}
        >
          {/* Hamburger: always on owner mobile; on field roles only when opening More isn't enough — also show for non-tab roles */}
          {!fieldRole && (
            <button
              type="button"
              className="md:hidden p-2 rounded-lg text-slate-500 hover:bg-slate-100 flex-shrink-0 min-h-[44px] min-w-[44px] flex items-center justify-center"
              onClick={() => setDrawerOpen(true)}
              aria-label="Open menu"
            >
              <Menu className="w-5 h-5" />
            </button>
          )}
          <Header title={title} />
        </header>

        <main
          className={`flex-1 overflow-y-auto overflow-x-hidden p-3 sm:p-4 md:p-6 overscroll-contain ${
            fieldRole ? 'pb-[calc(4.5rem+env(safe-area-inset-bottom,0px))] md:pb-6' : 'pb-[max(1rem,env(safe-area-inset-bottom))]'
          }`}
        >
          <Outlet />
        </main>
      </div>

      {fieldRole && <BottomTabBar onMoreClick={() => setDrawerOpen(true)} />}
    </div>
  );
}
