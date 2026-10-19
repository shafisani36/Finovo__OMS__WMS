import React, { useRef, useEffect } from 'react';

interface OtpCellsProps {
  length?: number;
  value: string;
  onChange: (value: string) => void;
  onComplete?: (code: string) => void;
  disabled?: boolean;
  hasError?: boolean;
}

export const OtpCells: React.FC<OtpCellsProps> = ({
  length = 6,
  value,
  onChange,
  onComplete,
  disabled = false,
  hasError = false,
}) => {
  const inputsRef = useRef<(HTMLInputElement | null)[]>([]);

  // Split value into an array of length cells
  const digits = Array.from({ length }, (_, i) => value[i] || '');

  useEffect(() => {
    if (!disabled) {
      inputsRef.current[0]?.focus();
    }
  }, [disabled]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>, index: number) => {
    const rawVal = e.target.value;
    // Allow only single numeric digit
    const cleaned = rawVal.replace(/\D/g, '');

    if (!cleaned) {
      const newDigits = [...digits];
      newDigits[index] = '';
      const newVal = newDigits.join('');
      onChange(newVal);
      return;
    }

    const digit = cleaned.slice(-1);
    const newDigits = [...digits];
    newDigits[index] = digit;
    const newVal = newDigits.join('');
    onChange(newVal);

    if (newVal.length === length && onComplete) {
      onComplete(newVal);
    }

    // Auto-advance to next cell
    if (index < length - 1 && digit) {
      inputsRef.current[index + 1]?.focus();
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>, index: number) => {
    if (e.key === 'Backspace') {
      if (!digits[index] && index > 0) {
        // Move to previous cell and clear it
        inputsRef.current[index - 1]?.focus();
        const newDigits = [...digits];
        newDigits[index - 1] = '';
        onChange(newDigits.join(''));
      }
    } else if (e.key === 'ArrowLeft' && index > 0) {
      e.preventDefault();
      inputsRef.current[index - 1]?.focus();
    } else if (e.key === 'ArrowRight' && index < length - 1) {
      e.preventDefault();
      inputsRef.current[index + 1]?.focus();
    }
  };

  const handlePaste = (e: React.ClipboardEvent<HTMLInputElement>) => {
    e.preventDefault();
    const pastedData = e.clipboardData.getData('text/plain').replace(/\D/g, '').slice(0, length);
    if (!pastedData) return;

    onChange(pastedData);
    if (pastedData.length === length && onComplete) {
      onComplete(pastedData);
    }

    // Focus cell after last pasted digit
    const nextIndex = Math.min(pastedData.length, length - 1);
    inputsRef.current[nextIndex]?.focus();
  };

  return (
    <div className="otp-grid" role="group" aria-label="One-Time Verification Code Input">
      {Array.from({ length }).map((_, index) => (
        <input
          key={index}
          ref={(el) => {
            inputsRef.current[index] = el;
          }}
          type="text"
          inputMode="numeric"
          autoComplete={index === 0 ? 'one-time-code' : 'off'}
          pattern="[0-9]*"
          maxLength={1}
          value={digits[index]}
          onChange={(e) => handleChange(e, index)}
          onKeyDown={(e) => handleKeyDown(e, index)}
          onPaste={handlePaste}
          disabled={disabled}
          className={`otp-cell ${hasError ? 'otp-error' : ''}`}
          aria-label={`Digit ${index + 1} of ${length}`}
        />
      ))}
    </div>
  );
};
