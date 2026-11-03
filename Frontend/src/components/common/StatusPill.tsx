import React from 'react';
import { humanize } from '../../utils/format';
import './StatusPill.css';

type Tone = 'green' | 'blue' | 'amber' | 'red' | 'grey' | 'purple';

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
  requested: 'amber',
  received: 'blue',
  completed: 'green',
  unfulfilled: 'grey',
  cancelled: 'red',
  failed: 'red',
  refunded: 'red',
  partially_refunded: 'amber',
  returned: 'red',
  exception: 'red',
  suspended: 'red',
};

/** Coloured pill for an order, payment or fulfillment status; tone sets the colour of a status the company named. */
export const StatusPill: React.FC<{ status: string; label?: string; tone?: Tone }> = ({ status, label, tone }) => (
  <span className={`status-pill status-pill-${tone ?? TONES[status] ?? 'grey'}`}>{label ?? humanize(status)}</span>
);

export default StatusPill;
