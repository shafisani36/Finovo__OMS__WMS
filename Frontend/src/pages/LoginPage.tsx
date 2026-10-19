import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { Mail, ArrowRight, Shield } from 'lucide-react';
import { AuthLayout } from '../components/layout/AuthLayout';
import { Input } from '../components/common/Input';
import { PasswordInput } from '../components/common/PasswordInput';
import { Button } from '../components/common/Button';
import { Alert } from '../components/common/Alert';
import { authService } from '../services/authService';
import { ApiError } from '../services/apiClient';

export const LoginPage: React.FC = () => {
  const navigate = useNavigate();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; password?: string }>({});

  const validateForm = (): boolean => {
    const errors: { email?: string; password?: string } = {};

    if (!email.trim()) {
      errors.email = 'Work email is required.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      errors.email = 'Please enter a valid email address.';
    }

    if (!password) {
      errors.password = 'Password is required.';
    }

    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    if (!validateForm()) return;

    setIsLoading(true);

    try {
      const response = await authService.login(email.trim(), password, remember);

      if (response.data.requires_otp) {
        navigate('/verify-otp', {
          state: {
            challenge_id: response.data.challenge_id,
            destination: response.data.destination,
            expires_in_seconds: response.data.expires_in_seconds,
            resend_cooldown_seconds: response.data.resend_cooldown_seconds,
            dev_code: response.data.dev_code,
          },
        });
      }
    } catch (err) {
      if (err instanceof ApiError) {
        setErrorMessage(err.message);
        if (err.errors) {
          setFieldErrors({
            email: err.errors.email?.[0],
            password: err.errors.password?.[0],
          });
        }
      } else {
        setErrorMessage('Unable to log in. Please check your network connection.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthLayout>
      <div className="auth-card">
        <div className="card-header">
          <h2 className="card-title">Welcome back</h2>
          <p className="card-subtitle">
            Log in to access your workspace and continue managing your operations.
          </p>
        </div>

        {errorMessage && <Alert type="error" message={errorMessage} />}

        <form onSubmit={handleSubmit} className="auth-form" noValidate>
          <Input
            label="Work email"
            type="email"
            value={email}
            onChange={(e) => {
              setEmail(e.target.value);
              if (fieldErrors.email) setFieldErrors((prev) => ({ ...prev, email: undefined }));
            }}
            placeholder="you@company.com"
            error={fieldErrors.email}
            leftIcon={<Mail size={16} aria-hidden="true" />}
            autoComplete="username"
            disabled={isLoading}
            required
          />

          <PasswordInput
            label="Password"
            value={password}
            onChange={(e) => {
              setPassword(e.target.value);
              if (fieldErrors.password) setFieldErrors((prev) => ({ ...prev, password: undefined }));
            }}
            placeholder="Enter your password"
            error={fieldErrors.password}
            autoComplete="current-password"
            disabled={isLoading}
            required
          />

          <div className="form-options-row">
            <label className="checkbox-label">
              <input
                type="checkbox"
                className="checkbox-input"
                checked={remember}
                onChange={(e) => setRemember(e.target.checked)}
                disabled={isLoading}
              />
              <span>Keep me signed in</span>
            </label>

            <Link to="/forgot-password" className="link-btn">
              Forgot password?
            </Link>
          </div>

          <Button
            type="submit"
            variant="primary"
            isLoading={isLoading}
            rightIcon={<ArrowRight size={16} aria-hidden="true" />}
          >
            Log in
          </Button>

          <div className="divider-row">
            <div className="divider-line" />
            <span>Or continue with</span>
            <div className="divider-line" />
          </div>

          <div className="sso-group">
            <Button
              type="button"
              variant="outline"
              disabled={isLoading}
              onClick={() => alert('Enterprise Microsoft SSO is configured through your identity provider.')}
              leftIcon={
                <svg width="18" height="18" viewBox="0 0 21 21" fill="none" aria-hidden="true">
                  <path fill="#f25022" d="M1 1h9v9H1z"/>
                  <path fill="#00a4ef" d="M1 11h9v9H1z"/>
                  <path fill="#7fba00" d="M11 1h9v9H11z"/>
                  <path fill="#ffb900" d="M11 11h9v9H11z"/>
                </svg>
              }
            >
              Sign in with Microsoft
            </Button>

            <Button
              type="button"
              variant="outline"
              disabled={isLoading}
              onClick={() => alert('SAML / OIDC Single Sign-On is available for corporate domain users.')}
              leftIcon={<Shield size={16} color="#64748b" aria-hidden="true" />}
            >
              Sign in with SSO
            </Button>
          </div>
        </form>

        <div className="card-footer">
          <span>Need access to Finovo OMS / WMS? </span>
          <span style={{ color: '#2563eb', fontWeight: 600 }}>Contact your administrator</span>
        </div>
      </div>
    </AuthLayout>
  );
};
