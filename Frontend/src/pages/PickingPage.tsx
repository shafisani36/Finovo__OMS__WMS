import React, { useEffect, useState } from 'react';
import { useActiveStore } from '../hooks/useActiveStore';
import {
  Package,
  Layers,
  RefreshCw,
  Truck,
  Check,
  Filter,
  Download,
  Info,
  Loader2,
  ScanLine,
  Waves,
  X
} from 'lucide-react';
import { Alert } from '../components/common/Alert';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import CustomDropdown from '../components/common/CustomDropdown';
import omsService from '../services/omsService';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import type { PickingItem, PickWaveSummary } from '../services/omsService';
import { PickScanModal, PickWaveModal } from '../components/picking/PickingScan';
import '../components/picking/Picking.css';
import { useDebouncedValue } from '../hooks/useDebouncedValue';

export const PickingPage: React.FC = () => {
  const { user } = useAuth();
  const canUpdate = can(user, 'picking.update');
  const [tasks, setTasks] = useState<PickingItem[]>([]);
  const [kpis, setKpis] = useState({
    pending_orders: 0,
    items_to_pick: 0,
    in_progress: 0,
    ready_to_dispatch: 0,
  });
  const [loading, setLoading] = useState(true);
  const { activeStore } = useActiveStore();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState('');
  // Live search: the list reloads 300 ms after typing stops.
  const debouncedSearch = useDebouncedValue(search.trim());
  const [statusFilter, setStatusFilter] = useState('');

  // Row selection for bulk actions
  const [selectedIds, setSelectedIds] = useState<Set<string | number>>(new Set());
  const [bulkModalOpen, setBulkModalOpen] = useState(false);
  const [bulkStatus, setBulkStatus] = useState('in_progress');
  const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [waves, setWaves] = useState<PickWaveSummary[]>([]);
  const [scanTaskId, setScanTaskId] = useState<number | null>(null);
  const [waveId, setWaveId] = useState<number | null>(null);
  const [creatingWave, setCreatingWave] = useState(false);

  const showResult = (message: string) => setBanner({ type: 'success', text: message });
  const showError = (err: unknown, fallback: string) =>
    setBanner({ type: 'error', text: err instanceof Error && err.message ? err.message : fallback });

  const fetchTasks = async () => {
    try {
      setLoading(true);
      const res = await omsService.getPickingTasks({
        store_id: activeStore?.id,
        search: debouncedSearch || undefined,
        status: statusFilter || undefined,
        page,
        per_page: perPage,
      });
      setTasks(res.data);
      setKpis(res.kpis);
      setTotal(res.meta.total);
      setLastPage(res.meta.last_page);
      setWaves(await omsService.getPickWaves());
    } catch (err) {
      showError(err, 'Failed to load picking tasks.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTasks();
  }, [page, perPage, statusFilter, activeStore?.id, debouncedSearch]);

  const handleSearchSubmit = (e: React.FormEvent) => e.preventDefault();

  // A new search starts from the first page.
  useEffect(() => setPage(1), [debouncedSearch]);

  const handleQuickComplete = async (taskId: number) => {
    try {
      setActionLoadingId(taskId);
      const res = await omsService.completePickTask(taskId);
      showResult(res.message);
      await fetchTasks();
    } catch (err) {
      showError(err, 'Failed to update the pick task.');
    } finally {
      setActionLoadingId(null);
    }
  };

  const handleStatusChange = async (taskId: number, newStatus: string) => {
    try {
      const res = await omsService.updatePickStatus(taskId, newStatus);
      showResult(res.message);
      fetchTasks();
    } catch (err) {
      showError(err, 'Failed to update the pick task.');
    }
  };

  const handleSelectRow = (id: string | number) => {
    const next = new Set(selectedIds);
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
    }
    setSelectedIds(next);
  };

  const handleSelectAll = (allIds: (string | number)[]) => {
    if (allIds.every((id) => selectedIds.has(id))) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(allIds));
    }
  };

  const handleBulkUpdate = async () => {
    if (selectedIds.size === 0) return;
    try {
      const res = await omsService.bulkUpdatePickTasks(Array.from(selectedIds) as number[], bulkStatus);
      setBulkModalOpen(false);
      setSelectedIds(new Set());
      showResult(bulkStatus === 'packed' ? `${res.message} Packed orders are now in Shipments for dispatch.` : res.message);
      fetchTasks();
    } catch (err) {
      setBulkModalOpen(false);
      showError(err, 'Failed to update the pick tasks.');
    }
  };

  /** Put the selected orders into a wave and open its pick list. */
  const handleCreateWave = async () => {
    try {
      setCreatingWave(true);
      const res = await omsService.createPickWave(Array.from(selectedIds) as number[]);
      setSelectedIds(new Set());
      showResult(res.message);
      setWaveId(res.data.id);
      fetchTasks();
    } catch (err) {
      showError(err, 'The wave could not be created.');
    } finally {
      setCreatingWave(false);
    }
  };

  /** A scan screen finished an order or a wave: show it and refresh the list. */
  const handleScanChanged = (message: string) => {
    showResult(message);
    fetchTasks();
  };

  /** Download the rows on screen as a CSV picking list. */
  const handleExport = () => {
    const header = ['Order', 'Customer', 'Product', 'Quantity', 'Items', 'Status', 'Warehouse', 'Priority'];
    const rows = tasks.map((t) => [t.order_number, t.customer, t.product, t.quantity, t.items_count, t.status_label, t.warehouse, t.priority]);
    const csv = [header, ...rows]
      .map((row) => row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
      .join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `picking-list-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  };

  const columns: Column<PickingItem>[] = [
    {
      key: 'order_number',
      header: 'ORDER',
      width: '12%',
      render: (row) => (
        <div>
          <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.order_number}</span>
          {row.wave_number && (
            <div>
              <span className="pick-wave-tag">{row.wave_number}</span>
            </div>
          )}
        </div>
      ),
    },
    {
      key: 'customer',
      header: 'CUSTOMER',
      width: '18%',
      render: (row) => (
        <span style={{ fontSize: 13, color: '#334155' }}>
          {row.customer}
        </span>
      ),
    },
    {
      key: 'product',
      header: 'PRODUCT',
      width: '32%',
      render: (row) => (
        <span style={{ fontSize: 13, color: '#0f172a', fontWeight: 500 }}>
          {row.product}
        </span>
      ),
    },
    {
      key: 'quantity',
      header: 'QTY',
      width: '8%',
      align: 'center',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>
          {row.quantity}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'STATUS',
      width: '18%',
      render: (row) => (
        <div style={{ width: 140 }}>
          <CustomDropdown
            value={row.status}
            onChange={(val) => handleStatusChange(row.id, val)}
            disabled={!canUpdate}
            options={[
              { value: 'not_started', label: 'Not Started' },
              { value: 'in_progress', label: 'In Progress' },
              { value: 'picked', label: 'Picked' },
              { value: 'packed', label: 'Packed' },
            ]}
            size="sm"
            searchable={false}
          />
        </div>
      ),
    },
    {
      key: 'action',
      header: 'ACTION',
      width: '12%',
      align: 'center',
      render: (row) => (
        <div className="pick-row-actions">
          <button
            type="button"
            className="pick-row-button"
            onClick={() => setScanTaskId(row.id)}
            title={row.status === 'picked' ? 'Scan to pack' : row.status === 'packed' ? 'View scanned items' : 'Scan to pick'}
            aria-label="Scan items"
          >
            <ScanLine size={16} />
          </button>
          <button
            type="button"
            className="pick-row-button"
            onClick={() => handleQuickComplete(row.id)}
            disabled={!canUpdate || actionLoadingId === row.id}
            title="Advance status without scanning"
            aria-label="Advance status"
          >
            {actionLoadingId === row.id ? <Loader2 size={16} className="animate-spin" /> : <Check size={16} strokeWidth={2.5} />}
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="page-container">
      {/* Breadcrumb */}
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Picking</span>
      </nav>

      {/* Page Header matching Screenshot 3 */}
      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Picking & Packing</h1>
            <span className="page-count-pill">{kpis.pending_orders}</span>
          </div>
          <p className="page-subtitle">Orders waiting to be picked and packed before dispatch.</p>
        </div>

        <InfoCalloutCard
          title="Fulfill orders efficiently"
          description="Keep picking and packing status up to date to ensure faster dispatch and happier customers."
          icon={<Info size={18} />}
        />
      </div>

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      {/* 4 KPI Metric Cards matching Screenshot 3 */}
      <div className="kpi-grid">
        <MetricCard
          title="Pending Orders"
          value={kpis.pending_orders}
          icon={<Package size={20} />}
          pillText="All"
          pillVariant="blue"
        />
        <MetricCard
          title="Items to Pick"
          value={kpis.items_to_pick}
          icon={<Layers size={20} />}
          pillText="To pick"
          pillVariant="blue"
        />
        <MetricCard
          title="In Progress"
          value={kpis.in_progress}
          icon={<RefreshCw size={20} />}
          pillText="On track"
          pillVariant="blue"
        />
        <MetricCard
          title="Ready to Dispatch"
          value={kpis.ready_to_dispatch}
          icon={<Truck size={20} />}
          pillText="Ready"
          pillVariant="blue"
        />
      </div>

      {/* Main Table Card matching Screenshot 3 */}
      <div className="content-card">
        <div className="content-card-header">
          <h3 className="content-card-title">Orders to Pick & Pack</h3>

          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <button
              onClick={handleCreateWave}
              className="btn-finovo-secondary"
              disabled={!canUpdate || selectedIds.size < 2 || creatingWave}
              title="Pick the selected orders together in one walk through the warehouse"
            >
              {creatingWave ? <Loader2 size={14} className="animate-spin" /> : <Waves size={14} />}
              <span>Create Wave</span>
            </button>

            <button
              onClick={() => setBulkModalOpen(true)}
              className="btn-finovo-secondary"
              disabled={!canUpdate || selectedIds.size === 0}
            >
              <RefreshCw size={14} />
              <span>Bulk Update</span>
              {selectedIds.size > 0 && <span>({selectedIds.size})</span>}
            </button>

            <div style={{ width: 170 }}>
              <CustomDropdown
                value={statusFilter}
                onChange={(val) => {
                  setStatusFilter(String(val));
                  setPage(1);
                }}
                options={[
                  { value: '', label: 'All Statuses' },
                  { value: 'not_started', label: 'Not Started' },
                  { value: 'in_progress', label: 'In Progress' },
                  { value: 'picked', label: 'Picked' },
                  { value: 'packed', label: 'Packed' },
                ]}
                searchable={false}
                icon={<Filter size={14} />}
              />
            </div>

            <button
              onClick={handleExport}
              className="btn-finovo-secondary"
              disabled={tasks.length === 0}
            >
              <Download size={14} />
              <span>Export</span>
            </button>
          </div>
        </div>

        {waves.length > 0 && (
          <div className="pick-waves-strip">
            <span>Open waves:</span>
            {waves.map((wave) => (
              <button key={wave.id} type="button" className="pick-wave-chip" onClick={() => setWaveId(wave.id)}>
                <Waves size={13} />
                {wave.wave_number}
                <span>
                  {wave.orders} orders{wave.warehouse ? ` · ${wave.warehouse}` : ''}
                </span>
              </button>
            ))}
          </div>
        )}

        {/* Full-width Search Bar matching Screenshot 3 */}
        <div style={{ padding: '12px 24px', borderBottom: '1px solid #f1f5f9' }}>
          <form onSubmit={handleSearchSubmit}>
            <div className="topbar-search-input" style={{ width: '100%', boxSizing: 'border-box' }}>
              <input
                type="text"
                placeholder="Search orders, customers or products..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          </form>
        </div>

        <DataTable
          columns={columns}
          data={tasks}
          keyField="id"
          isLoading={loading}
          emptyMessage="No pending orders to pick."
          selectable={true}
          selectedIds={selectedIds}
          onSelectRow={handleSelectRow}
          onSelectAll={handleSelectAll}
          pagination={{
            currentPage: page,
            lastPage,
            perPage,
            total,
            onPageChange: (p) => setPage(p),
            onPerPageChange: (pp) => {
              setPerPage(pp);
              setPage(1);
            },
          }}
        />
      </div>

      {scanTaskId !== null && (
        <PickScanModal taskId={scanTaskId} canScan={canUpdate} onClose={() => setScanTaskId(null)} onChanged={handleScanChanged} />
      )}
      {waveId !== null && <PickWaveModal waveId={waveId} canScan={canUpdate} onClose={() => setWaveId(null)} onChanged={handleScanChanged} />}

      {/* Bulk Update Modal */}
      {bulkModalOpen && (
        <div className="modal-overlay" onClick={() => setBulkModalOpen(false)}>
          <div className="procurement-modal" style={{ maxWidth: 460, overflow: 'visible' }} onClick={(e) => e.stopPropagation()}>
            <div className="procurement-modal-header" style={{ borderRadius: '18px 18px 0 0' }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Bulk Update</h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Set one status on the {selectedIds.size} selected order(s).
                </p>
              </div>
              <button type="button" onClick={() => setBulkModalOpen(false)} className="topbar-icon-button" aria-label="Close">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body" style={{ overflow: 'visible' }}>
              <CustomDropdown
                label="Set Status to"
                value={bulkStatus}
                onChange={(val) => setBulkStatus(val)}
                options={[
                  { value: 'not_started', label: 'Not Started' },
                  { value: 'in_progress', label: 'In Progress' },
                  { value: 'picked', label: 'Picked' },
                  { value: 'packed', label: 'Packed', description: 'Sends the orders to Shipments for dispatch' },
                ]}
                searchable={false}
              />
            </div>

            <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end', borderRadius: '0 0 18px 18px' }}>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="button" onClick={() => setBulkModalOpen(false)} className="btn-outline">
                  Cancel
                </button>
                <button type="button" onClick={handleBulkUpdate} className="btn-primary">
                  Update Tasks
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default PickingPage;
