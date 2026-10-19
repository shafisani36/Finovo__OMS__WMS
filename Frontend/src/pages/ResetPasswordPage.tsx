import React, { useState, useEffect } from 'react';
import { useLocation, useNavigate, Link } from 'react-router-dom';
import { ArrowRight, CheckCircle2, LockKeyhole } from 'lucide-react';
import { AuthLayout } from '../components/layout/AuthLayout';
import { PasswordInput } from '../components/common/PasswordInput';
import { PasswordStrength } from '../components/common/PasswordStrength';
import { Button } from '../components/common/Button';
import { Alert } from '../components/common/Alert';
import { authService } from '../services/authService';
import { ApiError } from '../services/apiClient';

interface LocationState {
  reset_token?: string;
}

export const ResetPasswordPage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();

  const state = (location.state as LocationState) || {};
  const [resetToken] = useState<string>(() => {
    if (state.reset_token) return state.reset_token;
    const searchParams = new URLSearchParams(window.location.search);
    return searchParams.get('token') || '';
  });

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isSuccess, setIsSuccess] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<{ password?: string; password_confirmation?: string }>({});

  useEffect(() => {
    if (!resetToken) {
      navigate('/forgot-password', { replace: true });
    }
  }, [resetToken, navigate]);

  const validate = (): boolean => {
    const errors: { password?: string; password_confirmation?: string } = {};

    if (!password) {
      errors.password = 'A new password is required.';
    } else if (password.length < 12) {
      errors.password = 'Password must be at least 12 characters.';
    }

    if (!passwordConfirmation) {
      errors.password_confirmation = 'Please confirm your password.';
    } else if (password !== passwordConfirmation) {
      errors.password_confirmation = 'Password confirmation does not match.';
    }

    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    if (!validate()) return;

    setIsLoading(true);

    try {
      await authService.resetPassword(resetToken, password, passwordConfirmation);
      setIsSuccess(true);
    } catch (err) {
      if (err instanceof ApiError) {
        setErrorMessage(err.message);
        if (err.errors) {
          setFieldErrors({
            password: err.errors.password?.[0],
            password_confirmation: err.errors.password_confirmation?.[0],
          });
        }
      } else {
        setErrorMessage('Failed to reset password. Please check your network.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthLayout>
      <div className="auth-card">
        {isSuccess ? (
          <div style={{ textAlign: 'center', padding: '16px 0' }}>
            <div
              style={{
                width: '64px',
                height: '64px',
                borderRadius: '50%',
                background: '#f0fdf4',
                color: '#16a34a',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                margin: '0 auto 20px',
              }}
            >
              <CheckCircle2 size={36} />
            </div>

            <h2 className="card-title">Password Reset Complete</h2>
            <p className="card-subtitle" style={{ marginBottom: '24px' }}>
              Your password has been successfully updated. All other active sessions have been revoked for your security.
            </p>

            <Button
              type="button"
              variant="primary"
              onClick={() => navigate('/login', { replace: true })}
              rightIcon={<ArrowRight size={16} />}
            >
              Proceed to log in
            </Button>
          </div>
        ) : (
          <>
            <div className="card-header">
              <div style={{ display: 'inline-flex', padding: '8px', borderRadius: '12px', background: '#eff6ff', color: '#2563eb', marginBottom: '12px' }}>
                <LockKeyhole size={28} />
              </div>
              <h2 className="card-title">Set new password</h2>
              <p className="card-subtitle">
                Please enter a secure password with a minimum of 12 characters.
              </p>
            </div>

            {errorMessage && <Alert type="error" message={errorMessage} />}

            <form onSubmit={handleSubmit} className="auth-form" noValidate>
              <div>
                <PasswordInput
                  label="New password"
                  value={password}
                  onChange={(e) => {
                    setPassword(e.target.value);
                    if (fieldErrors.password) setFieldErrors((prev) => ({ ...prev, password: undefined }));
                  }}
                  placeholder="Enter at least 12 characters"
                  error={fieldErrors.password}
                  autoComplete="new-password"
                  disabled={isLoading}
                  required
                />
                <PasswordStrength password={password} />
              </div>

              <PasswordInput
                label="Confirm new password"
                value={passwordConfirmation}
                onChange={(e) => {
                  setPasswordConfirmation(e.target.value);
                  if (fieldErrors.password_confirmation) {
                    setFieldErrors((prev) => ({ ...prev, password_confirmation: undefined }));
                  }
                }}
                placeholder="Re-enter your new password"
                error={fieldErrors.password_confirmation}
                autoComplete="new-password"
                disabled={isLoading}
                required
              />

              <Button
                type="submit"
                variant="primary"
                isLoading={isLoading}
                rightIcon={<ArrowRight size={16} aria-hidden="true" />}
              >
                Reset password
              </Button>

              <div style={{ textAlign: 'center', marginTop: '12px' }}>
                <Link to="/login" className="link-btn">
                  Cancel and return to log in
                </Link>
              </div>
            </form>
          </>
        )}
      </div>
    </AuthLayout>
  );
};
