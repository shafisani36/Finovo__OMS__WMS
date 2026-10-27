import React, { useEffect, useMemo, useState } from 'react';
import { Loader2, Search, Trash2, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService, { ORDER_CREATE_PLATFORMS } from '../../services/omsService';
import type { OrderModel, OrderPayload, ProductModel } from '../../services/omsService';
import { platformLabel } from '../stores/storePlatforms';
import { useStoreList } from '../../hooks/useStoreList';
import { useActiveStore } from '../../hooks/useActiveStore';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { errorMessage } from '../../utils/errors';
import { formatMoney } from '../../utils/format';
import './CatalogForms.css';

interface Line {
  variantId: number;
  name: string;
  sku: string;
  available: number | null;
  quantity: string;
  unitPrice: string;
}

const CURRENCIES = ['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'INR', 'CAD', 'AUD'];

const ADDRESS_FIELDS: { key: keyof NonNullable<OrderPayload['shipping_address']>; label: string; wide?: boolean }[] = [
  { key: 'address1', label: 'Address line 1', wide: true },
  { key: 'address2', label: 'Address line 2', wide: true },
  { key: 'city', label: 'City' },
  { key: 'province', label: 'Province / state' },
  { key: 'zip', label: 'Postal code' },
  { key: 'country_code', label: 'Country code (e.g. PK)' },
];

const linesOf = (order?: OrderModel | null): Line[] =>
  (order?.items ?? [])
    .filter((item) => item.variant)
    .map((item) => ({
      variantId: item.variant!.id,
      name: item.product_name,
      sku: item.external_sku,
      available: null,
      quantity: String(item.quantity),
      unitPrice: String(Number(item.unit_price)),
    }));

/** Search the catalog and pick a variant to add to the order. */
const ProductPicker: React.FC<{ onPick: (line: Line) => void }> = ({ onPick }) => {
  const [search, setSearch] = useState('');
  const term = useDebouncedValue(search.trim());
  const [results, setResults] = useState<ProductModel[]>([]);

  useEffect(() => {
    if (!term) {
      setResults([]);
      return;
    }
    let active = true;
    omsService
      .getProducts({ search: term, per_page: 8 })
      .then((res) => active && setResults(res.data))
      .catch(() => active && setResults([]));
    return () => {
      active = false;
    };
  }, [term]);

  const options = results.flatMap((product) =>
    (product.variants ?? [])
      .filter((variant) => variant.is_active !== false)
      .map((variant) => ({ product, variant }))
  );

  return (
    <div className="cf-picker">
      <div className="topbar-search-input" style={{ width: '100%', boxSizing: 'border-box' }}>
        <Search size={16} color="#94a3b8" />
        <input type="text" placeholder="Search a product by name or SKU to add it..." value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search products to add" />
      </div>
      {term && (
        <div className="cf-picker-list" role="listbox">
          {options.length === 0 ? (
            <div style={{ padding: 10, fontSize: 13, color: '#64748b' }}>No products match.</div>
          ) : (
            options.map(({ product, variant }) => (
              <button
                key={variant.id}
                type="button"
                role="option"
                aria-selected="false"
                className="cf-picker-item"
                onClick={() => {
                  onPick({
                    variantId: variant.id,
                    name: product.name + (variant.option_value ? ` - ${variant.option_value}` : ''),
                    sku: variant.sku,
                    available: variant.stock?.available ?? null,
                    quantity: '1',
                    unitPrice: String(variant.price ?? 0),
                  });
                  setSearch('');
                }}
              >
                <span>
                  {product.name}
                  {variant.option_value ? ` - ${variant.option_value}` : ''}
                  <small style={{ display: 'block' }}>{variant.sku}</small>
                </span>
                <small>
                  {variant.stock?.available ?? 0} available · {Number(variant.price ?? 0).toFixed(2)}
                </small>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
};

interface Props {
  /** The order being edited; empty to create a new one. */
  order?: OrderModel | null;
  onClose: () => void;
  onSaved: (message: string) => void;
}

/**
 * Create an order by hand (phone, walk-in or wholesale) or edit one still waiting for stock. An order placed on
 * a connected store is also created there; for an order already on its store only the customer, address and
 * note can change.
 */
export const OrderFormModal: React.FC<Props> = ({ order, onClose, onSaved }) => {
  const stores = useStoreList();
  const { activeStore } = useActiveStore();
  const onStore = Boolean(order?.external_order_id);
  // A new order belongs to the store being managed, so it shows in that store's order list.
  const [storeId, setStoreId] = useState<number | ''>(order ? (order.store?.id ?? '') : (activeStore?.id ?? ''));
  const [customer, setCustomer] = useState({
    name: order?.customer_name ?? '',
    email: order?.customer_email ?? '',
    phone: order?.customer_phone ?? '',
  });
  const [address, setAddress] = useState<NonNullable<OrderPayload['shipping_address']>>({ ...(order?.shipping_address ?? {}) });
  const [payment, setPayment] = useState({
    status: (order?.payment_status === 'paid' ? 'paid' : 'pending') as OrderPayload['payment_status'],
    method: order?.payment_method ?? '',
    currency: order?.currency ?? 'PKR',
  });
  const [amounts, setAmounts] = useState({
    shipping: order?.shipping_amount ? String(Number(order.shipping_amount)) : '',
    discount: order?.discount_amount ? String(Number(order.discount_amount)) : '',
    tax: order?.tax_amount ? String(Number(order.tax_amount)) : '',
  });
  const [notes, setNotes] = useState(order?.notes ?? '');
  const [lines, setLines] = useState<Line[]>(() => linesOf(order));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const subtotal = useMemo(() => lines.reduce((sum, line) => sum + Number(line.quantity || 0) * Number(line.unitPrice || 0), 0), [lines]);
  const total = Math.max(0, subtotal - Number(amounts.discount || 0) + Number(amounts.shipping || 0) + Number(amounts.tax || 0));
  const chosenStore = stores.find((store) => store.id === storeId);

  const addLine = (line: Line) =>
    setLines((prev) =>
      prev.some((l) => l.variantId === line.variantId)
        ? prev.map((l) => (l.variantId === line.variantId ? { ...l, quantity: String(Number(l.quantity || 0) + 1) } : l))
        : [...prev, line]
    );
  const updateLine = (variantId: number, field: 'quantity' | 'unitPrice', value: string) =>
    setLines((prev) => prev.map((line) => (line.variantId === variantId ? { ...line, [field]: value } : line)));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!onStore && lines.length === 0) {
      setError('Add at least one product to the order.');
      return;
    }

    const payload: OrderPayload = {
      store_id: storeId === '' ? null : storeId,
      customer_name: customer.name.trim(),
      customer_email: customer.email.trim() || null,
      customer_phone: customer.phone.trim() || null,
      shipping_address: Object.values(address).some((value) => value?.trim())
        ? { ...address, name: address.name || customer.name.trim(), country_code: address.country_code?.trim().toUpperCase() || undefined }
        : null,
      payment_status: payment.status,
      payment_method: payment.method.trim() || null,
      currency: payment.currency.trim().toUpperCase(),
      notes: notes.trim() || null,
      shipping_amount: Number(amounts.shipping || 0),
      discount_amount: Number(amounts.discount || 0),
      tax_amount: Number(amounts.tax || 0),
      // An order on its store keeps its lines; they are only sent when they can change.
      ...(onStore ? {} : { items: lines.map((line) => ({ product_variant_id: line.variantId, quantity: Number(line.quantity), unit_price: Number(line.unitPrice) })) }),
    };

    try {
      setSaving(true);
      setError(null);
      const res = order ? await omsService.updateOrder(order.id, payload) : await omsService.createOrder(payload);
      onSaved(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The order could not be saved.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={() => !saving && onClose()}>
      <form className="procurement-modal cf-modal" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>{order ? `Edit order ${order.order_number}` : 'Create Order'}</h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>
              {order ? 'Changes are also sent to the order’s store.' : 'For phone, walk-in or wholesale orders. Stock is reserved when you allocate it.'}
            </p>
          </div>
          <button type="button" className="topbar-icon-button" onClick={onClose} aria-label="Close" disabled={saving}>
            <X size={18} />
          </button>
        </div>

        <div className="procurement-modal-body cf-body">
          {error && <Alert type="error" message={error} />}

          <div className="cf-grid">
            <div className="cf-field">
              <span className="cf-label">Store</span>
              <CustomDropdown
                value={storeId === '' ? '' : String(storeId)}
                onChange={(val) => setStoreId(val === '' ? '' : Number(val))}
                disabled={onStore}
                searchable={false}
                options={[
                  { value: '', label: 'No store (Finovo only)', description: 'Walk-in, phone or wholesale' },
                  ...stores.map((store) => ({
                    value: String(store.id),
                    label: store.name,
                    description:
                      store.connection_state === 'connected' && ORDER_CREATE_PLATFORMS.includes(store.platform)
                        ? `Also created on ${platformLabel(store.platform)}`
                        : `${platformLabel(store.platform)}: kept in Finovo only`,
                  })),
                ]}
              />
            </div>
            <label className="cf-field">
              <span className="cf-label">Customer name *</span>
              <input className="cf-input" value={customer.name} onChange={(e) => setCustomer({ ...customer, name: e.target.value })} maxLength={255} required autoFocus />
            </label>
            <label className="cf-field">
              <span className="cf-label">Email</span>
              <input className="cf-input" type="email" value={customer.email} onChange={(e) => setCustomer({ ...customer, email: e.target.value })} maxLength={255} />
            </label>
            <label className="cf-field">
              <span className="cf-label">Phone</span>
              <input className="cf-input" value={customer.phone} onChange={(e) => setCustomer({ ...customer, phone: e.target.value })} maxLength={50} />
            </label>
          </div>

          <div className="cf-section-title">Shipping address</div>
          <div className="cf-grid">
            {ADDRESS_FIELDS.map((field) => (
              <label key={field.key} className={`cf-field ${field.wide ? 'is-wide' : ''}`}>
                <span className="cf-label">{field.label}</span>
                <input
                  className="cf-input"
                  value={address[field.key] ?? ''}
                  onChange={(e) => setAddress({ ...address, [field.key]: e.target.value })}
                  maxLength={field.key === 'country_code' ? 2 : 255}
                />
              </label>
            ))}
          </div>

          <div className="cf-section-title">Products</div>
          {onStore ? (
            <div className="cf-note">This order is on {order?.store?.name}, so its products and quantities are changed there.</div>
          ) : (
            <ProductPicker onPick={addLine} />
          )}

          {lines.length > 0 && (
            <div className="cf-table-wrap">
              <table className="cf-table">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Unit price</th>
                    <th>Line total</th>
                    {!onStore && <th aria-label="Remove" />}
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line) => {
                    const short = line.available !== null && Number(line.quantity) > line.available;
                    return (
                      <tr key={line.variantId}>
                        <td>
                          <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{line.name}</div>
                          <div style={{ fontSize: 11, color: short ? '#b45309' : '#64748b' }}>
                            {line.sku}
                            {line.available !== null && ` · ${line.available} available${short ? ' (not enough stock yet)' : ''}`}
                          </div>
                        </td>
                        <td style={{ width: 110 }}>
                          <input className="cf-input" type="number" min={1} aria-label={`Quantity of ${line.name}`} value={line.quantity} onChange={(e) => updateLine(line.variantId, 'quantity', e.target.value)} disabled={onStore} required />
                        </td>
                        <td style={{ width: 130 }}>
                          <input className="cf-input" type="number" min={0} step="0.01" aria-label={`Unit price of ${line.name}`} value={line.unitPrice} onChange={(e) => updateLine(line.variantId, 'unitPrice', e.target.value)} disabled={onStore} required />
                        </td>
                        <td style={{ whiteSpace: 'nowrap', fontWeight: 600 }}>{formatMoney(Number(line.quantity || 0) * Number(line.unitPrice || 0), payment.currency)}</td>
                        {!onStore && (
                          <td>
                            <button type="button" className="table-action-btn is-danger" onClick={() => setLines((prev) => prev.filter((l) => l.variantId !== line.variantId))} aria-label={`Remove ${line.name}`}>
                              <Trash2 size={15} />
                            </button>
                          </td>
                        )}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          <div className="cf-section-title">Payment and totals</div>
          <div className="cf-totals">
            <div className="cf-field">
              <span className="cf-label">Payment</span>
              <CustomDropdown
                value={payment.status}
                onChange={(val) => setPayment({ ...payment, status: val as OrderPayload['payment_status'] })}
                searchable={false}
                options={[
                  { value: 'pending', label: 'Not paid yet', description: 'e.g. cash on delivery' },
                  { value: 'paid', label: 'Paid' },
                ]}
              />
            </div>
            <label className="cf-field">
              <span className="cf-label">Payment method</span>
              <input className="cf-input" value={payment.method} onChange={(e) => setPayment({ ...payment, method: e.target.value })} placeholder="e.g. Cash on delivery" maxLength={100} />
            </label>
            <label className="cf-field">
              <span className="cf-label">Currency *</span>
              <input className="cf-input" list="order-currencies" value={payment.currency} onChange={(e) => setPayment({ ...payment, currency: e.target.value.toUpperCase() })} maxLength={3} required />
              <datalist id="order-currencies">
                {CURRENCIES.map((code) => (
                  <option key={code} value={code} />
                ))}
              </datalist>
            </label>
            <label className="cf-field">
              <span className="cf-label">Shipping</span>
              <input className="cf-input" type="number" min={0} step="0.01" value={amounts.shipping} onChange={(e) => setAmounts({ ...amounts, shipping: e.target.value })} />
            </label>
            <label className="cf-field">
              <span className="cf-label">Discount</span>
              <input className="cf-input" type="number" min={0} step="0.01" value={amounts.discount} onChange={(e) => setAmounts({ ...amounts, discount: e.target.value })} />
            </label>
            <label className="cf-field">
              <span className="cf-label">Tax</span>
              <input className="cf-input" type="number" min={0} step="0.01" value={amounts.tax} onChange={(e) => setAmounts({ ...amounts, tax: e.target.value })} />
            </label>
            <div className="cf-total-box">
              <span>Order total</span>
              <strong>{formatMoney(total, payment.currency || 'PKR')}</strong>
            </div>
          </div>

          <label className="cf-field">
            <span className="cf-label">Note</span>
            <textarea className="cf-input" value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} placeholder="Delivery instructions or anything the team should know" />
          </label>

          {chosenStore && !onStore && !(chosenStore.connection_state === 'connected' && ORDER_CREATE_PLATFORMS.includes(chosenStore.platform)) && (
            <div className="cf-note">{chosenStore.name} cannot take orders from Finovo, so this order stays in Finovo only.</div>
          )}
        </div>

        <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn-outline" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving && <Loader2 size={16} className="animate-spin" />}
              <span>{order ? 'Save Order' : 'Create Order'}</span>
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

export default OrderFormModal;
