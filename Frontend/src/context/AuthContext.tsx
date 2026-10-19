import React, { createContext, useEffect, useState, useCallback } from 'react';
import type { User } from '../types/auth';
import { authService } from '../services/authService';
import { getAuthToken, setAuthToken } from '../services/apiClient';

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

  const setAuthSession = useCallback((newUser: User, newToken: string, remember = true) => {
    setUser(newUser);
    setTokenState(newToken);
    setAuthToken(newToken, remember);
    try {
      const serialized = JSON.stringify(newUser);
      if (remember) {
        localStorage.setItem(USER_STORAGE_KEY, serialized);
      }
      sessionStorage.setItem(USER_STORAGE_KEY, serialized);
    } catch {
      // Ignore
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
    } catch {
      // Ignored during client cleanup
    } finally {
      setUser(null);
      setTokenState(null);
      setAuthToken(null);
      try {
        localStorage.removeItem(USER_STORAGE_KEY);
        sessionStorage.removeItem(USER_STORAGE_KEY);
      } catch {
        // Ignore
      }
    }
  }, []);

  const refreshUser = useCallback(async () => {
    try {
      const response = await authService.getMe();
      if (response.data?.user) {
        setUser(response.data.user);
        try {
          const serialized = JSON.stringify(response.data.user);
          sessionStorage.setItem(USER_STORAGE_KEY, serialized);
          if (localStorage.getItem('finovo_auth_token')) {
            localStorage.setItem(USER_STORAGE_KEY, serialized);
          }
        } catch {
          // Ignore
        }
      }
    } catch {
      setUser(null);
      setTokenState(null);
      setAuthToken(null);
      try {
        localStorage.removeItem(USER_STORAGE_KEY);
        sessionStorage.removeItem(USER_STORAGE_KEY);
      } catch {
        // Ignore
      }
    }
  }, []);

  useEffect(() => {
    const initAuth = async () => {
      const currentToken = getAuthToken();
      if (currentToken) {
        try {
          const response = await authService.getMe();
          if (response.data?.user) {
            setUser(response.data.user);
            try {
              const serialized = JSON.stringify(response.data.user);
              sessionStorage.setItem(USER_STORAGE_KEY, serialized);
              if (localStorage.getItem('finovo_auth_token')) {
                localStorage.setItem(USER_STORAGE_KEY, serialized);
              }
            } catch {
              // Ignore
            }
          }
        } catch {
          // Token is invalid/expired on backend
          setUser(null);
          setTokenState(null);
          setAuthToken(null);
          try {
            localStorage.removeItem(USER_STORAGE_KEY);
            sessionStorage.removeItem(USER_STORAGE_KEY);
          } catch {
            // Ignore
          }
        }
      } else {
        setUser(null);
      }
      setIsLoading(false);
    };

    initAuth();
  }, []);

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
