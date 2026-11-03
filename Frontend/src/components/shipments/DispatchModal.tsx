import React, { useState } from 'react';
import { CheckCircle2, Loader2, Send, X, XCircle } from 'lucide-react';
import CustomDropdown from '../common/CustomDropdown';
import { Alert } from '../common/Alert';
import omsService from '../../services/omsService';
import type { BulkDispatchResult, DispatchPayload, ShipmentCourier, ShipmentModel } from '../../services/omsService';
import { errorMessage } from '../../utils/errors';

interface DispatchModalProps {
  /** One shipment, or several packed orders to send with the same carrier. */
  shipments: ShipmentModel[];
  accounts: ShipmentCourier[];
  carriers: string[];
  onClose: () => void;
  /** Called once orders were dispatched, with the message to show on the list. */
  onDispatched: (message: string) => void;
}

/** Value of the "Send with" choice that means: enter the carrier and tracking details by hand. */
const MANUAL = 'manual';
const OTHER = 'Other';

const optionalNumber = (value: string): number | undefined => (value.trim() === '' ? undefined : Number(value));

/**
 * Dispatch popup: book the parcel with a courier account or name the carrier, with the parcel's weight, pieces
 * and (for one order) the cash to collect. For several orders, each order's result is listed.
 */
export const DispatchModal: React.FC<DispatchModalProps> = ({ shipments, accounts, carriers, onClose, onDispatched }) => {
  const single = shipments.length === 1 ? shipments[0] : null;
  const [sendWith, setSendWith] = useState(accounts[0] ? String(accounts[0].id) : MANUAL);
  const [carrier, setCarrier] = useState(carriers[0] ?? OTHER);
  const [otherCarrier, setOtherCarrier] = useState('');
  const [trackingNumber, setTrackingNumber] = useState('');
  const [trackingUrl, setTrackingUrl] = useState('');
  const [weight, setWeight] = useState('');
  const [pieces, setPieces] = useState('1');
  // Length, width and height in cm from the packing station (all three or none).
  const [size, setSize] = useState({ length: '', width: '', height: '' });
  const [cod, setCod] = useState(single ? String(single.cod_amount) : '');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [results, setResults] = useState<{ message: string; rows: BulkDispatchResult[] } | null>(null);

  const account = accounts.find((a) => String(a.id) === sendWith);
  const close = () => {
    if (submitting) return;
    if (results) onDispatched(results.message);
    else onClose();
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const carrierName = carrier === OTHER ? otherCarrier.trim() : carrier;
    if (!account && !carrierName) {
      setError('Choose the carrier to dispatch with.');
      return;
    }

    const payload: DispatchPayload = {
      ...(account ? { courier_account_id: account.id } : { carrier: carrierName }),
      weight_kg: optionalNumber(weight),
      pieces: optionalNumber(pieces),
      length_cm: optionalNumber(size.length),
      width_cm: optionalNumber(size.width),
      height_cm: optionalNumber(size.height),
    };

    try {
      setSubmitting(true);
      setError(null);
      if (single) {
        const res = await omsService.dispatchShipment(single.id, {
          ...payload,
          cod_amount: optionalNumber(cod),
          ...(account ? {} : { tracking_number: trackingNumber.trim() || undefined, tracking_url: trackingUrl.trim() || undefined }),
        });
        onDispatched(res.message);
        return;
      }
      const res = await omsService.dispatchShipments(
        shipments.map((s) => s.id),
        payload,
      );
      if (res.data.every((row) => row.dispatched)) onDispatched(res.message);
      else setResults({ message: res.message, rows: res.data });
    } catch (err) {
      setError(errorMessage(err, 'The orders could not be dispatched.'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={close}>
      <form className="procurement-modal" style={{ maxWidth: 540 }} onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
              {single ? `Dispatch Order ${single.order_number}` : `Dispatch ${shipments.length} Orders`}
            </h3>
            <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
              {single
                ? `${single.items_count} item(s) for ${single.customer}${single.warehouse ? ` from ${single.warehouse}` : ''}`
                : 'All selected orders go with the same carrier. Each one gets its own tracking ID.'}
            </p>
          </div>
          <button type="button" onClick={close} className="topbar-icon-button" aria-label="Close">
            <X size={18} />
          </button>
        </div>

        {results ? (
          <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            <Alert type="error" message={results.message} />
            <ul className="dispatch-results">
              {results.rows.map((row) => (
                <li key={row.id} className={row.dispatched ? 'is-done' : 'is-failed'}>
                  {row.dispatched ? <CheckCircle2 size={16} /> : <XCircle size={16} />}
                  <div>
                    <strong>{row.order_number ?? `Shipment ${row.id}`}</strong>
                    <span>{row.message}</span>
                  </div>
                </li>
              ))}
            </ul>
          </div>
        ) : (
          <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {error && <Alert type="error" message={error} />}

            {accounts.length > 0 && (
              <div>
                <label className="form-label">Send with *</label>
                <CustomDropdown
                  value={sendWith}
                  onChange={(val) => setSendWith(String(val))}
                  options={[
                    ...accounts.map((a) => ({ value: String(a.id), label: `Book with ${a.name}`, description: `${a.carrier_name}: tracking number and label come from the courier` })),
                    { value: MANUAL, label: 'Enter carrier details myself', description: 'For carriers without a courier account' },
                  ]}
                  searchable={false}
                />
              </div>
            )}

            {!account && (
              <div>
                <label className="form-label">Carrier *</label>
                <CustomDropdown value={carrier} onChange={(val) => setCarrier(String(val))} options={[...carriers, OTHER].map((c) => ({ value: c, label: c }))} />
              </div>
            )}

            {!account && carrier === OTHER && (
              <div>
                <label className="form-label">Carrier Name *</label>
                <input type="text" className="form-input" value={otherCarrier} onChange={(e) => setOtherCarrier(e.target.value)} placeholder="e.g. Local Rider" maxLength={100} />
              </div>
            )}

            {single && !account && (
              <>
                <div>
                  <label className="form-label">Tracking ID</label>
                  <input
                    type="text"
                    className="form-input"
                    value={trackingNumber}
                    onChange={(e) => setTrackingNumber(e.target.value)}
                    placeholder="Leave empty to generate a unique tracking ID"
                    maxLength={100}
                  />
                </div>
                <div>
                  <label className="form-label">Tracking Link</label>
                  <input
                    type="url"
                    className="form-input"
                    value={trackingUrl}
                    onChange={(e) => setTrackingUrl(e.target.value)}
                    placeholder="Optional; added automatically for carriers with a tracking page"
                    maxLength={500}
                  />
                </div>
              </>
            )}

            <div className="dispatch-parcel-grid">
              <div>
                <label className="form-label">Weight (kg)</label>
                <input
                  type="number"
                  className="form-input"
                  value={weight}
                  onChange={(e) => setWeight(e.target.value)}
                  placeholder={account ? `Default ${account.default_weight_kg}` : 'Optional'}
                  min={0.01}
                  max={1000}
                  step={0.01}
                />
              </div>
              <div>
                <label className="form-label">Pieces</label>
                <input type="number" className="form-input" value={pieces} onChange={(e) => setPieces(e.target.value)} min={1} max={99} step={1} />
              </div>
              {single && (
                <div>
                  <label className="form-label">COD ({single.currency ?? 'PKR'})</label>
                  <input type="number" className="form-input" value={cod} onChange={(e) => setCod(e.target.value)} min={0} step={1} />
                </div>
              )}
            </div>

            <div className="dispatch-parcel-grid">
              {(['length', 'width', 'height'] as const).map((side) => (
                <div key={side}>
                  <label className="form-label" style={{ textTransform: 'capitalize' }}>
                    {side} (cm)
                  </label>
                  <input
                    type="number"
                    className="form-input"
                    value={size[side]}
                    onChange={(e) => setSize({ ...size, [side]: e.target.value })}
                    placeholder="Optional"
                    min={1}
                    max={500}
                    step={0.1}
                    required={Object.values(size).some((value) => value !== '')}
                  />
                </div>
              ))}
            </div>

            <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
              {single ? 'COD is the cash the courier collects; 0 for a paid order. ' : 'COD is taken from each order (0 for paid orders). '}
              Dispatching removes the items from warehouse stock, marks the order as shipped and emails the customer the tracking details.
            </p>
          </div>
        )}

        <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
          {results ? (
            <button type="button" onClick={close} className="btn-primary">
              Done
            </button>
          ) : (
            <div style={{ display: 'flex', gap: 8 }}>
              <button type="button" onClick={close} className="btn-outline" disabled={submitting}>
                Cancel
              </button>
              <button type="submit" className="btn-primary" disabled={submitting}>
                {submitting ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
                <span>{single ? 'Dispatch Order' : `Dispatch ${shipments.length} Orders`}</span>
              </button>
            </div>
          )}
        </div>
      </form>
    </div>
  );
};

export default DispatchModal;
