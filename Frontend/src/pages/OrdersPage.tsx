import React, { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { FileText, CheckCircle, AlertCircle, Eye, Merge, PlayCircle, Plus, PackageCheck, Settings2, Tags, Upload, UploadCloud, XCircle } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import omsService, { ORDER_CREATE_PLATFORMS, canSplitOrMerge } from '../services/omsService';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import { useActiveStore } from '../hooks/useActiveStore';
import type { OrderHoldType, OrderModel, SlaReport } from '../services/omsService';
import { HoldPill, OrderRulesModal } from '../components/catalog/OrderControls';
import CustomDropdown from '../components/common/CustomDropdown';
import { BulkOrdersBar, CustomStatusPill, ImportOrdersModal, OrderStatusesModal } from '../components/catalog/OrderOperations';
import { useCustomStatuses } from '../hooks/useCustomStatuses';
import type { BulkOrderResult } from '../services/omsService';
import ProductThumb from '../components/catalog/ProductThumb';
import StatusPill from '../components/common/StatusPill';
import { formatMoney, variantNote } from '../utils/format';
import OrderDetailModal from '../components/catalog/OrderDetailModal';
import OrderFormModal from '../components/catalog/OrderFormModal';
import { Alert } from '../components/common/Alert';
import RowActionsMenu from '../components/common/RowActionsMenu';
import type { RowAction } from '../components/common/RowActionsMenu';
import { errorMessage } from '../utils/errors';
import '../components/catalog/DetailModal.css';

/** Same rules as the backend's Order::ALLOCATABLE_STATUSES and Order::SHIPPED_STATUSES. */
const ALLOCATABLE_STATUSES = ['imported', 'pending', 'partially_allocated', 'exception'];
const SHIPPED_STATUSES = ['partially_shipped', 'shipped', 'delivered', 'returned'];

/** How often the managed store's new orders are imported and the list refreshed while the page is open. */
const ORDER_REFRESH_MS = 60 * 1000;

export const OrdersPage: React.FC = () => {
  const { user } = useAuth();
  const canAllocate = can(user, 'orders.allocate');
  const canCancel = can(user, 'orders.cancel');
  const canManage = can(user, 'orders.manage');
  const { confirm, notify, dialog } = useConfirmDialog();
  const { activeStore } = useActiveStore();
  const [orders, setOrders] = useState<OrderModel[]>([]);
  const [kpis, setKpis] = useState({
    total_orders: 0,
    pending: 0,
    allocated: 0,
    shipped: 0,
    on_hold: 0,
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
  const [holdFilter, setHoldFilter] = useState<OrderHoldType | 'any' | ''>('');
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [sla, setSla] = useState<SlaReport | null>(null);
  const [editingRules, setEditingRules] = useState(false);
  const [customFilter, setCustomFilter] = useState('');
  const [importing, setImporting] = useState(false);
  const [editingStatuses, setEditingStatuses] = useState(false);
  const [bulkFailures, setBulkFailures] = useState<BulkOrderResult['failed']>([]);
  const { statuses, reload: reloadStatuses } = useCustomStatuses();
  const [viewOrder, setViewOrder] = useState<OrderModel | null>(null);
  const [creating, setCreating] = useState(false);
  const [banner, setBanner] = useState<string | null>(null);


  /** Load the current page of orders; a quiet refresh keeps the table on screen while it loads. */
  const fetchOrders = async (quiet = false) => {
    try {
      if (!quiet) setLoading(true);
      const res = await omsService.getOrders({
        search: debouncedSearch || undefined,
        status: statusFilter || undefined,
        hold: holdFilter || undefined,
        custom_status: customFilter || undefined,
        store_id: activeStore?.id,
        page,
        per_page: perPage,
      });
      setOrders(res.data);
      setKpis(res.kpis);
      setTotal(res.meta.total);
      setLastPage(res.meta.last_page);
      setSla(await omsService.getOrderSla(activeStore?.id));
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
  }, [page, perPage, statusFilter, holdFilter, customFilter, debouncedSearch, activeStore?.id]);

  // A new search, filter or page starts with nothing selected.
  useEffect(() => setSelected(new Set()), [page, holdFilter, customFilter, debouncedSearch, activeStore?.id]);

  // The latest fetchOrders, for the timer below (it keeps the page, search and store the user is on).
  const fetchOrdersRef = useRef(fetchOrders);
  useEffect(() => {
    fetchOrdersRef.current = fetchOrders;
  });

  // New orders of the managed store are imported when the page opens, every minute while it is visible and when
  // the tab is shown again, so they appear without pressing Sync. Orders sent by store notifications show up on
  // the same refresh.
  useEffect(() => {
    const storeId = activeStore?.id;

    /** Import the store's new orders, then reload the list (on opening, only when orders arrived: it was just loaded). */
    const refresh = async (opening = false) => {
      if (document.visibilityState !== 'visible') return;
      let changed = false;
      if (storeId) {
        try {
          const result = await omsService.syncStoreOrders(storeId);
          changed = result.orders_created + result.orders_updated > 0;
        } catch {
          // The store could not be read this time; the reason is shown on the Stores page and the next refresh tries again.
        }
      }
      if (!opening || changed) fetchOrdersRef.current(true);
    };
    const onTick = () => refresh();

    refresh(true);
    const timer = window.setInterval(onTick, ORDER_REFRESH_MS);
    document.addEventListener('visibilitychange', onTick);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onTick);
    };
  }, [activeStore?.id]);

  const handleAllocate = async (orderId: number) => {
    try {
      const res = await omsService.allocateOrder(orderId);
      notify({ title: 'Stock allocation', message: res.message });
      fetchOrders();
    } catch (err) {
      notify({ title: 'Allocation failed', message: err instanceof Error ? err.message : 'The order could not be allocated.' });
    }
  };

  const handlePush = async (order: OrderModel) => {
    try {
      const res = await omsService.pushOrder(order.id);
      setBanner(res.message);
      fetchOrders(true);
    } catch (err) {
      notify({ title: 'Order not sent', message: errorMessage(err, 'The order could not be sent to its store.') });
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

  const handleRelease = async (order: OrderModel) => {
    try {
      setBanner((await omsService.releaseOrder(order.id)).message);
      fetchOrders(true);
    } catch (err) {
      notify({ title: 'Hold not released', message: errorMessage(err, 'The hold could not be released.') });
    }
  };

  const selectedOrders = orders.filter((order) => selected.has(order.id));
  const canMerge = canManage && selectedOrders.length >= 2 && selectedOrders.every((order) => canSplitOrMerge(order));

  const handleMerge = async () => {
    const ok = await confirm({
      title: `Merge ${selectedOrders.length} orders?`,
      message: `Their items are combined into order ${[...selectedOrders].sort((a, b) => a.id - b.id)[0].order_number}; the other orders are cancelled.`,
      confirmLabel: 'Merge Orders',
    });
    if (!ok) return;
    try {
      setBanner((await omsService.mergeOrders(selectedOrders.map((order) => order.id))).message);
      setSelected(new Set());
      fetchOrders();
    } catch (err) {
      notify({ title: 'Orders not merged', message: errorMessage(err, 'The orders could not be merged.') });
    }
  };

  const toggleRow = (id: string | number) =>
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(Number(id))) next.delete(Number(id));
      else next.add(Number(id));
      return next;
    });
  const toggleAll = (ids: (string | number)[]) =>
    setSelected((current) => (ids.every((id) => current.has(Number(id))) ? new Set() : new Set(ids.map(Number))));

  /** What the user may do with an order, in the order the work happens. */
  const rowActions = (order: OrderModel): RowAction[] => {
    const isCancelled = order.order_status === 'cancelled';
    const actions: RowAction[] = [{ label: 'Manage', icon: Eye, onSelect: () => setViewOrder(order) }];

    if (canManage && order.hold_type) {
      actions.push({ label: order.hold_type === 'approval' ? 'Approve' : 'Release Hold', icon: PlayCircle, onSelect: () => handleRelease(order) });
    }

    if (canAllocate && !order.hold_type && ALLOCATABLE_STATUSES.includes(order.order_status)) {
      actions.push({ label: 'Allocate', icon: PackageCheck, onSelect: () => handleAllocate(order.id) });
    }
    // Only an order not on its store yet is pushed; one imported from (or already sent to) its store is kept in
    // step automatically: its status, shipments and cancellation reach the store by themselves.
    const canPush = !order.external_order_id && !isCancelled && order.store && ORDER_CREATE_PLATFORMS.includes(order.store.platform);
    if (canManage && canPush) {
      actions.push({ label: 'Push to Store', icon: UploadCloud, onSelect: () => handlePush(order) });
    }
    if (canCancel && !isCancelled && !SHIPPED_STATUSES.includes(order.order_status)) {
      actions.push({ label: 'Cancel', icon: XCircle, onSelect: () => handleCancel(order.id), danger: true });
    }

    return actions;
  };

  const columns: Column<OrderModel>[] = [
    {
      key: 'order_number',
      header: 'Order #',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.order_number}</span>
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
      render: (row) => (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
          <StatusPill status={row.order_status} />
          <HoldPill type={row.hold_type} />
          <CustomStatusPill value={row.custom_status} statuses={statuses} />
        </div>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (row) => <RowActionsMenu label={`Actions for order ${row.order_number}`} actions={rowActions(row)} />,
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
            description="New store orders arrive automatically; orders created here are also created on their store."
            icon={<FileText size={18} />}
          />
          {canManage && (
            <button type="button" className="btn-finovo-secondary" style={{ height: 42 }} onClick={() => setImporting(true)}>
              <Upload size={16} />
              <span>Import CSV</span>
            </button>
          )}
          {canManage && (
            <button type="button" className="btn-finovo-secondary" style={{ height: 42 }} onClick={() => setEditingStatuses(true)}>
              <Tags size={16} />
              <span>Statuses</span>
            </button>
          )}
          {canManage && (
            <button type="button" className="btn-finovo-secondary" style={{ height: 42 }} onClick={() => setEditingRules(true)}>
              <Settings2 size={16} />
              <span>Order Rules</span>
            </button>
          )}
          {canManage && (
            <button type="button" className="btn-finovo-primary" style={{ height: 42 }} onClick={() => setCreating(true)}>
              <Plus size={16} />
              <span>Create Order</span>
            </button>
          )}
        </div>
      </div>

      {banner && <Alert type="success" message={banner} className="page-banner" />}
      {sla && sla.breached.length + sla.at_risk.length > 0 && (
        <Alert
          type={sla.breached.length > 0 ? 'error' : 'info'}
          className="page-banner"
          message={[
            sla.breached.length > 0 && `${sla.breached.length} order(s) are past the ${sla.sla_hours}-hour shipping deadline: ${sla.breached.slice(0, 5).map((o) => o.order_number).join(', ')}${sla.breached.length > 5 ? '…' : ''}.`,
            sla.at_risk.length > 0 && `${sla.at_risk.length} order(s) are close to it: ${sla.at_risk.slice(0, 5).map((o) => o.order_number).join(', ')}${sla.at_risk.length > 5 ? '…' : ''}.`,
          ]
            .filter(Boolean)
            .join(' ')}
        />
      )}

      <div className="kpi-grid">
        <MetricCard title="Total Orders" value={kpis.total_orders} icon={<FileText size={20} />} pillText={activeStore?.name ?? 'All Stores'} />
        <MetricCard title="Pending Allocation" value={kpis.pending} icon={<AlertCircle size={20} />} pillText={kpis.on_hold > 0 ? `${kpis.on_hold} on hold` : 'Action Req.'} pillVariant="amber" />
        <MetricCard title="Allocated Stock" value={kpis.allocated} icon={<CheckCircle size={20} />} pillText="Reserved" pillVariant="blue" />
        <MetricCard title="Shipped Orders" value={kpis.shipped} icon={<CheckCircle size={20} />} pillText="Dispatched" pillVariant="green" />
      </div>

      <div className="content-card">
        <div className="content-card-header">
          <h3 className="content-card-title">All Orders</h3>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            {canMerge && (
              <button type="button" className="btn-finovo-secondary" onClick={handleMerge}>
                <Merge size={14} />
                <span>Merge {selectedOrders.length} Orders</span>
              </button>
            )}
            <div style={{ width: 190 }}>
              <CustomDropdown
                value={holdFilter}
                onChange={(val) => {
                  setHoldFilter(val as OrderHoldType | 'any' | '');
                  setPage(1);
                }}
                searchable={false}
                options={[
                  { value: '', label: 'All orders' },
                  { value: 'any', label: 'All held orders' },
                  { value: 'approval', label: 'Needs approval' },
                  { value: 'credit', label: 'Over credit limit' },
                  { value: 'manual', label: 'Held by hand' },
                ]}
              />
            </div>
            {statuses.length > 0 && (
              <div style={{ width: 170 }}>
                <CustomDropdown
                  value={customFilter}
                  onChange={(val) => {
                    setCustomFilter(String(val ?? ''));
                    setPage(1);
                  }}
                  searchable={false}
                  options={[{ value: '', label: 'Any status' }, ...statuses.map((status) => ({ value: status.key, label: status.label }))]}
                />
              </div>
            )}
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

        {selected.size > 0 && (
          <BulkOrdersBar
            orderIds={[...selected]}
            statuses={statuses}
            can={{ allocate: canAllocate, cancel: canCancel, manage: canManage }}
            confirmCancel={(count) =>
              confirm({
                title: `Cancel ${count} order(s)?`,
                message: 'Each order is cancelled and its reserved stock released. Shipped orders are skipped.',
                confirmLabel: 'Cancel Orders',
                cancelLabel: 'Keep Orders',
                tone: 'danger',
              })
            }
            onDone={(message, result) => {
              setBanner(message);
              setBulkFailures(result.failed);
              setSelected(new Set());
              fetchOrders(true);
            }}
            onClear={() => setSelected(new Set())}
          />
        )}
        {bulkFailures.length > 0 && (
          <div className="alert alert-error order-bulk-failures">
            <ul className="order-import-problems">
              {bulkFailures.map((failure) => (
                <li key={failure.order_number}>
                  <strong>{failure.order_number}</strong>: {failure.message}
                </li>
              ))}
            </ul>
            <button type="button" className="topbar-icon-button" onClick={() => setBulkFailures([])} aria-label="Dismiss">
              <XCircle size={16} />
            </button>
          </div>
        )}

        <DataTable
          columns={columns}
          data={orders}
          keyField="id"
          isLoading={loading}
          selectable={canManage}
          selectedIds={selected}
          onSelectRow={toggleRow}
          onSelectAll={toggleAll}
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

      {viewOrder && (
        <OrderDetailModal
          order={viewOrder}
          statuses={statuses}
          onClose={() => setViewOrder(null)}
          onChanged={(message) => {
            if (message) setBanner(message);
            fetchOrders();
          }}
        />
      )}
      {importing && (
        <ImportOrdersModal
          storeId={activeStore?.id}
          storeName={activeStore?.name}
          onClose={() => setImporting(false)}
          onImported={(message) => {
            setImporting(false);
            setBanner(message);
            setPage(1);
            fetchOrders();
          }}
        />
      )}
      {editingStatuses && (
        <OrderStatusesModal
          statuses={statuses}
          onClose={() => setEditingStatuses(false)}
          onSaved={(message) => {
            setEditingStatuses(false);
            setBanner(message);
            reloadStatuses();
          }}
        />
      )}
      {editingRules && (
        <OrderRulesModal
          onClose={() => setEditingRules(false)}
          onSaved={(message) => {
            setEditingRules(false);
            setBanner(message);
            fetchOrders(true);
          }}
        />
      )}
      {creating && (
        <OrderFormModal
          onClose={() => setCreating(false)}
          onSaved={(message) => {
            setCreating(false);
            setBanner(message);
            setPage(1);
            fetchOrders();
          }}
        />
      )}
      {dialog}
    </div>
  );
};

export default OrdersPage;
