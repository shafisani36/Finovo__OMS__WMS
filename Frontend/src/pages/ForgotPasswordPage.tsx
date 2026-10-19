import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { Mail, ArrowRight, ArrowLeft, KeyRound } from 'lucide-react';
import { AuthLayout } from '../components/layout/AuthLayout';
import { Input } from '../components/common/Input';
import { OtpCells } from '../components/common/OtpCells';
import { Button } from '../components/common/Button';
import { Alert } from '../components/common/Alert';
import { authService } from '../services/authService';
import { ApiError } from '../services/apiClient';

export const ForgotPasswordPage: React.FC = () => {
  const navigate = useNavigate();

  const [step, setStep] = useState<'request' | 'verify'>('request');
  const [email, setEmail] = useState('');
  const [challengeId, setChallengeId] = useState<string | null>(null);
  const [destination, setDestination] = useState<string>('');
  const [otpCode, setOtpCode] = useState('');

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; otp?: string }>({});

  const handleRequestReset = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setInfoMessage(null);

    if (!email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setFieldErrors({ email: 'Please enter a valid work email address.' });
      return;
    }

    setIsLoading(true);

    try {
      const response = await authService.forgotPassword(email.trim());

      setInfoMessage(response.message);

      if (response.data?.challenge_id) {
        setChallengeId(response.data.challenge_id);
        setDestination(response.data.destination);
        setStep('verify');
      }
    } catch (err) {
      if (err instanceof ApiError) {
        setErrorMessage(err.message);
      } else {
        setErrorMessage('Unable to process password reset request. Please check your network.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  const handleVerifyResetOtp = async (codeToSubmit?: string) => {
    const finalCode = codeToSubmit || otpCode;
    setErrorMessage(null);

    if (!challengeId) return;

    if (finalCode.length !== 6) {
      setErrorMessage('Please enter the 6-digit code sent to your email.');
      return;
    }

    setIsLoading(true);

    try {
      const response = await authService.verifyResetOtp(challengeId, finalCode);

      // Navigate to reset password page with validated reset_token
      navigate('/reset-password', {
        state: {
          reset_token: response.data.reset_token,
        },
      });
    } catch (err) {
      if (err instanceof ApiError) {
        setErrorMessage(err.message || 'Invalid or expired reset code.');
      } else {
        setErrorMessage('Failed to verify reset code. Please try again.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthLayout>
      <div className="auth-card">
        <div className="card-header">
          <div style={{ display: 'inline-flex', padding: '8px', borderRadius: '12px', background: '#eff6ff', color: '#2563eb', marginBottom: '12px' }}>
            <KeyRound size={28} />
          </div>
          <h2 className="card-title">
            {step === 'request' ? 'Reset your password' : 'Enter reset code'}
          </h2>
          <p className="card-subtitle">
            {step === 'request'
              ? "Enter your registered work email and we'll send you a secure verification code to reset your password."
              : `Enter the 6-digit verification code sent to ${destination} to authorize setting your new password.`}
          </p>
        </div>

        {errorMessage && <Alert type="error" message={errorMessage} />}
        {infoMessage && <Alert type="info" message={infoMessage} />}

        {step === 'request' ? (
          <form onSubmit={handleRequestReset} className="auth-form" noValidate>
            <Input
              label="Work email"
              type="email"
              value={email}
              onChange={(e) => {
                setEmail(e.target.value);
                if (fieldErrors.email) setFieldErrors({});
              }}
              placeholder="you@company.com"
              error={fieldErrors.email}
              leftIcon={<Mail size={16} aria-hidden="true" />}
              autoComplete="username"
              disabled={isLoading}
              required
            />

            <Button
              type="submit"
              variant="primary"
              isLoading={isLoading}
              rightIcon={<ArrowRight size={16} aria-hidden="true" />}
            >
              Send reset code
            </Button>

            <div style={{ textAlign: 'center', marginTop: '12px' }}>
              <Link to="/login" className="link-btn" style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
                <ArrowLeft size={14} /> Back to log in
              </Link>
            </div>
          </form>
        ) : (
          <div className="auth-form">
            <div className="form-group">
              <label className="form-label">6-digit reset code</label>
              <OtpCells
                length={6}
                value={otpCode}
                onChange={setOtpCode}
                onComplete={(completedCode) => handleVerifyResetOtp(completedCode)}
                disabled={isLoading}
                hasError={!!errorMessage}
              />
            </div>

            <Button
              type="button"
              variant="primary"
              onClick={() => handleVerifyResetOtp()}
              isLoading={isLoading}
              disabled={otpCode.length !== 6}
              rightIcon={<ArrowRight size={16} aria-hidden="true" />}
            >
              Confirm code
            </Button>

            <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: '12px', fontSize: '13px' }}>
              <button
                type="button"
                onClick={() => setStep('request')}
                className="link-btn"
                style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}
              >
                <ArrowLeft size={14} /> Use different email
              </button>

              <Link to="/login" className="link-btn">
                Cancel
              </Link>
            </div>
          </div>
        )}
      </div>
    </AuthLayout>
  );
};
