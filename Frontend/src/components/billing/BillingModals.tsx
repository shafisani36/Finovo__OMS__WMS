import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, Download, FileText, Loader2, Plus, RefreshCw, Send, Trash2, X, XCircle } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import StatusPill from '../common/StatusPill';
import ConfirmDialog from '../common/ConfirmDialog';
import omsService from '../../services/omsService';
import type { BillingStore, ClientCharges, ClientInput, ClientInvoiceLine, ClientInvoiceModel, ClientModel, ClientRates } from '../../services/omsService';
import { platformLabel } from '../stores/storePlatforms';
import { formatDate, formatMoney } from '../../utils/format';
import './Billing.css';
import { errorMessage } from '../../utils/errors';


const isoDate = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** First and last day of last month, the usual period to bill. */
export const lastMonth = (): { from: string; to: string } => {
  const now = new Date();
  return { from: isoDate(new Date(now.getFullYear(), now.getMonth() - 1, 1)), to: isoDate(new Date(now.getFullYear(), now.getMonth(), 0)) };
};

const thisMonth = (): { from: string; to: string } => {
  const now = new Date();
  return { from: isoDate(new Date(now.getFullYear(), now.getMonth(), 1)), to: isoDate(now) };
};

/** "0.5", "12", "1,250.75": quantities and rates without trailing zeros. */
const plain = (value: number) => Number(value).toLocaleString(undefined, { maximumFractionDigits: 4 });

/** The invoice status as people read it: an issued invoice past its due date is overdue. */
export const InvoiceStatus: React.FC<{ invoice: ClientInvoiceModel }> = ({ invoice }) =>
  invoice.is_overdue ? <StatusPill status="failed" label="overdue" /> : <StatusPill status={invoice.status === 'issued' ? 'pending' : invoice.status === 'void' ? 'cancelled' : invoice.status} label={invoice.status === 'issued' ? 'unpaid' : invoice.status} />;

/** Rate card fields with their meaning, in the order of an invoice. */
export const RATE_FIELDS: { key: keyof ClientRates; label: string; hint: string }[] = [
  { key: 'storage_per_unit_day', label: 'Storage per unit per day', hint: '10 units kept 30 days = 300 unit-days' },
  { key: 'receiving_per_unit', label: 'Receiving per unit', hint: 'Units received into stock' },
  { key: 'order_fee', label: 'Fee per order shipped', hint: 'Pick, pack and the first unit' },
  { key: 'additional_unit_fee', label: 'Per additional unit', hint: 'Each unit after the first of an order' },
  { key: 'return_fee', label: 'Fee per return', hint: 'Returns received and inspected' },
  { key: 'monthly_minimum', label: 'Monthly minimum', hint: 'Charges are topped up to this amount' },
];

const Modal: React.FC<{ title: string; subtitle?: string; width?: number; onClose: () => void; footer?: React.ReactNode; children: React.ReactNode }> = ({
  title,
  subtitle,
  width = 560,
  onClose,
  footer,
  children,
}) => {
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onClose]);

  return createPortal(
    <div className="modal-overlay" onClick={onClose}>
      <div className="procurement-modal" style={{ maxWidth: width }} onClick={(e) => e.stopPropagation()}>
        <div className="procurement-modal-header">
          <div>
            <h3 className="billing-modal-title">{title}</h3>
            {subtitle && <p className="billing-modal-subtitle">{subtitle}</p>}
          </div>
          <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close">
            <X size={18} />
          </button>
        </div>
        <div className="procurement-modal-body billing-modal-body">{children}</div>
        {footer && <div className="procurement-modal-footer">{footer}</div>}
      </div>
    </div>,
    document.body
  );
};

const Field: React.FC<{ label: string; hint?: string; children: React.ReactNode }> = ({ label, hint, children }) => (
  <div className="billing-field">
    <label className="form-label">{label}</label>
    {children}
    {hint && <span className="billing-hint">{hint}</span>}
  </div>
);

