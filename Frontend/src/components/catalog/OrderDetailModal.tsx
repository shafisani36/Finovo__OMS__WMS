import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Download, Loader2, Pencil, Trash2, X } from 'lucide-react';
import omsService, { ORDER_WAITING_STATUSES, canSplitOrMerge } from '../../services/omsService';
import type { CustomOrderStatus, OrderFulfillment, OrderModel } from '../../services/omsService';
import { platformLabel } from '../stores/storePlatforms';
import ProductThumb from './ProductThumb';
import StatusPill from '../common/StatusPill';
import { addressLines, formatMoney, variantNote } from '../../utils/format';
import { BarcodeLabel } from '../barcode/BarcodeLabel';
import { Alert } from '../common/Alert';
import { DetailValue } from '../common/DetailValue';
import OrderWorkflow from './OrderWorkflow';
import OrderFormModal from './OrderFormModal';
import { HoldPill, OrderHoldSection, OrderSplitSection, OrderTimeline } from './OrderControls';
import { CustomStatusPill, OrderCustomStatusSection, OrderRepeatSection } from './OrderOperations';
import { useConfirmDialog } from '../common/ConfirmDialog';
import { useAuth } from '../../hooks/useAuth';
import { can } from '../../types/auth';
import { errorMessage } from '../../utils/errors';
import './DetailModal.css';

interface OrderDetailModalProps {
  order: OrderModel;
  /** The company's own order statuses. */
  statuses?: CustomOrderStatus[];
  onClose: () => void;
  /** Called after the order changes here, so the list behind can refresh (with a message after an edit or delete). */
  onChanged?: (message?: string) => void;
}

