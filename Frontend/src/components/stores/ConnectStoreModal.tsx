import React, { useMemo, useState } from 'react';
import { Store as StoreIcon, X, Loader2, Copy, Check, ExternalLink, Info, Download } from 'lucide-react';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { StorePlatform, WarehouseModel, ConnectStoreResponse } from '../../services/omsService';
import { ApiError } from '../../services/apiClient';
import { STORE_PLATFORMS, platformDefinition } from './storePlatforms';

interface ConnectStoreModalProps {
  warehouses: WarehouseModel[];
  onClose: () => void;
  /** Called after a store is saved and needs no further step in this dialog. */
  onConnected: (message: string) => void;
  /** Called when the merchant must approve Finovo on the platform's own page (the parent animates, then goes there). */
  onRedirect: (url: string, storeName: string, platform: StorePlatform) => void;
}

type ResultStep =
  | { kind: 'bridge_keys'; apiKey: string; apiSecret: string; message: string }
  | { kind: 'install_app'; message: string }
  | { kind: 'install_module'; url: string; message: string };

const EMPTY_FORM = { name: '', platform: 'shopify' as StorePlatform, warehouseId: null as number | null };

export const ConnectStoreModal: React.FC<ConnectStoreModalProps> = ({ warehouses, onClose, onConnected, onRedirect }) => {
  const [form, setForm] = useState(EMPTY_FORM);
  const [credentials, setCredentials] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState('');
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<ResultStep | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  const platform = platformDefinition(form.platform);
  const connectsDirectly = !!platform?.directKeyFields?.every((key) => (credentials[key] ?? '').trim() !== '');

  const warehouseOptions = useMemo(
    () => warehouses.map((wh) => ({ value: wh.id, label: wh.name, description: wh.code })),
    [warehouses]
  );

  const changePlatform = (value: StorePlatform) => {
    setForm({ ...form, platform: value });
    setCredentials({});
    setFieldErrors({});
    setFormError('');
  };

  const buildCredentials = (): Record<string, string | number> => {
    const payload: Record<string, string | number> = {};
    for (const field of platform?.fields ?? []) {
      const value = (credentials[field.key] ?? '').trim();
      if (value !== '') {
        payload[field.key] = field.type === 'number' ? Number(value) : value;
      }
    }
    return payload;
  };

  const handleResponse = (response: ConnectStoreResponse) => {
    if (response.next_action?.type === 'redirect') {
      onRedirect(response.next_action.url, response.data.name, response.data.platform);
      return;
    }
    if (response.next_action?.type === 'install_app') {
      setResult({ kind: 'install_app', message: response.next_action.message });
      return;
    }
    if (response.next_action?.type === 'install_module') {
      setResult({ kind: 'install_module', url: response.next_action.url, message: response.next_action.message });
      return;
    }
    if (response.credentials) {
      setResult({
        kind: 'bridge_keys',
        apiKey: response.credentials.api_key,
        apiSecret: response.credentials.api_secret,
        message: response.message,
      });
      return;
    }
    onConnected(response.message);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError('');
    setFieldErrors({});

    if (!form.warehouseId) {
      setFieldErrors({ warehouse_id: 'Select the warehouse that fulfils this store\'s orders.' });
      return;
    }

    try {
      setSaving(true);
      const response = await omsService.createStore({
        name: form.name.trim(),
        platform: form.platform,
        warehouse_id: form.warehouseId,
        credentials: buildCredentials(),
      });
      handleResponse(response);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const mapped: Record<string, string> = {};
        Object.entries(err.errors).forEach(([key, messages]) => {
          mapped[key.replace(/^credentials\./, '')] = messages[0];
        });
        setFieldErrors(mapped);
        setFormError(mapped.credentials || mapped.platform || '');
      } else {
        setFormError(err instanceof Error ? err.message : 'Could not connect the store.');
      }
    } finally {
      setSaving(false);
    }
  };

  const copy = async (key: string, value: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopiedKey(key);
      setTimeout(() => setCopiedKey(null), 1500);
    } catch {
      setCopiedKey(null);
    }
  };

  const finish = () => onConnected(result?.kind === 'bridge_keys' ? result.message : 'Store saved. It connects as soon as the app or module is installed.');

  return (
    <div className="modal-overlay" onClick={saving ? undefined : onClose}>
      <div className="modal-dialog store-connect-dialog" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby="connect-store-title">
        <div className="store-connect-header">
          <div className="store-connect-title">
            <span className="store-connect-icon"><StoreIcon size={18} /></span>
            <h3 id="connect-store-title">Connect New Store</h3>
          </div>
          <button type="button" className="store-connect-close" onClick={onClose} disabled={saving} aria-label="Close">
            <X size={20} />
          </button>
        </div>

        {result ? (
          <div className="store-connect-body">
            {result.kind === 'bridge_keys' ? (
              <>
                <div className="store-connect-note store-connect-note-success">
                  <Info size={16} />
                  <span>Copy these keys into the bridge files on the website. The secret is shown only once.</span>
                </div>
                {[
                  { key: 'api_key', label: 'API Key', value: result.apiKey },
                  { key: 'api_secret', label: 'API Secret', value: result.apiSecret },
                ].map((item) => (
                  <div className="store-connect-field" key={item.key}>
                    <span className="store-connect-label">{item.label}</span>
                    <div className="store-connect-secret">
                      <code>{item.value}</code>
                      <button type="button" className="btn-finovo-secondary" onClick={() => copy(item.key, item.value)}>
                        {copiedKey === item.key ? <Check size={14} /> : <Copy size={14} />}
                        <span>{copiedKey === item.key ? 'Copied' : 'Copy'}</span>
                      </button>
                    </div>
                  </div>
                ))}
                <p className="store-connect-help">
                  When the keys are in place, use <strong>Test</strong> on the store to confirm the connection.
                </p>
              </>
            ) : (
              <>
                <div className="store-connect-note">
                  <ExternalLink size={16} />
                  <span>{result.message}</span>
                </div>
                {result.kind === 'install_module' && (
                  <a className="btn-finovo-secondary store-connect-download" href={result.url} download>
                    <Download size={16} />
                    <span>Download Finovo module</span>
                  </a>
                )}
              </>
            )}
            <div className="store-connect-actions">
              <button type="button" className="btn-finovo-primary" onClick={finish}>Done</button>
            </div>
          </div>
        ) : (
          <form className="store-connect-body" onSubmit={handleSubmit} noValidate>
            {formError && <div className="store-connect-error-banner">{formError}</div>}

            <div className="store-connect-field">
              <label className="store-connect-label" htmlFor="store-name">Store Name *</label>
              <input
                id="store-name"
                className={`store-connect-input ${fieldErrors.name ? 'has-error' : ''}`}
                type="text"
                placeholder="e.g. Finovo Shopify Store"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                required
              />
              {fieldErrors.name && <span className="store-connect-field-error">{fieldErrors.name}</span>}
            </div>

            <div className="store-connect-grid">
              <CustomDropdown
                label="Platform"
                required
                value={form.platform}
                onChange={(val: StorePlatform) => changePlatform(val)}
                options={STORE_PLATFORMS.map((p) => ({ value: p.value, label: p.label }))}
                error={fieldErrors.platform}
              />
              <CustomDropdown
                label="Fulfilling Warehouse"
                required
                value={form.warehouseId}
                onChange={(val: number) => setForm({ ...form, warehouseId: val })}
                options={warehouseOptions}
                placeholder={warehouses.length ? 'Select warehouse' : 'No warehouses yet'}
                disabled={warehouses.length === 0}
                searchable={warehouses.length > 6}
                error={fieldErrors.warehouse_id}
              />
            </div>
            {warehouses.length === 0 && (
              <p className="store-connect-help">Create a warehouse first (Warehouses → New Warehouse Wizard); every store ships from one.</p>
            )}

            {platform && (
              <div className="store-connect-note">
                <Info size={16} />
                <span>{platform.instructions}</span>
              </div>
            )}

            {platform?.fields.length ? (
              <div className="store-connect-grid">
                {platform.fields.map((field) => (
                  <div className="store-connect-field" key={field.key}>
                    <label className="store-connect-label" htmlFor={`cred-${field.key}`}>
                      {field.label}{field.required ? ' *' : ''}
                    </label>
                    <input
                      id={`cred-${field.key}`}
                      className={`store-connect-input ${fieldErrors[field.key] ? 'has-error' : ''}`}
                      type={field.type}
                      placeholder={field.placeholder}
                      value={credentials[field.key] ?? ''}
                      onChange={(e) => setCredentials({ ...credentials, [field.key]: e.target.value })}
                      autoComplete={field.type === 'password' ? 'new-password' : 'off'}
                      required={field.required}
                    />
                    {fieldErrors[field.key] ? (
                      <span className="store-connect-field-error">{fieldErrors[field.key]}</span>
                    ) : (
                      field.help && <span className="store-connect-help">{field.help}</span>
                    )}
                  </div>
                ))}
              </div>
            ) : null}

            <div className="store-connect-actions">
              <button type="button" className="btn-finovo-secondary" onClick={onClose} disabled={saving}>
                Cancel
              </button>
              <button type="submit" className="btn-finovo-primary" disabled={saving || warehouses.length === 0}>
                {saving && <Loader2 size={16} className="animate-spin" />}
                <span>{connectsDirectly ? 'Connect Store' : platform?.submitLabel ?? 'Connect Store'}</span>
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};

export default ConnectStoreModal;
