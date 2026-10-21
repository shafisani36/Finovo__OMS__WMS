import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  Mail,
  Phone,
  MapPin,
  Globe,
  Store as StoreIcon,
  Server,
  TrendingUp,
  CalendarPlus,
  ShoppingBag,
  Wallet,
  Package,
  CalendarDays,
  Contact,
  FileText,
  Search,
  Loader2,
  AlertCircle,
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import omsService from '../services/omsService';
import type { CustomerDetailModel, CustomerOrderModel } from '../services/omsService';
import { platformLabel } from '../components/stores/storePlatforms';
import ProductThumb from '../components/catalog/ProductThumb';
import StatusPill from '../components/common/StatusPill';
import { formatDate, formatMoney, formatMoneyByCurrency, humanize, initials } from '../utils/format';
import '../components/customers/CustomerDetail.css';

type OrderLine = { order: CustomerOrderModel; item: CustomerOrderModel['items'][number] | null };

const Field: React.FC<{ icon: React.ReactNode; label: string; children?: React.ReactNode }> = ({ icon, label, children }) => (
  <div>
    <div className="customer-field-label">
      {icon}
      <span>{label}</span>
    </div>
    <div className={`customer-field-value${children ? '' : ' is-empty'}`}>{children || 'Not provided'}</div>
  </div>
);

