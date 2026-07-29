/** Shared helpers for PM-04 customer chat vs internal notes. */

export function looksLikeInternalContent(text) {
  const t = (text || '').toLowerCase();
  if (!t.trim()) return false;

  const hasMoney = /\$\s?\d|\d+\s?(dollars|cad)|price\s*[:=]|\d{2,}\.\d{2}/i.test(t);
  const internalTerms = [
    'margin',
    'commission',
    'contractor price',
    'split',
    'pm share',
    'company share',
    'markup',
    'our cost',
    'internal',
    'do not tell',
    'don\'t tell',
    'dont tell',
    'contractor pay',
    'payout',
  ];
  const hasTerm = internalTerms.some((term) => t.includes(term));

  return hasMoney && hasTerm;
}

export function channelLabel(visibility) {
  return visibility === 'internal' ? 'internal note' : 'customer message';
}
