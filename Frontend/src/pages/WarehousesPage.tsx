import React, { useEffect, useState, useMemo } from 'react';
import {
  Warehouse as WarehouseIcon,
  Plus,
  MapPin,
  CheckCircle2,
  AlertTriangle,
  Search,
  Settings,
  Layers,
  Users,
  Power,
  ShieldCheck,
  RefreshCw,
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import omsService from '../services/omsService';
import type { WarehouseModel } from '../services/omsService';
import WarehouseStatusBadge from '../components/warehouse/WarehouseStatusBadge';
import WarehouseWizardModal from '../components/warehouse/WarehouseWizardModal';
import WarehouseDetailModal from '../components/warehouse/WarehouseDetailModal';
import CustomDropdown from '../components/common/CustomDropdown';

export const WarehousesPage: React.FC = () => {
  const [warehouses, setWarehouses] = useState<WarehouseModel[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [typeFilter, setTypeFilter] = useState<string>('all');

  // Modals state
  const [wizardOpen, setWizardOpen] = useState(false);
  const [selectedWarehouseId, setSelectedWarehouseId] = useState<number | null>(null);

  const fetchWarehouses = async () => {
    try {
      setLoading(true);
      const res = await omsService.getWarehouses();
      setWarehouses(res.data);
    } catch (err) {
      console.error('Failed to fetch warehouses:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchWarehouses();
  }, []);

  // Filtered List
  const filteredWarehouses = useMemo(() => {
    return warehouses.filter((w) => {
      // Status filter
      if (statusFilter !== 'all' && w.status !== statusFilter) {
        return false;
      }
      // Type filter
      if (typeFilter !== 'all' && w.type !== typeFilter) {
        return false;
      }
      // Search
      if (searchQuery.trim()) {
        const query = searchQuery.toLowerCase();
        const matchesName = w.name?.toLowerCase().includes(query);
        const matchesCode = w.code?.toLowerCase().includes(query);
        const matchesCity = w.city?.toLowerCase().includes(query);
        return matchesName || matchesCode || matchesCity;
      }
      return true;
    });
  }, [warehouses, statusFilter, typeFilter, searchQuery]);

  // Counts for tabs/filters
  const counts = useMemo(() => {
    return {
      all: warehouses.length,
      active: warehouses.filter((w) => w.status === 'active').length,
      ready_for_activation: warehouses.filter((w) => w.status === 'ready_for_activation').length,
      setup_incomplete: warehouses.filter((w) => w.status === 'setup_incomplete').length,
      draft: warehouses.filter((w) => w.status === 'draft').length,
      inactive: warehouses.filter((w) => w.status === 'inactive').length,
    };
  }, [warehouses]);

  const handleQuickActivate = async (warehouseId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    try {
      await omsService.activateWarehouse(warehouseId);
      fetchWarehouses();
    } catch (err: any) {
      alert(err.message || 'Cannot activate warehouse: check readiness issues.');
      setSelectedWarehouseId(warehouseId);
    }
  };

  const columns: Column<WarehouseModel>[] = [
    {
      key: 'name',
      header: 'Warehouse / Facility',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div
            style={{
              width: 38,
              height: 38,
              borderRadius: 10,
              backgroundColor: '#eff6ff',
              color: '#1e56ff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              flexShrink: 0,
            }}
          >
            <WarehouseIcon size={18} />
          </div>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.name}</span>
              {row.is_default && (
                <span
                  style={{
                    fontSize: 10,
                    fontWeight: 700,
                    backgroundColor: '#eff6ff',
                    color: '#1e56ff',
                    padding: '1px 6px',
                    borderRadius: 4,
                  }}
                >
                  DEFAULT
                </span>
              )}
            </div>
            <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>
              Code: <strong style={{ color: '#334155' }}>{row.code}</strong>
            </div>
          </div>
        </div>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (row) => (
        <span
          style={{
            fontSize: 11,
            fontWeight: 600,
            textTransform: 'capitalize',
            padding: '3px 8px',
            borderRadius: 6,
            backgroundColor: '#f1f5f9',
            color: '#475569',
          }}
        >
          {row.type?.replace(/_/g, ' ') || 'Distribution Center'}
        </span>
      ),
    },
    {
      key: 'location',
      header: 'Location & Region',
      render: (row) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 13, color: '#334155' }}>
            <MapPin size={13} color="#94a3b8" />
            <span>
              {row.city || 'Primary City'}, {row.country || 'Global'}
            </span>
          </div>
          <span style={{ fontSize: 11, color: '#94a3b8', paddingLeft: 18 }}>
            TZ: {row.timezone || 'UTC'}
          </span>
        </div>
      ),
    },
    {
      key: 'hierarchy',
      header: 'Locations & Staff',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12, color: '#475569' }}>
          <span
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 4,
              backgroundColor: '#f8fafc',
              padding: '2px 8px',
              borderRadius: 6,
              border: '1px solid #e2e8f0',
            }}
          >
            <Layers size={13} color="#64748b" />
            {row.locations_count || 0} Locs
          </span>
          <span
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 4,
              backgroundColor: '#f8fafc',
              padding: '2px 8px',
              borderRadius: 6,
              border: '1px solid #e2e8f0',
            }}
          >
            <Users size={13} color="#64748b" />
            {row.memberships_count || 0} Staff
          </span>
        </div>
      ),
    },
    {
      key: 'priority',
      header: 'Priority Rank',
      align: 'center',
      render: (row) => (
        <span className="kpi-pill kpi-pill-blue" style={{ fontSize: 11 }}>
          Rank #{row.priority}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Operating Status',
      render: (row) => <WarehouseStatusBadge status={row.status} size="sm" />,
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'right',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, justifyContent: 'flex-end' }}>
          {row.status === 'ready_for_activation' && (
            <button
              onClick={(e) => handleQuickActivate(row.id, e)}
              className="btn-finovo-primary"
              style={{ padding: '4px 10px', fontSize: 11 }}
              title="Quick Activate"
            >
              <Power size={12} />
              <span>Activate</span>
            </button>
          )}

          <button
            onClick={() => setSelectedWarehouseId(row.id)}
            className="btn-finovo-secondary"
            style={{ padding: '4px 10px', fontSize: 11 }}
          >
            <Settings size={12} />
            <span>Manage</span>
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="page-container">
      {/* Breadcrumb Navigation */}
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span>Warehouse Operations</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 600 }}>Warehouses</span>
      </nav>

      {/* Page Header */}
      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Warehouses & Fulfillment Centers</h1>
            <span className="page-count-pill">{warehouses.length} Total</span>
          </div>
          <p className="page-subtitle">
            Configure physical fulfillment facilities, multi-level storage hierarchies, policy controls, and staff assignments.
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <InfoCalloutCard
            title="Readiness Engine Active"
            description="Warehouses validate locations, staff, and policies before activation is granted."
            icon={<ShieldCheck size={18} />}
          />
          <button
            onClick={() => setWizardOpen(true)}
            className="btn-finovo-primary"
            style={{ padding: '10px 18px', fontSize: 13 }}
          >
            <Plus size={16} />
            <span>New Warehouse Wizard</span>
          </button>
        </div>
      </div>

      {/* KPI Cards Grid */}
      <div className="kpi-grid">
        <MetricCard
          title="Total Facilities"
          value={counts.all}
          icon={<WarehouseIcon size={20} />}
          pillText="Registered"
          pillVariant="blue"
        />
        <MetricCard
          title="Active Hubs"
          value={counts.active}
          icon={<CheckCircle2 size={20} />}
          pillText="Operational"
          pillVariant="green"
        />
        <MetricCard
          title="Ready For Activation"
          value={counts.ready_for_activation}
          icon={<ShieldCheck size={20} />}
          pillText="Passed Checks"
          pillVariant="blue"
        />
        <MetricCard
          title="Needs Setup"
          value={counts.setup_incomplete + counts.draft}
          icon={<AlertTriangle size={20} />}
          pillText="Draft / Incomplete"
          pillVariant="amber"
        />
      </div>

      {/* Main Content Card */}
      <div className="content-card">
        {/* Filter Pills Bar */}
        <div
          style={{
            padding: '16px 20px',
            borderBottom: '1px solid #e2e8f0',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 16,
            flexWrap: 'wrap',
          }}
        >
          {/* Status Filter Tabs */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
            {[
              { id: 'all', label: 'All Warehouses', count: counts.all },
              { id: 'active', label: 'Active', count: counts.active },
              { id: 'ready_for_activation', label: 'Ready', count: counts.ready_for_activation },
              { id: 'setup_incomplete', label: 'Incomplete', count: counts.setup_incomplete },
              { id: 'draft', label: 'Drafts', count: counts.draft },
              { id: 'inactive', label: 'Inactive', count: counts.inactive },
            ].map((tab) => {
              const isSelected = statusFilter === tab.id;
              return (
                <button
                  key={tab.id}
                  onClick={() => setStatusFilter(tab.id)}
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    padding: '6px 12px',
                    borderRadius: 8,
                    fontSize: 12,
                    fontWeight: isSelected ? 700 : 500,
                    backgroundColor: isSelected ? '#1e56ff' : '#f8fafc',
                    color: isSelected ? '#ffffff' : '#475569',
                    border: `1px solid ${isSelected ? '#1e56ff' : '#e2e8f0'}`,
                    cursor: 'pointer',
                    transition: 'all 0.15s ease',
                  }}
                >
                  <span>{tab.label}</span>
                  <span
                    style={{
                      fontSize: 11,
                      padding: '1px 6px',
                      borderRadius: 999,
                      backgroundColor: isSelected ? 'rgba(255,255,255,0.25)' : '#e2e8f0',
                      color: isSelected ? '#ffffff' : '#64748b',
                      fontWeight: 700,
                    }}
                  >
                    {tab.count}
                  </span>
                </button>
              );
            })}
          </div>

          {/* Search, Type Filter & Refresh */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ width: 170 }}>
              <CustomDropdown
                value={typeFilter}
                onChange={(val) => setTypeFilter(val)}
                options={[
                  { value: 'all', label: 'All Facility Types' },
                  { value: 'distribution_center', label: 'Distribution Center' },
                  { value: 'fulfillment_hub', label: 'Fulfillment Hub' },
                  { value: 'retail_backroom', label: 'Retail Backroom' },
                  { value: 'cross_dock', label: 'Cross-Dock' },
                  { value: 'quarantine_zone', label: 'Quarantine Zone' },
                  { value: 'third_party_3pl', label: '3PL Partner' },
                ]}
                size="sm"
              />
            </div>

            <div style={{ position: 'relative', width: 220 }}>
              <Search
                size={14}
                style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: '#94a3b8' }}
              />
              <input
                type="text"
                placeholder="Search name, code, city..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                style={{
                  width: '100%',
                  padding: '6px 10px 6px 32px',
                  borderRadius: 8,
                  border: '1px solid #cbd5e1',
                  fontSize: 12,
                  boxSizing: 'border-box',
                }}
              />
            </div>

            <button
              onClick={fetchWarehouses}
              className="btn-finovo-secondary"
              style={{ padding: '6px 10px' }}
              title="Refresh"
            >
              <RefreshCw size={14} />
            </button>
          </div>
        </div>

        {/* Data Table */}
        <DataTable
          columns={columns}
          data={filteredWarehouses}
          keyField="id"
          isLoading={loading}
          emptyMessage="No warehouses match your filter criteria."
        />
      </div>

      {/* Warehouse Creation Wizard Modal */}
      <WarehouseWizardModal
        isOpen={wizardOpen}
        onClose={() => setWizardOpen(false)}
        onSuccess={() => {
          fetchWarehouses();
        }}
      />

      {/* Warehouse Detail & Configuration Modal */}
      {selectedWarehouseId !== null && (
        <WarehouseDetailModal
          warehouseId={selectedWarehouseId}
          isOpen={true}
          onClose={() => setSelectedWarehouseId(null)}
          onRefreshList={fetchWarehouses}
        />
      )}
    </div>
  );
};

export default WarehousesPage;
