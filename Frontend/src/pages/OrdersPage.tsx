import React, { useEffect, useState } from 'react';
import { FileText, Plus, CheckCircle, XCircle, Loader2, AlertCircle } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import CustomDropdown from '../components/common/CustomDropdown';
import omsService from '../services/omsService';
import type { OrderModel, ProductModel, StoreModel } from '../services/omsService';

export const OrdersPage: React.FC = () => {
  const [orders, setOrders] = useState<OrderModel[]>([]);
  const [products, setProducts] = useState<ProductModel[]>([]);
  const [stores, setStores] = useState<StoreModel[]>([]);
  const [kpis, setKpis] = useState({
    total_orders: 0,
    pending: 0,
    allocated: 0,
    shipped: 0,
  });
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter] = useState('');

  // Manual Order Modal
  const [modalOpen, setModalOpen] = useState(false);
  const [modalLoading, setModalLoading] = useState(false);
  const [selectedStoreId, setSelectedStoreId] = useState<number | ''>('');
  const [customerName, setCustomerName] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [selectedProductId, setSelectedProductId] = useState<number | ''>('');
  const [quantity, setQuantity] = useState(1);
  const [unitPrice, setUnitPrice] = useState('49.00');

  const fetchOrders = async () => {
    try {
      setLoading(true);
      const res = await omsService.getOrders({
        search: search || undefined,
        status: statusFilter || undefined,
        page,
        per_page: perPage,
      });
      setOrders(res.data);
      setKpis(res.kpis);
      setTotal(res.meta.total);
      setLastPage(res.meta.last_page);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const fetchDependencies = async () => {
    try {
      const [pRes, sRes] = await Promise.all([
        omsService.getProducts({ per_page: 50 }),
        omsService.getStores(),
      ]);
      setProducts(pRes.data);
      setStores(sRes.data);
      if (sRes.data.length > 0) setSelectedStoreId(sRes.data[0].id);
      if (pRes.data.length > 0) setSelectedProductId(pRes.data[0].id);
    } catch (err) {
      console.error(err);
    }
  };

  useEffect(() => {
    fetchOrders();
  }, [page, perPage, statusFilter]);

  useEffect(() => {
    fetchDependencies();
  }, []);

  const handleCreateOrder = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedStoreId || !customerName || !selectedProductId) return;

    try {
      setModalLoading(true);
      await omsService.createOrder({
        store_id: Number(selectedStoreId),
        customer_name: customerName,
        customer_email: customerEmail || undefined,
        items: [
          {
            product_id: Number(selectedProductId),
            quantity: Number(quantity),
            unit_price: Number(unitPrice),
          },
        ],
      });
      setModalOpen(false);
      setCustomerName('');
      setCustomerEmail('');
      fetchOrders();
    } catch (err) {
      console.error(err);
    } finally {
      setModalLoading(false);
    }
  };

  const handleAllocate = async (orderId: number) => {
    try {
      await omsService.allocateOrder(orderId);
      fetchOrders();
    } catch (err) {
      console.error(err);
    }
  };

  const handleCancel = async (orderId: number) => {
    if (!confirm('Are you sure you want to cancel this order and release stock reservations?')) return;
    try {
      await omsService.cancelOrder(orderId);
      fetchOrders();
    } catch (err) {
      console.error(err);
    }
  };

  const columns: Column<OrderModel>[] = [
    {
      key: 'order_number',
      header: 'Order #',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
          {row.order_number}
        </span>
      ),
    },
    {
      key: 'customer',
      header: 'Customer',
      render: (row) => (
        <div>
          <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{row.customer_name}</div>
          <div style={{ fontSize: 11, color: '#94a3b8' }}>{row.customer_email || 'No email'}</div>
        </div>
      ),
    },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
          ${Number(row.total_amount).toFixed(2)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Order Status',
      render: (row) => {
        const isAllocated = row.order_status === 'allocated';
        const isShipped = row.order_status === 'shipped';
        const isCancelled = row.order_status === 'cancelled';
        return (
          <span
            className={`kpi-pill ${
              isAllocated
                ? 'kpi-pill-blue'
                : isShipped
                ? 'kpi-pill-green'
                : isCancelled
                ? 'bg-rose-50 text-rose-700'
                : 'bg-amber-50 text-amber-700'
            }`}
          >
            {row.order_status}
          </span>
        );
      },
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
          {row.order_status === 'pending' || row.order_status === 'imported' ? (
            <button
              onClick={() => handleAllocate(row.id)}
              className="btn-finovo-primary"
              style={{ padding: '4px 8px', fontSize: 11 }}
            >
              Allocate
            </button>
          ) : null}
          {row.order_status !== 'cancelled' && row.order_status !== 'shipped' && (
            <button
              onClick={() => handleCancel(row.id)}
              className="btn-finovo-secondary"
              style={{ padding: '4px 8px', fontSize: 11, color: '#ef4444' }}
            >
              Cancel
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Orders</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Orders</h1>
            <span className="page-count-pill">{kpis.total_orders}</span>
          </div>
          <p className="page-subtitle">Track, allocate, and fulfill multichannel customer orders.</p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <InfoCalloutCard
            title="Multichannel Order Flow"
            description="Orders imported from connected stores are automatically validated and assigned to fulfillment hubs."
            icon={<FileText size={18} />}
          />
          <button onClick={() => setModalOpen(true)} className="btn-finovo-primary">
            <Plus size={16} />
            <span>Create Order</span>
          </button>
        </div>
      </div>

      <div className="kpi-grid">
        <MetricCard title="Total Orders" value={kpis.total_orders} icon={<FileText size={20} />} pillText="All Channels" />
        <MetricCard title="Pending Allocation" value={kpis.pending} icon={<AlertCircle size={20} />} pillText="Action Req." pillVariant="amber" />
        <MetricCard title="Allocated Stock" value={kpis.allocated} icon={<CheckCircle size={20} />} pillText="Reserved" pillVariant="blue" />
        <MetricCard title="Shipped Orders" value={kpis.shipped} icon={<CheckCircle size={20} />} pillText="Dispatched" pillVariant="green" />
      </div>

      <div className="content-card">
        <div className="content-card-header">
          <h3 className="content-card-title">All Orders</h3>
          <div style={{ display: 'flex', gap: 10 }}>
            <input
              type="text"
              placeholder="Search orders..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="topbar-search-input"
              style={{ width: 220 }}
            />
          </div>
        </div>

        <DataTable
          columns={columns}
          data={orders}
          keyField="id"
          isLoading={loading}
          emptyMessage="No orders found."
          pagination={{
            currentPage: page,
            lastPage,
            perPage,
            total,
            onPageChange: (p) => setPage(p),
          }}
        />
      </div>

      {modalOpen && (
        <div className="modal-overlay" onClick={() => setModalOpen(false)}>
          <div className="modal-dialog" onClick={(e) => e.stopPropagation()}>
            <div style={{ padding: '20px 24px', borderBottom: '1px solid #e2e8f0', display: 'flex', justifyContent: 'space-between' }}>
              <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700 }}>New Manual Order</h3>
              <button onClick={() => setModalOpen(false)} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>
                <XCircle size={20} />
              </button>
            </div>

            <form onSubmit={handleCreateOrder} style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div>
                <CustomDropdown
                  label="Store"
                  required
                  placeholder="Select store..."
                  value={selectedStoreId}
                  onChange={(val) => setSelectedStoreId(Number(val))}
                  options={stores.map((s) => ({
                    value: s.id,
                    label: `${s.name} (${s.platform})`,
                  }))}
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Customer Name *</label>
                <input
                  type="text"
                  placeholder="e.g. John Doe"
                  value={customerName}
                  onChange={(e) => setCustomerName(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', boxSizing: 'border-box' }}
                  required
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Customer Email</label>
                <input
                  type="email"
                  placeholder="john@example.com"
                  value={customerEmail}
                  onChange={(e) => setCustomerEmail(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', boxSizing: 'border-box' }}
                />
              </div>

              <div>
                <CustomDropdown
                  label="Select Product"
                  required
                  placeholder="Select product..."
                  value={selectedProductId}
                  onChange={(val) => setSelectedProductId(Number(val))}
                  options={products.map((p) => ({
                    value: p.id,
                    label: `${p.name} (${p.sku})`,
                  }))}
                />
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Quantity *</label>
                  <input
                    type="number"
                    min="1"
                    value={quantity}
                    onChange={(e) => setQuantity(Number(e.target.value))}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', boxSizing: 'border-box' }}
                    required
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Unit Price ($) *</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={unitPrice}
                    onChange={(e) => setUnitPrice(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', boxSizing: 'border-box' }}
                    required
                  />
                </div>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12, marginTop: 12 }}>
                <button type="button" onClick={() => setModalOpen(false)} className="btn-finovo-secondary">Cancel</button>
                <button type="submit" disabled={modalLoading} className="btn-finovo-primary">
                  {modalLoading && <Loader2 size={16} className="animate-spin" />}
                  <span>Place Order</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default OrdersPage;
