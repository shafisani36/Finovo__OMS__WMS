import React, { createContext, useEffect, useState, useCallback } from 'react';
import type { User } from '../types/auth';
import { authService } from '../services/authService';
import { getAuthToken, setAuthToken } from '../services/apiClient';

interface AuthContextType {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  setAuthSession: (user: User, token: string) => void;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [token, setTokenState] = useState<string | null>(getAuthToken());
  const [isLoading, setIsLoading] = useState<boolean>(true);

  const setAuthSession = useCallback((newUser: User, newToken: string) => {
    setUser(newUser);
    setTokenState(newToken);
    setAuthToken(newToken);
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
    }
  }, []);

  const refreshUser = useCallback(async () => {
    try {
      const response = await authService.getMe();
      if (response.data?.user) {
        setUser(response.data.user);
      }
    } catch {
      setUser(null);
      setTokenState(null);
      setAuthToken(null);
    }
  }, []);

  useEffect(() => {
    const initAuth = async () => {
      const hasActiveSession = sessionStorage.getItem('finovo_auth_active');
      if (hasActiveSession) {
        try {
          const response = await authService.getMe();
          if (response.data?.user) {
            setUser(response.data.user);
          }
        } catch {
          sessionStorage.removeItem('finovo_auth_active');
          setUser(null);
        }
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
