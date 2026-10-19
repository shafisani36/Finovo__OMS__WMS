import React, { useState, useEffect } from 'react';
import { useLocation, useNavigate, Link } from 'react-router-dom';
import { ArrowRight, ArrowLeft, RefreshCw, ShieldCheck } from 'lucide-react';
import { AuthLayout } from '../components/layout/AuthLayout';
import { OtpCells } from '../components/common/OtpCells';
import { Button } from '../components/common/Button';
import { Alert } from '../components/common/Alert';
import { useAuth } from '../hooks/useAuth';
import { authService } from '../services/authService';
import { ApiError } from '../services/apiClient';

interface LocationState {
  challenge_id?: string;
  destination?: string;
  expires_in_seconds?: number;
  resend_cooldown_seconds?: number;
}

export const VerifyOtpPage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { setAuthSession } = useAuth();

  const state = (location.state as LocationState) || {};
  const [challengeId, setChallengeId] = useState<string>(state.challenge_id || '');
  const [destination, setDestination] = useState<string>(state.destination || 'your email');
  const [code, setCode] = useState<string>('');

  const [secondsRemaining, setSecondsRemaining] = useState<number>(
    state.resend_cooldown_seconds || 60
  );
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isResending, setIsResending] = useState<boolean>(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // If user lands directly without a challenge, redirect to login
  useEffect(() => {
    if (!challengeId) {
      navigate('/login', { replace: true });
    }
  }, [challengeId, navigate]);

  // Countdown timer
  useEffect(() => {
    if (secondsRemaining <= 0) return;

    const timer = setInterval(() => {
      setSecondsRemaining((prev) => Math.max(0, prev - 1));
    }, 1000);

    return () => clearInterval(timer);
  }, [secondsRemaining]);

  const handleVerify = async (codeToSubmit?: string) => {
    const finalCode = codeToSubmit || code;
    setErrorMessage(null);

    if (finalCode.length !== 6) {
      setErrorMessage('Please enter all 6 digits of the verification code.');
      return;
    }

    setIsLoading(true);

    try {
      const response = await authService.verifyOtp(challengeId, finalCode);

      setSuccessMessage('Identity verified! Redirecting to your workspace...');
      setAuthSession(response.data.user, response.data.token);

      setTimeout(() => {
        navigate('/dashboard', { replace: true });
      }, 700);
    } catch (err) {
      if (err instanceof ApiError) {
        setErrorMessage(err.message || 'Invalid verification code.');
      } else {
        setErrorMessage('Failed to verify code. Please check your connection.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  const handleResend = async () => {
    if (secondsRemaining > 0 || isResending) return;

    setIsResending(true);
    setErrorMessage(null);
    setSuccessMessage(null);

    try {
      const response = await authService.resendOtp(challengeId);

      setChallengeId(response.data.challenge_id);
      setDestination(response.data.destination);
      setSecondsRemaining(response.data.resend_cooldown_seconds || 60);
      setCode('');
      setSuccessMessage('A fresh verification code has been dispatched to your email.');
    } catch (err) {
      if (err instanceof ApiError) {
        setErrorMessage(err.message);
      } else {
        setErrorMessage('Unable to resend code. Please try again.');
      }
    } finally {
      setIsResending(false);
    }
  };

  const formatCountdown = (seconds: number): string => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  };

  return (
    <AuthLayout>
      <div className="auth-card">
        <div className="card-header">
          <div style={{ display: 'inline-flex', padding: '8px', borderRadius: '12px', background: '#eff6ff', color: '#2563eb', marginBottom: '12px' }}>
            <ShieldCheck size={28} />
          </div>
          <h2 className="card-title">Two-Factor Authentication</h2>
          <p className="card-subtitle">
            Enter the 6-digit numeric verification code dispatched to <strong style={{ color: '#0f172a' }}>{destination}</strong>.
          </p>
        </div>

        {errorMessage && <Alert type="error" message={errorMessage} />}
        {successMessage && <Alert type="success" message={successMessage} />}

        <div className="auth-form">
          <div className="form-group">
            <label className="form-label">Verification code</label>
            <OtpCells
              length={6}
              value={code}
              onChange={setCode}
              onComplete={(completedCode) => handleVerify(completedCode)}
              disabled={isLoading || isResending}
              hasError={!!errorMessage}
            />
          </div>

          <div className="countdown-box">
            <span>Didn't receive the code?</span>
            {secondsRemaining > 0 ? (
              <span className="countdown-badge">
                Resend in {formatCountdown(secondsRemaining)}
              </span>
            ) : (
              <button
                type="button"
                onClick={handleResend}
                disabled={isResending}
                className="link-btn"
                style={{ display: 'flex', alignItems: 'center', gap: '4px' }}
              >
                <RefreshCw size={13} className={isResending ? 'animate-spin' : ''} />
                Resend code
              </button>
            )}
          </div>

          <Button
            type="button"
            variant="primary"
            onClick={() => handleVerify()}
            isLoading={isLoading}
            disabled={code.length !== 6}
            rightIcon={<ArrowRight size={16} aria-hidden="true" />}
          >
            Verify &amp; Continue
          </Button>

          <div style={{ textAlign: 'center', marginTop: '12px' }}>
            <Link to="/login" className="link-btn" style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
              <ArrowLeft size={14} /> Back to log in
            </Link>
          </div>
        </div>
      </div>
    </AuthLayout>
  );
};
