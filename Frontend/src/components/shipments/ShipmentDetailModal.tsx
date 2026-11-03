import React, { useEffect, useState } from 'react';
import { Loader2, X } from 'lucide-react';
import StatusPill from '../common/StatusPill';
import type { RowAction } from '../common/RowActionsMenu';
import omsService, { SHIPMENT_STATUS_LABELS } from '../../services/omsService';
import type { ShipmentDetail, ShipmentModel } from '../../services/omsService';
import { addressLines, formatDate, formatMoney } from '../../utils/format';
import { errorMessage } from '../../utils/errors';
import '../catalog/DetailModal.css';

interface ShipmentDetailModalProps {
  shipmentId: number;
  /** Changes after an action on the list, so the open shipment reloads. */
  refreshKey: number;
  /** The same actions as the shipment's row menu. */
  actions: (shipment: ShipmentModel) => RowAction[];
  onClose: () => void;
}

const formatWeight = (kg: number | null) => (kg === null ? null : `${Number(kg)} kg`);

/** One shipment: customer, address, parcel, order lines and the timeline of what happened to it. */
export const ShipmentDetailModal: React.FC<ShipmentDetailModalProps> = ({ shipmentId, refreshKey, actions, onClose }) => {
  const [shipment, setShipment] = useState<ShipmentDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    omsService
      .getShipment(shipmentId)
      .then((data) => active && setShipment(data))
      .catch((err) => active && setError(errorMessage(err, 'The shipment could not be loaded.')));
    return () => {
      active = false;
    };
  }, [shipmentId, refreshKey]);

  const address = shipment ? addressLines(shipment.shipping_address).join(', ') : '';
  const parcel = shipment
    ? [`${shipment.pieces} piece(s)`, formatWeight(shipment.weight_kg), shipment.dimensions_cm && `${shipment.dimensions_cm.join(' × ')} cm`].filter(Boolean).join(' · ')
    : '';

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-dialog detail-modal order-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label="Shipment">
        <div className="detail-modal-head">
          <div style={{ minWidth: 0 }}>
            <div className="order-modal-title">
              <h3>Shipment for Order {shipment?.order_number ?? ''}</h3>
              {shipment && <StatusPill status={shipment.status} label={SHIPMENT_STATUS_LABELS[shipment.status]} />}
            </div>
            {shipment && (
              <div className="detail-muted" style={{ marginTop: 2 }}>
                {[shipment.store, shipment.warehouse, shipment.packed_at && `Packed ${formatDate(shipment.packed_at, true)}`].filter(Boolean).join(' · ')}
              </div>
            )}
          </div>
          <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
            {shipment &&
              actions(shipment).map(({ label, icon: Icon, onSelect, danger }) => (
                <button key={label} type="button" onClick={onSelect} className="btn-finovo-secondary" style={danger ? { color: '#dc2626' } : undefined}>
                  <Icon size={14} />
                  <span>{label}</span>
                </button>
              ))}
            <button type="button" onClick={onClose} className="btn-finovo-secondary" style={{ padding: 6 }} aria-label="Close">
              <X size={18} />
            </button>
          </div>
        </div>

        <div className="detail-modal-body order-modal-body">
          {error && <div className="detail-error" style={{ marginBottom: 0 }}>{error}</div>}
          {!shipment && !error && (
            <div className="detail-muted" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Loader2 size={14} className="animate-spin" /> Loading shipment...
            </div>
          )}

          {shipment && (
            <>
              <div className="order-cards">
                <div className="order-card">
                  <div className="detail-fact-label">Customer</div>
                  <div className="detail-fact-value">{shipment.customer}</div>
                  {shipment.customer_phone && <div className="detail-muted">{shipment.customer_phone}</div>}
                  <div className="detail-muted">{shipment.customer_email || 'No email'}</div>
                </div>
                <div className="order-card">
                  <div className="detail-fact-label">Ship To</div>
                  <div className="detail-fact-value">{address || 'Not provided'}</div>
                </div>
                <div className="order-card">
                  <div className="detail-fact-label">Parcel</div>
                  <div className="detail-fact-value">{shipment.carrier ?? 'Not dispatched yet'}</div>
                  {shipment.tracking_number &&
                    (shipment.tracking_url ? (
                      <a href={shipment.tracking_url} target="_blank" rel="noopener noreferrer" className="shipment-tracking-link">
                        {shipment.tracking_number}
                      </a>
                    ) : (
                      <div className="detail-muted">{shipment.tracking_number}</div>
                    ))}
                  <div className="detail-muted">{parcel}</div>
                  <div className="detail-muted">
                    {shipment.cod_amount > 0 ? `COD ${formatMoney(shipment.cod_amount, shipment.currency ?? 'PKR')}` : 'Paid, nothing to collect'}
                  </div>
                </div>
              </div>

              {shipment.courier_status_text && (
                <div className={`shipment-courier-banner${shipment.courier_status === 'delivery_failed' || shipment.courier_status === 'cancelled' ? ' is-problem' : ''}`}>
                  Courier: <strong>{shipment.courier_status_text}</strong>
                  {shipment.courier_status_at ? ` · ${formatDate(shipment.courier_status_at, true)}` : ''}
                </div>
              )}

              <section className="order-section">
                <h4 className="detail-section-title">Items ({shipment.items.length})</h4>
                <div className="table-responsive-wrapper">
                  <table className="detail-lines">
                    <thead>
                      <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th style={{ textAlign: 'center' }}>Qty</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {shipment.items.map((item) => (
                        <tr key={item.id}>
                          <td>{item.product_name}</td>
                          <td className="detail-muted">{item.sku ?? '—'}</td>
                          <td style={{ textAlign: 'center', fontWeight: 600 }}>{item.quantity}</td>
                          <td>
                            <StatusPill status={item.status} />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {shipment.notes && <p className="detail-muted" style={{ margin: '12px 0 0' }}>Order note: {shipment.notes}</p>}
              </section>

              <section className="order-section">
                <h4 className="detail-section-title">Timeline</h4>
                {shipment.events.length === 0 ? (
                  <div className="detail-muted">Packed and waiting for dispatch.</div>
                ) : (
                  <ol className="shipment-timeline">
                    {[...shipment.events].reverse().map((event) => (
                      <li key={event.id} className={`is-${event.type}`}>
                        <div className="shipment-timeline-text">{event.description}</div>
                        <div className="detail-muted">
                          {formatDate(event.happened_at, true)}
                          {event.user ? ` · ${event.user}` : event.type === 'courier' ? ' · Courier update' : ''}
                        </div>
                      </li>
                    ))}
                  </ol>
                )}
              </section>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default ShipmentDetailModal;
