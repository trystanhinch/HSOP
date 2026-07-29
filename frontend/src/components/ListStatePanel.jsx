/**
 * Shared empty / error / permission / blocked states (PM-10 / CT-11).
 */
export default function ListStatePanel({
  state = 'empty',
  title,
  body,
  actionLabel,
  onAction,
  href,
}) {
  const tone =
    state === 'error' || state === 'permission' || state === 'blocked'
      ? 'border-amber-200 bg-amber-50 text-amber-900'
      : 'border-slate-200 bg-white text-slate-700';

  return (
    <div className={`rounded-xl border px-4 py-8 text-center ${tone}`}>
      <p className="text-sm font-medium">{title}</p>
      {body && <p className="text-sm mt-1 opacity-80 max-w-md mx-auto">{body}</p>}
      {(onAction || href) && (
        <div className="mt-3">
          {href ? (
            <a href={href} className="text-sm font-medium text-blue-600 hover:underline">{actionLabel || 'Continue'}</a>
          ) : (
            <button type="button" onClick={onAction} className="text-sm font-medium text-blue-600 hover:underline">
              {actionLabel || 'Retry'}
            </button>
          )}
        </div>
      )}
    </div>
  );
}
