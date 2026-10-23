import React, { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { FileText, CheckCircle, AlertCircle, Eye } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import omsService from '../services/omsService';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import { useActiveStore } from '../hooks/useActiveStore';
import type { OrderModel } from '../services/omsService';
import ProductThumb from '../components/catalog/ProductThumb';
import StatusPill from '../components/common/StatusPill';
import { formatMoney, variantNote } from '../utils/format';
import OrderDetailModal from '../components/catalog/OrderDetailModal';
import '../components/catalog/DetailModal.css';

/** Same rules as the backend's Order::ALLOCATABLE_STATUSES and Order::SHIPPED_STATUSES. */
const ALLOCATABLE_STATUSES = ['imported', 'pending', 'partially_allocated', 'exception'];
const SHIPPED_STATUSES = ['partially_shipped', 'shipped', 'delivered', 'returned'];

export const OrdersPage: React.FC = () => {
  const { confirm, notify, dialog } = useConfirmDialog();
  const { activeStore } = useActiveStore();
  const [orders, setOrders] = useState<OrderModel[]>([]);
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
  const [searchParams] = useSearchParams();
  const [search, setSearch] = useState(searchParams.get('search') ?? '');
  const [debouncedSearch, setDebouncedSearch] = useState(search.trim());
  const [statusFilter] = useState('');
  const [viewOrder, setViewOrder] = useState<OrderModel | null>(null);


  const fetchOrders = async () => {
    try {
      setLoading(true);
      const res = await omsService.getOrders({
        search: debouncedSearch || undefined,
        status: statusFilter || undefined,
        store_id: activeStore?.id,
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

  // A search handed over in the address (e.g. from the top-bar search) fills the search box.
  useEffect(() => {
    const fromUrl = searchParams.get('search');
    if (fromUrl !== null) setSearch(fromUrl);
  }, [searchParams]);

  // Search 300 ms after the user stops typing, from the first page.
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    fetchOrders();
  }, [page, perPage, statusFilter, debouncedSearch, activeStore?.id]);

  const handleAllocate = async (orderId: number) => {
    try {
      const res = await omsService.allocateOrder(orderId);
      notify({ title: 'Stock allocation', message: res.message });
      fetchOrders();
    } catch (err) {
      notify({ title: 'Allocation failed', message: err instanceof Error ? err.message : 'The order could not be allocated.' });
    }
  };

  const handleCancel = async (orderId: number) => {
    const ok = await confirm({
      title: 'Cancel this order?',
      message: 'The order will be cancelled and its reserved stock released back to the warehouse.',
      confirmLabel: 'Cancel Order',
      cancelLabel: 'Keep Order',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      await omsService.cancelOrder(orderId);
      fetchOrders();
    } catch (err) {
      notify({ title: 'Order not cancelled', message: err instanceof Error ? err.message : 'The order could not be cancelled.' });
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
      key: 'items',
      header: 'Items',
      render: (row) => {
        const items = row.items ?? [];
        if (items.length === 0) return <span style={{ fontSize: 12, color: '#94a3b8' }}>No items</span>;
        const first = items[0];
        const units = items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
        const variant = variantNote(first.product_name, first.variant);
        return (
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, maxWidth: 300 }}>
            <div className="thumb-stack">
              {items.slice(0, 3).map((item) => (
                <ProductThumb key={item.id} src={item.variant?.product?.image_url} size={34} />
              ))}
            </div>
            <div style={{ minWidth: 0 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: '#0f172a', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {first.product_name}
              </div>
              <div style={{ fontSize: 11, color: '#94a3b8', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {variant ? `${variant} · ` : ''}
                {units} unit{units === 1 ? '' : 's'}
                {items.length > 1 ? ` · +${items.length - 1} more` : ''}
              </div>
            </div>
          </div>
        );
      },
    },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
          {formatMoney(row.total_amount, row.currency)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Order Status',
      render: (row) => <StatusPill status={row.order_status} />,
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }} onClick={(e) => e.stopPropagation()}>
          <button
            onClick={() => setViewOrder(row)}
            className="btn-finovo-secondary"
            style={{ padding: '4px 8px', fontSize: 11 }}
          >
            <Eye size={13} />
            <span>Manage</span>
          </button>
          {ALLOCATABLE_STATUSES.includes(row.order_status) ? (
            <button
              onClick={() => handleAllocate(row.id)}
              className="btn-finovo-primary"
              style={{ padding: '4px 8px', fontSize: 11 }}
            >
              Allocate
            </button>
          ) : null}
          {row.order_status !== 'cancelled' && !SHIPPED_STATUSES.includes(row.order_status) && (
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
          <p className="page-subtitle">
            {activeStore ? `Orders from ${activeStore.name}.` : 'Track, allocate, and fulfill multichannel customer orders.'}
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <InfoCalloutCard
            title="Multichannel Order Flow"
            description="Orders imported from connected stores are automatically validated and assigned to fulfillment hubs."
            icon={<FileText size={18} />}
          />
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
          onRowClick={(row) => setViewOrder(row)}
          pagination={{
            currentPage: page,
            lastPage,
            perPage,
            total,
            onPageChange: (p) => setPage(p),
          }}
        />
      </div>

      {viewOrder && <OrderDetailModal order={viewOrder} onClose={() => setViewOrder(null)} onChanged={fetchOrders} />}
      {dialog}
    </div>
  );
};

export default OrdersPage;