export const CustomerDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [customer, setCustomer] = useState<CustomerDetailModel | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      try {
        setLoading(true);
        setError(null);
        const res = await omsService.getCustomer(Number(id));
        if (!cancelled) setCustomer(res.data);
      } catch (err) {
        console.error(err);
        if (!cancelled) setError('This customer could not be found.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };
    load();
    return () => {
      cancelled = true;
    };
  }, [id]);

  // One row per product line, like the order history in the store admin.
  const lines = useMemo<OrderLine[]>(
    () =>
      (customer?.orders ?? []).flatMap((order): OrderLine[] =>
        order.items.length ? order.items.map((item) => ({ order, item })) : [{ order, item: null }],
      ),
    [customer],
  );

  const visibleLines = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return lines;
    return lines.filter(({ order, item }) =>
      [order.order_number, order.external_order_id, item?.product_name, item?.external_sku]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(term)),
    );
  }, [lines, search]);

  if (loading) {
    return (
      <div className="page-container" style={{ display: 'flex', justifyContent: 'center', padding: 80 }}>
        <Loader2 size={28} className="animate-spin" color="#64748b" />
      </div>
    );
  }

  if (error || !customer) {
    return (
      <div className="page-container">
        <div className="content-card" style={{ padding: 48, textAlign: 'center' }}>
          <AlertCircle size={28} color="#ef4444" />
          <p style={{ fontSize: 14, color: '#334155' }}>{error ?? 'Something went wrong.'}</p>
          <button className="btn-finovo-secondary" onClick={() => navigate('/customers')}>
            <ArrowLeft size={14} />
            <span>Back to Customers</span>
          </button>
        </div>
      </div>
    );
  }

  const { stats } = customer;
  const address = customer.default_address;
  const street = [address?.address1, address?.address2].filter(Boolean).join(', ');
  const cityCountry = [address?.city, address?.province, address?.country || address?.country_code].filter(Boolean).join(', ');
  const storeNames = customer.stores.map((store) => store.name).join(', ');
  const sources = Array.from(new Set(customer.stores.map((store) => platformLabel(store.platform)))).join(', ');

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ cursor: 'pointer' }} onClick={() => navigate('/customers')}>Customers</span>
        <span>&gt;</span>
        <span style={{ color: '#2563eb', fontWeight: 500 }}>{customer.name}</span>
      </nav>

      <div className="customer-head">
        <div className="customer-hero">
          <div className="customer-avatar">{initials(customer.name).charAt(0)}</div>
          <div>
            <h1 className="page-title" style={{ margin: 0 }}>{customer.name}</h1>
            <p className="page-subtitle" style={{ margin: '4px 0 0' }}>
              {stats.orders_count} order(s) from this customer
            </p>
          </div>
        </div>
        <button className="btn-finovo-secondary" onClick={() => navigate('/customers')}>
          <ArrowLeft size={14} />
          <span>Back to Customers</span>
        </button>
      </div>

      <div className="kpi-grid">
        <MetricCard
          title="Orders"
          value={stats.orders_count}
          icon={<ShoppingBag size={20} />}
          pillText={stats.orders_count > 1 ? 'Repeat' : 'One-time'}
          pillVariant={stats.orders_count > 1 ? 'green' : 'amber'}
        />
        <MetricCard title="Total Spent" value={formatMoneyByCurrency(stats.spent)} icon={<Wallet size={20} />} />
        <MetricCard title="Items Bought" value={stats.items_purchased} icon={<Package size={20} />} pillText="Units" />
        <MetricCard title="Last Order" value={stats.last_order_at ? formatDate(stats.last_order_at) : '—'} icon={<CalendarDays size={20} />} />
      </div>

      <section className="customer-section">
        <div className="customer-section-head">
          <div className="customer-section-title">
            <div className="customer-section-icon">
              <Contact size={18} />
            </div>
            <div>
              <h3>Contact Details</h3>
              <p>Taken from this customer's orders on the store's platform.</p>
            </div>
          </div>
        </div>
        <div className="customer-fields">
          <Field icon={<Mail size={13} />} label="Email">
            {customer.email && <a href={`mailto:${customer.email}`}>{customer.email}</a>}
          </Field>
          <Field icon={<Phone size={13} />} label="Phone">
            {customer.phone && <a href={`tel:${customer.phone}`}>{customer.phone}</a>}
          </Field>
          <Field icon={<MapPin size={13} />} label="Address">
            {street || (address?.zip ? `Postcode ${address.zip}` : null)}
          </Field>
          <Field icon={<Globe size={13} />} label="City / Country">
            {cityCountry}
          </Field>
          <Field icon={<StoreIcon size={13} />} label={customer.stores.length > 1 ? 'Stores' : 'Store'}>
            {storeNames}
          </Field>
          <Field icon={<Server size={13} />} label="Source">
            {sources}
          </Field>
          <Field icon={<TrendingUp size={13} />} label="Avg. Order Value">
            {stats.spent.length ? formatMoneyByCurrency(stats.spent.map((entry) => ({ currency: entry.currency, amount: entry.average }))) : null}
          </Field>
          <Field icon={<CalendarPlus size={13} />} label="Customer Since">
            {stats.first_order_at ? formatDate(stats.first_order_at) : formatDate(customer.created_at)}
          </Field>
        </div>
      </section>

      <section className="customer-section">
        <div className="customer-section-head">
          <div className="customer-section-title">
            <div className="customer-section-icon">
              <FileText size={18} />
            </div>
            <div>
              <h3>Order History</h3>
              <p>Every order placed by {customer.name}.</p>
            </div>
          </div>
          <div className="customer-search">
            <Search size={15} />
            <input type="text" placeholder="Search product or order #..." value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </div>

        <div className="table-responsive-wrapper">
          <table className="finovo-table">
            <thead>
              <tr>
                <th>Order</th>
                <th>Product</th>
                <th style={{ textAlign: 'center' }}>Qty</th>
                <th style={{ textAlign: 'right' }}>Price</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {visibleLines.length === 0 && (
                <tr>
                  <td colSpan={6} style={{ textAlign: 'center', padding: 40 }}>
                    <FileText size={24} color="#94a3b8" />
                    <div style={{ marginTop: 8, fontSize: 13, color: '#334155' }}>
                      {search ? 'No order lines match your search.' : 'No orders found for this customer.'}
                    </div>
                  </td>
                </tr>
              )}
              {visibleLines.map(({ order, item }) => (
                <tr key={`${order.id}-${item?.id ?? 'none'}`}>
                  <td>
                    <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{order.order_number}</div>
                    <div className="customer-muted">{order.store ? order.store.name : '—'}</div>
                  </td>
                  <td>
                    {item ? (
                      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <ProductThumb src={item.image_url} />
                        <div>
                          <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{item.product_name}</div>
                          <div className="customer-muted">{item.external_sku || 'No SKU'}</div>
                        </div>
                      </div>
                    ) : (
                      <span className="customer-muted">No line items imported</span>
                    )}
                  </td>
                  <td style={{ textAlign: 'center', fontSize: 13 }}>{item ? item.quantity : '—'}</td>
                  <td style={{ textAlign: 'right', fontSize: 13, fontWeight: 600 }}>
                    {formatMoney(item ? item.total_amount : order.total_amount, order.currency)}
                  </td>
                  <td>
                    <StatusPill status={order.order_status} />
                    <div className="customer-muted" style={{ marginTop: 4 }}>
                      {humanize(order.payment_status)} · {humanize(order.fulfillment_status)}
                    </div>
                  </td>
                  <td style={{ fontSize: 13, color: '#475569' }}>{formatDate(order.placed_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="customer-history-footer">
          Showing {visibleLines.length} of {lines.length} order lines
        </div>
      </section>
    </div>
  );
};

export default CustomerDetailPage;
