import React, { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Store as StoreIcon,
  Link as LinkIcon,
  RefreshCw,
  ShoppingCart,
  Plus,
  Filter,
  Settings,
  Building2,
  FileCode,
  PlugZap,
  ShieldCheck,
  Loader2,
  Trash2
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import AlertBanner from '../components/common/AlertBanner';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import { Alert } from '../components/common/Alert';
import ConfirmDialog from '../components/common/ConfirmDialog';
import ConnectStoreModal from '../components/stores/ConnectStoreModal';
import { STORE_PLATFORMS, platformLabel } from '../components/stores/storePlatforms';
import CustomDropdown from '../components/common/CustomDropdown';
import omsService from '../services/omsService';
import type { StoreModel, WarehouseModel } from '../services/omsService';
import { useActiveStore } from '../hooks/useActiveStore';

/** Platforms whose products and orders Finovo can import. */
const SYNC_SUPPORTED = ['shopify', 'woocommerce', 'bigcommerce', 'prestashop', 'wix', 'ebay', 'magento', 'opencart', 'oscommerce'];
const AUTHORIZED_ON_PLATFORM = ['shopify', 'woocommerce', 'bigcommerce', 'wix', 'ebay', 'prestashop', 'opencart'];

type Notice = { type: 'success' | 'error' | 'info'; message: string } | null;

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
  const [statusFilter, setStatusFilter] = useState('');
  const [platformFilter, setPlatformFilter] = useState('');
  const [showAlert, setShowAlert] = useState(true);

  const [modalOpen, setModalOpen] = useState(false);
  const [storeToDelete, setStoreToDelete] = useState<StoreModel | null>(null);
  const [notice, setNotice] = useState<Notice>(null);
  const [busyStoreId, setBusyStoreId] = useState<number | null>(null);
  const [searchParams, setSearchParams] = useSearchParams();
  const { activeStore, setActiveStore, refreshActiveStore } = useActiveStore();

  const fetchStores = async () => {
    try {
      setLoading(true);
      const res = await omsService.getStores({
        search: search || undefined,
        status: statusFilter || undefined,
        platform: platformFilter || undefined,
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
  }, [page, perPage, statusFilter, platformFilter]);

  // Shopify, BigCommerce and eBay send the merchant back here after the approval step.
  useEffect(() => {
    const connected = searchParams.get('connected');
    const error = searchParams.get('connection_error');
    if (!connected && !error) return;

    setNotice(
      error
        ? { type: 'error', message: error }
        : { type: 'success', message: `${platformLabel(connected as string)} store connected.` }
    );
    setSearchParams({}, { replace: true });
  }, [searchParams, setSearchParams]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchStores();
  };

  const handleStoreConnected = (message: string) => {
    setModalOpen(false);
    setNotice({ type: 'success', message });
    fetchStores();
    refreshActiveStore();
  };

  const handleManage = (store: StoreModel) => {
    setActiveStore(store);
    setNotice({ type: 'info', message: `Now managing ${store.name}.` });
  };

  const handleAuthorize = async (store: StoreModel) => {
    try {
      setBusyStoreId(store.id);
      const { next_action } = await omsService.getStoreAuthorization(store.id);
      if (next_action.type === 'redirect') {
        window.location.assign(next_action.url);
        return;
      }
      if (next_action.type === 'install_module') {
        // Starts the module download; the page stays open.
        window.location.assign(next_action.url);
      }
      setNotice({ type: 'info', message: next_action.message });
    } catch (err) {
      setNotice({ type: 'error', message: err instanceof Error ? err.message : 'Could not start the authorization.' });
    } finally {
      setBusyStoreId(null);
    }
  };

  const handleTestConnection = async (store: StoreModel) => {
    try {
      setBusyStoreId(store.id);
      const res = await omsService.testStoreConnection(store.id);
      setNotice({ type: 'success', message: res.message });
    } catch (err) {
      setNotice({ type: 'error', message: err instanceof Error ? err.message : 'Connection test failed.' });
    } finally {
      setBusyStoreId(null);
      fetchStores();
    }
  };

  const handleSync = async (store: StoreModel) => {
    try {
      setBusyStoreId(store.id);
      setNotice({ type: 'info', message: `Syncing ${store.name}... this can take a minute for a large catalog.` });
      const res = await omsService.syncStore(store.id);
      setNotice({ type: 'success', message: res.message });
    } catch (err) {
      setNotice({ type: 'error', message: err instanceof Error ? err.message : 'Sync failed.' });
    } finally {
      setBusyStoreId(null);
      fetchStores();
    }
  };

  const handleDelete = async () => {
    if (!storeToDelete) return;
    try {
      setBusyStoreId(storeToDelete.id);
      const res = await omsService.deleteStore(storeToDelete.id);
      setNotice({ type: 'success', message: res.message });
      fetchStores();
      refreshActiveStore();
    } catch (err) {
      setNotice({ type: 'error', message: err instanceof Error ? err.message : 'Could not delete the store.' });
    } finally {
      setBusyStoreId(null);
      setStoreToDelete(null);
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
            {activeStore?.id === row.id && (
              <span style={{ fontSize: 11, fontWeight: 600, color: '#059669' }}>Managing</span>
            )}
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
            <span style={{ fontSize: 13, fontWeight: 600, color: '#334155' }}>
              {platformLabel(row.platform)}
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
        const label = isActive
          ? 'Connected'
          : row.status === 'disconnected'
            ? 'Disconnected'
            : AUTHORIZED_ON_PLATFORM.includes(row.platform) && !row.connection_error
              ? 'Awaiting Authorization'
              : 'Setup Incomplete';
        return (
          <div>
            <div className={`status-dot-badge ${isActive ? 'status-active' : 'status-warning'}`}>
              <span className="status-dot" />
              <span>{label}</span>
            </div>
            {!isActive && row.connection_error && (
              <span className="store-connection-error" title={row.connection_error}>{row.connection_error}</span>
            )}
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
      render: (row) => {
        const busy = busyStoreId === row.id;
        const needsAuthorization = row.status !== 'active' && AUTHORIZED_ON_PLATFORM.includes(row.platform);
        return (
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            {needsAuthorization ? (
              <button
                onClick={() => handleAuthorize(row)}
                className="btn-finovo-primary"
                style={{ padding: '6px 12px', fontSize: 12 }}
                disabled={busy}
                title={`Approve access on ${platformLabel(row.platform)}`}
              >
                {busy ? <Loader2 size={14} className="animate-spin" /> : <PlugZap size={14} />}
                <span>Connect</span>
              </button>
            ) : (
              <button
                onClick={() => handleTestConnection(row)}
                className="btn-finovo-secondary"
                style={{ padding: '6px 12px', fontSize: 12 }}
                disabled={busy}
                title="Check the connection with the platform"
              >
                {busy ? <Loader2 size={14} className="animate-spin" /> : <ShieldCheck size={14} color="#1e56ff" />}
                <span>Test</span>
              </button>
            )}
            {row.status === 'active' && SYNC_SUPPORTED.includes(row.platform) && (
              <button
                onClick={() => handleSync(row)}
                className="btn-finovo-secondary"
                style={{ padding: '6px 12px', fontSize: 12 }}
                disabled={busy}
                title="Import products, variants, stock and orders from this store"
              >
                <RefreshCw size={14} color="#1e56ff" className={busy ? 'animate-spin' : undefined} />
                <span>Sync</span>
              </button>
            )}
            <button
              onClick={() => handleManage(row)}
              className="btn-finovo-secondary"
              style={{ padding: '6px 12px', fontSize: 12 }}
              disabled={activeStore?.id === row.id}
              title="Make this the store you are managing"
            >
              <Settings size={14} color="#1e56ff" />
              <span>{activeStore?.id === row.id ? 'Managing' : 'Manage'}</span>
            </button>
            <button
              onClick={() => setStoreToDelete(row)}
              className="btn-finovo-secondary"
              style={{ padding: '6px 10px', fontSize: 12, color: '#dc2626' }}
              disabled={busy}
              title="Delete this store"
              aria-label={`Delete ${row.name}`}
            >
              <Trash2 size={14} />
            </button>
          </div>
        );
      },
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

      {notice && <Alert type={notice.type} message={notice.message} className="store-notice" />}

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

            <div style={{ width: 170 }}>
              <CustomDropdown
                value={statusFilter}
                onChange={(val) => {
                  setStatusFilter(String(val));
                  setPage(1);
                }}
                options={[
                  { value: '', label: 'All Statuses' },
                  { value: 'active', label: 'Active' },
                  { value: 'setup_incomplete', label: 'Setup Incomplete' },
                  { value: 'disconnected', label: 'Disconnected' },
                ]}
                searchable={false}
                icon={<Filter size={14} />}
              />
            </div>

            <div style={{ width: 170 }}>
              <CustomDropdown
                value={platformFilter}
                onChange={(val) => {
                  setPlatformFilter(String(val));
                  setPage(1);
                }}
                options={[{ value: '', label: 'All Platforms' }, ...STORE_PLATFORMS.map((p) => ({ value: p.value, label: p.label }))]}
                searchable={false}
              />
            </div>
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

      {modalOpen && (
        <ConnectStoreModal
          warehouses={warehouses}
          onClose={() => setModalOpen(false)}
          onConnected={handleStoreConnected}
        />
      )}

      <ConfirmDialog
        open={storeToDelete !== null}
        title="Delete store?"
        message={<>Delete <strong>{storeToDelete?.name}</strong>? Finovo will stop syncing its orders and stock.</>}
        note="This cannot be undone. Admins will receive an email about the deletion."
        confirmLabel="Delete Store"
        loading={busyStoreId !== null && busyStoreId === storeToDelete?.id}
        onConfirm={handleDelete}
        onCancel={() => setStoreToDelete(null)}
      />
    </div>
  );
};

export default StoresPage;
