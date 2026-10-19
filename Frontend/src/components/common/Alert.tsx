import React from 'react';
import { AlertCircle, CheckCircle2, Info } from 'lucide-react';

interface AlertProps {
  type?: 'error' | 'success' | 'info';
  message: string;
  className?: string;
}

export const Alert: React.FC<AlertProps> = ({ type = 'error', message, className = '' }) => {
  if (!message) return null;

  const Icon = type === 'error' ? AlertCircle : type === 'success' ? CheckCircle2 : Info;
  const alertClass = type === 'error' ? 'alert-error' : type === 'success' ? 'alert-success' : 'alert-info';

  return (
    <div className={`alert ${alertClass} ${className}`} role="alert" aria-live="polite">
      <Icon size={16} className="shrink-0" style={{ marginTop: '2px' }} aria-hidden="true" />
      <span>{message}</span>
    </div>
  );
};
