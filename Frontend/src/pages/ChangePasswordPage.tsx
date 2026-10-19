import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ShieldAlert } from 'lucide-react';
import { PasswordInput } from '../components/common/PasswordInput';
import { PasswordStrength } from '../components/common/PasswordStrength';
import { Button } from '../components/common/Button';
import { Alert } from '../components/common/Alert';
import { authService } from '../services/authService';
import { ApiError } from '../services/apiClient';

export const ChangePasswordPage: React.FC = () => {

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState('');

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{
    current_password?: string;
    new_password?: string;
    new_password_confirmation?: string;
  }>({});

  const validate = (): boolean => {
    const errors: typeof fieldErrors = {};

    if (!currentPassword) {
      errors.current_password = 'Your current password is required.';
    }

    if (!newPassword) {
      errors.new_password = 'A new password is required.';
    } else if (newPassword.length < 12) {
      errors.new_password = 'The new password must be at least 12 characters.';
    } else if (newPassword === currentPassword) {
      errors.new_password = 'The new password must be different from your current password.';
    }

    if (!newPasswordConfirmation) {
      errors.new_password_confirmation = 'Please confirm your new password.';
    } else if (newPassword !== newPasswordConfirmation) {
      errors.new_password_confirmation = 'Password confirmation does not match.';
    }

    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setSuccessMessage(null);

    if (!validate()) return;

    setIsLoading(true);

    try {
      const response = await authService.changePassword(
        currentPassword,
        newPassword,
        newPasswordConfirmation
      );

      setSuccessMessage(
        response.message || 'Password changed successfully! All other active sessions have been revoked.'
      );
      setCurrentPassword('');
      setNewPassword('');
      setNewPasswordConfirmation('');
    } catch (err) {
      if (err instanceof ApiError) {
        setErrorMessage(err.message);
        if (err.errors) {
          setFieldErrors({
            current_password: err.errors.current_password?.[0],
            new_password: err.errors.new_password?.[0],
            new_password_confirmation: err.errors.new_password_confirmation?.[0],
          });
        }
      } else {
        setErrorMessage('Failed to change password. Please check your network.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div style={{ maxWidth: '580px', margin: '40px auto', padding: '0 16px' }}>
      <div style={{ marginBottom: '20px' }}>
        <Link
          to="/dashboard"
          className="link-btn"
          style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
        >
          <ArrowLeft size={16} /> Back to dashboard
        </Link>
      </div>

      <div
        className="auth-card"
        style={{
          maxWidth: '100%',
          boxShadow: '0 10px 30px rgba(15, 23, 42, 0.05)',
        }}
      >
        <div className="card-header">
          <div
            style={{
              display: 'inline-flex',
              padding: '8px',
              borderRadius: '12px',
              background: '#eff6ff',
              color: '#2563eb',
              marginBottom: '12px',
            }}
          >
            <ShieldAlert size={28} />
          </div>
          <h2 className="card-title">Change Password</h2>
          <p className="card-subtitle">
            Update your account password. For your security, changing your password revokes all other active sessions and access tokens.
          </p>
        </div>

        {errorMessage && <Alert type="error" message={errorMessage} />}
        {successMessage && <Alert type="success" message={successMessage} />}

        <form onSubmit={handleSubmit} className="auth-form" noValidate>
          <PasswordInput
            label="Current password"
            value={currentPassword}
            onChange={(e) => {
              setCurrentPassword(e.target.value);
              if (fieldErrors.current_password) {
                setFieldErrors((prev) => ({ ...prev, current_password: undefined }));
              }
            }}
            placeholder="Enter your current password"
            error={fieldErrors.current_password}
            autoComplete="current-password"
            disabled={isLoading}
            required
          />

          <div>
            <PasswordInput
              label="New password"
              value={newPassword}
              onChange={(e) => {
                setNewPassword(e.target.value);
                if (fieldErrors.new_password) {
                  setFieldErrors((prev) => ({ ...prev, new_password: undefined }));
                }
              }}
              placeholder="Enter at least 12 characters"
              error={fieldErrors.new_password}
              autoComplete="new-password"
              disabled={isLoading}
              required
            />
            <PasswordStrength password={newPassword} />
          </div>

          <PasswordInput
            label="Confirm new password"
            value={newPasswordConfirmation}
            onChange={(e) => {
              setNewPasswordConfirmation(e.target.value);
              if (fieldErrors.new_password_confirmation) {
                setFieldErrors((prev) => ({ ...prev, new_password_confirmation: undefined }));
              }
            }}
            placeholder="Re-enter your new password"
            error={fieldErrors.new_password_confirmation}
            autoComplete="new-password"
            disabled={isLoading}
            required
          />

          <Button type="submit" variant="primary" isLoading={isLoading}>
            Update Password
          </Button>
        </form>
      </div>
    </div>
  );
};