export const OrderDetailModal: React.FC<OrderDetailModalProps> = ({ order, statuses = [], onClose, onChanged }) => {
  const items = order.items ?? [];
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fulfillment, setFulfillment] = useState<OrderFulfillment | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [deleting, setDeleting] = useState(false);
  /** Bumped after every change here, so the history reloads. */
  const [historyKey, setHistoryKey] = useState(0);
  const { user } = useAuth();
  const { confirm, dialog } = useConfirmDialog();

  const loadFulfillment = useCallback(async () => {
    try {
      setFulfillment((await omsService.getOrderFulfillment(order.id)).data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load the order status.');
    }
  }, [order.id]);

  useEffect(() => {
    loadFulfillment();
  }, [loadFulfillment]);

  const handleChanged = async (message: string) => {
    setNotice(message);
    setHistoryKey((key) => key + 1);
    await loadFulfillment();
    onChanged?.();
  };

  /** A hold, release or split changes the order itself: the list reloads it and this view closes with the message. */
  const handleControlChanged = (message: string) => {
    onClose();
    onChanged?.(message);
  };

  const status = fulfillment?.order_status ?? order.order_status;
  const canEdit = can(user, 'orders.manage') && ORDER_WAITING_STATUSES.includes(status);
  // Orders on a store, and orders that reserved or moved stock, are kept for the record (cancel them instead).
  const canDelete = can(user, 'orders.delete') && !order.external_order_id && [...ORDER_WAITING_STATUSES, 'cancelled'].includes(status);

  const remove = async () => {
    const ok = await confirm({
      title: `Delete order ${order.order_number}?`,
      message: 'Use this only for an order created by mistake. It is removed permanently.',
      confirmLabel: 'Delete Order',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      setDeleting(true);
      const res = await omsService.deleteOrder(order.id);
      onClose();
      onChanged?.(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The order could not be deleted.'));
      setDeleting(false);
    }
  };

  const downloadInvoice = async () => {
    setDownloading(true);
    setError(null);
    try {
      await omsService.downloadOrderInvoice(order.id, order.order_number);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not download the invoice.');
    } finally {
      setDownloading(false);
    }
  };
  const addressText = addressLines(order.shipping_address).join(', ');
  const billingText = addressLines(order.billing_address).join(', ');
  const details = Object.entries(order.details ?? {});
  // Totals the store sent, shown above the order total when present.
  const totals = [
    { label: 'Subtotal', value: order.subtotal_amount },
    { label: 'Discount', value: order.discount_amount, negative: true },
    { label: 'Shipping', value: order.shipping_amount },
    { label: 'Tax', value: order.tax_amount },
  ].filter((row) => row.value !== null && row.value !== undefined && Number(row.value) !== 0);

  // The edit form takes the place of the order view while it is open.
  if (editing) {
    return (
      <OrderFormModal
        order={order}
        onClose={() => setEditing(false)}
        onSaved={(message) => {
          setEditing(false);
          onClose();
          onChanged?.(message);
        }}
      />
    );
  }

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div
        className="modal-dialog detail-modal order-modal"
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={`Order ${order.order_number}`}
      >
        <div className="detail-modal-head">
          <div style={{ minWidth: 0 }}>
            <div className="order-modal-title">
              <h3>Order {order.order_number}</h3>
              <StatusPill status={status} />
              <HoldPill type={order.hold_type} />
              <CustomStatusPill value={order.custom_status} statuses={statuses} />
            </div>
            <div className="detail-muted" style={{ marginTop: 2 }}>
              {order.store ? `${order.store.name} · ${platformLabel(order.store.platform)}` : 'Created in Finovo'}
              {order.placed_at ? ` · Placed ${new Date(order.placed_at).toLocaleString()}` : ''}
            </div>
          </div>
          <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
            {canEdit && (
              <button onClick={() => setEditing(true)} className="btn-finovo-secondary">
                <Pencil size={14} />
                <span>Edit</span>
              </button>
            )}
            {canDelete && (
              <button onClick={remove} className="btn-finovo-secondary" style={{ color: '#dc2626' }} disabled={deleting} aria-label="Delete order">
                {deleting ? <Loader2 size={14} className="animate-spin" /> : <Trash2 size={14} />}
              </button>
            )}
            {status !== 'cancelled' && (
              <button onClick={downloadInvoice} className="btn-finovo-secondary" disabled={downloading}>
                {downloading ? <Loader2 size={14} className="animate-spin" /> : <Download size={14} />}
                <span>{order.invoice_number ? `Invoice ${order.invoice_number}` : 'Invoice'}</span>
              </button>
            )}
            <button onClick={onClose} className="btn-finovo-secondary" style={{ padding: 6 }} aria-label="Close">
              <X size={18} />
            </button>
          </div>
        </div>

        <div className="detail-modal-body order-modal-body">
          {error && <div className="detail-error" style={{ marginBottom: 0 }}>{error}</div>}

          <div className="order-cards">
            <div className="order-card">
              <div className="detail-fact-label">Customer</div>
              <div className="detail-fact-value">{order.customer_name}</div>
              <div className="detail-muted">{order.customer_email || 'No email'}</div>
              {order.customer_phone && <div className="detail-muted">{order.customer_phone}</div>}
            </div>
            <div className="order-card">
              <div className="detail-fact-label">Ship To</div>
              <div className="detail-fact-value">{addressText || 'Not provided'}</div>
              {billingText && billingText !== addressText && <div className="detail-muted">Bill to: {billingText}</div>}
            </div>
            <div className="order-card">
              <div className="detail-fact-label">Payment</div>
              <div className="detail-fact-value">{formatMoney(order.total_amount, order.currency)}</div>
              {order.payment_method && <div className="detail-muted">{order.payment_method}</div>}
              <div className="order-card-pills">
                <StatusPill status={order.payment_status} />
                <StatusPill status={fulfillment?.fulfillment_status ?? order.fulfillment_status} />
              </div>
            </div>
          </div>

          <section className="order-section">
            <h4 className="detail-section-title">Fulfillment</h4>
            {notice && <Alert type="success" message={notice} className="page-banner" />}
            {fulfillment ? <OrderWorkflow order={fulfillment} onChanged={handleChanged} /> : <div className="detail-muted">Loading order status...</div>}
          </section>

          <OrderHoldSection order={order} status={status} canManage={can(user, 'orders.manage')} onChanged={handleControlChanged} />
          {can(user, 'orders.manage') && <OrderCustomStatusSection order={order} statuses={statuses} onChanged={handleControlChanged} />}

          <section className="order-section">
            <h4 className="detail-section-title">Items ({items.length})</h4>
            <div className="table-responsive-wrapper">
              <table className="detail-lines">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th style={{ textAlign: 'center' }}>Qty</th>
                    <th style={{ textAlign: 'right' }}>Unit Price</th>
                    <th style={{ textAlign: 'right' }}>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {items.length === 0 && (
                    <tr>
                      <td colSpan={4} className="detail-muted">No line items were imported for this order.</td>
                    </tr>
                  )}
                  {items.map((item) => {
                    const variant = variantNote(item.product_name, item.variant);
                    return (
                      <tr key={item.id}>
                        <td>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                            <ProductThumb src={item.image_url ?? item.variant?.product?.image_url} size={44} />
                            <div style={{ minWidth: 0 }}>
                              <div style={{ fontWeight: 600 }}>
                                {item.variant?.product ? (
                                  <Link to={`/products/${item.variant.product.id}`} className="pd-link" onClick={onClose}>
                                    {item.product_name}
                                  </Link>
                                ) : (
                                  item.product_name
                                )}
                              </div>
                              {variant && <span className="detail-variant-chip">{variant}</span>}
                              <div className="detail-muted">SKU: {item.external_sku || item.variant?.sku || '—'}</div>
                            </div>
                          </div>
                        </td>
                        <td style={{ textAlign: 'center' }}>{item.quantity}</td>
                        <td style={{ textAlign: 'right' }}>{formatMoney(item.unit_price, order.currency)}</td>
                        <td style={{ textAlign: 'right', fontWeight: 600 }}>
                          {formatMoney(item.total_amount ?? Number(item.unit_price) * item.quantity, order.currency)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot>
                  {totals.map((row) => (
                    <tr key={row.label}>
                      <td colSpan={3} style={{ textAlign: 'right', color: '#64748b', borderBottom: 'none' }}>{row.label}</td>
                      <td style={{ textAlign: 'right', borderBottom: 'none' }}>
                        {row.negative ? '−' : ''}
                        {formatMoney(Math.abs(Number(row.value)), order.currency)}
                      </td>
                    </tr>
                  ))}
                  <tr>
                    <td colSpan={3} style={{ textAlign: 'right', fontWeight: 700, borderBottom: 'none' }}>Order Total</td>
                    <td style={{ textAlign: 'right', fontWeight: 700, borderBottom: 'none' }}>{formatMoney(order.total_amount, order.currency)}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </section>

          {can(user, 'orders.manage') && canSplitOrMerge(order, status) && <OrderSplitSection order={order} onChanged={handleControlChanged} />}

          <section className="order-section">
            <h4 className="detail-section-title">Labels</h4>
            <div className="order-labels">
              <div className="order-label-card">
                <span className="order-label-card-title">Order</span>
                <BarcodeLabel
                  value={order.order_number}
                  details={[order.customer_name, `${order.currency ?? ''} ${Number(order.total_amount).toFixed(2)}`, `${items.length} item(s)`]}
                  caption={order.customer_name}
                  printable
                  compact
                />
              </div>
              {fulfillment?.shipment?.tracking_number && (
                <div className="order-label-card">
                  <span className="order-label-card-title">Shipping · {fulfillment.shipment.carrier}</span>
                  <BarcodeLabel
                    value={fulfillment.shipment.tracking_number}
                    details={[fulfillment.shipment.carrier, `Order ${order.order_number}`, order.customer_name]}
                    caption="Tracking ID"
                    printable
                    compact
                  />
                </div>
              )}
            </div>
          </section>

          {details.length > 0 && (
            <section className="order-section">
              <h4 className="detail-section-title">More from {order.store?.name ?? 'the store'}</h4>
              <dl className="pd-facts" style={{ borderTop: 'none', paddingTop: 0 }}>
                {details.map(([label, value]) => (
                  <div key={label}>
                    <dt>{label}</dt>
                    <dd>
                      <DetailValue value={value} />
                    </dd>
                  </div>
                ))}
              </dl>
            </section>
          )}

          {can(user, 'orders.manage') && status !== 'cancelled' && <OrderRepeatSection order={order} onChanged={handleControlChanged} />}

          <OrderTimeline orderId={order.id} refreshKey={historyKey} />

          {order.notes && (
            <section className="order-section">
              <h4 className="detail-section-title">Customer Note</h4>
              <div style={{ fontSize: 13, color: '#334155', lineHeight: 1.5 }}>{order.notes}</div>
            </section>
          )}
        </div>
      </div>

      {dialog}
    </div>
  );
};

export default OrderDetailModal;
