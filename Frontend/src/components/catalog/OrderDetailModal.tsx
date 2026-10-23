import React, { useCallback, useEffect, useState } from 'react';
import { Download, Loader2, X } from 'lucide-react';
import omsService from '../../services/omsService';
import type { OrderFulfillment, OrderModel } from '../../services/omsService';
import { platformLabel } from '../stores/storePlatforms';
import ProductThumb from './ProductThumb';
import StatusPill from '../common/StatusPill';
import { addressLines, formatMoney, variantNote } from '../../utils/format';
import { BarcodeLabel } from '../barcode/BarcodeLabel';
import { Alert } from '../common/Alert';
import OrderWorkflow from './OrderWorkflow';
import './DetailModal.css';

interface OrderDetailModalProps {
  order: OrderModel;
  onClose: () => void;
  /** Called after the order's status changes here, so the list behind can refresh. */
  onChanged?: () => void;
}

export const OrderDetailModal: React.FC<OrderDetailModalProps> = ({ order, onClose, onChanged }) => {
  const items = order.items ?? [];
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fulfillment, setFulfillment] = useState<OrderFulfillment | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

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
    await loadFulfillment();
    onChanged?.();
  };

  const status = fulfillment?.order_status ?? order.order_status;

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
            </div>
            <div className="detail-muted" style={{ marginTop: 2 }}>
              {order.store ? `${order.store.name} · ${platformLabel(order.store.platform)}` : 'Store order'}
              {order.placed_at ? ` · Placed ${new Date(order.placed_at).toLocaleString()}` : ''}
            </div>
          </div>
          <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
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
            </div>
            <div className="order-card">
              <div className="detail-fact-label">Payment</div>
              <div className="detail-fact-value">{formatMoney(order.total_amount, order.currency)}</div>
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
                            <ProductThumb src={item.variant?.product?.image_url} size={44} />
                            <div style={{ minWidth: 0 }}>
                              <div style={{ fontWeight: 600 }}>{item.product_name}</div>
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
                  <tr>
                    <td colSpan={3} style={{ textAlign: 'right', fontWeight: 700, borderBottom: 'none' }}>Order Total</td>
                    <td style={{ textAlign: 'right', fontWeight: 700, borderBottom: 'none' }}>{formatMoney(order.total_amount, order.currency)}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </section>

          <section className="order-section">
            <h4 className="detail-section-title">Labels</h4>
            <div className="order-labels">
              <div className="order-label-card">
                <span className="order-label-card-title">Order</span>
                <BarcodeLabel value={order.order_number} caption={order.customer_name} printable compact />
              </div>
              {fulfillment?.shipment?.tracking_number && (
                <div className="order-label-card">
                  <span className="order-label-card-title">Shipping · {fulfillment.shipment.carrier}</span>
                  <BarcodeLabel value={fulfillment.shipment.tracking_number} caption="Tracking ID" printable compact />
                </div>
              )}
            </div>
          </section>

          {order.notes && (
            <section className="order-section">
              <h4 className="detail-section-title">Customer Note</h4>
              <div style={{ fontSize: 13, color: '#334155', lineHeight: 1.5 }}>{order.notes}</div>
            </section>
          )}
        </div>
      </div>
    </div>
  );
};

export default OrderDetailModal;
