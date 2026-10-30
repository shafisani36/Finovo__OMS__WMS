import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, MapPin, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { InventoryReceiptModel, PutawaySuggestion } from '../../services/omsService';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { errorMessage } from '../../utils/errors';


/**
 * Put received units away into a storage bin. The system suggests the bin (where the same stock already is,
 * otherwise the area that suits the product's ABC class); the user can take it, pick another suggestion or type
 * any bin code.
 */
export const PutawayModal: React.FC<{ receipt: InventoryReceiptModel; onClose: () => void; onDone: (message: string) => void }> = ({ receipt, onClose, onDone }) => {
  const items = receipt.items ?? [];
  const [itemId, setItemId] = useState<number | null>(items[0]?.id ?? null);
  const item = items.find((it) => it.id === itemId) ?? null;
  const maxQty = item ? item.received_quantity || item.expected_quantity : 1;
  const [quantity, setQuantity] = useState(maxQty);
  const [binCode, setBinCode] = useState('');
  // Once the user types or picks a bin, a new suggestion no longer overwrites it.
  const typed = useRef(false);
  const [suggestion, setSuggestion] = useState<PutawaySuggestion | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const debouncedQty = useDebouncedValue(quantity);

  useEffect(() => {
    setQuantity(maxQty);
    typed.current = false;
  }, [itemId, maxQty]);

  useEffect(() => {
    if (!item?.product_variant_id || debouncedQty < 1) return;
    let current = true;
    omsService
      .getPutawaySuggestion(receipt.warehouse_id, { product_variant_id: item.product_variant_id, quantity: debouncedQty, lot_number: item.lot_number ?? null })
      .then((result) => {
        if (!current) return;
        setSuggestion(result);
        if (!typed.current) setBinCode(result.location?.code ?? '');
      })
      .catch(() => current && setSuggestion(null));
    return () => {
      current = false;
    };
  }, [receipt.warehouse_id, item?.product_variant_id, item?.lot_number, debouncedQty]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!item) return;
    try {
      setSaving(true);
      setError(null);
      await omsService.putawayReceiptItem(receipt.id, { item_id: item.id, destination_location_code: binCode.trim(), quantity });
      onDone(`${quantity} units of ${item.sku} put away into ${binCode.trim()}.`);
    } catch (err) {
      setError(errorMessage(err, 'The putaway could not be saved.'));
    } finally {
      setSaving(false);
    }
  };

  return createPortal(
    <div className="modal-overlay" onClick={onClose}>
      <div className="procurement-modal" style={{ maxWidth: 520 }} onClick={(e) => e.stopPropagation()}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Put Away {receipt.receipt_number}</h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>Move received units from the dock into a storage bin.</p>
          </div>
          <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close">
            <X size={18} />
          </button>
        </div>

        <form id="putaway-form" onSubmit={submit} className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {error && <Alert type="error" message={error} />}

          <div>
            <label className="form-label">Item</label>
            <CustomDropdown
              value={itemId ? String(itemId) : null}
              onChange={(value) => setItemId(Number(value))}
              options={items.map((it) => ({ value: String(it.id), label: it.product_name, description: `${it.sku}${it.lot_number ? ` · Lot ${it.lot_number}` : ''}` }))}
              searchable={items.length > 6}
            />
          </div>

          <div>
            <label className="form-label">Quantity (up to {maxQty})</label>
            <input
              className="form-input"
              type="number"
              min={1}
              max={maxQty}
              value={quantity}
              onChange={(e) => setQuantity(Math.min(maxQty, Math.max(1, Number(e.target.value) || 1)))}
              required
            />
          </div>

          <div>
            <label className="form-label">Storage bin</label>
            <input
              className="form-input"
              value={binCode}
              onChange={(e) => {
                setBinCode(e.target.value);
                typed.current = true;
              }}
              placeholder="Scan or type the bin code"
              required
            />
          </div>

          {suggestion && (
            <div className="putaway-suggestion">
              <div className="putaway-suggestion-head">
                <MapPin size={14} />
                <strong>{suggestion.location ? `Suggested: ${suggestion.location.code}` : 'No bin suggested'}</strong>
                <span className="wh-badge">Class {suggestion.abc_class}</span>
              </div>
              <p>{suggestion.reason}</p>
              {(suggestion.location || suggestion.alternatives.length > 0) && (
                <div className="putaway-suggestion-bins">
                  {[suggestion.location, ...suggestion.alternatives]
                    .filter((bin) => bin !== null)
                    .map((bin) => (
                      <button
                        key={bin.id}
                        type="button"
                        className={`putaway-bin${bin.code === binCode.trim() ? ' is-selected' : ''}`}
                        onClick={() => {
                          setBinCode(bin.code);
                          typed.current = true;
                        }}
                      >
                        {bin.code}
                        <span>{bin.room === null ? bin.area_label : `${bin.room} free`}</span>
                      </button>
                    ))}
                </div>
              )}
            </div>
          )}
        </form>

        <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn-outline" onClick={onClose}>
              Cancel
            </button>
            <button type="submit" form="putaway-form" className="btn-primary" disabled={saving || !item}>
              {saving ? <Loader2 size={16} className="animate-spin" /> : null}
              Put Away
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
};

export default PutawayModal;
