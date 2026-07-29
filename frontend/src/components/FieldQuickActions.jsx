import { Phone, MessageSquare, Navigation } from 'lucide-react';

/**
 * PM-12 / CT-06 field quick actions — one-tap call / text / directions.
 * Renders nothing when no usable contact/address is provided.
 */
export default function FieldQuickActions({ phone, address, className = '' }) {
  const tel = phone ? String(phone).replace(/[^\d+]/g, '') : '';
  const mapsQuery = address ? encodeURIComponent(address) : '';

  if (!tel && !mapsQuery) return null;

  const btn =
    'inline-flex items-center justify-center gap-1.5 min-h-[44px] px-3 py-2 rounded-lg text-sm font-medium border transition-colors';

  return (
    <div className={`flex flex-wrap gap-2 ${className}`}>
      {tel && (
        <a href={`tel:${tel}`} className={`${btn} bg-green-50 border-green-200 text-green-800 hover:bg-green-100`}>
          <Phone className="w-4 h-4" /> Call
        </a>
      )}
      {tel && (
        <a href={`sms:${tel}`} className={`${btn} bg-sky-50 border-sky-200 text-sky-800 hover:bg-sky-100`}>
          <MessageSquare className="w-4 h-4" /> Text
        </a>
      )}
      {mapsQuery && (
        <a
          href={`https://maps.google.com/?q=${mapsQuery}`}
          target="_blank"
          rel="noopener noreferrer"
          className={`${btn} bg-indigo-50 border-indigo-200 text-indigo-800 hover:bg-indigo-100`}
        >
          <Navigation className="w-4 h-4" /> Directions
        </a>
      )}
    </div>
  );
}
