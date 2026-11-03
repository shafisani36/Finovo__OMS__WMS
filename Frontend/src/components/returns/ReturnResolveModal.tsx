import React, { useState } from 'react';
import { Loader2, X } from 'lucide-react';
import omsService from '../../services/omsService';
import type { ReturnModel } from '../../services/omsService';
import { Alert } from '../common/Alert';
import { errorMessage } from '../../utils/errors';

interface ReturnResolveModalProps {
  ret: ReturnModel;
  onClose: () => void;
  onResolved: (message: string) => void;
}

/** Settle a completed return once: money back, or the same items sent again on a free replacement order. */
export const ReturnResolveModal: React.FC<ReturnResolveModalProps> = ({ ret, onClose, onResolved }) => {
  const paid = ret.payment_status === 'paid' || ret.payment_status === 'partially_refunded';
  const [resolution, setResolution] = useState<'refund' | 'replacement'>(paid ? 'refund' : 'replacement');
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const payload = resolution === 'refund' ? { resolution, amount: Number(amount), method: method.trim() || undefined } : { resolution };
      onResolved((await omsService.resolveReturn(ret.id, payload)).message);
    } catch (err) {
      setError(errorMessage(err, 'The return could not be settled.'));
      setBusy(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={() => !busy && onClose()}>
      <form className="procurement-modal" style={{ maxWidth: 480 }} onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Settle {ret.return_number}</h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>{ret.items.map((item) => `${item.quantity} × ${item.product_name}`).join(', ')}</p>
          </div>
          <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close" disabled={busy}>
            <X size={18} />
          </button>
        </div>
        <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {error && <Alert type="error" message={error} />}
          <div className="return-disposition-grid" role="radiogroup">
            <button type="button" role="radio" aria-checked={resolution === 'refund'} disabled={!paid} className={`return-disposition-option ${resolution === 'refund' ? 'is-selected' : ''}`} onClick={() => setResolution('refund')}>
              <strong>Refund</strong>
              <span>{paid ? 'Record money paid back to the customer.' : 'The order was not paid.'}</span>
            </button>
            <button type="button" role="radio" aria-checked={resolution === 'replacement'} className={`return-disposition-option ${resolution === 'replacement' ? 'is-selected' : ''}`} onClick={() => setResolution('replacement')}>
              <strong>Replacement</strong>
              <span>Send the same items again on a free order.</span>
            </button>
          </div>
          {resolution === 'refund' && (
            <>
              <label className="form-label">
                Amount ({ret.currency}) *
                <input className="form-input" type="number" min={0.01} step="0.01" required value={amount} onChange={(e) => setAmount(e.target.value)} style={{ width: '100%' }} />
              </label>
              <label className="form-label">
                Paid back by
                <input className="form-input" placeholder="e.g. Bank transfer, JazzCash" maxLength={100} value={method} onChange={(e) => setMethod(e.target.value)} style={{ width: '100%' }} />
              </label>
            </>
          )}
        </div>
        <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" onClick={onClose} className="btn-outline" disabled={busy}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={busy}>
              {busy && <Loader2 size={16} className="animate-spin" />}
              <span>{resolution === 'refund' ? 'Record Refund' : 'Create Replacement'}</span>
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

export default ReturnResolveModal;
