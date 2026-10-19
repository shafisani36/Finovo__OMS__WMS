import React, { useEffect, useState } from 'react';
import { Warehouse as WarehouseIcon, Plus, MapPin, Building2, CheckCircle2, X, Loader2 } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import omsService from '../services/omsService';
import type { WarehouseModel } from '../services/omsService';

export const WarehousesPage: React.FC = () => {
  const [warehouses, setWarehouses] = useState<WarehouseModel[]>([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [modalLoading, setModalLoading] = useState(false);
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [city, setCity] = useState('');
  const [address, setAddress] = useState('');

  const fetchWarehouses = async () => {
    try {
      setLoading(true);
      const res = await omsService.getWarehouses();
      setWarehouses(res.data);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchWarehouses();
  }, []);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;

    try {
      setModalLoading(true);
      await omsService.createWarehouse({ name, code, city, address });
      setModalOpen(false);
      setName('');
      setCode('');
      setCity('');
      setAddress('');
      fetchWarehouses();
    } catch (err) {
      console.error(err);
    } finally {
      setModalLoading(false);
    }
  };

  const columns: Column<WarehouseModel>[] = [
    {
      key: 'name',
      header: 'Warehouse Name',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div style={{ width: 34, height: 34, borderRadius: 8, backgroundColor: '#eff6ff', color: '#1e56ff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <WarehouseIcon size={16} />
          </div>
          <div>
            <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.name}</div>
            <div style={{ fontSize: 11, color: '#94a3b8' }}>Code: {row.code}</div>
          </div>
        </div>
      ),
    },
    {
      key: 'location',
      header: 'Location',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#475569' }}>
          <MapPin size={14} color="#94a3b8" />
          <span>{row.city || 'Primary Hub'}, {row.country || 'Global'}</span>
        </div>
      ),
    },
    {
      key: 'priority',
      header: 'Priority Rank',
      align: 'center',
      render: (row) => <span className="kpi-pill kpi-pill-blue">Priority #{row.priority}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <div className={`status-dot-badge ${row.is_active ? 'status-active' : 'status-warning'}`}>
          <span className="status-dot" />
          <span>{row.is_active ? 'Active' : 'Inactive'}</span>
        </div>
      ),
    },
  ];

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Warehouses</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Warehouses</h1>
            <span className="page-count-pill">{warehouses.length}</span>
          </div>
          <p className="page-subtitle">Configure physical fulfillment centers, storage zones, and stock priorities.</p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <InfoCalloutCard
            title="Warehouse Optimization"
            description="Prioritize allocation locations to minimize shipping distances and order splits."
            icon={<Building2 size={18} />}
          />
          <button onClick={() => setModalOpen(true)} className="btn-finovo-primary">
            <Plus size={16} />
            <span>Add Warehouse</span>
          </button>
        </div>
      </div>

      <div className="kpi-grid">
        <MetricCard
          title="Total Warehouses"
          value={warehouses.length}
          icon={<WarehouseIcon size={20} />}
          pillText="Configured"
          pillVariant="blue"
        />
        <MetricCard
          title="Active Centers"
          value={warehouses.filter((w) => w.is_active).length}
          icon={<CheckCircle2 size={20} />}
          pillText="Operational"
          pillVariant="green"
        />
      </div>

      <div className="content-card">
        <div className="content-card-header">
          <h3 className="content-card-title">All Warehouses</h3>
        </div>

        <DataTable
          columns={columns}
          data={warehouses}
          keyField="id"
          isLoading={loading}
          emptyMessage="No warehouses created yet."
        />
      </div>

      {modalOpen && (
        <div className="modal-overlay" onClick={() => setModalOpen(false)}>
          <div className="modal-dialog" onClick={(e) => e.stopPropagation()}>
            <div style={{ padding: '20px 24px', borderBottom: '1px solid #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>Add Warehouse</h3>
              <button onClick={() => setModalOpen(false)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}>
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleCreate} style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Warehouse Name *
                </label>
                <input
                  type="text"
                  placeholder="e.g. West Coast Distribution"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  required
                />
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Code
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. WCD-01"
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    City
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Seattle"
                    value={city}
                    onChange={(e) => setCity(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12, marginTop: 12 }}>
                <button type="button" onClick={() => setModalOpen(false)} className="btn-finovo-secondary">
                  Cancel
                </button>
                <button type="submit" disabled={modalLoading} className="btn-finovo-primary">
                  {modalLoading && <Loader2 size={16} className="animate-spin" />}
                  <span>Save Warehouse</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default WarehousesPage;
