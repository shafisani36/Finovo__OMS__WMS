import React from 'react';
import { Lightbulb, ArrowRight, X } from 'lucide-react';

export interface AlertBannerProps {
  title: string;
  message: string;
  actionText?: string;
  onAction?: () => void;
  onDismiss?: () => void;
}

export const AlertBanner: React.FC<AlertBannerProps> = ({
  title,
  message,
  actionText,
  onAction,
  onDismiss,
}) => {
  return (
    <div className="alert-banner-attention">
      <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
        <div className="alert-banner-icon">
          <Lightbulb size={20} />
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span style={{ fontSize: 13, fontWeight: 700, color: '#713f12' }}>{title}</span>
          <span style={{ fontSize: 12, color: '#854d0e' }}>{message}</span>
        </div>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
        {actionText && (
          <button
            onClick={onAction}
            style={{
              background: 'none',
              border: 'none',
              color: '#1e56ff',
              fontSize: 13,
              fontWeight: 600,
              cursor: 'pointer',
              display: 'inline-flex',
              alignItems: 'center',
              gap: 4,
            }}
          >
            <span>{actionText}</span>
            <ArrowRight size={14} />
          </button>
        )}

        {onDismiss && (
          <button
            onClick={onDismiss}
            style={{
              background: 'none',
              border: 'none',
              color: '#94a3b8',
              cursor: 'pointer',
              padding: 4,
              borderRadius: 6,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
            title="Dismiss"
          >
            <X size={16} />
          </button>
        )}
      </div>
    </div>
  );
};

export default AlertBanner;
