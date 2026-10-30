import React, { useState } from 'react';
import { CheckCircle2, Loader2, Plus, Send, Trash2 } from 'lucide-react';
import { Alert } from '../common/Alert';
import { useConfirmDialog } from '../common/ConfirmDialog';
import omsService from '../../services/omsService';
import { errorMessage } from '../../utils/errors';

interface StockLine {
  sku: string;
  location_code: string;
  quantity: number;
  lot_number: string;
  expiry_date: string;
}

interface PreviewResult {
  can_commit: boolean;
  total_rows: number;
  valid_rows: number;
  total_units: number;
  items: { row: number; product_name: string | null; variant_title: string | null; is_valid: boolean; validation_errors: string[] }[];
}

const emptyLine = (): StockLine => ({ sku: '', location_code: '', quantity: 1, lot_number: '', expiry_date: '' });

interface Props {
  warehouseId: number;
  onChanged: () => void;
}

/**
 * Opening stock tab: load the stock already on the shelves when the warehouse goes live. Lines are checked
 * first (Preview), then committed to the inventory ledger in one go.
 */
export const WarehouseOpeningStockTab: React.FC<Props> = ({ warehouseId, onChanged }) => {
  const { confirm, dialog } = useConfirmDialog();
  const [lines, setLines] = useState<StockLine[]>([emptyLine()]);
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const update = (index: number, field: keyof StockLine, value: string | number) => {
    setLines((prev) => prev.map((line, i) => (i === index ? { ...line, [field]: value } : line)));
    // Any change makes the last check out of date.
    setPreview(null);
  };

  const payload = () =>
    lines.map((line) => ({
      ...line,
      lot_number: line.lot_number || undefined,
      expiry_date: line.expiry_date || undefined,
    }));

  const check = async () => {
    try {
      setBusy(true);
      setMessage(null);
      const res = await omsService.previewOpeningStock(warehouseId, payload());
      setPreview(res.data);
      setMessage(
        res.data.can_commit
          ? { type: 'success', text: `All ${res.data.total_rows} lines are valid (${res.data.total_units} units). You can commit them now.` }
          : { type: 'error', text: 'Some lines need fixing; see the messages under them.' }
      );
    } catch (err) {
      setPreview(null);
      setMessage({ type: 'error', text: errorMessage(err, 'The lines could not be checked.') });
    } finally {
      setBusy(false);
    }
  };

  const commit = async () => {
    const ok = await confirm({
      title: 'Commit opening stock?',
      message: `${preview?.total_units ?? 0} units are added to this warehouse and recorded in the stock ledger.`,
      note: 'This cannot be undone; later corrections need a stock adjustment.',
      confirmLabel: 'Commit Stock',
      tone: 'primary',
    });
    if (!ok) return;
    try {
      setBusy(true);
      setMessage(null);
      await omsService.commitOpeningStock(warehouseId, { items: payload(), idempotency_key: crypto.randomUUID() });
      setMessage({ type: 'success', text: 'Opening stock was added to the warehouse.' });
      setLines([emptyLine()]);
      setPreview(null);
      onChanged();
    } catch (err) {
      setMessage({ type: 'error', text: errorMessage(err, 'Failed to commit the opening stock.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="wh-stack">
      {message && <Alert type={message.type} message={message.text} />}

      <section className="wh-card" aria-labelledby="wh-opening-title">
        <div className="wh-card-head">
          <div>
            <h2 id="wh-opening-title" className="wh-card-title">Controlled Opening Stock</h2>
            <p className="wh-card-subtitle">Enter the stock already on the shelves. Preview checks every line; Commit records them in the inventory ledger.</p>
          </div>
        </div>

        <div className="wh-table-wrap">
          <table className="wh-table">
            <thead>
              <tr>
                <th>SKU *</th>
                <th>Location code *</th>
                <th>Quantity *</th>
                <th>Lot number</th>
                <th>Expiry date</th>
                <th aria-label="Remove line" />
              </tr>
            </thead>
            <tbody>
              {lines.map((line, index) => {
                const result = preview?.items[index];
                return (
                  <tr key={index} className={result && !result.is_valid ? 'wh-row-error' : undefined}>
                    <td style={{ minWidth: 170 }}>
                      <input className="wh-input" aria-label={`SKU, line ${index + 1}`} value={line.sku} onChange={(e) => update(index, 'sku', e.target.value)} placeholder="e.g. MUG-WHT" />
                      {result?.is_valid && result.product_name && (
                        <span style={{ display: 'block', marginTop: 4, fontSize: 11, color: '#15803d', whiteSpace: 'normal' }}>
                          {result.product_name}
                          {result.variant_title ? ` · ${result.variant_title}` : ''}
                        </span>
                      )}
                      {result && !result.is_valid && result.validation_errors.map((text) => <span key={text} className="wh-error-text">{text}</span>)}
                    </td>
                    <td style={{ minWidth: 150 }}>
                      <input className="wh-input" aria-label={`Location code, line ${index + 1}`} value={line.location_code} onChange={(e) => update(index, 'location_code', e.target.value.toUpperCase())} placeholder="e.g. A01-R01-S1-B01" />
                    </td>
                    <td style={{ minWidth: 100 }}>
                      <input className="wh-input" type="number" min={1} aria-label={`Quantity, line ${index + 1}`} value={line.quantity} onChange={(e) => update(index, 'quantity', Number(e.target.value))} />
                    </td>
                    <td style={{ minWidth: 130 }}>
                      <input className="wh-input" aria-label={`Lot number, line ${index + 1}`} value={line.lot_number} onChange={(e) => update(index, 'lot_number', e.target.value)} placeholder="Optional" />
                    </td>
                    <td style={{ minWidth: 150 }}>
                      <input className="wh-input" type="date" aria-label={`Expiry date, line ${index + 1}`} value={line.expiry_date} onChange={(e) => update(index, 'expiry_date', e.target.value)} />
                    </td>
                    <td>
                      <button
                        type="button"
                        className="table-action-btn is-danger"
                        onClick={() => {
                          setLines((prev) => prev.filter((_, i) => i !== index));
                          setPreview(null);
                        }}
                        disabled={lines.length === 1 || busy}
                        aria-label={`Remove line ${index + 1}`}
                      >
                        <Trash2 size={15} />
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        <div className="wh-footer-actions">
          <button type="button" className="btn-finovo-secondary" onClick={() => setLines((prev) => [...prev, emptyLine()])} disabled={busy}>
            <Plus size={14} />
            <span>Add line</span>
          </button>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button type="button" className="btn-finovo-secondary" onClick={check} disabled={busy}>
              {busy ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle2 size={14} />}
              <span>Preview</span>
            </button>
            <button type="button" className="btn-finovo-primary" onClick={commit} disabled={busy || !preview?.can_commit} title={preview?.can_commit ? undefined : 'Preview the lines first'}>
              <Send size={14} />
              <span>Commit Opening Stock</span>
            </button>
          </div>
        </div>
      </section>

      {dialog}
    </div>
  );
};

export default WarehouseOpeningStockTab;
