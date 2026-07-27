import { useAuth } from '../context/AuthContext';

/**
 * Calm PRODUCTION label; loud STAGING / LOCAL banners so environments are never confused.
 */
export default function EnvironmentBadge({ className = '' }) {
  const { user } = useAuth();
  const raw = (user?.app_env || import.meta.env.MODE || 'production').toLowerCase();

  let label = 'PRODUCTION';
  let tone = 'production';
  if (raw === 'local' || raw === 'development' || raw === 'dev') {
    label = 'LOCAL';
    tone = 'local';
  } else if (raw === 'staging' || raw === 'stage' || raw === 'testing' || raw === 'test') {
    label = 'STAGING';
    tone = 'staging';
  } else if (raw !== 'production' && raw !== 'prod') {
    label = raw.toUpperCase();
    tone = 'other';
  }

  const styles = {
    production: 'bg-slate-700/40 text-slate-300 border border-slate-600/60',
    staging: 'bg-amber-400 text-amber-950 border-2 border-amber-600 shadow-sm animate-pulse',
    local: 'bg-lime-300 text-lime-950 border-2 border-lime-600 shadow-sm',
    other: 'bg-orange-300 text-orange-950 border-2 border-orange-500',
  };

  return (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase ${styles[tone]} ${className}`}
      title={`Application environment: ${raw}`}
      data-testid="environment-badge"
      data-env={label.toLowerCase()}
    >
      {label}
    </span>
  );
}
