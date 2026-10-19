import React, { useEffect, useState } from 'react';
import {
  ArrowLeftRight,
  Warehouse as WarehouseIcon,
  Package,
  Clock,
  Lightbulb,
  Edit3,
  FileText,
  Filter,
  Loader2,
  CheckCircle,
  AlertCircle
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import omsService from '../services/omsService';
import type {
  StockMovementItem,
  WarehouseModel,
  ProductModel,
} from '../services/omsService';

export const StockMovementsPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState<'transfer' | 'adjust' | 'history'>('transfer');
  const [movements, setMovements] = useState<StockMovementItem[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseModel[]>([]);
  const [products, setProducts] = useState<ProductModel[]>([]);
  const [kpis, setKpis] = useState({
    total_warehouses: 0,
    total_products: 0,
    movements_this_month: 0,
  });

  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('');

  // Transfer Form State
  const [selectedProductId, setSelectedProductId] = useState<number | ''>('');
  const [fromWarehouseId, setFromWarehouseId] = useState<number | ''>('');
  const [toWarehouseId, setToWarehouseId] = useState<number | ''>('');
  const [transferQty, setTransferQty] = useState('');
  const [transferNotes, setTransferNotes] = useState('');
  const [availableStock, setAvailableStock] = useState<number | null>(null);
  const [transferLoading, setTransferLoading] = useState(false);
  const [transferFeedback, setTransferFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Adjustment Form State
  const [adjWarehouseId, setAdjWarehouseId] = useState<number | ''>('');
  const [adjType, setAdjType] = useState<'add' | 'subtract' | 'recount'>('add');
  const [adjQty, setAdjQty] = useState('');
  const [adjReason, setAdjReason] = useState('Stock Receipt');
  const [adjNotes, setAdjNotes] = useState('');
  const [adjLoading, setAdjLoading] = useState(false);

  const fetchMovements = async () => {
    try {
      setLoading(true);
      const res = await omsService.getStockMovements({
        search: search || undefined,
        type: typeFilter || undefined,
        page,
        per_page: perPage,
      });
      setMovements(res.data);
      setKpis(res.kpis);
      setTotal(res.meta.total);
      setLastPage(res.meta.last_page);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const fetchMetadata = async () => {
    try {
      const [whRes, prodRes] = await Promise.all([
        omsService.getWarehouses(),
        omsService.getProducts({ per_page: 100 }),
      ]);
      setWarehouses(whRes.data);
      setProducts(prodRes.data);

      if (whRes.data.length >= 2) {
        setFromWarehouseId(whRes.data[0].id);
        setToWarehouseId(whRes.data[1].id);
        setAdjWarehouseId(whRes.data[0].id);
      }
      if (prodRes.data.length > 0) {
        setSelectedProductId(prodRes.data[0].id);
      }
    } catch (err) {
      console.error(err);
    }
  };

  useEffect(() => {
    fetchMetadata();
  }, []);

  useEffect(() => {
    fetchMovements();
  }, [page, perPage, typeFilter]);

  // Update availability when product or source warehouse changes
  useEffect(() => {
    if (selectedProductId && fromWarehouseId) {
      omsService
        .getAvailability(Number(selectedProductId), Number(fromWarehouseId))
        .then((data) => setAvailableStock(data.available))
        .catch(() => setAvailableStock(0));
    } else {
      setAvailableStock(null);
    }
  }, [selectedProductId, fromWarehouseId]);

  const handleTransferSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setTransferFeedback(null);

    if (!selectedProductId || !fromWarehouseId || !toWarehouseId || !transferQty) {
      setTransferFeedback({ type: 'error', message: 'Please complete all required transfer fields.' });
      return;
    }

    try {
      setTransferLoading(true);
      await omsService.transferStock({
        product_id: Number(selectedProductId),
        from_warehouse_id: Number(fromWarehouseId),
        to_warehouse_id: Number(toWarehouseId),
        quantity: Number(transferQty),
        notes: transferNotes || undefined,
      });

      setTransferFeedback({ type: 'success', message: 'Stock successfully transferred and logged to the ledger.' });
      setTransferQty('');
      setTransferNotes('');
      // Refresh
      fetchMovements();
      const updated = await omsService.getAvailability(Number(selectedProductId), Number(fromWarehouseId));
      setAvailableStock(updated.available);
    } catch (err: any) {
      setTransferFeedback({
        type: 'error',
        message: err.response?.data?.message || 'Stock transfer failed.',
      });
    } finally {
      setTransferLoading(false);
    }
  };

  const handleAdjustmentSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setTransferFeedback(null);

    if (!selectedProductId || !adjWarehouseId || adjQty === '') {
      setTransferFeedback({ type: 'error', message: 'Please complete all required adjustment fields.' });
      return;
    }

    try {
      setAdjLoading(true);
      await omsService.adjustStock({
        product_id: Number(selectedProductId),
        warehouse_id: Number(adjWarehouseId),
        adjustment_type: adjType,
        quantity: Number(adjQty),
        reason: adjReason,
        notes: adjNotes || undefined,
      });

      setTransferFeedback({ type: 'success', message: 'Inventory balance adjusted and movement recorded.' });
      setAdjQty('');
      setAdjNotes('');
      fetchMovements();
    } catch (err: any) {
      setTransferFeedback({
        type: 'error',
        message: err.response?.data?.message || 'Adjustment failed.',
      });
    } finally {
      setAdjLoading(false);
    }
  };

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchMovements();
  };

  const columns: Column<StockMovementItem>[] = [
    {
      key: 'reference',
      header: 'Reference',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
          {row.reference}
        </span>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (row) => (
        <span
          style={{
            fontSize: 11,
            fontWeight: 700,
            padding: '3px 10px',
            borderRadius: 6,
            backgroundColor: row.type === 'Transfer' ? '#eff6ff' : '#f0fdf4',
            color: row.type === 'Transfer' ? '#1e56ff' : '#16a34a',
            border: `1px solid ${row.type === 'Transfer' ? '#dbeafe' : '#bbf7d0'}`,
          }}
        >
          {row.type}
        </span>
      ),
    },
    {
      key: 'product',
      header: 'Product',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>
          {row.product}
        </span>
      ),
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      render: (row) => (
        <span style={{ fontSize: 13, color: '#475569' }}>
          {row.warehouse}
        </span>
      ),
    },
    {
      key: 'quantity',
      header: 'Quantity',
      align: 'center',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 700, color: row.direction === 'in' ? '#16a34a' : '#ef4444' }}>
          {row.direction === 'in' ? `+${row.quantity}` : `-${row.quantity}`}
        </span>
      ),
    },
    {
      key: 'before',
      header: 'Before',
      align: 'center',
      render: (row) => <span style={{ fontSize: 13, color: '#64748b' }}>{row.before}</span>,
    },
    {
      key: 'after',
      header: 'After',
      align: 'center',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{row.after}</span>,
    },
    {
      key: 'user',
      header: 'User',
      render: (row) => <span style={{ fontSize: 13, color: '#334155' }}>{row.user}</span>,
    },
    {
      key: 'date',
      header: 'Date & Time',
      render: (row) => <span style={{ fontSize: 12, color: '#64748b' }}>{row.date}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: () => (
        <div className="status-dot-badge status-active">
          <span className="status-dot" />
          <span>Completed</span>
        </div>
      ),
    },
    {
      key: 'action',
      header: 'Action',
      render: (row) => (
        <button
          onClick={() => alert(`Movement Details:\nRef: ${row.reference}\nProduct: ${row.product}\nReason: ${row.reason || 'N/A'}`)}
          className="btn-finovo-secondary"
          style={{ padding: '4px 10px', fontSize: 12 }}
        >
          View
        </button>
      ),
    },
  ];

  const fromWarehouse = warehouses.find((w) => w.id === Number(fromWarehouseId));

  return (
    <div className="page-container">
      {/* Breadcrumb matching Screenshot 4 */}
      <nav className="breadcrumb-nav">
        <span>Home</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Stock Movements</span>
      </nav>

      {/* Page Header */}
      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">Stock Movements</h1>
          <p className="page-subtitle">Transfer stock between warehouses, or adjust stock for damage/loss/correction.</p>
        </div>

        <InfoCalloutCard
          title="Keep your inventory accurate"
          description="Use stock movements to maintain accurate inventory across all warehouses and stores."
          linkText="Learn more ->"
          icon={<ArrowLeftRight size={18} />}
        />
      </div>

      {/* 4 KPI Metric Cards matching Screenshot 4 */}
      <div className="kpi-grid">
        <MetricCard
          title="Total Warehouses"
          value={kpis.total_warehouses}
          icon={<WarehouseIcon size={20} />}
          pillText="Active"
          pillVariant="blue"
        />
        <MetricCard
          title="Total Products"
          value={kpis.total_products}
          icon={<Package size={20} />}
          pillText="In system"
          pillVariant="blue"
        />
        <MetricCard
          title="Movements This Month"
          value={kpis.movements_this_month}
          icon={<Clock size={20} />}
          pillText="On track"
          pillVariant="blue"
        />
        {/* Yellow Best Practice Card */}
        <div
          className="kpi-card"
          style={{
            backgroundColor: '#fefce8',
            borderColor: '#fef08a',
          }}
        >
          <div
            className="kpi-card-icon"
            style={{
              backgroundColor: '#fef08a',
              color: '#a16207',
            }}
          >
            <Lightbulb size={20} />
          </div>
          <div className="kpi-card-content">
            <h4 className="kpi-card-title" style={{ color: '#854d0e', fontWeight: 700 }}>
              Best Practice
            </h4>
            <span style={{ fontSize: 11, color: '#713f12', lineHeight: 1.3 }}>
              Record all stock movements to keep your inventory accurate and auditable.
            </span>
          </div>
        </div>
      </div>

      {/* Tabs matching Screenshot 4 */}
      <div className="finovo-tabs">
        <div
          className={`finovo-tab-item ${activeTab === 'transfer' ? 'active' : ''}`}
          onClick={() => setActiveTab('transfer')}
        >
          <ArrowLeftRight size={16} />
          <span>Transfer Stock</span>
        </div>
        <div
          className={`finovo-tab-item ${activeTab === 'adjust' ? 'active' : ''}`}
          onClick={() => setActiveTab('adjust')}
        >
          <Edit3 size={16} />
          <span>Stock Adjustment</span>
        </div>
        <div
          className={`finovo-tab-item ${activeTab === 'history' ? 'active' : ''}`}
          onClick={() => setActiveTab('history')}
        >
          <FileText size={16} />
          <span>Movement History</span>
        </div>
      </div>

      {/* Feedback banner */}
      {transferFeedback && (
        <div
          style={{
            padding: '12px 18px',
            borderRadius: 12,
            marginBottom: 20,
            display: 'flex',
            alignItems: 'center',
            gap: 10,
            backgroundColor: transferFeedback.type === 'success' ? '#f0fdf4' : '#fef2f2',
            border: `1px solid ${transferFeedback.type === 'success' ? '#bbf7d0' : '#fecaca'}`,
            color: transferFeedback.type === 'success' ? '#15803d' : '#b91c1c',
            fontSize: 13,
          }}
        >
          {transferFeedback.type === 'success' ? <CheckCircle size={18} /> : <AlertCircle size={18} />}
          <span>{transferFeedback.message}</span>
        </div>
      )}

      {/* Tab 1: Transfer Stock Card matching Screenshot 4 */}
      {activeTab === 'transfer' && (
        <div className="content-card" style={{ padding: 24 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <div
                style={{
                  width: 36,
                  height: 36,
                  borderRadius: 10,
                  backgroundColor: '#eff6ff',
                  color: '#1e56ff',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <ArrowLeftRight size={18} />
              </div>
              <div>
                <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                  Transfer Stock Between Warehouses
                </h3>
                <p style={{ margin: '2px 0 0 0', fontSize: 13, color: '#64748b' }}>
                  Move stock from one warehouse to another.
                </p>
              </div>
            </div>

            <span className="kpi-pill kpi-pill-blue" style={{ fontSize: 12, padding: '4px 10px' }}>
              Transfer
            </span>
          </div>

          <form onSubmit={handleTransferSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
            {/* Product Select */}
            <div>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                Product
              </label>
              <select
                value={selectedProductId}
                onChange={(e) => setSelectedProductId(Number(e.target.value))}
                style={{
                  width: '100%',
                  padding: '10px 14px',
                  borderRadius: 10,
                  border: '1px solid #cbd5e1',
                  fontSize: 14,
                  backgroundColor: '#ffffff',
                }}
              >
                <option value="">Select product...</option>
                {products.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name} ({p.sku})
                  </option>
                ))}
              </select>
            </div>

            {/* Available Stock Info Bar matching Screenshot 4 */}
            <div
              style={{
                backgroundColor: '#f8fafc',
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                padding: '12px 18px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <Package size={18} color="#1e56ff" />
                <span style={{ fontSize: 13, color: '#334155' }}>
                  <strong>Available Stock:</strong>{' '}
                  <span style={{ color: '#1e56ff', fontWeight: 700 }}>
                    {availableStock !== null ? `${availableStock} units` : 'Select product & warehouse'}
                  </span>{' '}
                  — Warehouse: {fromWarehouse ? fromWarehouse.name : 'None selected'}
                </span>
              </div>
              <span style={{ fontSize: 12, fontWeight: 600, color: '#1e56ff', cursor: 'pointer' }}>
                View by Warehouse
              </span>
            </div>

            {/* From & To Warehouses Row */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  From Warehouse
                </label>
                <select
                  value={fromWarehouseId}
                  onChange={(e) => setFromWarehouseId(Number(e.target.value))}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 14,
                    backgroundColor: '#ffffff',
                  }}
                >
                  <option value="">Select warehouse...</option>
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name} ({w.code})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  To Warehouse
                </label>
                <select
                  value={toWarehouseId}
                  onChange={(e) => setToWarehouseId(Number(e.target.value))}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 14,
                    backgroundColor: '#ffffff',
                  }}
                >
                  <option value="">Select warehouse...</option>
                  {warehouses
                    .filter((w) => w.id !== Number(fromWarehouseId))
                    .map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.name} ({w.code})
                      </option>
                    ))}
                </select>
              </div>
            </div>

            {/* Quantity & Notes Row */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Quantity
                </label>
                <input
                  type="number"
                  min="1"
                  placeholder="Enter quantity..."
                  value={transferQty}
                  onChange={(e) => setTransferQty(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 14,
                    boxSizing: 'border-box',
                  }}
                  required
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Notes (optional)
                </label>
                <input
                  type="text"
                  placeholder="e.g. Redundancing stock, seasonal transfer, etc."
                  value={transferNotes}
                  onChange={(e) => setTransferNotes(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 14,
                    boxSizing: 'border-box',
                  }}
                />
              </div>
            </div>

            {/* Transfer Stock Submit Button matching Screenshot 4 */}
            <button
              type="submit"
              disabled={transferLoading}
              className="btn-finovo-primary"
              style={{
                width: '100%',
                padding: '12px 20px',
                justifyContent: 'center',
                fontSize: 14,
                marginTop: 6,
              }}
            >
              {transferLoading ? <Loader2 size={18} className="animate-spin" /> : <ArrowLeftRight size={18} />}
              <span>Transfer Stock</span>
            </button>
          </form>
        </div>
      )}

      {/* Tab 2: Stock Adjustment */}
      {activeTab === 'adjust' && (
        <div className="content-card" style={{ padding: 24 }}>
          <h3 style={{ margin: '0 0 16px 0', fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
            Adjust Physical Stock
          </h3>

          <form onSubmit={handleAdjustmentSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Product
                </label>
                <select
                  value={selectedProductId}
                  onChange={(e) => setSelectedProductId(Number(e.target.value))}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14 }}
                  required
                >
                  <option value="">Select product...</option>
                  {products.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} ({p.sku})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Warehouse
                </label>
                <select
                  value={adjWarehouseId}
                  onChange={(e) => setAdjWarehouseId(Number(e.target.value))}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14 }}
                  required
                >
                  <option value="">Select warehouse...</option>
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name} ({w.code})
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Adjustment Type
                </label>
                <select
                  value={adjType}
                  onChange={(e) => setAdjType(e.target.value as any)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14 }}
                >
                  <option value="add">Add (Inbound Receipt)</option>
                  <option value="subtract">Subtract (Damage / Loss)</option>
                  <option value="recount">Recount (Physical Cycle Count)</option>
                </select>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Quantity
                </label>
                <input
                  type="number"
                  min="0"
                  placeholder="Enter quantity..."
                  value={adjQty}
                  onChange={(e) => setAdjQty(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  required
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Reason
                </label>
                <select
                  value={adjReason}
                  onChange={(e) => setAdjReason(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14 }}
                >
                  <option value="Stock Receipt">Stock Receipt</option>
                  <option value="Damaged Goods">Damaged Goods</option>
                  <option value="Loss / Theft">Loss / Theft</option>
                  <option value="Audit Correction">Audit Correction</option>
                  <option value="Return Restock">Return Restock</option>
                </select>
              </div>
            </div>

            <button
              type="submit"
              disabled={adjLoading}
              className="btn-finovo-primary"
              style={{ width: '100%', padding: '12px 20px', justifyContent: 'center' }}
            >
              {adjLoading && <Loader2 size={18} className="animate-spin" />}
              <span>Commit Stock Adjustment</span>
            </button>
          </form>
        </div>
      )}

      {/* Recent Movements Section matching Screenshot 4 */}
      <div className="content-card">
        <div className="content-card-header">
          <h3 className="content-card-title">Recent Movements</h3>

          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <form onSubmit={handleSearchSubmit}>
              <div className="topbar-search-input" style={{ width: 280 }}>
                <input
                  type="text"
                  placeholder="Search orders, customers or products..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
            </form>

            <button
              onClick={() => {
                const nextType = typeFilter === '' ? 'transfer' : typeFilter === 'transfer' ? 'adjustment' : '';
                setTypeFilter(nextType);
              }}
              className="btn-finovo-secondary"
            >
              <Filter size={14} />
              <span>{typeFilter ? `Type: ${typeFilter}` : 'Filters'}</span>
            </button>
          </div>
        </div>

        <DataTable
          columns={columns}
          data={movements}
          keyField="id"
          isLoading={loading}
          emptyMessage="No stock movements recorded yet."
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
    </div>
  );
};

export default StockMovementsPage;
