import React, { useEffect, useState } from 'react';
import { LogOut, Search } from 'lucide-react';
import StatusPill from '../components/common/StatusPill';
import { Alert } from '../components/common/Alert';
import omsService from '../services/omsService';
import type { PortalOrder, PortalStockRow } from '../services/omsService';
import { useAuth } from '../hooks/useAuth';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { formatDate, formatMoney } from '../utils/format';
import { errorMessage } from '../utils/errors';

type Tab = 'stock' | 'orders' | 'activity';

/**
 * 3PL client portal: a client's own user sees the stock of their products, their orders with tracking, and the
 * latest shipment updates. Nothing can be changed here.
 */
export const ClientPortalPage: React.FC = () => {
  const { user, logout } = useAuth();
  const [tab, setTab] = useState<Tab>('stock');
  const [client, setClient] = useState('');
  const [stock, setStock] = useState<PortalStockRow[]>([]);
  const [orders, setOrders] = useState<PortalOrder[]>([]);
  const [activity, setActivity] = useState<{ id: number; order_number: string | null; description: string; happened_at: string | null }[]>([]);
  const [search, setSearch] = useState('');
  const appliedSearch = useDebouncedValue(search.trim());
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    omsService
      .getPortalStock()
      .then((res) => {
        setClient(res.client.name);
        setStock(res.data);
      })
      .catch((err) => setError(errorMessage(err, 'Your stock could not be loaded.')));
    omsService
      .getPortalActivity()
      .then((res) => setActivity(res.data))
      .catch(() => setActivity([]));
  }, []);

  useEffect(() => {
    omsService
      .getPortalOrders({ search: appliedSearch || undefined })
      .then((res) => setOrders(res.data))
      .catch((err) => setError(errorMessage(err, 'Your orders could not be loaded.')));
  }, [appliedSearch]);

  const tabs: { key: Tab; label: string; count: number }[] = [
    { key: 'stock', label: 'Stock', count: stock.length },
    { key: 'orders', label: 'Orders', count: orders.length },
    { key: 'activity', label: 'Shipment Updates', count: activity.length },
  ];

  return (
    <div className="page-container" style={{ maxWidth: 1200, margin: '0 auto' }}>
      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">{client || 'Client Portal'}</h1>
          <p className="page-subtitle">Your stock in our warehouses, your orders and their delivery. Signed in as {user?.name}.</p>
        </div>
        <button type="button" className="btn-finovo-secondary" onClick={logout}>
          <LogOut size={14} />
          <span>Sign Out</span>
        </button>
      </div>

      {error && <Alert type="error" message={error} className="page-banner" />}

      <div className="content-card">
        <div className="procurement-tabs" role="tablist" style={{ margin: 0, padding: '0 24px' }}>
          {tabs.map((t) => (
            <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} className={`procurement-tab-btn ${tab === t.key ? 'is-active' : ''}`} onClick={() => setTab(t.key)}>
              <span>{t.label}</span>
              <span className="procurement-tab-count">{t.count}</span>
            </button>
          ))}
        </div>

        <div className="table-responsive-wrapper" style={{ padding: '0 24px 24px' }}>
          {tab === 'stock' && (
            <table className="finovo-table">
              <thead>
                <tr>
                  <th>Product & SKU</th>
                  <th style={{ textAlign: 'right' }}>On Hand</th>
                  <th style={{ textAlign: 'right' }}>Reserved for Orders</th>
                  <th style={{ textAlign: 'right' }}>Available</th>
                </tr>
              </thead>
              <tbody>
                {stock.length === 0 ? (
                  <tr>
                    <td colSpan={4} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>No stock yet.</td>
                  </tr>
                ) : (
                  stock.map((row) => (
                    <tr key={row.sku}>
                      <td>
                        <div style={{ display: 'flex', flexDirection: 'column' }}>
                          <span style={{ fontWeight: 600 }}>{row.product_name}</span>
                          <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{row.sku}</span>
                        </div>
                      </td>
                      <td style={{ textAlign: 'right' }}>{row.on_hand}</td>
                      <td style={{ textAlign: 'right' }}>{row.reserved}</td>
                      <td style={{ textAlign: 'right', fontWeight: 700 }}>{row.available}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}

          {tab === 'orders' && (
            <>
              <div className="topbar-search-input" style={{ width: '100%', boxSizing: 'border-box', margin: '12px 0' }}>
                <Search size={16} color="#94a3b8" />
                <input type="text" placeholder="Search order number or customer..." value={search} onChange={(e) => setSearch(e.target.value)} />
              </div>
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Store</th>
                    <th style={{ textAlign: 'right' }}>Total</th>
                    <th>Status</th>
                    <th>Delivery</th>
                    <th>Placed</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.length === 0 ? (
                    <tr>
                      <td colSpan={7} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>No orders found.</td>
                    </tr>
                  ) : (
                    orders.map((order) => (
                      <tr key={order.id}>
                        <td style={{ fontWeight: 700 }}>{order.order_number}</td>
                        <td>{order.customer}</td>
                        <td>{order.store}</td>
                        <td style={{ textAlign: 'right' }}>{formatMoney(order.total_amount, order.currency ?? 'PKR')}</td>
                        <td>
                          <StatusPill status={order.status} />
                        </td>
                        <td>
                          {order.shipment?.tracking_number ? (
                            order.shipment.tracking_url ? (
                              <a href={order.shipment.tracking_url} target="_blank" rel="noopener noreferrer" style={{ color: '#1e56ff' }}>
                                {order.shipment.carrier} · {order.shipment.tracking_number}
                              </a>
                            ) : (
                              `${order.shipment.carrier} · ${order.shipment.tracking_number}`
                            )
                          ) : (
                            <span style={{ color: '#94a3b8' }}>Not shipped yet</span>
                          )}
                        </td>
                        <td>{formatDate(order.placed_at)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </>
          )}

          {tab === 'activity' && (
            <table className="finovo-table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Order</th>
                  <th>Update</th>
                </tr>
              </thead>
              <tbody>
                {activity.length === 0 ? (
                  <tr>
                    <td colSpan={3} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>No shipment updates yet.</td>
                  </tr>
                ) : (
                  activity.map((event) => (
                    <tr key={event.id}>
                      <td>{formatDate(event.happened_at, true)}</td>
                      <td style={{ fontWeight: 700 }}>{event.order_number}</td>
                      <td>{event.description}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
};

export default ClientPortalPage;
