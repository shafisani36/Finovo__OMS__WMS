import React, { useState } from 'react';
import { Download, Loader2, PackageCheck, PauseCircle, PlayCircle, Plus, Repeat, Tag, Upload, X, XCircle } from 'lucide-react';
import omsService, { RECURRING_INTERVAL_LABELS } from '../../services/omsService';
import type { BulkOrderAction, BulkOrderResult, CustomOrderStatus, CustomStatusColor, OrderModel, RecurringInterval } from '../../services/omsService';
import { ApiError } from '../../services/apiClient';
import { Alert } from '../common/Alert';
import StatusPill from '../common/StatusPill';
import { errorMessage } from '../../utils/errors';

const COLORS: CustomStatusColor[] = ['grey', 'blue', 'green', 'amber', 'red', 'purple'];

const today = () => new Date().toISOString().slice(0, 10);

/** The company status of an order, in its colour. */
export const CustomStatusPill: React.FC<{ value?: string | null; statuses: CustomOrderStatus[] }> = ({ value, statuses }) => {
  const status = statuses.find((s) => s.key === value);
  return status ? <StatusPill status={status.key} label={status.label} tone={status.color} /> : null;
};

/** Key from a label, e.g. "Awaiting stock" → "awaiting_stock" (kept unique in the list). */
const statusKey = (label: string, taken: string[]): string => {
  const slug = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 34).replace(/_$/, '');
  const base = slug ? `s_${slug}` : 's_status';
  let key = base;
  for (let n = 2; taken.includes(key); n++) key = `${base}_${n}`;
  return key;
};

interface OrderStatusesModalProps {
  statuses: CustomOrderStatus[];
  onClose: () => void;
  onSaved: (message: string) => void;
}

/** Edit the company's own order statuses (label and colour); a status still on orders cannot be removed. */
export const OrderStatusesModal: React.FC<OrderStatusesModalProps> = ({ statuses, onClose, onSaved }) => {
  const [rows, setRows] = useState<CustomOrderStatus[]>(statuses);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      onSaved((await omsService.saveCustomStatuses(rows.map((row) => ({ ...row, label: row.label.trim() })))).message);
    } catch (err) {
      setError(errorMessage(err, 'The statuses could not be saved.'));
      setSaving(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={() => !saving && onClose()}>
      <form className="procurement-modal cf-modal" style={{ maxWidth: 520 }} onClick={(e) => e.stopPropagation()} onSubmit={save}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Order Statuses</h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>Your own labels, shown next to the order's progress.</p>
          </div>
          <button type="button" className="topbar-icon-button" onClick={onClose} aria-label="Close" disabled={saving}>
            <X size={18} />
          </button>
        </div>
        <div className="procurement-modal-body cf-body">
          {error && <Alert type="error" message={error} />}
          {rows.length === 0 && <div className="detail-muted">No statuses yet.</div>}
          {rows.map((row, index) => (
            <div key={row.key} className="order-status-row">
              <input
                className="cf-input"
                value={row.label}
                maxLength={40}
                required
                aria-label={`Status ${index + 1} name`}
                onChange={(e) => setRows(rows.map((r, i) => (i === index ? { ...r, label: e.target.value } : r)))}
              />
              <select className="cf-input" value={row.color} aria-label={`Status ${index + 1} colour`} onChange={(e) => setRows(rows.map((r, i) => (i === index ? { ...r, color: e.target.value as CustomStatusColor } : r)))}>
                {COLORS.map((color) => (
                  <option key={color} value={color}>
                    {color}
                  </option>
                ))}
              </select>
              <StatusPill status={row.key} label={row.label || 'Preview'} tone={row.color} />
              <button type="button" className="topbar-icon-button" aria-label={`Remove ${row.label}`} onClick={() => setRows(rows.filter((_, i) => i !== index))}>
                <X size={14} />
              </button>
            </div>
          ))}
          <button
            type="button"
            className="btn-finovo-secondary"
            disabled={rows.length >= 30}
            onClick={() => {
              const label = `Status ${rows.length + 1}`;
              setRows([...rows, { key: statusKey(label, rows.map((r) => r.key)), label, color: 'blue' }]);
            }}
          >
            <Plus size={14} />
            <span>Add status</span>
          </button>
        </div>
        <div className="procurement-modal-footer">
          <button type="button" className="btn-finovo-secondary" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button type="submit" className="btn-finovo-primary" disabled={saving}>
            {saving && <Loader2 size={14} className="animate-spin" />}
            <span>Save Statuses</span>
          </button>
        </div>
      </form>
    </div>
  );
};

