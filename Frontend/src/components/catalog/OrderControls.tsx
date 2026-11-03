import React, { useEffect, useState } from 'react';
import { Loader2, PauseCircle, PlayCircle, Scissors, X } from 'lucide-react';
import omsService from '../../services/omsService';
import { ORDER_WAITING_STATUSES } from '../../services/omsService';
import type { OrderControlSettings, OrderHoldType, OrderModel, OrderTimelineEvent } from '../../services/omsService';
import { Alert } from '../common/Alert';
import StatusPill from '../common/StatusPill';
import { errorMessage } from '../../utils/errors';

/** Statuses that can still be held (the order has not left the warehouse). */
const HOLDABLE_STATUSES = [...ORDER_WAITING_STATUSES, 'allocated', 'partially_allocated', 'picking', 'picked', 'packed'];

const HOLD_LABELS: Record<OrderHoldType, string> = {
  manual: 'On hold',
  approval: 'Needs approval',
  credit: 'Over credit limit',
};

/** Small red pill for a held order, shown next to its status. */
export const HoldPill: React.FC<{ type?: OrderHoldType | null }> = ({ type }) =>
  type ? <StatusPill status="exception" label={HOLD_LABELS[type]} /> : null;

interface OrderHoldSectionProps {
  order: OrderModel;
  status: string;
  canManage: boolean;
  onChanged: (message: string) => void;
}

/**
 * The hold state of an order: why it is held with a release (or approve) button, or a box to put it on hold.
 */
export const OrderHoldSection: React.FC<OrderHoldSectionProps> = ({ order, status, canManage, onChanged }) => {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async (action: () => Promise<{ message: string }>) => {
    setBusy(true);
    setError(null);
    try {
      onChanged((await action()).message);
      setReason('');
    } catch (err) {
      setError(errorMessage(err, 'The order could not be changed.'));
    } finally {
      setBusy(false);
    }
  };

  if (!order.hold_type && (!canManage || !HOLDABLE_STATUSES.includes(status))) return null;

  return (
    <section className="order-section">
      <h4 className="detail-section-title">Hold</h4>
      {error && <Alert type="error" message={error} />}
      {order.hold_type ? (
        <div className="order-hold-row">
          <div style={{ minWidth: 0 }}>
            <HoldPill type={order.hold_type} />
            <div className="detail-muted" style={{ marginTop: 4 }}>{order.hold_reason}</div>
          </div>
          {canManage && (
            <button type="button" className="btn-finovo-primary" disabled={busy} onClick={() => run(() => omsService.releaseOrder(order.id))}>
              {busy ? <Loader2 size={14} className="animate-spin" /> : <PlayCircle size={14} />}
              <span>{order.hold_type === 'approval' ? 'Approve' : 'Release'}</span>
            </button>
          )}
        </div>
      ) : (
        <form
          className="order-hold-row"
          onSubmit={(e) => {
            e.preventDefault();
            run(() => omsService.holdOrder(order.id, reason.trim()));
          }}
        >
          <input
            className="cf-input"
            placeholder="Reason, e.g. waiting for payment"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={500}
            aria-label="Hold reason"
          />
          <button type="submit" className="btn-finovo-secondary" disabled={busy || !reason.trim()}>
            {busy ? <Loader2 size={14} className="animate-spin" /> : <PauseCircle size={14} />}
            <span>Hold</span>
          </button>
        </form>
      )}
    </section>
  );
};

interface OrderSplitSectionProps {
  order: OrderModel;
  onChanged: (message: string) => void;
}

/** Choose quantities to move into a new order (to ship what is in stock now and the rest later). */
export const OrderSplitSection: React.FC<OrderSplitSectionProps> = ({ order, onChanged }) => {
  const items = order.items ?? [];
  const [quantities, setQuantities] = useState<Record<number, number>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const moving = Object.entries(quantities).filter(([, quantity]) => quantity > 0);

  const split = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await omsService.splitOrder(order.id, moving.map(([itemId, quantity]) => ({ item_id: Number(itemId), quantity })));
      setQuantities({});
      onChanged(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The order could not be split.'));
    } finally {
      setBusy(false);
    }
  };

  if (items.reduce((sum, item) => sum + Number(item.quantity), 0) < 2) return null;

  return (
    <section className="order-section">
      <h4 className="detail-section-title">Split Order</h4>
      {error && <Alert type="error" message={error} />}
      <div className="detail-muted" style={{ marginBottom: 8 }}>Units to move into a new order:</div>
      <div className="order-split-grid">
        {items.map((item) => (
          <label key={item.id} className="cf-field">
            <span className="cf-label">
              {item.product_name} (of {item.quantity})
            </span>
            <input
              className="cf-input"
              type="number"
              min={0}
              max={item.quantity}
              value={quantities[item.id] ?? 0}
              onChange={(e) => setQuantities({ ...quantities, [item.id]: Math.min(item.quantity, Math.max(0, Number(e.target.value) || 0)) })}
            />
          </label>
        ))}
      </div>
      <button type="button" className="btn-finovo-secondary" style={{ marginTop: 10 }} disabled={busy || moving.length === 0} onClick={split}>
        {busy ? <Loader2 size={14} className="animate-spin" /> : <Scissors size={14} />}
        <span>Split</span>
      </button>
    </section>
  );
};

