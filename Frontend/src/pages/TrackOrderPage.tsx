import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { ExternalLink, Hash, Mail, PackageSearch } from 'lucide-react';
import { AuthLayout } from '../components/layout/AuthLayout';
import { Input } from '../components/common/Input';
import { Button } from '../components/common/Button';
import { Alert } from '../components/common/Alert';
import StatusPill from '../components/common/StatusPill';
import omsService from '../services/omsService';
import type { TrackedOrder } from '../services/omsService';
import { formatDate, formatMoney, humanize } from '../utils/format';
import { errorMessage } from '../utils/errors';

type CustomerRequest = 'cancel' | 'return' | null;

/**
 * Customer self-service, without an account: the order number and the email used to place the order show its
 * progress and parcels, and let the customer ask to cancel it (before it ships) or return shipped items.
 */
export const TrackOrderPage: React.FC = () => {
  const [orderNumber, setOrderNumber] = useState('');
  const [email, setEmail] = useState('');
  const [order, setOrder] = useState<TrackedOrder | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [request, setRequest] = useState<CustomerRequest>(null);
  const [reason, setReason] = useState('');
  const [quantities, setQuantities] = useState<Record<number, number>>({});

  const load = async () => {
    setOrder(await omsService.trackOrder(orderNumber.trim(), email.trim()));
  };

  const run = async (action: () => Promise<string | void>, fallback: string) => {
    setBusy(true);
    setMessage(null);
    try {
      const text = await action();
      if (text) setMessage({ type: 'success', text });
    } catch (err) {
      setMessage({ type: 'error', text: errorMessage(err, fallback) });
    } finally {
      setBusy(false);
    }
  };

  const find = (e: React.FormEvent) => {
    e.preventDefault();
    setOrder(null);
    setRequest(null);
    run(load, 'No order matches that number and email.');
  };

  const sendRequest = (e: React.FormEvent) => {
    e.preventDefault();
    run(async () => {
      const items = Object.entries(quantities)
        .filter(([, quantity]) => quantity > 0)
        .map(([itemId, quantity]) => ({ item_id: Number(itemId), quantity }));
      const res =
        request === 'cancel'
          ? await omsService.requestOrderCancellation(orderNumber.trim(), email.trim(), reason.trim())
          : await omsService.requestOrderReturn(orderNumber.trim(), email.trim(), reason.trim(), items);
      setRequest(null);
      setReason('');
      setQuantities({});
      await load();
      return res.message;
    }, 'Your request could not be sent.');
  };

  const returnable = order?.items.filter((item) => item.returnable_quantity > 0) ?? [];
  const returnUnits = Object.values(quantities).reduce((sum, quantity) => sum + quantity, 0);

  return (
    <AuthLayout>
      <div className="auth-card">
        <div className="card-header">
          <h2 className="card-title">Track your order</h2>
          <p className="card-subtitle">Enter your order number and the email you used when ordering.</p>
        </div>

        {message && <Alert type={message.type} message={message.text} />}

        <form onSubmit={find} className="auth-form">
          <Input label="Order number" value={orderNumber} onChange={(e) => setOrderNumber(e.target.value)} leftIcon={<Hash size={16} />} maxLength={100} required />
          <Input label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} leftIcon={<Mail size={16} />} maxLength={255} required />
          <Button type="submit" isLoading={busy && !order} leftIcon={<PackageSearch size={16} />}>
            Find my order
          </Button>
        </form>

        {order && (
          <div style={{ marginTop: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
              <div>
                <div style={{ fontWeight: 700, color: '#0f172a' }}>Order {order.order_number}</div>
                <div style={{ fontSize: 12, color: '#64748b' }}>
                  {order.store ? `${order.store} · ` : ''}
                  {formatDate(order.placed_at)} · {formatMoney(order.total, order.currency)}
                </div>
              </div>
              <StatusPill status={order.status} label={order.on_hold ? 'Paused for review' : humanize(order.status)} />
            </div>

            <ul style={{ margin: 0, paddingLeft: 18, fontSize: 13, color: '#334155' }}>
              {order.items.map((item) => (
                <li key={item.item_id}>
                  {item.quantity} × {item.name}
                </li>
              ))}
            </ul>

            {order.shipments.map((shipment, index) => (
              <div key={index} style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 12, fontSize: 13 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                  <strong>
                    {shipment.carrier ?? 'Parcel'} {shipment.tracking_number}
                  </strong>
                  <StatusPill status={shipment.status} />
                </div>
                {shipment.tracking_url && (
                  <a href={shipment.tracking_url} target="_blank" rel="noopener noreferrer" style={{ fontSize: 12 }}>
                    Follow on the courier's site <ExternalLink size={11} />
                  </a>
                )}
                <ul style={{ margin: '8px 0 0', paddingLeft: 18, fontSize: 12, color: '#64748b' }}>
                  {[...shipment.events].reverse().map((event, i) => (
                    <li key={i}>
                      {formatDate(event.at, true)}: {event.description}
                    </li>
                  ))}
                </ul>
              </div>
            ))}

            {order.returns.map((ret) => (
              <div key={ret.return_number} style={{ fontSize: 13 }}>
                Return {ret.return_number}: <StatusPill status={ret.status} />
                {ret.resolution && <span style={{ marginLeft: 6, color: '#047857' }}>{ret.resolution === 'refund' ? 'Refunded' : 'Replacement sent'}</span>}
              </div>
            ))}

            {request === null && (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {order.can_cancel && (
                  <Button type="button" variant="outline" onClick={() => setRequest('cancel')}>
                    Ask to cancel
                  </Button>
                )}
                {returnable.length > 0 && (
                  <Button type="button" variant="outline" onClick={() => setRequest('return')}>
                    Return items
                  </Button>
                )}
              </div>
            )}

            {request && (
              <form onSubmit={sendRequest} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {request === 'return' &&
                  returnable.map((item) => (
                    <label key={item.item_id} className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                      <span>
                        {item.name} (up to {item.returnable_quantity})
                      </span>
                      <input
                        className="form-input"
                        type="number"
                        min={0}
                        max={item.returnable_quantity}
                        value={quantities[item.item_id] ?? 0}
                        onChange={(e) => setQuantities({ ...quantities, [item.item_id]: Math.min(item.returnable_quantity, Math.max(0, Number(e.target.value) || 0)) })}
                        style={{ width: 80 }}
                      />
                    </label>
                  ))}
                <label className="form-label">
                  {request === 'cancel' ? 'Why do you want to cancel?' : 'Why are you returning these?'}
                  <textarea className="form-input" required minLength={3} maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} style={{ width: '100%', height: 70 }} />
                </label>
                <div style={{ display: 'flex', gap: 8 }}>
                  <Button type="submit" isLoading={busy} disabled={reason.trim().length < 3 || (request === 'return' && returnUnits === 0)}>
                    Send request
                  </Button>
                  <Button type="button" variant="outline" onClick={() => setRequest(null)} disabled={busy}>
                    Back
                  </Button>
                </div>
              </form>
            )}
          </div>
        )}

        <p className="card-subtitle" style={{ marginTop: 20, textAlign: 'center' }}>
          Store staff? <Link to="/login">Log in</Link>
        </p>
      </div>
    </AuthLayout>
  );
};

export default TrackOrderPage;