const LinesTable: React.FC<{ lines: ClientInvoiceLine[]; currency: string; total: number; onRemove?: (line: ClientInvoiceLine) => void }> = ({
  lines,
  currency,
  total,
  onRemove,
}) => (
  <div className="table-responsive-wrapper">
    <table className="finovo-table billing-lines">
      <thead>
        <tr>
          <th>Charge</th>
          <th style={{ textAlign: 'right' }}>Quantity</th>
          <th style={{ textAlign: 'right' }}>Rate</th>
          <th style={{ textAlign: 'right' }}>Amount</th>
          {onRemove && <th aria-label="Remove" />}
        </tr>
      </thead>
      <tbody>
        {lines.length === 0 ? (
          <tr>
            <td colSpan={onRemove ? 5 : 4} className="billing-empty">
              No charges for this period.
            </td>
          </tr>
        ) : (
          lines.map((line, index) => (
            <tr key={line.id ?? index}>
              <td>
                {line.description}
                {line.type === 'manual' && <span className="billing-manual-tag">Added by hand</span>}
              </td>
              <td style={{ textAlign: 'right' }}>{plain(line.quantity)}</td>
              <td style={{ textAlign: 'right' }}>{plain(line.rate)}</td>
              <td style={{ textAlign: 'right', fontWeight: 600 }}>{formatMoney(line.amount, currency)}</td>
              {onRemove && (
                <td style={{ textAlign: 'right' }}>
                  {line.type === 'manual' && (
                    <button type="button" className="billing-icon-button" onClick={() => onRemove(line)} aria-label={`Remove ${line.description}`}>
                      <Trash2 size={14} />
                    </button>
                  )}
                </td>
              )}
            </tr>
          ))
        )}
      </tbody>
      <tfoot>
        <tr>
          <td colSpan={3}>Total</td>
          <td style={{ textAlign: 'right' }}>{formatMoney(total, currency)}</td>
          {onRemove && <td />}
        </tr>
      </tfoot>
    </table>
  </div>
);

const emptyClient = (): ClientInput => ({
  name: '',
  code: '',
  contact_name: '',
  email: '',
  phone: '',
  billing_address: '',
  currency: 'PKR',
  payment_terms_days: 15,
  is_active: true,
  store_ids: [],
  storage_per_unit_day: 0,
  receiving_per_unit: 0,
  order_fee: 0,
  additional_unit_fee: 0,
  return_fee: 0,
  monthly_minimum: 0,
});

/**
 * Add or edit a client: contact details, the stores billed to it and its rate card.
 */
export const ClientFormModal: React.FC<{ client: ClientModel | null; stores: BillingStore[]; onClose: () => void; onSaved: (message: string) => void }> = ({
  client,
  stores,
  onClose,
  onSaved,
}) => {
  const [form, setForm] = useState<ClientInput>(() =>
    client ? { ...emptyClient(), ...client, store_ids: client.stores.map((s) => s.id) } : emptyClient()
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = <K extends keyof ClientInput>(key: K, value: ClientInput[K]) => setForm((prev) => ({ ...prev, [key]: value }));
  const otherClientStores = stores.filter((s) => form.store_ids.includes(s.id) && s.client_id && s.client_id !== client?.id);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError(null);
      const res = await omsService.saveClient({ ...form, code: form.code.trim().toUpperCase(), currency: form.currency.trim().toUpperCase() }, client?.id);
      onSaved(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The client could not be saved.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      title={client ? `Edit ${client.name}` : 'Add Client'}
      subtitle="A brand whose stock you keep and ship. Its stores' orders and products are billed with this rate card."
      width={680}
      onClose={onClose}
      footer={
        <div className="billing-footer-actions">
          <button type="button" className="btn-outline" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" form="client-form" className="btn-primary" disabled={saving}>
            {saving ? <Loader2 size={16} className="animate-spin" /> : null}
            {client ? 'Save Client' : 'Add Client'}
          </button>
        </div>
      }
    >
      <form id="client-form" onSubmit={save} className="billing-form">
        {error && <Alert type="error" message={error} />}

        <div className="finovo-form-grid-2">
          <Field label="Client name *">
            <input className="form-input" value={form.name} onChange={(e) => set('name', e.target.value)} required maxLength={150} placeholder="e.g. Urban Threads" />
          </Field>
          <Field label="Code *" hint="Letters, numbers and dashes">
            <input className="form-input" value={form.code} onChange={(e) => set('code', e.target.value)} required maxLength={30} placeholder="e.g. URBAN" />
          </Field>
          <Field label="Contact person">
            <input className="form-input" value={form.contact_name ?? ''} onChange={(e) => set('contact_name', e.target.value)} maxLength={150} />
          </Field>
          <Field label="Email">
            <input className="form-input" type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} maxLength={150} />
          </Field>
          <Field label="Phone">
            <input className="form-input" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} maxLength={40} />
          </Field>
          <div className="finovo-form-grid-2">
            <Field label="Currency *">
              <input className="form-input" value={form.currency} onChange={(e) => set('currency', e.target.value)} required maxLength={3} minLength={3} />
            </Field>
            <Field label="Payment days *">
              <input
                className="form-input"
                type="number"
                min={0}
                max={365}
                value={form.payment_terms_days}
                onChange={(e) => set('payment_terms_days', Number(e.target.value))}
                required
              />
            </Field>
          </div>
        </div>

        <Field label="Billing address">
          <textarea className="form-input" style={{ height: 64, width: '100%' }} value={form.billing_address ?? ''} onChange={(e) => set('billing_address', e.target.value)} maxLength={1000} />
        </Field>

        <Field label="Stores billed to this client" hint="Orders of these stores, and the products sold in them, are charged to the client.">
          <CustomDropdown
            multiple
            value={form.store_ids}
            onMultiChange={(ids) => set('store_ids', ids as number[])}
            options={stores.map((s) => ({ value: s.id, label: s.name, description: platformLabel(s.platform) }))}
            placeholder={stores.length ? 'Choose stores' : 'No stores connected yet'}
            searchable
          />
        </Field>
        {otherClientStores.length > 0 && (
          <Alert type="info" message={`${otherClientStores.map((s) => s.name).join(', ')} will move to this client from the client it is billed to now.`} />
        )}

        <div>
          <h4 className="billing-section-title">Rate card ({form.currency || 'currency'})</h4>
          <div className="billing-rates-grid">
            {RATE_FIELDS.map((field) => (
              <Field key={field.key} label={field.label} hint={field.hint}>
                <input
                  className="form-input"
                  type="number"
                  min={0}
                  step="any"
                  value={form[field.key]}
                  onChange={(e) => set(field.key, Number(e.target.value))}
                  required
                />
              </Field>
            ))}
          </div>
        </div>

        {client && (
          <label className="billing-checkbox">
            <input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
            Active client
          </label>
        )}
      </form>
    </Modal>
  );
};