/** Everything that happened to the order, newest first; reloads when refreshKey changes. */
export const OrderTimeline: React.FC<{ orderId: number; refreshKey: number }> = ({ orderId, refreshKey }) => {
  const [events, setEvents] = useState<OrderTimelineEvent[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    omsService
      .getOrderTimeline(orderId)
      .then((data) => current && setEvents([...data].reverse()))
      .catch((err) => current && setError(errorMessage(err, 'Could not load the order history.')));
    return () => {
      current = false;
    };
  }, [orderId, refreshKey]);

  return (
    <section className="order-section">
      <h4 className="detail-section-title">History</h4>
      {error && <Alert type="error" message={error} />}
      {!events && !error && <div className="detail-muted">Loading history...</div>}
      {events?.length === 0 && <div className="detail-muted">Nothing recorded yet.</div>}
      <ol className="order-timeline">
        {events?.map((event, index) => (
          <li key={`${event.at}-${index}`}>
            <div className="order-timeline-text">{event.description}</div>
            <div className="detail-muted">
              {new Date(event.at).toLocaleString()}
              {event.user ? ` · ${event.user}` : ''}
            </div>
          </li>
        ))}
      </ol>
    </section>
  );
};

interface OrderRulesModalProps {
  onClose: () => void;
  onSaved: (message: string) => void;
}

/** The company's order rules: approval limit for high-value orders and the hours an order may take to ship. */
export const OrderRulesModal: React.FC<OrderRulesModalProps> = ({ onClose, onSaved }) => {
  const [settings, setSettings] = useState<OrderControlSettings | null>(null);
  const [threshold, setThreshold] = useState('');
  const [hours, setHours] = useState('48');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    omsService
      .getOrderControlSettings()
      .then((data) => {
        setSettings(data);
        setThreshold(data.approval_threshold === null ? '' : String(data.approval_threshold));
        setHours(String(data.sla_hours));
      })
      .catch((err) => setError(errorMessage(err, 'Could not load the order rules.')));
  }, []);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await omsService.saveOrderControlSettings({ approval_threshold: threshold.trim() === '' ? null : Number(threshold), sla_hours: Number(hours) });
      onSaved(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The order rules could not be saved.'));
      setSaving(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={() => !saving && onClose()}>
      <form className="procurement-modal cf-modal" style={{ maxWidth: 460 }} onClick={(e) => e.stopPropagation()} onSubmit={save}>
        <div className="procurement-modal-header">
          <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Order Rules</h3>
          <button type="button" className="topbar-icon-button" onClick={onClose} aria-label="Close" disabled={saving}>
            <X size={18} />
          </button>
        </div>
        <div className="procurement-modal-body cf-body">
          {error && <Alert type="error" message={error} />}
          <label className="cf-field">
            <span className="cf-label">Approval needed at or above (order total)</span>
            <input className="cf-input" type="number" min={0.01} step="0.01" placeholder="No approval" value={threshold} onChange={(e) => setThreshold(e.target.value)} disabled={!settings} />
          </label>
          <label className="cf-field">
            <span className="cf-label">Ship within (hours)</span>
            <input className="cf-input" type="number" min={1} max={720} required value={hours} onChange={(e) => setHours(e.target.value)} disabled={!settings} />
          </label>
          <div className="detail-muted">Orders past 75% of this time are shown as at risk, and past it as late.</div>
        </div>
        <div className="procurement-modal-footer">
          <button type="button" className="btn-finovo-secondary" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button type="submit" className="btn-finovo-primary" disabled={saving || !settings}>
            {saving && <Loader2 size={14} className="animate-spin" />}
            <span>Save Rules</span>
          </button>
        </div>
      </form>
    </div>
  );
};
