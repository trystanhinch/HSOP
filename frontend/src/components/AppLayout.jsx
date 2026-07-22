import { useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Menu, X } from 'lucide-react';
import Sidebar from './Sidebar';
import Header from './Header';

function getPageTitle(pathname, search) {
  if (search.includes('tab=database')) return 'Database Structure';
  if (pathname.includes('/dashboard/admin')) return 'Admin Dashboard';
  if (pathname.includes('/dashboard/pm')) return 'PM Dashboard';
  if (pathname.includes('/dashboard/contractor')) return 'Contractor Dashboard';
  if (pathname.includes('/dashboard/customer')) return 'Customer Dashboard';
  if (pathname.startsWith('/leads/')) return 'Lead Detail';
  if (pathname.startsWith('/jobs/')) return 'Job Detail';
  if (pathname.startsWith('/contractors/')) return 'Contractor Profile';
  const titles = {
    '/leads': 'Leads', '/jobs': 'Jobs', '/contractors': 'Contractors',
    '/customers': 'Customers', '/quotes': 'Quotes', '/schedule': 'Schedule',
    '/messages': 'Messages', '/invoices': 'Invoices', '/payouts': 'Payouts',
    '/accounting': 'Accounting',
    '/reports': 'Reports', '/settings': 'Settings', '/unauthorized': 'Access Denied',
  };
  return titles[pathname] || 'ServiceOP';
}

export default function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const { pathname, search } = useLocation();
  const title = getPageTitle(pathname, search);

  return (
    <div className="flex h-screen overflow-hidden bg-slate-50">
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="absolute inset-0 bg-black/50" onClick={() => setSidebarOpen(false)} />
          <div className="absolute left-0 top-0 h-full w-64 bg-slate-800 z-50 shadow-xl flex flex-col">
            <div className="flex items-center justify-between px-4 pt-4 pb-2">
              <span className="text-white font-bold text-lg">ServiceOP</span>
              <button type="button" onClick={() => setSidebarOpen(false)} className="text-slate-400 hover:text-white p-1">
                <X className="w-5 h-5" />
              </button>
            </div>
            <Sidebar onNavClick={() => setSidebarOpen(false)} />
          </div>
        </div>
      )}

      <div className="hidden md:flex md:flex-shrink-0">
        <div className="flex flex-col w-64 bg-slate-800">
          <div className="px-6 py-5 border-b border-slate-700">
            <h1 className="text-white text-lg font-bold tracking-tight">ServiceOP</h1>
          </div>
          <Sidebar />
        </div>
      </div>

      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        <div className="flex items-center bg-white border-b border-slate-200 px-4 h-16 flex-shrink-0 gap-3">
          <button
            type="button"
            className="md:hidden p-2 rounded-lg text-slate-500 hover:bg-slate-100 flex-shrink-0"
            onClick={() => setSidebarOpen(true)}
          >
            <Menu className="w-5 h-5" />
          </button>
          <Header title={title} />
        </div>
        <main className="flex-1 overflow-y-auto p-4 md:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
