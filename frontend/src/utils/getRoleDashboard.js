export function getRoleDashboard(role) {
  const map = {
    owner: '/dashboard/admin',
    pm: '/dashboard/pm',
    contractor: '/dashboard/contractor',
    customer: '/dashboard/customer',
  };
  return map[role] || '/dashboard/admin';
}
