import { createContext, useContext, useState } from 'react';
import { getRoleDashboard } from '../utils/getRoleDashboard';

const AuthContext = createContext(null);

function readStoredUser() {
  try {
    const stored = localStorage.getItem('user');
    if (!stored || stored === 'undefined' || stored === 'null') {
      return null;
    }
    const parsed = JSON.parse(stored);
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch {
    localStorage.removeItem('user');
    localStorage.removeItem('token');
    return null;
  }
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => readStoredUser());

  const login = (userData, token) => {
    if (!userData || !token) return;
    localStorage.setItem('token', token);
    localStorage.setItem('user', JSON.stringify(userData));
    setUser(userData);
  };

  const logout = () => {
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    setUser(null);
  };

  return (
    <AuthContext.Provider value={{ user, login, logout, getRoleDashboard }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
