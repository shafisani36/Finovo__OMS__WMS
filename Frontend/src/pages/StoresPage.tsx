import React, { useEffect, useState } from 'react';
import {
  Store as StoreIcon,
  Link as LinkIcon,
  RefreshCw,
  ShoppingCart,
  Plus,
  Filter,
  Settings,
  MoreVertical,
  Building2,
  X,
  Loader2,
  FileCode
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import AlertBanner from '../components/common/AlertBanner';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import CustomDropdown from '../components/common/CustomDropdown';
import omsService from '../services/omsService';
import type { StoreModel, WarehouseModel } from '../services/omsService';

export const StoresPage: React.FC = () => {
  const [stores, setStores] = useState<StoreModel[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseModel[]>([]);
  const [kpis, setKpis] = useState({
    total_stores: 0,
    connected_stores: 0,
    sync_issues: 0,
    orders_awaiting_sync: 0,
  });
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState('');
  const [showAlert, setShowAlert] = useState(true);

  // Modal State
  const [modalOpen, setModalOpen] = useState(false);
  const [modalLoading, setModalLoading] = useState(false);
  const [modalError, setModalError] = useState('');
  const [formData, setFormData] = useState({
    name: '',
    platform: 'shopify',
    bridge_url: '',
    selectedWarehouses: [] as number[],
  });

  const fetchStores = async () => {
    try {
      setLoading(true);
      const res = await omsService.getStores({
        search: search || undefined,
        page,
        per_page: perPage,
      });
      setStores(res.data);
      setKpis(res.kpis);
      setTotal(res.meta.total);
      setLastPage(res.meta.last_page);
    } catch (err: any) {
      console.error('Failed to load stores', err);
    } finally {
      setLoading(false);
    }
  };

  const fetchWarehouses = async () => {
    try {
      const res = await omsService.getWarehouses();
      setWarehouses(res.data);
    } catch (err) {
      console.error('Failed to load warehouses', err);
    }
  };

  useEffect(() => {
    fetchStores();
    fetchWarehouses();
  }, [page, perPage]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchStores();
  };

  const handleCreateStore = async (e: React.FormEvent) => {
    e.preventDefault();
    setModalError('');
    if (!formData.name.trim()) {
      setModalError('Store name is required');
      return;
    }

    try {
      setModalLoading(true);
      await omsService.createStore({
        name: formData.name,
        platform: formData.platform,
        bridge_url: formData.bridge_url || undefined,
        warehouse_ids: formData.selectedWarehouses,
      });
      setModalOpen(false);
      setFormData({ name: '', platform: 'shopify', bridge_url: '', selectedWarehouses: [] });
      fetchStores();
    } catch (err: any) {
      setModalError(err.response?.data?.message || 'Failed to create store connection.');
    } finally {
      setModalLoading(false);
    }
  };

  const handleSyncStore = async (storeId: number) => {
    try {
      await omsService.syncStore(storeId);
      fetchStores();
    } catch (err) {
      console.error(err);
    }
  };

  const columns: Column<StoreModel>[] = [
    {
      key: 'name',
      header: 'Store',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div
            style={{
              width: 38,
              height: 38,
              borderRadius: 10,
              backgroundColor: '#f0f5ff',
              color: '#1e56ff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <StoreIcon size={18} />
          </div>
          <div style={{ display: 'flex', flexDirection: 'column' }}>
            <span style={{ fontSize: 14, fontWeight: 700, color: '#0f172a' }}>{row.name}</span>
            <span style={{ fontSize: 11, fontWeight: 600, color: '#059669' }}>Managing</span>
          </div>
        </div>
      ),
    },
    {
      key: 'platform',
      header: 'Platform',
      render: (row) => {
        const isShopify = row.platform === 'shopify';
        const isWoo = row.platform === 'woocommerce';
        const isBridge = row.platform === 'custom_bridge';

        return (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <div
              style={{
                width: 24,
                height: 24,
                borderRadius: 6,
                backgroundColor: isShopify ? '#ecfdf5' : isWoo ? '#faf5ff' : '#eff6ff',
                color: isShopify ? '#059669' : isWoo ? '#9333ea' : '#1e56ff',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              {isBridge ? <FileCode size={14} /> : isShopify ? <StoreIcon size={14} /> : <ShoppingCart size={14} />}
            </div>
            <span style={{ fontSize: 13, fontWeight: 600, color: '#334155', textTransform: 'capitalize' }}>
              {row.platform.replace('_', ' ')}
            </span>
          </div>
        );
      },
    },
    {
      key: 'warehouses',
      header: 'Linked Warehouses',
      render: (row) => (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
          {row.warehouses && row.warehouses.length > 0 ? (
            row.warehouses.map((wh) => (
              <span key={wh.id} className="wh-tag">
                {wh.name}
              </span>
            ))
          ) : (
            <span style={{ fontSize: 12, color: '#94a3b8' }}>None linked</span>
          )}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => {
        const isActive = row.status === 'active';
        return (
          <div className={`status-dot-badge ${isActive ? 'status-active' : 'status-warning'}`}>
            <span className="status-dot" />
            <span>{isActive ? 'Active' : 'Setup Incomplete'}</span>
          </div>
        );
      },
    },
    {
      key: 'last_sync',
      header: 'Last Sync',
      render: (row) => (
        <span style={{ fontSize: 13, color: '#64748b' }}>
          {row.last_sync_at ? new Date(row.last_sync_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : 'Never'}
        </span>
      ),
    },
    {
      key: 'orders_awaiting_sync',
      header: 'Orders Awaiting Sync',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 600, color: row.orders_awaiting_sync > 0 ? '#d97706' : '#64748b' }}>
          {row.orders_awaiting_sync}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <button
            onClick={() => handleSyncStore(row.id)}
            className="btn-finovo-secondary"
            style={{ padding: '6px 12px', fontSize: 12 }}
            title="Sync Store"
          >
            <Settings size={14} color="#1e56ff" />
            <span>Manage</span>
          </button>
          <button
            className="topbar-icon-button"
            style={{ padding: 6 }}
            title="Options"
          >
            <MoreVertical size={16} />
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
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Stores</span>
      </nav>

      {/* Page Header matching Screenshot 2 */}
      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Stores</h1>
            <span className="page-count-pill">{kpis.total_stores}</span>
          </div>
          <p className="page-subtitle">Manage your sales channels and link them with warehouses.</p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <InfoCalloutCard
            title="Connect your stores"
            description="Link your stores with warehouses to streamline inventory and order fulfillment."
            icon={<StoreIcon size={18} />}
          />

          <button
            onClick={() => setModalOpen(true)}
            className="btn-finovo-primary"
            style={{ height: 42 }}
          >
            <Plus size={16} />
            <span>Add Store</span>
          </button>
        </div>
      </div>

      {/* Attention Alert Banner matching Screenshot 2 */}
      {showAlert && (
        <AlertBanner
          title="Some stores need attention"
          message="Review configuration, sync health, and linked warehouse allocations below."
          actionText="View Detail"
          onAction={() => {}}
          onDismiss={() => setShowAlert(false)}
        />
      )}

      {/* KPI Cards Grid matching Screenshot 2 */}
      <div className="kpi-grid">
        <MetricCard
          title="Total Stores"
          value={kpis.total_stores}
          icon={<StoreIcon size={20} />}
          pillText="Active"
          pillVariant="blue"
        />
        <MetricCard
          title="Connected Stores"
          value={kpis.connected_stores}
          icon={<LinkIcon size={20} />}
          pillText="All Connected"
          pillVariant="green"
        />
        <MetricCard
          title="Sync Issues"
          value={kpis.sync_issues}
          icon={<RefreshCw size={20} />}
          pillText="Healthy"
          pillVariant="green"
        />
        <MetricCard
          title="Order Awaiting Sync"
          value={kpis.orders_awaiting_sync}
          icon={<ShoppingCart size={20} />}
          pillText="Up to date"
          pillVariant="green"
        />
      </div>

      {/* Main Table Card matching Screenshot 2 */}
      <div className="content-card">
        <div className="content-card-header">
          <div className="content-card-title-group">
            <div
              style={{
                width: 36,
                height: 36,
                borderRadius: 10,
                backgroundColor: '#f1f5f9',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                color: '#334155',
              }}
            >
              <Building2 size={18} />
            </div>
            <div>
              <h3 className="content-card-title">All Stores</h3>
              <p className="content-card-subtitle">View and manage your sales channels.</p>
            </div>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <form onSubmit={handleSearchSubmit}>
              <div className="topbar-search-input" style={{ width: 220 }}>
                <input
                  type="text"
                  placeholder="Search stores..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
            </form>

            <button className="btn-finovo-secondary">
              <Filter size={14} />
              <span>Filter</span>
            </button>
          </div>
        </div>

        <DataTable
          columns={columns}
          data={stores}
          keyField="id"
          isLoading={loading}
          emptyMessage="No connected stores yet."
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

      {/* Add Store Modal */}
      {modalOpen && (
        <div className="modal-overlay" onClick={() => setModalOpen(false)}>
          <div className="modal-dialog" onClick={(e) => e.stopPropagation()}>
            <div style={{ padding: '20px 24px', borderBottom: '1px solid #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div style={{ width: 36, height: 36, borderRadius: 10, backgroundColor: '#eff6ff', color: '#1e56ff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <StoreIcon size={18} />
                </div>
                <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>Connect New Store</h3>
              </div>
              <button onClick={() => setModalOpen(false)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}>
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleCreateStore} style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
              {modalError && (
                <div style={{ padding: '10px 14px', borderRadius: 10, backgroundColor: '#fef2f2', border: '1px solid #fecaca', color: '#b91c1c', fontSize: 13 }}>
                  {modalError}
                </div>
              )}

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Store Name *
                </label>
                <input
                  type="text"
                  placeholder="e.g. My Shopify Store"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  required
                />
              </div>

              <div>
                <CustomDropdown
                  label="Commerce Platform"
                  required
                  value={formData.platform}
                  onChange={(val) => setFormData({ ...formData, platform: val })}
                  options={[
                    { value: 'shopify', label: 'Shopify' },
                    { value: 'woocommerce', label: 'WooCommerce' },
                    { value: 'custom_bridge', label: 'Finovo Custom Bridge (PHP / Custom)' },
                    { value: 'bigcommerce', label: 'BigCommerce' },
                    { value: 'magento', label: 'Magento 2 / Adobe Commerce' },
                    { value: 'prestashop', label: 'PrestaShop' },
                    { value: 'wix', label: 'Wix' },
                    { value: 'ebay', label: 'eBay' },
                    { value: 'manual', label: 'Manual Store' },
                  ]}
                />
              </div>

              {formData.platform === 'custom_bridge' && (
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Custom Website API URL
                  </label>
                  <input
                    type="url"
                    placeholder="https://mystore.com/api"
                    value={formData.bridge_url}
                    onChange={(e) => setFormData({ ...formData, bridge_url: e.target.value })}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                  <span style={{ fontSize: 12, color: '#64748b', marginTop: 4, display: 'block' }}>
                    Finovo will generate an HMAC secret and API key for this custom store.
                  </span>
                </div>
              )}

              {warehouses.length > 0 && (
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Link Initial Warehouses
                  </label>
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                    {warehouses.map((wh) => {
                      const isChecked = formData.selectedWarehouses.includes(wh.id);
                      return (
                        <label
                          key={wh.id}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 6,
                            padding: '6px 12px',
                            borderRadius: 8,
                            border: `1px solid ${isChecked ? '#1e56ff' : '#cbd5e1'}`,
                            backgroundColor: isChecked ? '#eff6ff' : '#ffffff',
                            cursor: 'pointer',
                            fontSize: 13,
                          }}
                        >
                          <input
                            type="checkbox"
                            checked={isChecked}
                            onChange={(e) => {
                              if (e.target.checked) {
                                setFormData({ ...formData, selectedWarehouses: [...formData.selectedWarehouses, wh.id] });
                              } else {
                                setFormData({ ...formData, selectedWarehouses: formData.selectedWarehouses.filter((id) => id !== wh.id) });
                              }
                            }}
                          />
                          <span>{wh.name}</span>
                        </label>
                      );
                    })}
                  </div>
                </div>
              )}

              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 12, marginTop: 12 }}>
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="btn-finovo-secondary"
                  disabled={modalLoading}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="btn-finovo-primary"
                  disabled={modalLoading}
                >
                  {modalLoading && <Loader2 size={16} className="animate-spin" />}
                  <span>Connect Store</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default StoresPage;
