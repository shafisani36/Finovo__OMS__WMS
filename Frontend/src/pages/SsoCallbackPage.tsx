import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import { authService } from '../services/authService';
import { setAuthToken } from '../services/apiClient';
import { useAuth } from '../hooks/useAuth';

/**
 * Back from Microsoft or the company's identity provider: the server put the sign-in token in the URL fragment.
 * It is taken out of the address bar at once, checked with the server, and the user goes on to the app.
 */
export const SsoCallbackPage: React.FC = () => {
  const navigate = useNavigate();
  const { setAuthSession } = useAuth();

  useEffect(() => {
    const token = new URLSearchParams(window.location.hash.slice(1)).get('token');
    window.history.replaceState(null, '', window.location.pathname);

    if (!token) {
      navigate('/login?sso_error=' + encodeURIComponent('Sign-in did not finish. Please try again.'), { replace: true });
      return;
    }

    setAuthToken(token, true);
    authService
      .getMe()
      .then((res) => {
        setAuthSession(res.data.user, token, true);
        navigate('/dashboard', { replace: true });
      })
      .catch(() => {
        setAuthToken(null);
        navigate('/login?sso_error=' + encodeURIComponent('Sign-in could not be confirmed. Please try again.'), { replace: true });
      });
  }, [navigate, setAuthSession]);

  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 12, color: '#1e56ff' }}>
      <Loader2 className="animate-spin" size={28} />
      <span style={{ color: '#64748b', fontSize: 14 }}>Signing you in...</span>
    </div>
  );
};

export default SsoCallbackPage;
