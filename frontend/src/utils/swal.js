import Swal from 'sweetalert2';

const defaults = {
  confirmButtonColor: '#2563eb',
  cancelButtonColor: '#64748b',
  customClass: {
    confirmButton: 'rounded-lg px-4 py-2 text-sm font-medium',
    cancelButton: 'rounded-lg px-4 py-2 text-sm font-medium',
  },
};

/** Show confirmation dialog — returns true if user confirmed */
export async function confirmAction({
  title = 'Are you sure?',
  text = '',
  confirmText = 'Yes, proceed',
  cancelText = 'Cancel',
  icon = 'question',
} = {}) {
  const result = await Swal.fire({
    ...defaults,
    title,
    text,
    icon,
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
  });
  return result.isConfirmed;
}

/** Destructive / reject actions */
export async function confirmDanger({ title, text, confirmText = 'Yes, confirm' } = {}) {
  return confirmAction({
    title,
    text,
    confirmText,
    icon: 'warning',
  });
}

export function showSuccess(message, title = 'Success!') {
  return Swal.fire({
    ...defaults,
    icon: 'success',
    title,
    text: message,
    timer: 2500,
    showConfirmButton: false,
  });
}

export function showError(message, title = 'Something went wrong') {
  return Swal.fire({
    ...defaults,
    icon: 'error',
    title,
    text: message,
    confirmButtonText: 'OK',
  });
}

/** Run async action after confirmation; show success/error automatically */
export async function withConfirm(
  { title, text, confirmText, icon, successMessage, errorMessage },
  action
) {
  const ok = await confirmAction({ title, text, confirmText, icon });
  if (!ok) return false;
  try {
    const result = await action();
    if (successMessage) await showSuccess(successMessage);
    return result ?? true;
  } catch (err) {
    await showError(err.response?.data?.message || errorMessage || 'Action failed. Please try again.');
    return false;
  }
}
