/**
 * Parse date strings without UTC timezone shift for calendar dates.
 * Laravel `date` casts historically serialize as "YYYY-MM-DDT00:00:00.000000Z".
 * `new Date("YYYY-MM-DD")` and UTC-midnight ISO strings parse as UTC and can
 * display as the previous day in North American timezones (e.g. Pacific).
 */
export function parseLocalDate(dateString) {
  if (!dateString) return null;
  const s = String(dateString).trim();
  const match = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) {
    const d = new Date(s);
    return Number.isNaN(d.getTime()) ? null : d;
  }

  // Date-only, or midnight UTC/local from a date cast — treat as calendar day.
  const isCalendarDate =
    s.length === 10
    || /^(\d{4}-\d{2}-\d{2})T00:00:00/.test(s);

  if (isCalendarDate) {
    const year = Number(match[1]);
    const month = Number(match[2]) - 1;
    const day = Number(match[3]);
    return new Date(year, month, day);
  }

  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? null : d;
}

export function formatDate(dateString, options = {}) {
  const date = parseLocalDate(dateString);
  if (!date) return '—';

  const defaultOptions = {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  };

  try {
    return date.toLocaleDateString('en-CA', {
      ...defaultOptions,
      ...options,
    });
  } catch {
    return '—';
  }
}

export function formatDateLong(dateString) {
  const date = parseLocalDate(dateString);
  if (!date) return null;

  try {
    return date.toLocaleDateString('en-CA', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  } catch {
    return null;
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

/** Value for <input type="date"> from API date / ISO strings. */
export function toDateInputValue(dateString) {
  if (!dateString) return '';
  const match = String(dateString).trim().match(/^(\d{4}-\d{2}-\d{2})/);
  return match ? match[1] : '';
}
