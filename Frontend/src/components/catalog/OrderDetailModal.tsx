import React, { useState } from 'react';
import { Download, Loader2, X } from 'lucide-react';
import omsService from '../../services/omsService';
import type { OrderModel } from '../../services/omsService';
import { platformLabel } from '../stores/storePlatforms';
import ProductThumb from './ProductThumb';
import StatusPill from '../common/StatusPill';
import { addressLines, formatMoney, variantNote } from '../../utils/format';
import './DetailModal.css';

export const OrderDetailModal: React.FC<{ order: OrderModel; onClose: () => void }> = ({ order, onClose }) => {
  const items = order.items ?? [];
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
      <div className="modal-dialog detail-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label={`Order ${order.order_number}`}>
        <div className="detail-modal-head">
          <div>
            <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700 }}>Order {order.order_number}</h3>
            <div className="detail-muted">
              {order.store ? `${order.store.name} · ${platformLabel(order.store.platform)}` : 'Manual order'}
              {order.placed_at ? ` · ${new Date(order.placed_at).toLocaleString()}` : ''}
            </div>
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            {order.order_status !== 'cancelled' && (
              <button onClick={downloadInvoice} className="btn-finovo-primary" disabled={downloading}>
                {downloading ? <Loader2 size={14} className="animate-spin" /> : <Download size={14} />}
                <span>{order.invoice_number ? `Invoice ${order.invoice_number}` : 'Download Invoice'}</span>
              </button>
            )}
            <button onClick={onClose} className="btn-finovo-secondary" style={{ padding: 6 }} aria-label="Close">
              <X size={18} />
            </button>
          </div>
        </div>

        <div className="detail-modal-body">
          {error && <div className="detail-error">{error}</div>}
          <div className="detail-modal-facts">
            <div>
              <div className="detail-fact-label">Customer</div>
              <div className="detail-fact-value">{order.customer_name}</div>
              <div className="detail-muted">{order.customer_email || 'No email'}</div>
              {order.customer_phone && <div className="detail-muted">{order.customer_phone}</div>}
            </div>
            <div>
              <div className="detail-fact-label">Ship To</div>
              <div className="detail-fact-value">{addressText || 'Not provided'}</div>
            </div>
            <div>
              <div className="detail-fact-label">Status</div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                <StatusPill status={order.order_status} />
                <StatusPill status={order.payment_status} />
                <StatusPill status={order.fulfillment_status} />
              </div>
            </div>
          </div>

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

          {order.notes && (
            <div style={{ marginTop: 16 }}>
              <div className="detail-fact-label">Customer Note</div>
              <div style={{ fontSize: 13, color: '#334155' }}>{order.notes}</div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default OrderDetailModal;
