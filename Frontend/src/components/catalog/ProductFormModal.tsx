import React, { useMemo, useState } from 'react';
import { Loader2, Plus, Trash2, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import omsService, { WRITABLE_PLATFORMS } from '../../services/omsService';
import type { ProductDetail, ProductPayload, StoreModel } from '../../services/omsService';
import { platformLabel } from '../stores/storePlatforms';
import { useStoreList } from '../../hooks/useStoreList';
import { errorMessage } from '../../utils/errors';
import './CatalogForms.css';

interface VariantRow {
  key: string;
  id?: number;
  option_value: string;
  sku: string;
  price: string;
  cost_price: string;
  barcode: string;
  is_active: boolean;
}

let rowCounter = 0;
const newRow = (values: Partial<VariantRow> = {}): VariantRow => ({
  key: `row-${++rowCounter}`,
  option_value: '',
  sku: '',
  price: '',
  cost_price: '',
  barcode: '',
  is_active: true,
  ...values,
});

/** Form values from an existing product, or an empty form. */
const initialState = (product?: ProductDetail | null) => ({
  name: product?.name ?? '',
  sku: product?.sku ?? '',
  category: product?.category ?? '',
  description: product?.description ?? '',
  image_url: '',
  is_active: product?.is_active ?? true,
  option_name: product?.variants.find((v) => v.option_name)?.option_name ?? '',
  rows: product?.variants.length
    ? product.variants.map((v) =>
        newRow({
          id: v.id,
          option_value: v.option_value ?? '',
          sku: v.sku,
          price: String(v.price),
          cost_price: v.cost_price !== null ? String(v.cost_price) : '',
          barcode: v.barcode ?? '',
          is_active: v.is_active,
        })
      )
    : [newRow()],
  store_ids: product?.store_ids ?? [],
});

/** A product's details as the save request; used by the form and by archive / reactivate. */
export const productPayload = (product: ProductDetail, overrides: Partial<ProductPayload> = {}): ProductPayload => ({
  name: product.name,
  sku: product.sku,
  category: product.category,
  description: product.description,
  is_active: product.is_active,
  variants: product.variants.map((v) => ({
    id: v.id,
    sku: v.sku,
    option_name: v.option_name,
    option_value: v.option_value,
    barcode: v.barcode,
    price: v.price,
    cost_price: v.cost_price,
    is_active: v.is_active,
  })),
  ...overrides,
});

const canWriteTo = (store: StoreModel) => store.connection_state === 'connected' && WRITABLE_PLATFORMS.includes(store.platform);

interface Props {
  /** The product being edited; empty to add a new one. */
  product?: ProductDetail | null;
  onClose: () => void;
  onSaved: (message: string, productId: number) => void;
}

/**
 * Add or edit a product with its variants. Saving also creates or updates it on the chosen stores; the stores
 * it is already on always get the change.
 */
export const ProductFormModal: React.FC<Props> = ({ product, onClose, onSaved }) => {
  const stores = useStoreList();
  const [form, setForm] = useState(() => initialState(product));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const hasOptions = form.rows.length > 1 || Boolean(form.option_name.trim());
  const linkedIds = useMemo(() => new Set(product?.store_ids ?? []), [product]);

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((prev) => ({ ...prev, [key]: value }));
  const setRow = (key: string, field: keyof VariantRow, value: string | boolean) =>
    setForm((prev) => ({ ...prev, rows: prev.rows.map((row) => (row.key === key ? { ...row, [field]: value } : row)) }));
  const toggleStore = (id: number) =>
    set('store_ids', form.store_ids.includes(id) ? form.store_ids.filter((storeId) => storeId !== id) : [...form.store_ids, id]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const payload: ProductPayload = {
      name: form.name.trim(),
      sku: form.sku.trim(),
      category: form.category.trim() || null,
      description: form.description.trim() || null,
      image_url: form.image_url.trim() || undefined,
      is_active: form.is_active,
      store_ids: form.store_ids.filter((id) => !linkedIds.has(id)),
      variants: form.rows.map((row) => ({
        id: row.id,
        // A single variant without options takes the product SKU when none is typed.
        sku: row.sku.trim() || (form.rows.length === 1 ? form.sku.trim() : ''),
        option_name: hasOptions ? form.option_name.trim() || 'Option' : null,
        option_value: hasOptions ? row.option_value.trim() : null,
        barcode: row.barcode.trim() || null,
        price: Number(row.price || 0),
        cost_price: row.cost_price === '' ? null : Number(row.cost_price),
        is_active: row.is_active,
      })),
    };

    try {
      setSaving(true);
      setError(null);
      const res = product ? await omsService.updateProduct(product.id, payload) : await omsService.createProduct(payload);
      onSaved(res.message, res.data.id);
    } catch (err) {
      setError(errorMessage(err, 'The product could not be saved.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={() => !saving && onClose()}>
      <form className="procurement-modal cf-modal" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>{product ? `Edit ${product.name}` : 'Add Product'}</h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>
              {product ? 'Changes are also sent to every store this product is on.' : 'The product is created in Finovo and on the stores you choose.'}
            </p>
          </div>
          <button type="button" className="topbar-icon-button" onClick={onClose} aria-label="Close" disabled={saving}>
            <X size={18} />
          </button>
        </div>

        <div className="procurement-modal-body cf-body">
          {error && <Alert type="error" message={error} />}

          <div className="cf-grid">
            <label className="cf-field">
              <span className="cf-label">Product name *</span>
              <input className="cf-input" value={form.name} onChange={(e) => set('name', e.target.value)} maxLength={255} required autoFocus />
            </label>
            <label className="cf-field">
              <span className="cf-label">Product SKU *</span>
              <input className="cf-input" value={form.sku} onChange={(e) => set('sku', e.target.value.toUpperCase())} maxLength={100} required />
            </label>
            <label className="cf-field">
              <span className="cf-label">Category</span>
              <input className="cf-input" value={form.category} onChange={(e) => set('category', e.target.value)} maxLength={100} placeholder="e.g. Kitchen" />
            </label>
            {!product && (
              <label className="cf-field">
                <span className="cf-label">Photo link</span>
                <input className="cf-input" type="url" value={form.image_url} onChange={(e) => set('image_url', e.target.value)} placeholder="https://..." />
              </label>
            )}
            <label className="cf-field is-wide">
              <span className="cf-label">Description</span>
              <textarea className="cf-input" value={form.description} onChange={(e) => set('description', e.target.value)} maxLength={10000} />
            </label>
          </div>

          <div className="cf-section-title">
            <span>Variants</span>
            <button type="button" className="btn-finovo-secondary" style={{ padding: '6px 12px', fontSize: 12 }} onClick={() => set('rows', [...form.rows, newRow()])}>
              <Plus size={14} />
              <span>Add variant</span>
            </button>
          </div>

          {hasOptions && (
            <label className="cf-field" style={{ maxWidth: 320 }}>
              <span className="cf-label">Option name *</span>
              <input className="cf-input" value={form.option_name} onChange={(e) => set('option_name', e.target.value)} placeholder="e.g. Size, or Size / Color" required />
              <span className="cf-hint">For two options write "Size / Color" and values like "M / Red".</span>
            </label>
          )}

          <div className="cf-table-wrap">
            <table className="cf-table">
              <thead>
                <tr>
                  {hasOptions && <th>Option value *</th>}
                  <th>SKU {form.rows.length > 1 ? '*' : ''}</th>
                  <th>Price *</th>
                  <th>Cost</th>
                  <th>Barcode</th>
                  <th>Active</th>
                  <th aria-label="Remove" />
                </tr>
              </thead>
              <tbody>
                {form.rows.map((row, index) => (
                  <tr key={row.key}>
                    {hasOptions && (
                      <td style={{ minWidth: 140 }}>
                        <input className="cf-input" aria-label={`Option value, variant ${index + 1}`} value={row.option_value} onChange={(e) => setRow(row.key, 'option_value', e.target.value)} required />
                      </td>
                    )}
                    <td style={{ minWidth: 150 }}>
                      <input
                        className="cf-input"
                        aria-label={`SKU, variant ${index + 1}`}
                        value={row.sku}
                        onChange={(e) => setRow(row.key, 'sku', e.target.value.toUpperCase())}
                        placeholder={form.rows.length === 1 ? form.sku || 'Same as product' : ''}
                        required={form.rows.length > 1}
                      />
                    </td>
                    <td style={{ minWidth: 110 }}>
                      <input className="cf-input" type="number" min={0} step="0.01" aria-label={`Price, variant ${index + 1}`} value={row.price} onChange={(e) => setRow(row.key, 'price', e.target.value)} required />
                    </td>
                    <td style={{ minWidth: 110 }}>
                      <input className="cf-input" type="number" min={0} step="0.01" aria-label={`Cost, variant ${index + 1}`} value={row.cost_price} onChange={(e) => setRow(row.key, 'cost_price', e.target.value)} />
                    </td>
                    <td style={{ minWidth: 140 }}>
                      <input className="cf-input" aria-label={`Barcode, variant ${index + 1}`} value={row.barcode} onChange={(e) => setRow(row.key, 'barcode', e.target.value)} />
                    </td>
                    <td>
                      <input type="checkbox" aria-label={`Variant ${index + 1} active`} checked={row.is_active} onChange={(e) => setRow(row.key, 'is_active', e.target.checked)} />
                    </td>
                    <td>
                      <button
                        type="button"
                        className="table-action-btn is-danger"
                        onClick={() => set('rows', form.rows.filter((r) => r.key !== row.key))}
                        disabled={form.rows.length === 1}
                        aria-label={`Remove variant ${index + 1}`}
                      >
                        <Trash2 size={15} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {stores.length > 0 && (
            <>
              <div className="cf-section-title">Sell on stores</div>
              <div className="cf-stores">
                {stores.map((store) => {
                  const linked = linkedIds.has(store.id);
                  const writable = canWriteTo(store);
                  const checked = linked || form.store_ids.includes(store.id);
                  return (
                    <label key={store.id} className={`cf-store ${checked ? 'is-selected' : ''} ${linked || !writable ? 'is-disabled' : ''}`}>
                      <input type="checkbox" checked={checked} disabled={linked || !writable} onChange={() => toggleStore(store.id)} />
                      <span>
                        {store.name}
                        <small>
                          {linked
                            ? `Already on ${platformLabel(store.platform)}; changes are sent there`
                            : writable
                              ? `Create it on ${platformLabel(store.platform)}`
                              : store.connection_state !== 'connected'
                                ? 'Store is not connected'
                                : `${platformLabel(store.platform)} products are added on the store itself`}
                        </small>
                      </span>
                    </label>
                  );
                })}
              </div>
            </>
          )}

          <label className="cf-toggle">
            <input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
            Active (unticked: archived, and saved as a draft on the stores)
          </label>
        </div>

        <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn-outline" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving && <Loader2 size={16} className="animate-spin" />}
              <span>{product ? 'Save Product' : 'Add Product'}</span>
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

export default ProductFormModal;
