import React, { createContext, useEffect, useState, useCallback } from 'react';
import type { User } from '../types/auth';
import { authService } from '../services/authService';
import { SESSION_EXPIRED_EVENT, TOKEN_STORAGE_KEY, getAuthToken, setAuthToken } from '../services/apiClient';

export const STOCK_ALERTS_ON_LOGIN_KEY = 'finovo_show_stock_alerts';

interface AuthContextType {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  setAuthSession: (user: User, token: string, remember?: boolean) => void;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

const USER_STORAGE_KEY = 'finovo_user';

/** Keeps the signed-in user for this tab, and across visits when "Keep me signed in" was chosen. */
const saveUser = (user: User, remember: boolean) => {
  try {
    const serialized = JSON.stringify(user);
    sessionStorage.setItem(USER_STORAGE_KEY, serialized);
    if (remember) {
      localStorage.setItem(USER_STORAGE_KEY, serialized);
    }
  } catch {
    // Storage unavailable (private mode); the session lasts for this tab only.
  }
};

const forgetUser = () => {
  try {
    localStorage.removeItem(USER_STORAGE_KEY);
    sessionStorage.removeItem(USER_STORAGE_KEY);
  } catch {
    // Storage unavailable
  }
};

const remembered = (): boolean => {
  try {
    return localStorage.getItem(TOKEN_STORAGE_KEY) !== null;
  } catch {
    return false;
  }
};

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  // Pre-load user from cached storage to prevent unauthenticated flash on page reloads
  const [user, setUser] = useState<User | null>(() => {
    try {
      const cached = localStorage.getItem(USER_STORAGE_KEY) || sessionStorage.getItem(USER_STORAGE_KEY);
      return cached ? JSON.parse(cached) : null;
    } catch {
      return null;
    }
  });
  const [token, setTokenState] = useState<string | null>(getAuthToken());
  const [isLoading, setIsLoading] = useState<boolean>(!user && !!getAuthToken());

  /** Signs out on this device only (the server token is already gone or expired). */
  const clearSession = useCallback(() => {
    setUser(null);
    setTokenState(null);
    setAuthToken(null);
    forgetUser();
  }, []);

  const setAuthSession = useCallback((newUser: User, newToken: string, remember = true) => {
    setUser(newUser);
    setTokenState(newToken);
    setAuthToken(newToken, remember);
    saveUser(newUser, remember);
    try {
      // The stock alerts open by themselves once after every login (see StockAlertsBell).
      sessionStorage.setItem(STOCK_ALERTS_ON_LOGIN_KEY, '1');
    } catch {
      // Storage unavailable
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
    } catch {
      // Ignored during client cleanup
    } finally {
      clearSession();
    }
  }, [clearSession]);

  const refreshUser = useCallback(async () => {
    try {
      const response = await authService.getMe();
      if (response.data?.user) {
        setUser(response.data.user);
        saveUser(response.data.user, remembered());
      }
    } catch {
      clearSession();
    }
  }, [clearSession]);

  // A request answered "Unauthenticated" (token expired or revoked): back to the sign-in screen.
  useEffect(() => {
    window.addEventListener(SESSION_EXPIRED_EVENT, clearSession);
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, clearSession);
  }, [clearSession]);

  useEffect(() => {
    const initAuth = async () => {
      if (getAuthToken()) {
        await refreshUser();
      } else {
        setUser(null);
      }
      setIsLoading(false);
    };

    initAuth();
  }, [refreshUser]);

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        isAuthenticated: !!user,
        isLoading,
        setAuthSession,
        logout,
        refreshUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};
export { AuthContext, type AuthContextType };
