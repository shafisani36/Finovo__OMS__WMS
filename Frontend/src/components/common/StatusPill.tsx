import React from 'react';
import { humanize } from '../../utils/format';
import './StatusPill.css';

type Tone = 'green' | 'blue' | 'amber' | 'red' | 'grey';

const TONES: Record<string, Tone> = {
  shipped: 'green',
  delivered: 'green',
  paid: 'green',
  fulfilled: 'green',
  active: 'green',
  sent: 'blue',
  draft: 'grey',
  rejected: 'red',
  allocated: 'blue',
  partially_allocated: 'blue',
  confirmed: 'blue',
  picking: 'blue',
  picked: 'blue',
  packed: 'blue',
  partially_shipped: 'blue',
  partially_fulfilled: 'blue',
  imported: 'amber',
  pending: 'amber',
  unfulfilled: 'grey',
  cancelled: 'red',
  failed: 'red',
  refunded: 'red',
  returned: 'red',
  exception: 'red',
};

/** Coloured pill for an order, payment or fulfillment status. */
export const StatusPill: React.FC<{ status: string; label?: string }> = ({ status, label }) => (
  <span className={`status-pill status-pill-${TONES[status] ?? 'grey'}`}>{label ?? humanize(status)}</span>
);

export default StatusPill;
