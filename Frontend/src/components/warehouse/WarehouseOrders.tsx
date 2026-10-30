import React, { useCallback, useEffect, useState } from 'react';
import { Eye, Search } from 'lucide-react';
import DataTable from '../common/DataTable';
import type { Column } from '../common/DataTable';
import StatusPill from '../common/StatusPill';
import { Alert } from '../common/Alert';
import OrderDetailModal from '../catalog/OrderDetailModal';
import omsService from '../../services/omsService';
import type { OrderModel } from '../../services/omsService';
import { formatDate, formatMoney } from '../../utils/format';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { useActiveStore } from '../../hooks/useActiveStore';

const STATUS_TABS: { value: string; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'allocated', label: 'To Pick & Pack' },
  { value: 'partially_allocated', label: 'Partly Reserved' },
  { value: 'shipped', label: 'Shipped' },
  { value: 'delivered', label: 'Delivered' },
];

/**
 * Orders sent to one warehouse (stock reserved or picked there), for the warehouse's Orders tab.
 * Opening an order shows its workflow, so staff can pick, pack and dispatch from here.
 */
export const WarehouseOrders: React.FC<{ warehouseId: number; onCountChange?: (count: number) => void }> = ({ warehouseId, onCountChange }) => {
  const { activeStore } = useActiveStore();
  const storeId = activeStore?.id;
  const [orders, setOrders] = useState<OrderModel[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  // Live search: the list reloads 300 ms after typing stops.
  const appliedSearch = useDebouncedValue(search.trim());
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [viewOrder, setViewOrder] = useState<OrderModel | null>(null);

  // A new search starts from the first page.
  useEffect(() => setPage(1), [appliedSearch]);

  const fetchOrders = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await omsService.getOrders({
        warehouse_id: warehouseId,
        // Several stores can share the warehouse; only the managed store's orders are listed.
        store_id: storeId,
        status: status || undefined,
        search: appliedSearch || undefined,
        page,
        per_page: perPage,
      });
      setOrders(res.data);
      setMeta({ total: res.meta.total, last_page: res.meta.last_page });
      onCountChange?.(res.kpis.total_orders);
    } catch (err) {
      setError(err instanceof Error && err.message ? err.message : 'Failed to load this warehouse\'s orders.');
    } finally {
      setLoading(false);
    }
  }, [warehouseId, storeId, status, appliedSearch, page, perPage, onCountChange]);

  useEffect(() => {
    fetchOrders();
  }, [fetchOrders]);

  const columns: Column<OrderModel>[] = [
    {
      key: 'order_number',
      header: 'ORDER',
      width: '14%',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.order_number}</span>,
    },
    {
      key: 'customer',
      header: 'CUSTOMER',
      width: '22%',
      render: (row) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{row.customer_name}</span>
          <span style={{ fontSize: 12, color: '#64748b' }}>{row.store?.name ?? 'No store'}</span>
        </div>
      ),
    },
    {
      key: 'items',
      header: 'ITEMS',
      width: '24%',
      render: (row) => {
        const items = row.items ?? [];
        const units = items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
        return (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <span style={{ fontSize: 13, color: '#334155' }}>{items[0]?.product_name ?? 'No items'}</span>
            <span style={{ fontSize: 12, color: '#64748b' }}>
              {units} unit{units === 1 ? '' : 's'}
              {items.length > 1 ? ` · ${items.length} products` : ''}
            </span>
          </div>
        );
      },
    },
    {
      key: 'total',
      header: 'TOTAL',
      width: '11%',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{formatMoney(row.total_amount, row.currency)}</span>,
    },
    {
      key: 'status',
      header: 'STATUS',
      width: '13%',
      render: (row) => <StatusPill status={row.order_status} />,
    },
    {
      key: 'placed_at',
      header: 'PLACED',
      width: '10%',
      render: (row) => <span style={{ fontSize: 12, color: '#64748b', whiteSpace: 'nowrap' }}>{formatDate(row.placed_at)}</span>,
    },
    {
      key: 'action',
      header: 'ACTION',
      width: '6%',
      align: 'right',
      render: (row) => (
        <button type="button" className="table-action-btn" onClick={() => setViewOrder(row)} title="Open order" aria-label="Open order">
          <Eye size={15} />
        </button>
      ),
    },
  ];

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <div>
        <h2 className="wh-card-title">Orders at this Warehouse</h2>
        <p className="wh-card-subtitle">
          Orders appear here once they are sent to this warehouse from the order screen. Open one to pick, pack and dispatch it.
        </p>
      </div>

      {error && <Alert type="error" message={error} />}

      <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
        <div className="wh-tabs is-muted" role="tablist" aria-label="Order status">
          {STATUS_TABS.map((tab) => (
            <button
              key={tab.value}
              type="button"
              role="tab"
              aria-selected={status === tab.value}
              className={`wh-tab ${status === tab.value ? 'is-active' : ''}`}
              onClick={() => {
                setStatus(tab.value);
                setPage(1);
              }}
            >
              <span>{tab.label}</span>
            </button>
          ))}
        </div>
        <form
          style={{ flex: '0 1 320px' }}
          onSubmit={(e) => {
            e.preventDefault();
          }}
        >
          <div className="topbar-search-input" style={{ width: '100%', boxSizing: 'border-box' }}>
            <Search size={16} color="#94a3b8" />
            <input type="text" placeholder="Search order number or customer..." value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </form>
      </div>

      <div style={{ border: '1px solid #f1f5f9', borderRadius: 12, overflow: 'hidden' }}>
        <DataTable
          columns={columns}
          data={orders}
          keyField="id"
          isLoading={loading}
          emptyMessage={
            status || appliedSearch ? 'No orders match these filters.' : 'No orders have been sent to this warehouse yet.'
          }
          pagination={{
            currentPage: page,
            lastPage: meta.last_page,
            perPage,
            total: meta.total,
            onPageChange: setPage,
            onPerPageChange: (pp) => {
              setPerPage(pp);
              setPage(1);
            },
          }}
        />
      </div>

      {viewOrder && <OrderDetailModal order={viewOrder} onClose={() => setViewOrder(null)} onChanged={fetchOrders} />}
    </div>
  );
};

export default WarehouseOrders;
