import React from 'react';
import { AlertCircle } from 'lucide-react';

interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label: string;
  error?: string | null;
  leftIcon?: React.ReactNode;
  rightElement?: React.ReactNode;
  helperText?: string;
}

export const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ label, error, leftIcon, rightElement, helperText, id, className = '', ...props }, ref) => {
    const inputId = id || `input-${label.toLowerCase().replace(/\s+/g, '-')}`;

    return (
      <div className="form-group">
        <label htmlFor={inputId} className="form-label">
          {label}
        </label>
        <div className="input-container">
          {leftIcon && <span className="input-icon-left">{leftIcon}</span>}
          <input
            id={inputId}
            ref={ref}
            className={`form-input ${error ? 'input-error' : ''} ${className}`}
            aria-invalid={!!error}
            aria-describedby={error ? `${inputId}-error` : helperText ? `${inputId}-help` : undefined}
            {...props}
          />
          {rightElement}
        </div>
        {error ? (
          <p id={`${inputId}-error`} className="form-error-msg" role="alert">
            <AlertCircle size={13} aria-hidden="true" />
            {error}
          </p>
        ) : helperText ? (
          <p id={`${inputId}-help`} className="form-helper-msg">
            {helperText}
          </p>
        ) : null}
      </div>
    );
  }
);

Input.displayName = 'Input';
