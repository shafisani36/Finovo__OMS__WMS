import React from 'react';

export interface InfoCalloutCardProps {
  title: string;
  description: string;
  linkText?: string;
  onLinkClick?: () => void;
  icon?: React.ReactNode;
}

export const InfoCalloutCard: React.FC<InfoCalloutCardProps> = ({
  title,
  description,
  linkText,
  onLinkClick,
  icon,
}) => {
  return (
    <div className="header-info-callout">
      {icon && <div className="header-info-callout-icon">{icon}</div>}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{title}</span>
        <span style={{ fontSize: 12, color: '#64748b', lineHeight: 1.4 }}>{description}</span>
        {linkText && (
          <button
            onClick={onLinkClick}
            style={{
              background: 'none',
              border: 'none',
              color: '#1e56ff',
              fontSize: 12,
              fontWeight: 600,
              padding: 0,
              cursor: 'pointer',
              textAlign: 'left',
              marginTop: 4,
              display: 'inline-flex',
              alignItems: 'center',
              gap: 4,
            }}
          >
            {linkText}
          </button>
        )}
      </div>
    </div>
  );
};

export default InfoCalloutCard;
