import React, { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, Loader2, Pencil, Plug, Plus, Trash2, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import { useConfirmDialog } from '../common/ConfirmDialog';
import omsService from '../../services/omsService';
import type { CourierAccountInput, CourierAccountModel, CourierCarrier } from '../../services/omsService';
import { formatDate } from '../../utils/format';
import { errorMessage } from '../../utils/errors';


const emptyInput = (carrier: CourierCarrier): CourierAccountInput => ({
  carrier: carrier.code,
  name: carrier.name,
  sandbox: false,
  is_active: true,
  credentials: {},
  settings: {},
});

/**
 * Courier accounts the company books parcels with. Credentials are only typed in, never shown again: a saved one
 * reads "Saved" and stays unless a new value is entered.
 */
export const CourierAccountsModal: React.FC<{ onClose: () => void; onChanged: () => void }> = ({ onClose, onChanged }) => {
  const [accounts, setAccounts] = useState<CourierAccountModel[] | null>(null);
  const [carriers, setCarriers] = useState<CourierCarrier[]>([]);
  const [editing, setEditing] = useState<CourierAccountModel | 'new' | null>(null);
  const [input, setInput] = useState<CourierAccountInput | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const { confirm, dialog } = useConfirmDialog();

  const load = useCallback(async () => {
    try {
      const res = await omsService.getCourierAccounts();
      setAccounts(res.data);
      setCarriers(res.carriers);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The courier accounts could not be loaded.') });
      setAccounts([]);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onClose]);

  const carrierOf = (code?: string) => carriers.find((c) => c.code === code) ?? null;
  const formCarrier = carrierOf(editing === 'new' ? input?.carrier : editing?.carrier);

  const startNew = () => {
    if (!carriers.length) return;
    setEditing('new');
    setInput(emptyInput(carriers[0]));
    setFeedback(null);
  };

  const startEdit = (account: CourierAccountModel) => {
    setEditing(account);
    setInput({ name: account.name, sandbox: account.sandbox, is_active: account.is_active, credentials: {}, settings: { ...account.settings } });
    setFeedback(null);
  };

  const run = async (key: string, action: () => Promise<{ message: string }>) => {
    try {
      setBusy(key);
      const res = await action();
      setFeedback({ type: 'success', text: res.message });
      await load();
      onChanged();
      return true;
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The courier account could not be updated.') });
      return false;
    } finally {
      setBusy(null);
    }
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!input) return;
    const saved = await run('save', () => omsService.saveCourierAccount(input, editing === 'new' || !editing ? undefined : editing.id));
    if (saved) setEditing(null);
  };

  const setField = (group: 'credentials' | 'settings', key: string, value: string) =>
    setInput((prev) => (prev ? { ...prev, [group]: { ...prev[group], [key]: value } } : prev));

  return createPortal(
    <div className="modal-overlay" onClick={onClose}>
      <div className="procurement-modal" style={{ maxWidth: 640 }} onClick={(e) => e.stopPropagation()}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Courier Accounts</h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>
              Book parcels, print labels and follow deliveries with Leopards, PostEx, TCS and Trax.
            </p>
          </div>
          <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close">
            <X size={18} />
          </button>
        </div>

        <div className="procurement-modal-body courier-modal-body">
          {feedback && <Alert type={feedback.type} message={feedback.text} />}

          {editing && input && formCarrier ? (
            <form id="courier-form" onSubmit={save} className="courier-form">
              {editing === 'new' && (
                <div>
                  <label className="form-label">Courier</label>
                  <CustomDropdown
                    value={input.carrier ?? null}
                    onChange={(code) => {
                      const carrier = carrierOf(String(code));
                      if (carrier) setInput(emptyInput(carrier));
                    }}
                    options={carriers.map((c) => ({ value: c.code, label: c.name }))}
                    searchable={false}
                  />
                </div>
              )}
              <div>
                <label className="form-label">Account name</label>
                <input className="form-input" value={input.name} onChange={(e) => setInput({ ...input, name: e.target.value })} required maxLength={100} />
              </div>

              <h4 className="courier-section">Login details</h4>
              <div className="finovo-form-grid-2">
                {formCarrier.credentials.map((field) => {
                  const saved = editing !== 'new' && editing.credentials_set[field.key];
                  return (
                    <div key={field.key}>
                      <label className="form-label">
                        {field.label}
                        {saved && <span className="courier-saved">Saved</span>}
                      </label>
                      <input
                        className="form-input"
                        type="password"
                        autoComplete="new-password"
                        value={input.credentials[field.key] ?? ''}
                        onChange={(e) => setField('credentials', field.key, e.target.value)}
                        placeholder={saved ? 'Leave blank to keep' : ''}
                        required={field.required && !saved}
                      />
                    </div>
                  );
                })}
              </div>

              <h4 className="courier-section">Booking defaults</h4>
              <div className="finovo-form-grid-2">
                {formCarrier.settings.map((field) => (
                  <div key={field.key}>
                    <label className="form-label">{field.label}</label>
                    <input
                      className="form-input"
                      value={input.settings[field.key] ?? ''}
                      onChange={(e) => setField('settings', field.key, e.target.value)}
                      required={field.required}
                    />
                  </div>
                ))}
              </div>

              <div className="courier-checks">
                {formCarrier.sandbox && (
                  <label>
                    <input type="checkbox" checked={input.sandbox} onChange={(e) => setInput({ ...input, sandbox: e.target.checked })} />
                    Test account (the courier's sandbox; parcels are not really booked)
                  </label>
                )}
                <label>
                  <input type="checkbox" checked={input.is_active} onChange={(e) => setInput({ ...input, is_active: e.target.checked })} />
                  Active (offered at dispatch and tracked)
                </label>
              </div>
            </form>
          ) : accounts === null ? (
            <Loader2 size={20} className="animate-spin" color="#1e56ff" />
          ) : accounts.length === 0 ? (
            <p className="courier-empty">No courier account yet. Add one to book parcels from the Dispatch screen.</p>
          ) : (
            <ul className="courier-list">
              {accounts.map((account) => (
                <li key={account.id}>
                  <div className="courier-list-main">
                    <strong>{account.name}</strong>
                    <span>
                      {account.carrier_name}
                      {account.sandbox ? ' · Test account' : ''}
                      {!account.is_active ? ' · Inactive' : ''}
                      {account.last_tracked_at ? ` · Tracked ${formatDate(account.last_tracked_at, true)}` : ''}
                    </span>
                    {account.last_error ? (
                      <span className="courier-error">{account.last_error}</span>
                    ) : (
                      account.last_tracked_at && (
                        <span className="courier-ok">
                          <CheckCircle2 size={12} /> Working
                        </span>
                      )
                    )}
                  </div>
                  <div className="courier-actions">
                    <button type="button" className="btn-finovo-secondary" onClick={() => run(`test-${account.id}`, () => omsService.testCourierAccount(account.id))} disabled={busy !== null}>
                      {busy === `test-${account.id}` ? <Loader2 size={13} className="animate-spin" /> : <Plug size={13} />}
                      <span>Test</span>
                    </button>
                    <button type="button" className="topbar-icon-button" onClick={() => startEdit(account)} aria-label={`Edit ${account.name}`}>
                      <Pencil size={15} />
                    </button>
                    <button
                      type="button"
                      className="topbar-icon-button"
                      onClick={async () => {
                        const ok = await confirm({
                          title: `Remove ${account.name}?`,
                          message: 'Parcels already booked keep their tracking numbers but are no longer followed.',
                          confirmLabel: 'Remove',
                        });
                        if (ok) run(`delete-${account.id}`, () => omsService.deleteCourierAccount(account.id));
                      }}
                      aria-label={`Remove ${account.name}`}
                    >
                      <Trash2 size={15} />
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
          {editing ? (
            <div style={{ display: 'flex', gap: 8 }}>
              <button type="button" className="btn-outline" onClick={() => setEditing(null)}>
                Back
              </button>
              <button type="submit" form="courier-form" className="btn-primary" disabled={busy !== null}>
                {busy === 'save' ? <Loader2 size={16} className="animate-spin" /> : null}
                Save Account
              </button>
            </div>
          ) : (
            <button type="button" className="btn-primary" onClick={startNew} disabled={!carriers.length}>
              <Plus size={16} /> Add Courier Account
            </button>
          )}
        </div>
      </div>
      {dialog}
    </div>,
    document.body
  );
};

export default CourierAccountsModal;
