/**
 * A-07 / PM-12 / CT-06 — shared nav config for AppLayout shell.
 * Owner: full sidebar / slide-out drawer.
 * PM & Contractor: bottom tabs for daily workflow + "More" drawer for the rest.
 */
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
  Wallet,
  Settings,
  Database,
  Building2,
  Bot,
  Calculator,
  MoreHorizontal,
} from 'lucide-react';

export const ROLE_LABEL = {
  owner: 'Admin',
  pm: 'Project Manager',
  contractor: 'Contractor',
  customer: 'Customer',
  content_editor: 'Content Editor',
};

export const ROLE_BG = {
  owner: 'bg-purple-600',
  pm: 'bg-blue-600',
  contractor: 'bg-orange-500',
  customer: 'bg-green-600',
  content_editor: 'bg-teal-600',
};

/** All sidebar/drawer items (filtered by role at render time). */
export const ALL_NAV_ITEMS = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, roles: ['owner', 'pm', 'contractor', 'customer'], dashboard: true },
  { id: 'brand-content', label: 'Brand Content', icon: Building2, path: '/brand-content', roles: ['content_editor', 'owner'] },
  { id: 'leads', label: 'Leads', icon: Users, path: '/leads', roles: ['owner', 'pm'] },
  { id: 'my-leads', label: 'Leads', icon: Users, path: '/my-leads', roles: ['contractor'] },
  { id: 'jobs', label: 'Jobs', icon: Briefcase, path: '/jobs', roles: ['owner', 'pm', 'contractor'] },
  { id: 'contractor-profile', label: 'My Profile', icon: HardHat, path: '__contractor_profile__', roles: ['contractor'] },
  { id: 'contractors', label: 'Contractors', icon: HardHat, path: '/contractors', roles: ['owner', 'pm'] },
  { id: 'customers', label: 'Customers', icon: UserCheck, path: '/customers', roles: ['owner', 'pm'] },
  { id: 'quotes', label: 'Quotes', icon: FileText, path: '/quotes', roles: ['owner', 'pm'] },
  { id: 'schedule', label: 'Schedule', icon: Calendar, path: '/schedule', roles: ['owner', 'pm', 'contractor'] },
  { id: 'messages', label: 'Messages', icon: MessageSquare, path: '/messages', roles: ['owner', 'pm', 'contractor', 'customer'] },
  { id: 'invoices', label: 'Invoices', icon: Receipt, path: '/invoices', roles: ['owner', 'pm'] },
  { id: 'payouts', label: 'Payouts', icon: DollarSign, path: '/payouts', roles: ['owner', 'pm', 'contractor'] },
  { id: 'accounting', label: 'Accounting', icon: Wallet, path: '/accounting', roles: ['owner'] },
  { id: 'ai', label: 'AI Command Center', icon: Bot, path: '/ai-command-center', roles: ['owner'] },
  { id: 'reports', label: 'Reports', icon: BarChart2, path: '/reports', roles: ['owner'] },
  { id: 'company-sources', label: 'Company Sources', icon: Building2, path: '/company-sources', roles: ['owner'] },
  { id: 'pricing-rules', label: 'Pricing Rules', icon: Calculator, path: '/pricing-rules', roles: ['owner'] },
  { id: 'availability', label: 'Availability', icon: Calendar, path: '/availability', roles: ['owner', 'pm'] },
  { id: 'db', label: 'DB Structure', icon: Database, path: '/settings?tab=database', roles: ['owner'] },
  { id: 'settings', label: 'Settings', icon: Settings, path: '/settings', roles: ['owner'] },
];

/**
 * Primary bottom-tab ids per field role (PM-12 / CT-06).
 * Remaining items appear under "More" → drawer.
 */
export const BOTTOM_TAB_IDS = {
  pm: ['dashboard', 'leads', 'jobs', 'messages'],
  contractor: ['dashboard', 'jobs', 'messages', 'schedule'],
};

export const MORE_TAB = {
  id: 'more',
  label: 'More',
  icon: MoreHorizontal,
};

export function navItemsForRole(role) {
  return ALL_NAV_ITEMS.filter((item) => item.roles.includes(role));
}

export function bottomTabsForRole(role) {
  const ids = BOTTOM_TAB_IDS[role];
  if (!ids) return null;
  const items = navItemsForRole(role);
  const tabs = ids
    .map((id) => items.find((i) => i.id === id))
    .filter(Boolean);
  return [...tabs, MORE_TAB];
}

export function moreItemsForRole(role) {
  const ids = BOTTOM_TAB_IDS[role];
  if (!ids) return navItemsForRole(role);
  return navItemsForRole(role).filter((i) => !ids.includes(i.id));
}

/** Owner/admin use a drawer on mobile; field roles use bottom tabs. */
export function usesBottomTabs(role) {
  return role === 'pm' || role === 'contractor';
}
