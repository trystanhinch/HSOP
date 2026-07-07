export const statusLabels = {
  new: 'New',
  contacted: 'Contacted',
  site_visit_scheduled: 'Site Visit Scheduled',
  site_visit_completed: 'Site Visit Completed',
  site_visit_cancelled: 'Site Visit Cancelled',
  quote_needed: 'Quote Needed',
  converted: 'Converted',
  lost: 'Lost',
  lead_assigned: 'Lead Assigned',

  new_job: 'New Job',
  contractor_assigned: 'Contractor Assigned',
  quote_sent: 'Quote Sent',
  quote_approved: 'Quote Approved',
  scheduled: 'Scheduled',
  in_progress: 'In Progress',
  waiting_on_customer: 'Waiting on Customer',
  completed_by_contractor: 'Completed by Contractor',
  final_review: 'Final Review',
  completed: 'Completed',
  cancelled: 'Cancelled',
  ready_for_review: 'Ready for Review',
  corrections_required: 'Corrections Required',
  invoiced: 'Invoiced',

  draft: 'Draft',
  sent: 'Sent',
  viewed: 'Viewed',
  approved: 'Approved',
  rejected: 'Rejected',
  revised: 'Revised',
  invoice_sent: 'Invoice Sent',
  awaiting_payment: 'Awaiting Payment',

  unpaid: 'Unpaid',
  paid: 'Paid',
  partially_paid: 'Partially Paid',
  overdue: 'Overdue',

  pending: 'Pending',
  ready_for_payout: 'Ready for Payout',
  not_ready: 'Not Ready',
  hold_issue: 'On Hold',
  pending_review: 'Pending Review',
  expired: 'Expired',
  suspended: 'Suspended',
  active: 'Active',
  submitted: 'Submitted',
  not_uploaded: 'Not Uploaded',
};

export function getStatusLabel(status) {
  if (!status) return 'Unknown';
  const key = String(status).toLowerCase().replace(/\s+/g, '_');
  return statusLabels[key] || key
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}