const PeriodFields: React.FC<{ from: string; to: string; onChange: (period: { from: string; to: string }) => void }> = ({ from, to, onChange }) => (
  <div className="finovo-form-grid-2">
    <Field label="From">
      <input className="form-input" type="date" value={from} max={to} onChange={(e) => onChange({ from: e.target.value, to })} required />
    </Field>
    <Field label="To">
      <input className="form-input" type="date" value={to} min={from} onChange={(e) => onChange({ from, to: e.target.value })} required />
    </Field>
  </div>
);

/**
 * What a client owes for a period so far, with the activity behind it; the period can be invoiced from here.
 */
export const ChargesModal: React.FC<{ client: ClientModel; canManage: boolean; onClose: () => void; onInvoiced: (invoice: ClientInvoiceModel, message: string) => void }> = ({
  client,
  canManage,
  onClose,
  onInvoiced,
}) => {
  const [period, setPeriod] = useState(thisMonth);
  const [charges, setCharges] = useState<ClientCharges | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    if (!period.from || !period.to || period.to < period.from) return;
    let current = true;
    setCharges(null);
    omsService
      .getClientCharges(client.id, period)
      .then((data) => current && (setCharges(data), setError(null)))
      .catch((err) => current && setError(errorMessage(err, 'The charges could not be calculated.')));
    return () => {
      current = false;
    };
  }, [client.id, period]);

  const invoice = async () => {
    try {
      setCreating(true);
      const res = await omsService.createClientInvoice({ client_id: client.id, period_start: period.from, period_end: period.to });
      onInvoiced(res.data, res.message);
    } catch (err) {
      setError(errorMessage(err, 'The invoice could not be created.'));
    } finally {
      setCreating(false);
    }
  };

  const activity = charges?.activity;

  return (
    <Modal
      title={`Charges · ${client.name}`}
      subtitle="Running charges for the chosen period, from the warehouse activity of the client's stores."
      width={680}
      onClose={onClose}
      footer={
        canManage ? (
          <div className="billing-footer-actions">
            <button type="button" className="btn-primary" onClick={invoice} disabled={creating || !charges}>
              {creating ? <Loader2 size={16} className="animate-spin" /> : <FileText size={16} />}
              Create Invoice for this Period
            </button>
          </div>
        ) : undefined
      }
    >
      <PeriodFields from={period.from} to={period.to} onChange={setPeriod} />
      {error && <Alert type="error" message={error} />}

      {!charges ? (
        !error && <Loader2 size={20} className="animate-spin" color="#1e56ff" />
      ) : (
        <>
          <div className="billing-activity">
            <div>
              <strong>{activity!.unit_days.toLocaleString()}</strong>
              <span>Unit-days stored</span>
            </div>
            <div>
              <strong>{activity!.units_received.toLocaleString()}</strong>
              <span>Units received</span>
            </div>
            <div>
              <strong>{activity!.orders_shipped.toLocaleString()}</strong>
              <span>Orders shipped</span>
            </div>
            <div>
              <strong>{activity!.units_shipped.toLocaleString()}</strong>
              <span>Units shipped</span>
            </div>
            <div>
              <strong>{activity!.returns.toLocaleString()}</strong>
              <span>Returns</span>
            </div>
          </div>
          <LinesTable lines={charges.lines} currency={charges.currency} total={charges.total} />
          {client.stores.length === 0 && <Alert type="info" message="No stores are billed to this client yet. Edit the client and choose its stores." />}
        </>
      )}
    </Modal>
  );
};

