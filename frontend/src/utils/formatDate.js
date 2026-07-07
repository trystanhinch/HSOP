export function formatDate(dateString, options = {}) {
  if (!dateString) return '—';
  const defaultOptions = {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  };
  try {
    return new Date(dateString).toLocaleDateString('en-CA', {
      ...defaultOptions,
      ...options,
    });
  } catch {
    return '—';
  }
}

export function formatDateTime(dateString) {
  if (!dateString) return '—';
  try {
    return new Date(dateString).toLocaleString('en-CA', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return '—';
  }
}

export function formatTime(timeString) {
  if (!timeString) return '—';
  try {
    const [hours, minutes] = timeString.split(':');
    const date = new Date();
    date.setHours(parseInt(hours, 10), parseInt(minutes, 10));
    return date.toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit' });
  } catch {
    return timeString;
  }
}
