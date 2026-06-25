import { getStatusLabel } from '../utils/statusLabels';

const colorMap = {
  approved: 'bg-green-100 text-green-700 border-green-200',
  converted: 'bg-green-100 text-green-700 border-green-200',
  completed: 'bg-green-100 text-green-700 border-green-200',
  paid: 'bg-green-100 text-green-700 border-green-200',
  active: 'bg-green-100 text-green-700 border-green-200',
  quote_approved: 'bg-green-100 text-green-700 border-green-200',

  pending: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  pending_review: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  scheduled: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  site_visit_scheduled: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  waiting_on_customer: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  partially_paid: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  draft: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  submitted: 'bg-yellow-100 text-yellow-700 border-yellow-200',

  new: 'bg-blue-100 text-blue-700 border-blue-200',
  new_job: 'bg-blue-100 text-blue-700 border-blue-200',
  in_progress: 'bg-blue-100 text-blue-700 border-blue-200',
  sent: 'bg-blue-100 text-blue-700 border-blue-200',
  viewed: 'bg-blue-100 text-blue-700 border-blue-200',
  contractor_assigned: 'bg-blue-100 text-blue-700 border-blue-200',
  quote_sent: 'bg-blue-100 text-blue-700 border-blue-200',
  contacted: 'bg-blue-100 text-blue-700 border-blue-200',

  lost: 'bg-red-100 text-red-700 border-red-200',
  cancelled: 'bg-red-100 text-red-700 border-red-200',
  rejected: 'bg-red-100 text-red-700 border-red-200',
  overdue: 'bg-red-100 text-red-700 border-red-200',
  expired: 'bg-red-100 text-red-700 border-red-200',
  suspended: 'bg-red-100 text-red-700 border-red-200',

  final_review: 'bg-purple-100 text-purple-700 border-purple-200',
  completed_by_contractor: 'bg-purple-100 text-purple-700 border-purple-200',
  ready_for_review: 'bg-purple-100 text-purple-700 border-purple-200',
  corrections_required: 'bg-orange-100 text-orange-700 border-orange-200',
  invoice_sent: 'bg-blue-100 text-blue-700 border-blue-200',
  awaiting_payment: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  ready_for_payout: 'bg-teal-100 text-teal-700 border-teal-200',
  not_ready: 'bg-slate-100 text-slate-600 border-slate-200',
  hold_issue: 'bg-red-100 text-red-700 border-red-200',
};

export default function StatusBadge({ status }) {
  const normalized = (status || 'unknown').toLowerCase().replace(/\s+/g, '_');
  const colorClass = colorMap[normalized] || 'bg-slate-100 text-slate-600 border-slate-200';
  const pulse = normalized === 'in_progress';

  return (
    <span className={`inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full border ${colorClass}`}>
      {pulse && <span className="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse" />}
      {getStatusLabel(status)}
    </span>
  );
}
