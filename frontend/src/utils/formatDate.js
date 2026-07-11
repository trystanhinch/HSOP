/**
 * Calendar-date helpers that never shift a day in Pacific (or any) timezone.
 *
 * Laravel historically serialized `date` casts as "YYYY-MM-DDT00:00:00.000000Z".
 * `new Date("YYYY-MM-DD")` parses as UTC midnight, which becomes the previous
 * calendar day in Americas timezones. Always format from Y-M-D parts directly.
 */

const SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const LONG_MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** Extract YYYY-MM-DD from date-only or ISO midnight strings. */
export function calendarDateParts(dateString) {
  if (!dateString) return null;
  const s = String(dateString).trim();
  const match = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return null;

  // Date-only, or midnight from a date cast — treat as calendar day (not instant).
  const isCalendarDate =
    s.length === 10
    || /^(\d{4}-\d{2}-\d{2})T00:00:00/.test(s);

  if (!isCalendarDate) return null;

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  if (!year || month < 1 || month > 12 || day < 1 || day > 31) return null;

  return { year, month, day };
}

/**
 * Parse date strings without UTC timezone shift for calendar dates.
 */
export function parseLocalDate(dateString) {
  if (!dateString) return null;

  const parts = calendarDateParts(dateString);
  if (parts) {
    return new Date(parts.year, parts.month - 1, parts.day);
  }

  const d = new Date(String(dateString).trim());
  return Number.isNaN(d.getTime()) ? null : d;
}

/** Format a calendar date as "Jul 14, 2026" — no Date/timezone involved. */
export function formatDate(dateString, options = {}) {
  const parts = calendarDateParts(dateString);
  if (parts) {
    // Ignore options for calendar dates — keep one stable display format.
    void options;
    return `${SHORT_MONTHS[parts.month - 1]} ${parts.day}, ${parts.year}`;
  }

  const date = parseLocalDate(dateString);
  if (!date) return '—';

  try {
    return date.toLocaleDateString('en-CA', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      ...options,
    });
  } catch {
    return '—';
  }
}

/** Format as "Tuesday, July 14, 2026" — no Date/timezone involved for calendar dates. */
export function formatDateLong(dateString) {
  const parts = calendarDateParts(dateString);
  if (parts) {
    // Weekday from local noon to avoid DST edge cases.
    const weekday = WEEKDAYS[new Date(parts.year, parts.month - 1, parts.day, 12).getDay()];
    return `${weekday}, ${LONG_MONTHS[parts.month - 1]} ${parts.day}, ${parts.year}`;
  }

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
    const [hours, minutes] = String(timeString).split(':');
    const date = new Date();
    date.setHours(parseInt(hours, 10), parseInt(minutes, 10), 0, 0);
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
