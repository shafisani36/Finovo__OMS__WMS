import React, { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  ClipboardList,
  Eye,
  Loader2,
  PackageCheck,
  Printer,
  RefreshCw,
  Search,
  Send,
  Settings2,
  Tag,
  Truck,
  Undo2,
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import StatusPill from '../components/common/StatusPill';
import RowActionsMenu from '../components/common/RowActionsMenu';
import type { RowAction } from '../components/common/RowActionsMenu';
import { Alert } from '../components/common/Alert';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import CourierAccountsModal from '../components/shipments/CourierAccountsModal';
import DispatchModal from '../components/shipments/DispatchModal';
import ShipmentDetailModal from '../components/shipments/ShipmentDetailModal';
import { useActiveStore } from '../hooks/useActiveStore';
import { useAuth } from '../hooks/useAuth';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { useShipmentCouriers } from '../hooks/useShipmentCouriers';
import omsService, { SHIPMENT_STATUS_LABELS } from '../services/omsService';
import type { ShipmentFilter, ShipmentKpis, ShipmentModel } from '../services/omsService';
import { can } from '../types/auth';
import { formatDate, formatMoney } from '../utils/format';
import { errorMessage } from '../utils/errors';

const STATUS_TABS: { value: ShipmentFilter | ''; label: string; kpi?: keyof ShipmentKpis }[] = [
  { value: '', label: 'All Shipments' },
  { value: 'pending', label: 'Ready to Dispatch', kpi: 'ready_to_dispatch' },
  { value: 'shipped', label: 'In Transit', kpi: 'in_transit' },
  { value: 'issues', label: 'Needs Attention', kpi: 'issues' },
  { value: 'delivered', label: 'Delivered', kpi: 'delivered' },
  { value: 'returned', label: 'Returned', kpi: 'returned' },
];

const EMPTY_KPIS: ShipmentKpis = { ready_to_dispatch: 0, dispatched_today: 0, in_transit: 0, issues: 0, delivered: 0, returned: 0 };

/** Courier statuses that need someone to follow up (the parcel was not delivered). */
const PROBLEM_STATUSES = ['delivery_failed', 'returned', 'cancelled'];

export const ShipmentsPage: React.FC = () => {
  const { user } = useAuth();
  const canDispatch = can(user, 'shipments.dispatch');
  const canManageCouriers = can(user, 'couriers.manage');
  const { activeStore } = useActiveStore();
  const storeId = activeStore?.id;
  const { confirm, dialog } = useConfirmDialog();
  const { accounts, carriers, reload: reloadCouriers } = useShipmentCouriers();

  const [shipments, setShipments] = useState<ShipmentModel[]>([]);
  const [kpis, setKpis] = useState<ShipmentKpis>(EMPTY_KPIS);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<ShipmentFilter | ''>('');
  const [search, setSearch] = useState('');
  // Live search: the list reloads 300 ms after typing stops.
  const appliedSearch = useDebouncedValue(search.trim());
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [working, setWorking] = useState(false);
  const [tracking, setTracking] = useState(false);

  const [couriersOpen, setCouriersOpen] = useState(false);
  const [dispatchList, setDispatchList] = useState<ShipmentModel[] | null>(null);
  const [viewingId, setViewingId] = useState<number | null>(null);
  // Bumped after every change, so an open shipment reloads.
  const [refreshKey, setRefreshKey] = useState(0);

  // A new search, filter or page starts with nothing selected; a new search starts from the first page.
  useEffect(() => setPage(1), [appliedSearch]);
  useEffect(() => setSelected(new Set()), [storeId, statusFilter, appliedSearch, page, perPage]);

  const fetchShipments = useCallback(async () => {
    try {
      setLoading(true);
      const res = await omsService.getShipments({
        store_id: storeId,
        status: statusFilter || undefined,
        search: appliedSearch || undefined,
        page,
        per_page: perPage,
      });
      setShipments(res.data);
      setKpis(res.kpis);
      setMeta({ total: res.meta.total, last_page: res.meta.last_page });
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'Failed to load shipments.') });
    } finally {
      setLoading(false);
    }
  }, [storeId, statusFilter, appliedSearch, page, perPage]);

  useEffect(() => {
    fetchShipments();
  }, [fetchShipments]);

  const changed = (message: string) => {
    setBanner({ type: 'success', text: message });
    setSelected(new Set());
    setRefreshKey((key) => key + 1);
    fetchShipments();
  };

  /** Runs one action, showing its message (or error) above the list. */
  const run = async (task: () => Promise<{ message: string } | void>, failure: string) => {
    try {
      setWorking(true);
      const res = await task();
      if (res) changed(res.message);
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, failure) });
    } finally {
      setWorking(false);
    }
  };

  const handleTrack = async () => {
    setTracking(true);
    await run(() => omsService.trackShipments(), 'The couriers could not be checked.');
    setTracking(false);
  };

  const handleDeliver = async (shipment: ShipmentModel) => {
    const ok = await confirm({
      title: 'Mark as delivered?',
      message: (
        <>
          Confirm that order <strong>{shipment.order_number}</strong> sent with {shipment.carrier} ({shipment.tracking_number}) has reached{' '}
          <strong>{shipment.customer}</strong>.
        </>
      ),
      confirmLabel: 'Mark Delivered',
      tone: 'primary',
    });
    if (ok) run(() => omsService.deliverShipment(shipment.id), 'Failed to update the shipment.');
  };

  const handleCancelDispatch = async (shipment: ShipmentModel) => {
    const ok = await confirm({
      title: `Cancel dispatch of order ${shipment.order_number}?`,
      message: (
        <>
          {shipment.booked_with_courier ? `The booking with ${shipment.carrier} is cancelled, the` : 'The'} stock goes back to its bins and the order
          returns to <strong>Ready to Dispatch</strong>. Tracking ID {shipment.tracking_number} stops working.
        </>
      ),
      confirmLabel: 'Cancel Dispatch',
      tone: 'danger',
    });
    if (ok) run(() => omsService.cancelShipmentDispatch(shipment.id), 'The dispatch could not be cancelled.');
  };

  const shipmentActions = (row: ShipmentModel): RowAction[] =>
    ([
      canDispatch && row.status === 'pending' && { label: 'Dispatch', icon: Send, onSelect: () => setDispatchList([row]) },
      canDispatch && row.status === 'shipped' && { label: 'Mark Delivered', icon: CheckCircle2, onSelect: () => handleDeliver(row) },
      row.has_label && {
        label: 'Courier Label',
        icon: Printer,
        onSelect: () => run(() => omsService.openShipmentLabel(row.id, row.tracking_number ?? String(row.id)), 'The label could not be opened.'),
      },
      row.status !== 'pending' && {
        label: 'Shipping Label',
        icon: Tag,
        onSelect: () => run(() => omsService.downloadShippingLabels([row.id]), 'The label could not be printed.'),
      },
      canDispatch && row.can_cancel_dispatch && { label: 'Cancel Dispatch', icon: Undo2, danger: true, onSelect: () => handleCancelDispatch(row) },
    ] as (RowAction | false)[]).filter((action): action is RowAction => Boolean(action));

  const selectedRows = shipments.filter((s) => selected.has(s.id));
  const readyRows = selectedRows.filter((s) => s.status === 'pending');
  const dispatchedIds = selectedRows.filter((s) => s.status !== 'pending').map((s) => s.id);

  const toggleRow = (id: string | number) =>
    setSelected((current) => {
      const next = new Set(current);
      if (!next.delete(Number(id))) next.add(Number(id));
      return next;
    });

  const toggleAll = (ids: (string | number)[]) =>
    setSelected((current) => (ids.every((id) => current.has(Number(id))) ? new Set() : new Set(ids.map(Number))));

  const columns: Column<ShipmentModel>[] = [
    {
      key: 'order_number',
      header: 'ORDER',
      width: '12%',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.order_number}</span>,
    },
    {
      key: 'customer',
      header: 'CUSTOMER',
      width: '17%',
      render: (row) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span style={{ fontSize: 13, color: '#0f172a', fontWeight: 500 }}>{row.customer}</span>
          <span style={{ fontSize: 12, color: '#64748b' }}>{[row.city, row.store].filter(Boolean).join(' · ')}</span>
        </div>
      ),
    },
    {
      key: 'items_count',
      header: 'ITEMS',
      width: '7%',
      align: 'center',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{row.items_count}</span>,
    },
    {
      key: 'cod_amount',
      header: 'COD',
      width: '10%',
      render: (row) =>
        row.cod_amount > 0 ? (
          <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{formatMoney(row.cod_amount, row.currency ?? 'PKR')}</span>
        ) : (
          <span style={{ fontSize: 12, color: '#16a34a', fontWeight: 600 }}>Paid</span>
        ),
    },
    {
      key: 'carrier',
      header: 'CARRIER / TRACKING',
      width: '20%',
      render: (row) =>
        row.carrier ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <span style={{ fontSize: 13, color: '#0f172a', fontWeight: 500 }}>{row.carrier}</span>
            {row.tracking_url ? (
              <a href={row.tracking_url} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()} style={{ fontSize: 12, color: '#1e56ff', fontFamily: 'monospace' }}>
                {row.tracking_number}
              </a>
            ) : (
              <span style={{ fontSize: 12, color: '#64748b', fontFamily: 'monospace' }}>{row.tracking_number}</span>
            )}
            {row.courier_status_text && (
              <span className={`shipment-courier-status${row.courier_status && PROBLEM_STATUSES.includes(row.courier_status) ? ' is-problem' : ''}`}>
                {row.courier_status_text}
                {row.courier_status_at ? ` · ${formatDate(row.courier_status_at, true)}` : ''}
              </span>
            )}
          </div>
        ) : (
          <span style={{ fontSize: 12, color: '#94a3b8' }}>{row.warehouse ? `Packed at ${row.warehouse}` : 'Not dispatched yet'}</span>
        ),
    },
    {
      key: 'status',
      header: 'STATUS',
      width: '12%',
      render: (row) => <StatusPill status={row.status} label={SHIPMENT_STATUS_LABELS[row.status]} />,
    },
    {
      key: 'date',
      header: 'DATE',
      width: '10%',
      render: (row) => (
        <span style={{ fontSize: 12, color: '#64748b' }}>{formatDate(row.returned_at ?? row.delivered_at ?? row.shipped_at ?? row.packed_at)}</span>
      ),
    },
    {
      key: 'action',
      header: 'ACTION',
      width: '12%',
      align: 'center',
      render: (row) => (
        <div className="shipment-row-actions" onClick={(e) => e.stopPropagation()}>
          {canDispatch && row.status === 'pending' && (
            <button type="button" className="table-action-primary" onClick={() => setDispatchList([row])}>
              <Send size={14} />
              <span>Dispatch</span>
            </button>
          )}
          {canDispatch && row.status === 'shipped' && (
            <button type="button" className="table-action-primary is-success" onClick={() => handleDeliver(row)} disabled={working}>
              <CheckCircle2 size={14} />
              <span>Delivered</span>
            </button>
          )}
          <RowActionsMenu
            label={`Actions for order ${row.order_number}`}
            actions={[{ label: 'View Details', icon: Eye, onSelect: () => setViewingId(row.id) }, ...shipmentActions(row)]}
          />
        </div>
      ),
    },
  ];

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Shipments</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Shipments & Dispatch</h1>
            <span className="page-count-pill">{kpis.ready_to_dispatch}</span>
          </div>
          <p className="page-subtitle">Packed orders arrive here from Picking. Dispatch them with a carrier and track them to delivery.</p>
        </div>

        <InfoCalloutCard
          title="Carrier Dispatch"
          description="Dispatching an order deducts its stock from the warehouse and marks the order as shipped."
          icon={<Truck size={18} />}
        />
      </div>

      {(canManageCouriers || (canDispatch && accounts.length > 0)) && (
        <div className="shipment-toolbar">
          {canDispatch && accounts.length > 0 && (
            <button type="button" className="btn-finovo-secondary" onClick={handleTrack} disabled={tracking}>
              {tracking ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
              <span>Update Courier Tracking</span>
            </button>
          )}
          {canManageCouriers && (
            <button type="button" className="btn-finovo-secondary" onClick={() => setCouriersOpen(true)}>
              <Settings2 size={14} />
              <span>Courier Accounts</span>
            </button>
          )}
        </div>
      )}

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      <div className="kpi-grid">
        <MetricCard title="Ready to Dispatch" value={kpis.ready_to_dispatch} icon={<PackageCheck size={20} />} pillText="Packed" pillVariant="amber" />
        <MetricCard title="Dispatched Today" value={kpis.dispatched_today} icon={<Send size={20} />} pillText="Today" />
        <MetricCard title="In Transit" value={kpis.in_transit} icon={<Truck size={20} />} pillText="On the way" pillVariant="blue" />
        <MetricCard title="Needs Attention" value={kpis.issues} icon={<AlertTriangle size={20} />} pillText="Failed or cancelled" pillVariant="amber" />
      </div>

      <div className="content-card">
        <div className="content-card-header" style={{ flexWrap: 'wrap', gap: 12 }}>
          <div className="procurement-tabs" role="tablist" style={{ margin: 0, borderBottom: 'none' }}>
            {STATUS_TABS.map((tab) => (
              <button
                key={tab.value}
                type="button"
                role="tab"
                aria-selected={statusFilter === tab.value}
                className={`procurement-tab-btn ${statusFilter === tab.value ? 'is-active' : ''}`}
                onClick={() => {
                  setStatusFilter(tab.value);
                  setPage(1);
                }}
              >
                <span>{tab.label}</span>
                {tab.kpi && <span className="procurement-tab-count">{kpis[tab.kpi]}</span>}
              </button>
            ))}
          </div>
        </div>

        <div style={{ padding: '12px 24px', borderBottom: '1px solid #f1f5f9' }}>
          <div className="topbar-search-input" style={{ width: '100%', boxSizing: 'border-box' }}>
            <Search size={16} color="#94a3b8" />
            <input
              type="text"
              placeholder="Search order number, customer, carrier or tracking number..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>

        {selectedRows.length > 0 && (
          <div className="shipment-bulk-bar">
            <strong>{selectedRows.length} selected</strong>
            {canDispatch && readyRows.length > 0 && (
              <button type="button" className="btn-finovo-primary" onClick={() => setDispatchList(readyRows)} disabled={working}>
                <Send size={14} />
                <span>Dispatch {readyRows.length}</span>
              </button>
            )}
            {dispatchedIds.length > 0 && (
              <>
                <button type="button" className="btn-finovo-secondary" onClick={() => run(() => omsService.downloadShippingLabels(dispatchedIds), 'The labels could not be printed.')} disabled={working}>
                  <Tag size={14} />
                  <span>Print {dispatchedIds.length} Label(s)</span>
                </button>
                <button type="button" className="btn-finovo-secondary" onClick={() => run(() => omsService.downloadLoadSheet(dispatchedIds), 'The load sheet could not be printed.')} disabled={working}>
                  <ClipboardList size={14} />
                  <span>Load Sheet</span>
                </button>
              </>
            )}
            <button type="button" className="btn-finovo-secondary" onClick={() => setSelected(new Set())}>
              Clear
            </button>
          </div>
        )}

        <DataTable
          columns={columns}
          data={shipments}
          keyField="id"
          isLoading={loading}
          selectable
          selectedIds={selected}
          onSelectRow={toggleRow}
          onSelectAll={toggleAll}
          onRowClick={(row) => setViewingId(row.id)}
          emptyMessage={
            statusFilter === 'pending' || statusFilter === ''
              ? 'No packed orders yet. Orders marked Packed in Picking appear here for dispatch.'
              : 'No shipments in this status.'
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

      {viewingId !== null && (
        <ShipmentDetailModal shipmentId={viewingId} refreshKey={refreshKey} actions={shipmentActions} onClose={() => setViewingId(null)} />
      )}

      {dispatchList && (
        <DispatchModal
          shipments={dispatchList}
          accounts={accounts}
          carriers={carriers}
          onClose={() => setDispatchList(null)}
          onDispatched={(message) => {
            setDispatchList(null);
            changed(message);
          }}
        />
      )}

      {couriersOpen && <CourierAccountsModal onClose={() => setCouriersOpen(false)} onChanged={reloadCouriers} />}

      {dialog}
    </div>
  );
};

export default ShipmentsPage;
