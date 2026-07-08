export function getRoleDashboard(role) {
  const map = {
    owner: '/dashboard/admin',
    pm: '/dashboard/pm',
    contractor: '/dashboard/contractor',
    customer: '/dashboard/customer',
    ai_super_admin: '/unauthorized',
  };
  return map[role] || '/unauthorized';
}
