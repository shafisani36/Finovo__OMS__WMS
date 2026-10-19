import React from 'react';
import type { WarehouseStatus } from '../../services/omsService';

interface WarehouseStatusBadgeProps {
  status: WarehouseStatus | string;
  size?: 'sm' | 'md';
}

const STATUS_CONFIG: Record<string, { label: string; bg: string; text: string; border: string; dot: string }> = {
  active: {
    label: 'Active',
    bg: '#ecfdf5',
    text: '#059669',
    border: '#a7f3d0',
    dot: '#10b981',
  },
  ready_for_activation: {
    label: 'Ready for Activation',
    bg: '#eff6ff',
    text: '#1e56ff',
    border: '#bfdbfe',
    dot: '#2563eb',
  },
  setup_incomplete: {
    label: 'Setup Incomplete',
    bg: '#fffbeb',
    text: '#b45309',
    border: '#fde68a',
    dot: '#f59e0b',
  },
  draft: {
    label: 'Draft',
    bg: '#f1f5f9',
    text: '#475569',
    border: '#cbd5e1',
    dot: '#94a3b8',
  },
  inactive: {
    label: 'Inactive',
    bg: '#f8fafc',
    text: '#64748b',
    border: '#e2e8f0',
    dot: '#94a3b8',
  },
  closing: {
    label: 'Closing',
    bg: '#fff7ed',
    text: '#c2410c',
    border: '#ffedd5',
    dot: '#ea580c',
  },
  closed: {
    label: 'Closed',
    bg: '#fef2f2',
    text: '#b91c1c',
    border: '#fecaca',
    dot: '#ef4444',
  },
};

export const WarehouseStatusBadge: React.FC<WarehouseStatusBadgeProps> = ({ status, size = 'sm' }) => {
  const config = STATUS_CONFIG[status] || {
    label: status.replace(/_/g, ' '),
    bg: '#f1f5f9',
    text: '#475569',
    border: '#cbd5e1',
    dot: '#94a3b8',
  };

  const isSmall = size === 'sm';

  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        padding: isSmall ? '2px 8px' : '4px 12px',
        borderRadius: 9999,
        fontSize: isSmall ? 11 : 12,
        fontWeight: 600,
        backgroundColor: config.bg,
        color: config.text,
        border: `1px solid ${config.border}`,
        whiteSpace: 'nowrap',
        textTransform: 'capitalize',
      }}
    >
      <span
        style={{
          width: isSmall ? 6 : 8,
          height: isSmall ? 6 : 8,
          borderRadius: '50%',
          backgroundColor: config.dot,
        }}
      />
      {config.label}
    </span>
  );
};

export default WarehouseStatusBadge;
