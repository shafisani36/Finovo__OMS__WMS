import React, { useCallback, useEffect, useState } from 'react';
import { Loader2, X } from 'lucide-react';
import omsService, { parseSerials } from '../../services/omsService';
import type { OrderSerialLine } from '../../services/omsService';
import { Alert } from '../common/Alert';
import { errorMessage } from '../../utils/errors';

/**
 * Serial numbers of the order's serial-tracked lines: one unit scanned per unit ordered, from the stock of the
 * line's warehouse. The order cannot be dispatched until every unit has one. Nothing shows for orders without
 * such products.
 */
export const OrderSerialsSection: React.FC<{ orderId: number; canScan: boolean; onChanged: (message: string) => void }> = ({ orderId, canScan, onChanged }) => {
  const [lines, setLines] = useState<OrderSerialLine[]>([]);
  const [inputs, setInputs] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    omsService
      .getOrderSerials(orderId)
      .then(setLines)
      .catch(() => setLines([]));
  }, [orderId]);

  useEffect(() => {
    load();
  }, [load]);

  const act = async (key: number, task: () => Promise<{ message: string }>) => {
    setBusy(key);
    setError(null);
    try {
      const res = await task();
      load();
      onChanged(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The serial numbers could not be saved.'));
    } finally {
      setBusy(null);
    }
  };

  const assign = (line: OrderSerialLine) => {
    const serials = parseSerials(inputs[line.item_id] ?? '');
    if (serials.length === 0) return;
    act(line.item_id, async () => {
      const res = await omsService.assignOrderSerials(orderId, line.item_id, serials);
      setInputs((current) => ({ ...current, [line.item_id]: '' }));
      return res;
    });
  };

  if (lines.length === 0) return null;

  return (
    <section className="order-section">
      <h4 className="detail-section-title">Serial Numbers</h4>
      {error && <Alert type="error" message={error} />}
      {lines.map((line) => {
        const editable = canScan && !['shipped', 'cancelled'].includes(line.status);
        return (
          <div key={line.item_id} style={{ marginBottom: 12 }}>
            <div style={{ fontWeight: 600, fontSize: 13 }}>
              {line.product_name} ({line.sku}) — {line.serials.length} of {line.quantity} scanned
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, margin: '6px 0' }}>
              {line.serials.map((unit) => (
                <span key={unit.id} className="status-pill status-pill-grey" style={{ fontFamily: 'monospace', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                  {unit.serial_number}
                  {editable && (
                    <button
                      type="button"
                      aria-label={`Take ${unit.serial_number} off the order`}
                      style={{ border: 0, background: 'none', cursor: 'pointer', padding: 0, display: 'inline-flex' }}
                      disabled={busy !== null}
                      onClick={() => act(unit.id, () => omsService.unassignOrderSerial(orderId, unit.id))}
                    >
                      <X size={12} />
                    </button>
                  )}
                </span>
              ))}
            </div>
            {editable && line.serials.length < line.quantity && (
              <div style={{ display: 'flex', gap: 8 }}>
                <input
                  className="cf-input"
                  value={inputs[line.item_id] ?? ''}
                  placeholder="Scan serial numbers (Enter to save)"
                  aria-label={`Serial numbers for ${line.sku}`}
                  onChange={(e) => setInputs((current) => ({ ...current, [line.item_id]: e.target.value }))}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      assign(line);
                    }
                  }}
                />
                <button type="button" className="btn-finovo-secondary" disabled={busy !== null} onClick={() => assign(line)}>
                  {busy === line.item_id ? <Loader2 size={14} className="animate-spin" /> : 'Save'}
                </button>
              </div>
            )}
          </div>
        );
      })}
    </section>
  );
};

export default OrderSerialsSection;
