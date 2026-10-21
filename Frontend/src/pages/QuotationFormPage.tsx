import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Loader2, Plus, Save, Trash2 } from 'lucide-react';
import CustomDropdown from '../components/common/CustomDropdown';
import ProductThumb from '../components/catalog/ProductThumb';
import SearchPicker from '../components/quotations/SearchPicker';
import { addDaysIso, apiErrorMessage, lineAmounts, quotationTotals, todayIso } from '../components/quotations/quotationUtils';
import '../components/quotations/Quotations.css';
import omsService from '../services/omsService';
import type { CustomerAddress, CustomerModel, ProductModel, ProductVariantModel, QuotationItemInput, StoreModel } from '../services/omsService';
import { useActiveStore } from '../hooks/useActiveStore';
import { formatMoney, variantLabel } from '../utils/format';

const CURRENCIES = ['PKR', 'USD', 'AED', 'SAR', 'GBP', 'EUR'];
const DEFAULT_VALID_DAYS = 15;

type ProductOption = { product: ProductModel; variant: ProductVariantModel };

const emptyItem = (): QuotationItemInput => ({ description: '', quantity: 1, unit_price: 0, discount_percent: 0, tax_percent: 0 });

export const QuotationFormPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const editingId = id ? Number(id) : null;
  const navigate = useNavigate();
  const { activeStore } = useActiveStore();

  const [stores, setStores] = useState<StoreModel[]>([]);
  const [loading, setLoading] = useState(Boolean(editingId));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [locked, setLocked] = useState(false);

  const [storeId, setStoreId] = useState<number | ''>(activeStore?.id ?? '');
  const [customerId, setCustomerId] = useState<number | null>(null);
  const [customerName, setCustomerName] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [address, setAddress] = useState<CustomerAddress>({});
  const [currency, setCurrency] = useState('PKR');
  const [issueDate, setIssueDate] = useState(todayIso());
  const [validUntil, setValidUntil] = useState(addDaysIso(todayIso(), DEFAULT_VALID_DAYS));
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [items, setItems] = useState<QuotationItemInput[]>([]);
  const [imageByVariant, setImageByVariant] = useState<Record<number, string | null | undefined>>({});

  useEffect(() => {
    omsService
      .getStores({ per_page: 100 })
      .then((res) => setStores(res.data))
      .catch((err) => console.error(err));
  }, []);

  useEffect(() => {
    if (!editingId) return;
    omsService
      .getQuotation(editingId)
      .then(({ data }) => {
        setStoreId(data.store_id);
        setCustomerId(data.customer_id ?? null);
        setCustomerName(data.customer_name);
        setCustomerEmail(data.customer_email ?? '');
        setCustomerPhone(data.customer_phone ?? '');
        setAddress(data.billing_address ?? {});
        setCurrency(data.currency);
        setIssueDate(data.issue_date);
        setValidUntil(data.valid_until);
        setNotes(data.notes ?? '');
        setTerms(data.terms ?? '');
        setItems(
          (data.items ?? []).map((item) => ({
            product_variant_id: item.product_variant_id ?? null,
            sku: item.sku ?? null,
            description: item.description,
            quantity: Number(item.quantity),
            unit_price: Number(item.unit_price),
            discount_percent: Number(item.discount_percent ?? 0),
            tax_percent: Number(item.tax_percent ?? 0),
          })),
        );
        setImageByVariant(
          Object.fromEntries((data.items ?? []).filter((i) => i.product_variant_id).map((i) => [i.product_variant_id, i.variant?.product?.image_url])),
        );
        if (data.status !== 'draft' && data.status !== 'sent') {
          setLocked(true);
          setError(`This quotation is ${data.status} and can no longer be edited.`);
        }
      })
      .catch((err) => setError(apiErrorMessage(err, 'This quotation could not be loaded.')))
      .finally(() => setLoading(false));
  }, [editingId]);

  const totals = useMemo(() => quotationTotals(items), [items]);

  const updateItem = (index: number, changes: Partial<QuotationItemInput>) =>
    setItems((current) => current.map((item, i) => (i === index ? { ...item, ...changes } : item)));

  const removeItem = (index: number) => setItems((current) => current.filter((_, i) => i !== index));

  const searchProducts = async (term: string): Promise<ProductOption[]> => {
    const res = await omsService.getProducts({ search: term || undefined, store_id: storeId || undefined, per_page: 15 });
    return (res.data as ProductModel[]).flatMap((product) => (product.variants ?? []).map((variant) => ({ product, variant })));
  };

  const addProduct = ({ product, variant }: ProductOption) => {
    const label = variantLabel(variant);
    setImageByVariant((current) => ({ ...current, [variant.id]: product.image_url }));
    setItems((current) => [
      ...current,
      {
        product_variant_id: variant.id,
        sku: variant.sku,
        description: label ? `${product.name} - ${label}` : product.name,
        quantity: 1,
        unit_price: Number(variant.price) || 0,
        discount_percent: 0,
        tax_percent: 0,
      },
    ]);
  };

  const searchCustomers = async (term: string): Promise<CustomerModel[]> => {
    const res = await omsService.getCustomers({ search: term || undefined, per_page: 10 });
    return res.data;
  };

  const pickCustomer = (customer: CustomerModel) => {
    setCustomerId(customer.id);
    setCustomerName(customer.name);
    setCustomerEmail(customer.email ?? '');
    setCustomerPhone(customer.phone ?? '');
    setAddress(customer.default_address ?? {});
    if (customer.spent?.[0]?.currency) setCurrency(customer.spent[0].currency);
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);

    if (!storeId) return setError('Choose the store this quotation is for.');
    if (!customerName.trim()) return setError('Enter the customer name.');
    if (items.length === 0) return setError('Add at least one item.');
    if (items.some((item) => !item.description.trim())) return setError('Each item needs a description.');

    const payload = {
      store_id: Number(storeId),
      customer_id: customerId,
      customer_name: customerName.trim(),
      customer_email: customerEmail.trim() || null,
      customer_phone: customerPhone.trim() || null,
      billing_address: address,
      currency,
      issue_date: issueDate,
      valid_until: validUntil,
      notes: notes.trim() || null,
      terms: terms.trim() || null,
      items,
    };

    try {
      setSaving(true);
      const res = editingId ? await omsService.updateQuotation(editingId, payload) : await omsService.createQuotation(payload);
      navigate(`/quotations/${res.data.id}`);
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="page-container" style={{ display: 'flex', justifyContent: 'center', padding: 80 }}>
        <Loader2 size={28} className="animate-spin" color="#64748b" />
      </div>
    );
  }

  const field = (label: string, input: React.ReactNode, className = '') => (
    <div className={`qt-field ${className}`}>
      <label>{label}</label>
      {input}
    </div>
  );

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ cursor: 'pointer' }} onClick={() => navigate('/quotations')}>Quotations</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>{editingId ? 'Edit Quotation' : 'New Quotation'}</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">{editingId ? 'Edit Quotation' : 'New Quotation'}</h1>
          <p className="page-subtitle">Prices offered to a customer. Download it as a PDF and convert it to an order once accepted.</p>
        </div>
        <button type="button" className="btn-finovo-secondary" onClick={() => navigate(editingId ? `/quotations/${editingId}` : '/quotations')}>
          <ArrowLeft size={14} />
          <span>Back</span>
        </button>
      </div>

      <form onSubmit={handleSubmit}>
        {error && <div className="qt-error">{error}</div>}

        <div className="qt-card">
          <h3 className="qt-card-title">Customer</h3>
          <div className="qt-grid">
            {field(
              'Find an existing customer',
              <SearchPicker<CustomerModel>
                placeholder="Search by name, email or phone..."
                search={searchCustomers}
                optionKey={(c) => c.id}
                onPick={pickCustomer}
                emptyText="No customer found. Type the details below instead."
                renderOption={(c) => (
                  <div>
                    <div style={{ fontWeight: 600, color: '#0f172a' }}>{c.name}</div>
                    <div className="qt-muted">{[c.email, c.phone].filter(Boolean).join(' · ') || 'No contact details'}</div>
                  </div>
                )}
              />,
              'qt-span-2',
            )}
            {field(
              'Store *',
              <CustomDropdown
                placeholder="Select store..."
                value={storeId}
                onChange={(value) => setStoreId(Number(value))}
                options={stores.map((store) => ({ value: store.id, label: store.name }))}
              />,
            )}
            {field(
              'Currency *',
              <CustomDropdown value={currency} onChange={(value) => setCurrency(String(value))} options={CURRENCIES.map((code) => ({ value: code, label: code }))} />,
            )}
            {field(
              'Customer name *',
              <input className="qt-input" value={customerName} onChange={(e) => { setCustomerName(e.target.value); setCustomerId(null); }} required />,
            )}
            {field('Email', <input className="qt-input" type="email" value={customerEmail} onChange={(e) => setCustomerEmail(e.target.value)} />)}
            {field('Phone', <input className="qt-input" value={customerPhone} onChange={(e) => setCustomerPhone(e.target.value)} />)}
            {field('City', <input className="qt-input" value={address.city ?? ''} onChange={(e) => setAddress({ ...address, city: e.target.value })} />)}
            {field('Address', <input className="qt-input" value={address.address1 ?? ''} onChange={(e) => setAddress({ ...address, address1: e.target.value })} />, 'qt-span-2')}
            {field('Country', <input className="qt-input" value={address.country ?? ''} onChange={(e) => setAddress({ ...address, country: e.target.value })} />)}
            {field('Postcode', <input className="qt-input" value={address.zip ?? ''} onChange={(e) => setAddress({ ...address, zip: e.target.value })} />)}
            {field('Issue date', <input className="qt-input" type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} />)}
            {field('Valid until', <input className="qt-input" type="date" min={issueDate} value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />)}
          </div>
        </div>

        <div className="qt-card">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
            <h3 className="qt-card-title" style={{ margin: 0 }}>Items</h3>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
              <div style={{ width: 320, maxWidth: '100%' }}>
                <SearchPicker<ProductOption>
                  placeholder="Add a product from the catalog..."
                  search={searchProducts}
                  optionKey={(o) => o.variant.id}
                  onPick={addProduct}
                  emptyText="No products found."
                  renderOption={({ product, variant }) => (
                    <>
                      <ProductThumb src={product.image_url} size={32} />
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <div style={{ fontWeight: 600, color: '#0f172a' }}>{product.name}</div>
                        <div className="qt-muted">{[variantLabel(variant), variant.sku].filter(Boolean).join(' · ')}</div>
                      </div>
                      <span style={{ fontWeight: 600 }}>{Number(variant.price).toFixed(2)}</span>
                    </>
                  )}
                />
              </div>
              <button type="button" className="btn-finovo-secondary" onClick={() => setItems((c) => [...c, emptyItem()])}>
                <Plus size={14} />
                <span>Custom line</span>
              </button>
            </div>
          </div>

          <div className="table-responsive-wrapper">
            <table className="qt-items">
              <thead>
                <tr>
                  <th style={{ minWidth: 260 }}>Description</th>
                  <th style={{ width: 80 }}>Qty</th>
                  <th style={{ width: 120 }}>Unit price</th>
                  <th style={{ width: 80 }}>Disc. %</th>
                  <th style={{ width: 80 }}>Tax %</th>
                  <th style={{ width: 130, textAlign: 'right' }}>Amount</th>
                  <th style={{ width: 40 }} />
                </tr>
              </thead>
              <tbody>
                {items.length === 0 && (
                  <tr>
                    <td colSpan={7} className="qt-muted" style={{ textAlign: 'center', padding: 24 }}>
                      Search a product above, or add a custom line.
                    </td>
                  </tr>
                )}
                {items.map((item, index) => (
                  <tr key={index}>
                    <td>
                      <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
                        {item.product_variant_id ? <ProductThumb src={imageByVariant[item.product_variant_id]} size={36} /> : null}
                        <div style={{ flex: 1 }}>
                          <input
                            className="qt-input"
                            value={item.description}
                            placeholder="What is being offered"
                            onChange={(e) => updateItem(index, { description: e.target.value })}
                          />
                          {item.sku && <div className="qt-muted" style={{ marginTop: 4 }}>SKU: {item.sku}</div>}
                        </div>
                      </div>
                    </td>
                    <td>
                      <input className="qt-input is-number" type="number" min={1} step={1} value={item.quantity} onChange={(e) => updateItem(index, { quantity: Math.max(1, Math.floor(Number(e.target.value) || 1)) })} />
                    </td>
                    <td>
                      <input className="qt-input is-number" type="number" min={0} step="0.01" value={item.unit_price} onChange={(e) => updateItem(index, { unit_price: Math.max(0, Number(e.target.value) || 0) })} />
                    </td>
                    <td>
                      <input className="qt-input is-number" type="number" min={0} max={100} step="0.01" value={item.discount_percent ?? 0} onChange={(e) => updateItem(index, { discount_percent: Math.min(100, Math.max(0, Number(e.target.value) || 0)) })} />
                    </td>
                    <td>
                      <input className="qt-input is-number" type="number" min={0} max={100} step="0.01" value={item.tax_percent ?? 0} onChange={(e) => updateItem(index, { tax_percent: Math.min(100, Math.max(0, Number(e.target.value) || 0)) })} />
                    </td>
                    <td style={{ textAlign: 'right', fontWeight: 600, paddingTop: 16 }}>{formatMoney(lineAmounts(item).total, currency)}</td>
                    <td>
                      <button type="button" className="btn-finovo-secondary" style={{ padding: 7, color: '#ef4444' }} onClick={() => removeItem(index)} aria-label="Remove item">
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="qt-totals">
            <div>
              <span>Subtotal</span>
              <span>{formatMoney(totals.subtotal, currency)}</span>
            </div>
            {totals.discount > 0 && (
              <div>
                <span>Discount</span>
                <span>- {formatMoney(totals.discount, currency)}</span>
              </div>
            )}
            {totals.tax > 0 && (
              <div>
                <span>Tax</span>
                <span>{formatMoney(totals.tax, currency)}</span>
              </div>
            )}
            <div className="qt-grand">
              <span>Total</span>
              <span>{formatMoney(totals.total, currency)}</span>
            </div>
          </div>
        </div>

        <div className="qt-card">
          <div className="qt-grid">
            {field('Notes (shown on the PDF)', <textarea className="qt-input" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. Delivery within 5 working days" />, 'qt-span-2')}
            {field('Terms & conditions', <textarea className="qt-input" value={terms} onChange={(e) => setTerms(e.target.value)} placeholder="Leave empty to use the standard validity terms" />, 'qt-span-2')}
          </div>
        </div>

        <div className="qt-actions" style={{ marginBottom: 32 }}>
          <button type="button" className="btn-finovo-secondary" onClick={() => navigate(editingId ? `/quotations/${editingId}` : '/quotations')}>
            Cancel
          </button>
          <button type="submit" className="btn-finovo-primary" disabled={saving || locked}>
            {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
            <span>{editingId ? 'Save Changes' : 'Create Quotation'}</span>
          </button>
        </div>
      </form>
    </div>
  );
};

export default QuotationFormPage;
