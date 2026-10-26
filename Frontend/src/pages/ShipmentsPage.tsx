import React, { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, Loader2, PackageCheck, Search, Send, Truck, X } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import CustomDropdown from '../components/common/CustomDropdown';
import StatusPill from '../components/common/StatusPill';
import { Alert } from '../components/common/Alert';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import { useActiveStore } from '../hooks/useActiveStore';
import omsService from '../services/omsService';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import type { ShipmentKpis, ShipmentModel } from '../services/omsService';
import { formatDate } from '../utils/format';
import { useDebouncedValue } from '../hooks/useDebouncedValue';

const CARRIERS = ['TCS', 'Leopards Courier', 'M&P', 'PostEx', 'Trax', 'BlueEx', 'DHL', 'FedEx', 'UPS', 'Other'];

const STATUS_TABS: { value: string; label: string; kpi?: keyof ShipmentKpis }[] = [
  { value: '', label: 'All Shipments' },
  { value: 'pending', label: 'Ready to Dispatch', kpi: 'ready_to_dispatch' },
  { value: 'shipped', label: 'In Transit', kpi: 'in_transit' },
  { value: 'delivered', label: 'Delivered', kpi: 'delivered' },
];

const STATUS_LABELS: Record<ShipmentModel['status'], string> = {
  pending: 'Ready to Dispatch',
  shipped: 'In Transit',
  delivered: 'Delivered',
};

const errorMessage = (err: unknown, fallback: string) => (err instanceof Error && err.message ? err.message : fallback);

