import { useEffect, useState } from 'react';
import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard,
  Users,
  Briefcase,
  HardHat,
  UserCheck,
  FileText,
  Calendar,
  MessageSquare,
  Receipt,
  DollarSign,
  BarChart2,
  Settings,
  Database,
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { getRoleDashboard } from '../utils/getRoleDashboard';
import api from '../api/axios';

const roleLabel = { owner: 'Admin', pm: 'Project Manager', contractor: 'Contractor', customer: 'Customer' };
const roleBg = { owner: 'bg-purple-600', pm: 'bg-blue-600', contractor: 'bg-orange-500', customer: 'bg-green-600' };

const allNavItems = [
  { label: 'Dashboard', icon: LayoutDashboard, roles: ['owner', 'pm', 'contractor', 'customer'], dashboard: true },
  { label: 'Leads', icon: Users, path: '/leads', roles: ['owner', 'pm'] },
  { label: 'Jobs', icon: Briefcase, path: '/jobs', roles: ['owner', 'pm', 'contractor'] },
  { label: 'My Profile', icon: HardHat, path: '__contractor_profile__', roles: ['contractor'] },
  { label: 'Contractors', icon: HardHat, path: '/contractors', roles: ['owner', 'pm'] },
  { label: 'Customers', icon: UserCheck, path: '/customers', roles: ['owner', 'pm'] },
  { label: 'Quotes', icon: FileText, path: '/quotes', roles: ['owner', 'pm'] },
  { label: 'Schedule', icon: Calendar, path: '/schedule', roles: ['owner', 'pm', 'contractor'] },
  { label: 'Messages', icon: MessageSquare, path: '/messages', roles: ['owner', 'pm', 'contractor', 'customer'] },
  { label: 'Invoices', icon: Receipt, path: '/invoices', roles: ['owner', 'pm'] },
  { label: 'Payouts', icon: DollarSign, path: '/payouts', roles: ['owner', 'pm', 'contractor'] },
  { label: 'Reports', icon: BarChart2, path: '/reports', roles: ['owner'] },
  { label: 'DB Structure', icon: Database, path: '/settings?tab=database', roles: ['owner'] },
  { label: 'Settings', icon: Settings, path: '/settings', roles: ['owner'] },
];

export default function Sidebar({ onNavClick }) {
  const { user } = useAuth();
  const [contractorId, setContractorId] = useState(null);
  const navItems = allNavItems.filter((item) => item.roles.includes(user?.role));

  useEffect(() => {
    if (user?.role === 'contractor') {
      api.get('/me/contractor').then(({ data }) => setContractorId(data.id)).catch(() => {});
    }
  }, [user?.role]);

  const getPath = (item) => {
    if (item.dashboard) return getRoleDashboard(user?.role);
    if (item.path === '__contractor_profile__' && contractorId) return `/contractors/${contractorId}`;
    return item.path;
  };

  return (
    <div className="flex flex-col h-full flex-1">
      <nav className="flex-1 overflow-y-auto py-4 px-3 space-y-1">
        {navItems.map((item) => {
          if (item.path === '__contractor_profile__' && !contractorId) return null;
          const Icon = item.icon;
          return (
            <NavLink
              key={item.label}
              to={getPath(item)}
              onClick={onNavClick}
              className={({ isActive }) =>
                `flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-colors cursor-pointer ${
                  isActive ? 'bg-[#334155] text-white' : 'text-[#F1F5F9] hover:bg-slate-700/60'
                }`
              }
            >
              <Icon size={18} />
              {item.label}
            </NavLink>
          );
        })}
      </nav>

      {user && (
        <div className="p-4 border-t border-slate-700 mt-auto">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-full bg-slate-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
              {user.name?.charAt(0)}
            </div>
            <div className="min-w-0">
              <p className="text-sm text-white font-medium truncate">{user.name}</p>
              <span className={`text-xs px-2 py-0.5 rounded-full text-white font-medium ${roleBg[user.role] || 'bg-slate-600'}`}>
                {roleLabel[user.role]}
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
