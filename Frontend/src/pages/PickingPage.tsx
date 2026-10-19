import React, { useEffect, useState } from 'react';
import {
  Package,
  Layers,
  RefreshCw,
  Truck,
  Check,
  Filter,
  Download,
  Info,
  Loader2
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import omsService from '../services/omsService';
import type { PickingItem } from '../services/omsService';

export const PickingPage: React.FC = () => {
  const [tasks, setTasks] = useState<PickingItem[]>([]);
  const [kpis, setKpis] = useState({
    pending_orders: 0,
    items_to_pick: 0,
    in_progress: 0,
    ready_to_dispatch: 0,
  });
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  // Row selection for bulk actions
  const [selectedIds, setSelectedIds] = useState<Set<string | number>>(new Set());
  const [bulkModalOpen, setBulkModalOpen] = useState(false);
  const [bulkStatus, setBulkStatus] = useState('in_progress');
  const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);

  const fetchTasks = async () => {
    try {
      setLoading(true);
      const res = await omsService.getPickingTasks({
        search: search || undefined,
        status: statusFilter || undefined,
        page,
        per_page: perPage,
      });
      setTasks(res.data);
      setKpis(res.kpis);
      setTotal(res.meta.total);
      setLastPage(res.meta.last_page);
    } catch (err) {
      console.error('Failed to load picking tasks', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTasks();
  }, [page, perPage, statusFilter]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchTasks();
  };

  const handleQuickComplete = async (taskId: number) => {
    try {
      setActionLoadingId(taskId);
      await omsService.completePickTask(taskId);
      await fetchTasks();
    } catch (err) {
      console.error(err);
    } finally {
      setActionLoadingId(null);
    }
  };

  const handleStatusChange = async (taskId: number, newStatus: string) => {
    try {
      await omsService.updatePickStatus(taskId, newStatus);
      fetchTasks();
    } catch (err) {
      console.error(err);
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
      await omsService.bulkUpdatePickTasks(Array.from(selectedIds) as number[], bulkStatus);
      setBulkModalOpen(false);
      setSelectedIds(new Set());
      fetchTasks();
    } catch (err) {
      console.error(err);
    }
  };

  const columns: Column<PickingItem>[] = [
    {
      key: 'order_number',
      header: 'ORDER',
      width: '12%',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
          {row.order_number}
        </span>
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
        <select
          value={row.status}
          onChange={(e) => handleStatusChange(row.id, e.target.value)}
          style={{
            padding: '6px 12px',
            borderRadius: 8,
            border: '1px solid #cbd5e1',
            fontSize: 12,
            fontWeight: 500,
            backgroundColor: '#ffffff',
            color: '#334155',
            cursor: 'pointer',
            outline: 'none',
          }}
        >
          <option value="not_started">Not Started</option>
          <option value="in_progress">In Progress</option>
          <option value="picked">Picked</option>
          <option value="packed">Packed</option>
        </select>
      ),
    },
    {
      key: 'action',
      header: 'ACTION',
      width: '12%',
      align: 'center',
      render: (row) => (
        <button
          onClick={() => handleQuickComplete(row.id)}
          disabled={actionLoadingId === row.id}
          style={{
            width: 32,
            height: 32,
            borderRadius: 8,
            border: '1px solid #dbeafe',
            backgroundColor: '#eff6ff',
            color: '#1e56ff',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            cursor: 'pointer',
            transition: 'all 0.15s ease',
          }}
          title="Advance Status"
        >
          {actionLoadingId === row.id ? (
            <Loader2 size={16} className="animate-spin" />
          ) : (
            <Check size={16} strokeWidth={2.5} />
          )}
        </button>
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
          linkText="Learn more ->"
          icon={<Info size={18} />}
        />
      </div>

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
              onClick={() => setBulkModalOpen(true)}
              className="btn-finovo-secondary"
              disabled={selectedIds.size === 0}
            >
              <RefreshCw size={14} />
              <span>Bulk Update</span>
              {selectedIds.size > 0 && <span>({selectedIds.size})</span>}
            </button>

            <button
              onClick={() => {
                const nextStatus = statusFilter === '' ? 'not_started' : statusFilter === 'not_started' ? 'in_progress' : '';
                setStatusFilter(nextStatus);
              }}
              className="btn-finovo-secondary"
            >
              <Filter size={14} />
              <span>{statusFilter ? `Status: ${statusFilter}` : 'Filters'}</span>
            </button>

            <button
              onClick={() => {
                alert('Exporting picking list to CSV...');
              }}
              className="btn-finovo-secondary"
            >
              <Download size={14} />
              <span>Export</span>
            </button>
          </div>
        </div>

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

      {/* Bulk Update Modal */}
      {bulkModalOpen && (
        <div className="modal-overlay" onClick={() => setBulkModalOpen(false)}>
          <div className="modal-dialog" onClick={(e) => e.stopPropagation()}>
            <div style={{ padding: '20px 24px', borderBottom: '1px solid #e2e8f0' }}>
              <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>
                Bulk Update ({selectedIds.size} selected)
              </h3>
            </div>
            <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Set Status to:
                </label>
                <select
                  value={bulkStatus}
                  onChange={(e) => setBulkStatus(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14 }}
                >
                  <option value="not_started">Not Started</option>
                  <option value="in_progress">In Progress</option>
                  <option value="picked">Picked</option>
                  <option value="packed">Packed</option>
                </select>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12, marginTop: 12 }}>
                <button
                  type="button"
                  onClick={() => setBulkModalOpen(false)}
                  className="btn-finovo-secondary"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={handleBulkUpdate}
                  className="btn-finovo-primary"
                >
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