export const ShipmentsPage: React.FC = () => {
  const { user } = useAuth();
  const canDispatch = can(user, 'shipments.dispatch');
  const { activeStore } = useActiveStore();
  const { confirm, dialog } = useConfirmDialog();

  const [shipments, setShipments] = useState<ShipmentModel[]>([]);
  const [kpis, setKpis] = useState<ShipmentKpis>({ ready_to_dispatch: 0, dispatched_today: 0, in_transit: 0, delivered: 0 });
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('');
  const [search, setSearch] = useState('');
  // Live search: the list reloads 300 ms after typing stops.
  const appliedSearch = useDebouncedValue(search.trim());
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Dispatch popup
  const [dispatching, setDispatching] = useState<ShipmentModel | null>(null);
  const [carrier, setCarrier] = useState(CARRIERS[0]);
  const [otherCarrier, setOtherCarrier] = useState('');
  const [trackingNumber, setTrackingNumber] = useState('');
  const [trackingUrl, setTrackingUrl] = useState('');
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [deliveringId, setDeliveringId] = useState<number | null>(null);

  // A new search starts from the first page.
  useEffect(() => setPage(1), [appliedSearch]);

  const fetchShipments = useCallback(async () => {
    try {
      setLoading(true);
      const res = await omsService.getShipments({
        store_id: activeStore?.id,
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
  }, [activeStore?.id, statusFilter, appliedSearch, page, perPage]);

  useEffect(() => {
    fetchShipments();
  }, [fetchShipments]);

  const openDispatch = (shipment: ShipmentModel) => {
    setDispatching(shipment);
    setCarrier(CARRIERS[0]);
    setOtherCarrier('');
    setTrackingNumber('');
    setTrackingUrl('');
    setFormError(null);
  };

  const closeDispatch = () => {
    if (!submitting) setDispatching(null);
  };

  const handleDispatch = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dispatching) return;

    const carrierName = carrier === 'Other' ? otherCarrier.trim() : carrier;
    if (!carrierName) {
      setFormError('Choose the carrier to dispatch this order.');
      return;
    }

    try {
      setSubmitting(true);
      const res = await omsService.dispatchShipment(dispatching.id, {
        carrier: carrierName,
        tracking_number: trackingNumber.trim() || undefined,
        tracking_url: trackingUrl.trim() || undefined,
      });
      setDispatching(null);
      setBanner({ type: 'success', text: res.message });
      fetchShipments();
    } catch (err) {
      setFormError(errorMessage(err, 'Failed to dispatch the order.'));
    } finally {
      setSubmitting(false);
    }
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
    if (!ok) return;

    try {
      setDeliveringId(shipment.id);
      const res = await omsService.deliverShipment(shipment.id);
      setBanner({ type: 'success', text: res.message });
      fetchShipments();
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'Failed to update the shipment.') });
    } finally {
      setDeliveringId(null);
    }
  };

  const columns: Column<ShipmentModel>[] = [
    {
      key: 'order_number',
      header: 'ORDER',
      width: '13%',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.order_number}</span>,
    },
    {
      key: 'customer',
      header: 'CUSTOMER',
      width: '17%',
      render: (row) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span style={{ fontSize: 13, color: '#0f172a', fontWeight: 500 }}>{row.customer}</span>
          {row.store && <span style={{ fontSize: 12, color: '#64748b' }}>{row.store}</span>}
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
      key: 'warehouse',
      header: 'WAREHOUSE',
      width: '14%',
      render: (row) => <span style={{ fontSize: 13, color: '#334155' }}>{row.warehouse ?? '—'}</span>,
    },
    {
      key: 'carrier',
      header: 'CARRIER / TRACKING',
      width: '18%',
      render: (row) =>
        row.carrier ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <span style={{ fontSize: 13, color: '#0f172a', fontWeight: 500 }}>{row.carrier}</span>
            {row.tracking_url ? (
              <a href={row.tracking_url} target="_blank" rel="noopener noreferrer" style={{ fontSize: 12, color: '#1e56ff', fontFamily: 'monospace' }}>
                {row.tracking_number}
              </a>
            ) : (
              <span style={{ fontSize: 12, color: '#64748b', fontFamily: 'monospace' }}>{row.tracking_number}</span>
            )}
          </div>
        ) : (
          <span style={{ fontSize: 12, color: '#94a3b8' }}>Not dispatched yet</span>
        ),
    },
    {
      key: 'status',
      header: 'STATUS',
      width: '13%',
      render: (row) => <StatusPill status={row.status} label={STATUS_LABELS[row.status]} />,
    },
    {
      key: 'date',
      header: 'DATE',
      width: '10%',
      render: (row) => (
        <span style={{ fontSize: 12, color: '#64748b' }}>
          {formatDate(row.delivered_at ?? row.shipped_at ?? row.packed_at)}
        </span>
      ),
    },
    {
      key: 'action',
      header: 'ACTION',
      width: '13%',
      align: 'center',
      render: (row) => {
        if (!canDispatch && row.status !== 'delivered') {
          return <span style={{ fontSize: 12, color: '#94a3b8' }}>{row.status === 'pending' ? 'Awaiting dispatch' : 'In transit'}</span>;
        }
        if (row.status === 'pending') {
          return (
            <button type="button" className="table-action-primary" onClick={() => openDispatch(row)}>
              <Send size={14} />
              <span>Dispatch</span>
            </button>
          );
        }
        if (row.status === 'shipped') {
          return (
            <button
              type="button"
              className="table-action-primary is-success"
              onClick={() => handleDeliver(row)}
              disabled={deliveringId === row.id}
            >
              {deliveringId === row.id ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle2 size={14} />}
              <span>Delivered</span>
            </button>
          );
        }
        return <span style={{ fontSize: 12, color: '#94a3b8' }}>Completed</span>;
      },
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

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      <div className="kpi-grid">
        <MetricCard title="Ready to Dispatch" value={kpis.ready_to_dispatch} icon={<PackageCheck size={20} />} pillText="Packed" pillVariant="amber" />
        <MetricCard title="Dispatched Today" value={kpis.dispatched_today} icon={<Send size={20} />} pillText="Today" />
        <MetricCard title="In Transit" value={kpis.in_transit} icon={<Truck size={20} />} pillText="On the way" pillVariant="blue" />
        <MetricCard title="Delivered" value={kpis.delivered} icon={<CheckCircle2 size={20} />} pillText="Completed" pillVariant="green" />
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
          <form
            onSubmit={(e) => {
              e.preventDefault();
            }}
          >
            <div className="topbar-search-input" style={{ width: '100%', boxSizing: 'border-box' }}>
              <Search size={16} color="#94a3b8" />
              <input
                type="text"
                placeholder="Search order number, customer, carrier or tracking number..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          </form>
        </div>

        <DataTable
          columns={columns}
          data={shipments}
          keyField="id"
          isLoading={loading}
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

      {dispatching && (
        <div className="modal-overlay" onClick={closeDispatch}>
          <form className="procurement-modal" style={{ maxWidth: 520 }} onClick={(e) => e.stopPropagation()} onSubmit={handleDispatch}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Dispatch Order {dispatching.order_number}</h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  {dispatching.items_count} item(s) for {dispatching.customer}
                  {dispatching.warehouse ? ` from ${dispatching.warehouse}` : ''}
                </p>
              </div>
              <button type="button" onClick={closeDispatch} className="topbar-icon-button" aria-label="Close">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {formError && <Alert type="error" message={formError} />}

              <div>
                <label className="form-label">Carrier *</label>
                <CustomDropdown value={carrier} onChange={(val) => setCarrier(String(val))} options={CARRIERS.map((c) => ({ value: c, label: c }))} />
              </div>

              {carrier === 'Other' && (
                <div>
                  <label className="form-label">Carrier Name *</label>
                  <input
                    type="text"
                    className="form-input"
                    value={otherCarrier}
                    onChange={(e) => setOtherCarrier(e.target.value)}
                    placeholder="e.g. Local Rider"
                    maxLength={100}
                  />
                </div>
              )}

              <div>
                <label className="form-label">Tracking ID</label>
                <input
                  type="text"
                  className="form-input"
                  value={trackingNumber}
                  onChange={(e) => setTrackingNumber(e.target.value)}
                  placeholder="Leave empty to generate a unique tracking ID"
                  maxLength={100}
                  autoFocus
                />
              </div>

              <div>
                <label className="form-label">Tracking Link</label>
                <input
                  type="url"
                  className="form-input"
                  value={trackingUrl}
                  onChange={(e) => setTrackingUrl(e.target.value)}
                  placeholder={
                    ['DHL', 'FedEx', 'UPS'].includes(carrier)
                      ? `Optional, ${carrier}'s tracking page is added automatically`
                      : 'Optional, e.g. the courier tracking page for this parcel'
                  }
                  maxLength={500}
                />
              </div>

              <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                Dispatching removes these items from warehouse stock, marks the order as shipped and emails the customer the
                tracking details.
              </p>
            </div>

            <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="button" onClick={closeDispatch} className="btn-outline" disabled={submitting}>
                  Cancel
                </button>
                <button type="submit" className="btn-primary" disabled={submitting}>
                  {submitting ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
                  <span>Dispatch Order</span>
                </button>
              </div>
            </div>
          </form>
        </div>
      )}

      {dialog}
    </div>
  );
};

export default ShipmentsPage;