interface OrderCustomStatusSectionProps {
  order: OrderModel;
  statuses: CustomOrderStatus[];
  onChanged: (message: string) => void;
}

/** Set or clear the company status of one order. */
export const OrderCustomStatusSection: React.FC<OrderCustomStatusSectionProps> = ({ order, statuses, onChanged }) => {
  const [error, setError] = useState<string | null>(null);
  if (statuses.length === 0) return null;

  const change = async (value: string) => {
    setError(null);
    try {
      onChanged((await omsService.setOrderCustomStatus(order.id, value || null)).message);
    } catch (err) {
      setError(errorMessage(err, 'The status could not be changed.'));
    }
  };

  return (
    <section className="order-section">
      <h4 className="detail-section-title">Company Status</h4>
      {error && <Alert type="error" message={error} />}
      <select className="cf-input" value={order.custom_status ?? ''} onChange={(e) => change(e.target.value)} aria-label="Company status" style={{ maxWidth: 320 }}>
        <option value="">None</option>
        {statuses.map((status) => (
          <option key={status.key} value={status.key}>
            {status.label}
          </option>
        ))}
      </select>
    </section>
  );
};

/** Place this order again on a schedule (shown on the Recurring Orders page). */
export const OrderRepeatSection: React.FC<{ order: OrderModel; onChanged: (message: string) => void }> = ({ order, onChanged }) => {
  const [interval, setInterval] = useState<RecurringInterval>('monthly');
  const [startsOn, setStartsOn] = useState(today());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      onChanged((await omsService.repeatOrder(order.id, interval, startsOn)).message);
    } catch (err) {
      setError(errorMessage(err, 'The order could not be set to repeat.'));
      setBusy(false);
    }
  };

  return (
    <section className="order-section">
      <h4 className="detail-section-title">Repeat Order</h4>
      {error && <Alert type="error" message={error} />}
      <form className="order-hold-row" onSubmit={submit}>
        <select className="cf-input" value={interval} onChange={(e) => setInterval(e.target.value as RecurringInterval)} aria-label="How often">
          {Object.entries(RECURRING_INTERVAL_LABELS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
        <input className="cf-input" type="date" min={today()} value={startsOn} onChange={(e) => setStartsOn(e.target.value)} aria-label="First order on" required />
        <button type="submit" className="btn-finovo-secondary" disabled={busy}>
          {busy ? <Loader2 size={14} className="animate-spin" /> : <Repeat size={14} />}
          <span>Repeat</span>
        </button>
      </form>
    </section>
  );
};

const TEMPLATE =
  'order_ref,customer_name,customer_email,customer_phone,address1,city,province,zip,country_code,sku,quantity,unit_price,currency,payment_status,notes\n' +
  'A1,Sara Khan,sara@example.com,03001234567,House 2 Street 5,Karachi,Sindh,75500,PK,SKU-001,2,1500,PKR,paid,\n' +
  'A1,Sara Khan,sara@example.com,03001234567,House 2 Street 5,Karachi,Sindh,75500,PK,SKU-002,1,800,PKR,paid,\n';

interface ImportOrdersModalProps {
  storeId?: number;
  storeName?: string;
  onClose: () => void;
  onImported: (message: string) => void;
}

/** Upload a CSV of order lines; every problem is listed by row and nothing is created until the file is right. */
export const ImportOrdersModal: React.FC<ImportOrdersModalProps> = ({ storeId, storeName, onClose, onImported }) => {
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [problems, setProblems] = useState<string[]>([]);

  const downloadTemplate = () => {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([TEMPLATE], { type: 'text/csv' }));
    link.download = 'orders-template.csv';
    link.click();
    URL.revokeObjectURL(link.href);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) return;
    setBusy(true);
    setProblems([]);
    try {
      onImported((await omsService.importOrders(file, storeId)).message);
    } catch (err) {
      setProblems(err instanceof ApiError && err.errors ? Object.values(err.errors).flat() : [errorMessage(err, 'The file could not be imported.')]);
      setBusy(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={() => !busy && onClose()}>
      <form className="procurement-modal cf-modal" style={{ maxWidth: 560 }} onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Import Orders</h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>
              One row per product; rows with the same order_ref make one order{storeName ? `, for ${storeName}` : ''}.
            </p>
          </div>
          <button type="button" className="topbar-icon-button" onClick={onClose} aria-label="Close" disabled={busy}>
            <X size={18} />
          </button>
        </div>
        <div className="procurement-modal-body cf-body">
          {problems.length > 0 && (
            <div className="alert alert-error">
              <ul className="order-import-problems">
                {problems.map((problem) => (
                  <li key={problem}>{problem}</li>
                ))}
              </ul>
            </div>
          )}
          <input className="cf-input" type="file" accept=".csv,text/csv" onChange={(e) => setFile(e.target.files?.[0] ?? null)} aria-label="CSV file" />
          <div className="detail-muted">Required columns: order_ref, customer_name, sku, quantity, unit_price, currency. Up to 1,000 rows and 2 MB.</div>
          <button type="button" className="btn-finovo-secondary" onClick={downloadTemplate} style={{ alignSelf: 'flex-start' }}>
            <Download size={14} />
            <span>Download template</span>
          </button>
        </div>
        <div className="procurement-modal-footer">
          <button type="button" className="btn-finovo-secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="submit" className="btn-finovo-primary" disabled={busy || !file}>
            {busy ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />}
            <span>Import</span>
          </button>
        </div>
      </form>
    </div>
  );
};

interface BulkOrdersBarProps {
  orderIds: number[];
  statuses: CustomOrderStatus[];
  can: { allocate: boolean; cancel: boolean; manage: boolean };
  /** Confirms cancelling many orders. */
  confirmCancel: (count: number) => Promise<boolean>;
  onDone: (message: string, result: BulkOrderResult) => void;
  onClear: () => void;
}

/** Actions for the selected orders; each order is handled on its own and failures are listed. */
export const BulkOrdersBar: React.FC<BulkOrdersBarProps> = ({ orderIds, statuses, can, confirmCancel, onDone, onClear }) => {
  const [busy, setBusy] = useState(false);
  const [holdReason, setHoldReason] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const run = async (action: BulkOrderAction, options: { reason?: string; custom_status?: string | null } = {}) => {
    if (action === 'cancel' && !(await confirmCancel(orderIds.length))) return;
    setBusy(true);
    setError(null);
    try {
      const res = await omsService.bulkOrders(action, orderIds, options);
      setHoldReason(null);
      onDone(res.message, res.data);
    } catch (err) {
      setError(errorMessage(err, 'The orders could not be changed.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="order-bulk-bar">
      <strong>{orderIds.length} selected</strong>
      {can.allocate && (
        <button type="button" className="btn-finovo-secondary" disabled={busy} onClick={() => run('allocate')}>
          <PackageCheck size={14} />
          <span>Allocate</span>
        </button>
      )}
      {can.manage && holdReason === null && (
        <button type="button" className="btn-finovo-secondary" disabled={busy} onClick={() => setHoldReason('')}>
          <PauseCircle size={14} />
          <span>Hold</span>
        </button>
      )}
      {can.manage && holdReason !== null && (
        <form
          className="order-bulk-hold"
          onSubmit={(e) => {
            e.preventDefault();
            run('hold', { reason: holdReason.trim() });
          }}
        >
          <input className="cf-input" autoFocus placeholder="Reason" value={holdReason} maxLength={500} onChange={(e) => setHoldReason(e.target.value)} aria-label="Hold reason" />
          <button type="submit" className="btn-finovo-primary" disabled={busy || !holdReason.trim()}>
            Hold
          </button>
        </form>
      )}
      {can.manage && (
        <button type="button" className="btn-finovo-secondary" disabled={busy} onClick={() => run('release')}>
          <PlayCircle size={14} />
          <span>Release</span>
        </button>
      )}
      {can.manage && statuses.length > 0 && (
        <label className="order-bulk-status">
          <Tag size={14} />
          <select className="cf-input" value="" disabled={busy} onChange={(e) => run('set_status', { custom_status: e.target.value === 'none' ? null : e.target.value })} aria-label="Set company status">
            <option value="">Set status...</option>
            {statuses.map((status) => (
              <option key={status.key} value={status.key}>
                {status.label}
              </option>
            ))}
            <option value="none">Clear status</option>
          </select>
        </label>
      )}
      {can.cancel && (
        <button type="button" className="btn-finovo-secondary" style={{ color: '#dc2626' }} disabled={busy} onClick={() => run('cancel')}>
          <XCircle size={14} />
          <span>Cancel</span>
        </button>
      )}
      {busy && <Loader2 size={16} className="animate-spin" />}
      <button type="button" className="topbar-icon-button" onClick={onClear} aria-label="Clear selection">
        <X size={16} />
      </button>
      {error && <Alert type="error" message={error} />}
    </div>
  );
};
