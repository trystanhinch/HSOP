import { useNavigate } from 'react-router-dom';
import { Bell, LogOut } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { confirmAction } from '../utils/swal';

export default function Header({ title }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  const handleLogout = async () => {
    const ok = await confirmAction({
      title: 'Log out?',
      text: 'You will need to sign in again to continue.',
      confirmText: 'Yes, log out',
      icon: 'question',
    });
    if (!ok) return;
    logout();
    navigate('/login');
  };

  return (
    <div className="flex items-center justify-between flex-1 min-w-0">
      <h1 className="text-lg font-semibold text-slate-900 truncate">{title}</h1>
      <div className="flex items-center gap-3 flex-shrink-0">
        <button type="button" className="hidden sm:block p-1.5 rounded-lg hover:bg-slate-100 text-slate-500">
          <Bell className="w-5 h-5" />
        </button>
        <span className="hidden sm:inline text-sm text-slate-500 truncate max-w-[120px]">{user?.name}</span>
        <button
          type="button"
          onClick={handleLogout}
          className="flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 font-medium px-2 py-1 rounded-lg hover:bg-slate-100"
        >
          <LogOut className="w-4 h-4" />
          <span className="hidden sm:inline">Logout</span>
        </button>
      </div>
    </div>
  );
}
