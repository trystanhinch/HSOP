/**
 * Proof script: calendar-date formatting must not shift in Pacific time.
 * Run: node frontend/scripts/prove-date-format.mjs
 */
import { formatDate, formatDateLong, parseLocalDate } from '../src/utils/formatDate.js';

const samples = [
  '2026-07-13T00:00:00.000000Z', // Laravel default date cast (production Horne St)
  '2026-07-13',                   // DateOnly cast / input type=date
  '2026-08-05T00:00:00.000000Z', // fresh proof date
];

function buggedFormat(dateString) {
  return new Date(dateString).toLocaleDateString('en-CA', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

console.log('process TZ hint:', process.env.TZ || '(system default)');
console.log('offset minutes:', new Date().getTimezoneOffset());
console.log('');

for (const s of samples) {
  const parsed = parseLocalDate(s);
  console.log('input:', s);
  console.log('  bugged new Date():', buggedFormat(s));
  console.log('  formatDate():     ', formatDate(s));
  console.log('  formatDateLong(): ', formatDateLong(s));
  console.log('  local Y-M-D:      ', parsed
    ? `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}-${String(parsed.getDate()).padStart(2, '0')}`
    : null);
  console.log('');
}

const horneApiValue = '2026-07-13T00:00:00.000000Z';
const fixed = formatDate(horneApiValue);
if (fixed !== 'Jul 13, 2026') {
  console.error('FAIL: Horne St expected Jul 13, 2026, got', fixed);
  process.exit(1);
}
console.log('PASS: Horne St API value formats as Jul 13, 2026');