/**
 * Make a draft invoice for a client and period (last month by default).
 */
export const NewInvoiceModal: React.FC<{ clients: ClientModel[]; onClose: () => void; onCreated: (invoice: ClientInvoiceModel, message: string) => void }> = ({
  clients,
  onClose,
  onCreated,
}) => {
  const active = clients.filter((c) => c.is_active);
  const [clientId, setClientId] = useState<number | null>(active[0]?.id ?? null);
  const [period, setPeriod] = useState(lastMonth);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!clientId) return;
    try {
      setSaving(true);
      setError(null);
      const res = await omsService.createClientInvoice({ client_id: clientId, period_start: period.from, period_end: period.to });
      onCreated(res.data, res.message);
    } catch (err) {
      setError(errorMessage(err, 'The invoice could not be created.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      title="New Invoice"
      subtitle="A draft is priced from the period's activity. You can add charges before issuing it."
      onClose={onClose}
      footer={
        <div className="billing-footer-actions">
          <button type="button" className="btn-outline" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" form="new-invoice-form" className="btn-primary" disabled={saving || !clientId}>
            {saving ? <Loader2 size={16} className="animate-spin" /> : null}
            Create Draft
          </button>
        </div>
      }
    >
      <form id="new-invoice-form" onSubmit={create} className="billing-form">
        {error && <Alert type="error" message={error} />}
        <Field label="Client *" hint={clientId ? `Billed until ${formatDate(clients.find((c) => c.id === clientId)?.billed_until)}` : undefined}>
          <CustomDropdown
            value={clientId}
            onChange={(id) => setClientId(Number(id))}
            options={active.map((c) => ({ value: c.id, label: c.name, description: c.code }))}
            placeholder={active.length ? 'Choose a client' : 'Add a client first'}
            searchable
          />
        </Field>
        <PeriodFields from={period.from} to={period.to} onChange={setPeriod} />
      </form>
    </Modal>
  );
};

/**
 * One invoice: its lines, and the next steps (recalculate or add charges to a draft, issue, mark paid, void).
 */
export const InvoiceModal: React.FC<{ invoiceId: number; canManage: boolean; onClose: () => void; onChanged: (message: string) => void }> = ({
  invoiceId,
  canManage,
  onClose,
  onChanged,
}) => {
  const [invoice, setInvoice] = useState<ClientInvoiceModel | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [line, setLine] = useState({ description: '', quantity: 1, rate: 0 });
  const [confirmVoid, setConfirmVoid] = useState(false);

  useEffect(() => {
    omsService
      .getClientInvoice(invoiceId)
      .then(setInvoice)
      .catch((err) => setFeedback({ type: 'error', text: errorMessage(err, 'The invoice could not be loaded.') }));
  }, [invoiceId]);

  const run = async (key: string, action: () => Promise<{ message: string; data: ClientInvoiceModel }>) => {
    try {
      setBusy(key);
      const res = await action();
      setInvoice(res.data);
      setFeedback({ type: 'success', text: res.message });
      onChanged(res.message);
      return true;
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The invoice could not be updated.') });
      return false;
    } finally {
      setBusy(null);
    }
  };

  const addLine = async (e: React.FormEvent) => {
    e.preventDefault();
    if (invoice && (await run('line', () => omsService.addClientInvoiceLine(invoice.id, line)))) setLine({ description: '', quantity: 1, rate: 0 });
  };

  const download = async () => {
    if (!invoice) return;
    try {
      setBusy('pdf');
      await omsService.downloadClientInvoice(invoice.id, invoice.invoice_number);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'Could not download the invoice.') });
    } finally {
      setBusy(null);
    }
  };

  const draft = invoice?.status === 'draft';
  const spinner = (key: string, icon: React.ReactNode) => (busy === key ? <Loader2 size={15} className="animate-spin" /> : icon);

  return (
    <Modal
      title={invoice ? `Invoice ${invoice.invoice_number}` : 'Invoice'}
      subtitle={invoice ? `${invoice.client?.name ?? ''} · ${formatDate(invoice.period_start)} – ${formatDate(invoice.period_end)}` : 'Loading…'}
      width={720}
      onClose={onClose}
      footer={
        invoice ? (
          <div className="billing-footer-actions billing-footer-wrap">
            <button type="button" className="btn-outline" onClick={download} disabled={busy !== null}>
              {spinner('pdf', <Download size={15} />)} PDF
            </button>
            {canManage && draft && (
              <button type="button" className="btn-outline" onClick={() => run('recalculate', () => omsService.clientInvoiceAction(invoice.id, 'recalculate'))} disabled={busy !== null}>
                {spinner('recalculate', <RefreshCw size={15} />)} Recalculate
              </button>
            )}
            {canManage && (draft || invoice.status === 'issued') && (
              <button type="button" className="btn-outline is-danger" onClick={() => setConfirmVoid(true)} disabled={busy !== null}>
                <XCircle size={15} /> Void
              </button>
            )}
            {canManage && draft && (
              <button type="button" className="btn-primary" onClick={() => run('issue', () => omsService.clientInvoiceAction(invoice.id, 'issue'))} disabled={busy !== null}>
                {spinner('issue', <Send size={15} />)} Issue Invoice
              </button>
            )}
            {canManage && invoice.status === 'issued' && (
              <button type="button" className="btn-primary" onClick={() => run('pay', () => omsService.clientInvoiceAction(invoice.id, 'pay'))} disabled={busy !== null}>
                {spinner('pay', <CheckCircle2 size={15} />)} Mark as Paid
              </button>
            )}
          </div>
        ) : undefined
      }
    >
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {invoice && (
        <>
          <div className="billing-invoice-meta">
            <div>
              <span>Status</span>
              <InvoiceStatus invoice={invoice} />
            </div>
            <div>
              <span>Issued</span>
              <strong>{formatDate(invoice.issued_at)}</strong>
            </div>
            <div>
              <span>Due</span>
              <strong>{formatDate(invoice.due_date)}</strong>
            </div>
            <div>
              <span>Paid</span>
              <strong>{formatDate(invoice.paid_at)}</strong>
            </div>
          </div>

          <LinesTable
            lines={invoice.lines ?? []}
            currency={invoice.currency}
            total={invoice.total}
            onRemove={canManage && draft ? (l) => run('remove', () => omsService.removeClientInvoiceLine(invoice.id, l.id!)) : undefined}
          />

          {canManage && draft && (
            <form className="billing-add-line" onSubmit={addLine}>
              <Field label="Extra charge">
                <input
                  className="form-input"
                  value={line.description}
                  onChange={(e) => setLine({ ...line, description: e.target.value })}
                  placeholder="e.g. Gift wrapping, or a discount with a negative rate"
                  maxLength={200}
                  required
                />
              </Field>
              <Field label="Quantity">
                <input className="form-input" type="number" min={0.01} step="any" value={line.quantity} onChange={(e) => setLine({ ...line, quantity: Number(e.target.value) })} required />
              </Field>
              <Field label="Rate">
                <input className="form-input" type="number" step="any" value={line.rate} onChange={(e) => setLine({ ...line, rate: Number(e.target.value) })} required />
              </Field>
              <button type="submit" className="btn-finovo-secondary" disabled={busy !== null || !line.rate}>
                {spinner('line', <Plus size={14} />)}
                <span>Add</span>
              </button>
            </form>
          )}
        </>
      )}

      <ConfirmDialog
        open={confirmVoid}
        title={`Void ${invoice?.invoice_number ?? 'invoice'}?`}
        message="The invoice is cancelled and its period can be billed again."
        confirmLabel="Void Invoice"
        loading={busy === 'void'}
        onCancel={() => setConfirmVoid(false)}
        onConfirm={async () => {
          if (invoice) await run('void', () => omsService.clientInvoiceAction(invoice.id, 'void'));
          setConfirmVoid(false);
        }}
      />
    </Modal>
  );
};
