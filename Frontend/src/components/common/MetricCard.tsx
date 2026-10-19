import React from 'react';

export interface MetricCardProps {
  title: string;
  value: string | number;
  icon: React.ReactNode;
  pillText?: string;
  pillVariant?: 'blue' | 'green' | 'amber';
}

export const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  icon,
  pillText,
  pillVariant = 'blue',
}) => {
  return (
    <div className="kpi-card">
      <div className="kpi-card-icon">{icon}</div>
      <div className="kpi-card-content">
        <h4 className="kpi-card-title">{title}</h4>
        <div className="kpi-card-value-wrap">
          <span className="kpi-card-value">{value}</span>
          {pillText && (
            <span
              className={`kpi-pill ${
                pillVariant === 'green'
                  ? 'kpi-pill-green'
                  : pillVariant === 'amber'
                  ? 'bg-amber-50 text-amber-700'
                  : 'kpi-pill-blue'
              }`}
            >
              {pillText}
            </span>
          )}
        </div>
      </div>
    </div>
  );
};

export default MetricCard;
