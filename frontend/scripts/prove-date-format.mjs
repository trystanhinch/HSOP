/**
 * Proof: calendar-date formatting must not shift in Pacific time.
 * Run: node frontend/scripts/prove-date-format.mjs
 */
import { formatDate, formatDateLong, parseLocalDate, calendarDateParts } from '../src/utils/formatDate.js';

const samples = [
  ['2026-07-14T00:00:00.000000Z', 'Jul 14, 2026'], // Cloverley (Laravel date cast)
  ['2026-07-14', 'Jul 14, 2026'],                   // DateOnly / input type=date
  ['2026-07-13T00:00:00.000000Z', 'Jul 13, 2026'], // Horne St
  ['2026-08-05', 'Aug 5, 2026'],                    // fresh proof date
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

let failed = false;
for (const [input, expected] of samples) {
  const got = formatDate(input);
  const bugged = buggedFormat(input);
  console.log('input:', input);
  console.log('  bugged new Date():', bugged);
  console.log('  formatDate():     ', got);
  console.log('  formatDateLong(): ', formatDateLong(input));
  console.log('  parts:            ', calendarDateParts(input));
  if (got !== expected) {
    console.error(`  FAIL expected ${expected}`);
    failed = true;
  } else {
    console.log('  PASS');
  }
  console.log('');
}

// Cloverley regression: dashboard must not show Jul 13 when API has Jul 14
if (formatDate('2026-07-14') !== 'Jul 14, 2026') {
  console.error('FAIL: Cloverley regression');
  failed = true;
}

if (failed) process.exit(1);
console.log('ALL PASS');
