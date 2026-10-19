import React from 'react';
import { Check, X } from 'lucide-react';

interface PasswordStrengthProps {
  password: string;
}

export const PasswordStrength: React.FC<PasswordStrengthProps> = ({ password }) => {
  if (!password) return null;

  const hasMinLength = password.length >= 12;
  const hasUpper = /[A-Z]/.test(password);
  const hasLower = /[a-z]/.test(password);
  const hasNumber = /[0-9]/.test(password);
  const hasSpecial = /[^A-Za-z0-9]/.test(password);

  const criteriaMet = [hasMinLength, hasUpper, hasLower, hasNumber, hasSpecial].filter(Boolean).length;

  let strengthLabel = 'Weak';
  let strengthClass = 'active-weak';
  let segmentsActive = 1;

  if (hasMinLength && criteriaMet >= 4) {
    strengthLabel = 'Strong';
    strengthClass = 'active-strong';
    segmentsActive = 4;
  } else if (hasMinLength && criteriaMet >= 2) {
    strengthLabel = 'Moderate';
    strengthClass = 'active-medium';
    segmentsActive = 2;
  } else if (password.length >= 8) {
    strengthLabel = 'Fair';
    strengthClass = 'active-medium';
    segmentsActive = 2;
  }

  return (
    <div className="password-meter-wrap" aria-live="polite">
      <div className="meter-bars">
        {[1, 2, 3, 4].map((step) => (
          <div
            key={step}
            className={`meter-segment ${step <= segmentsActive ? strengthClass : ''}`}
          />
        ))}
      </div>
      <div className="meter-labels">
        <span>Password Strength: <strong>{strengthLabel}</strong></span>
        <span>Requirement: min 12 characters</span>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: '3px', marginTop: '4px', fontSize: '11px', color: '#64748b' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          {hasMinLength ? <Check size={12} color="#16a34a" /> : <X size={12} color="#dc2626" />}
          <span style={{ color: hasMinLength ? '#16a34a' : '#64748b' }}>At least 12 characters</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          {hasUpper && hasLower ? <Check size={12} color="#16a34a" /> : <X size={12} color="#94a3b8" />}
          <span>Uppercase and lowercase letters</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          {hasNumber || hasSpecial ? <Check size={12} color="#16a34a" /> : <X size={12} color="#94a3b8" />}
          <span>Numbers or symbols recommended</span>
        </div>
      </div>
    </div>
  );
};
