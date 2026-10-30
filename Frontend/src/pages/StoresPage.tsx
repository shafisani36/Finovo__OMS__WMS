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
  Trash2,
  Download,
  Warehouse as WarehouseIcon
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import AlertBanner from '../components/common/AlertBanner';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import { Alert } from '../components/common/Alert';
import ConfirmDialog from '../components/common/ConfirmDialog';
import RowActionsMenu from '../components/common/RowActionsMenu';
import type { RowAction } from '../components/common/RowActionsMenu';
import StoreWarehousesModal from '../components/stores/StoreWarehousesModal';
import ConnectStoreModal from '../components/stores/ConnectStoreModal';
import StoreConnectingOverlay from '../components/stores/StoreConnectingOverlay';
import type { ConnectingMode } from '../components/stores/StoreConnectingOverlay';
import { STORE_PLATFORMS, platformLabel } from '../components/stores/storePlatforms';
import CustomDropdown from '../components/common/CustomDropdown';
import omsService, { AUTO_IMPORT_PLATFORMS } from '../services/omsService';
import type { StoreKpis, StoreModel, StoreStatusFilter, WarehouseModel } from '../services/omsService';
import { useActiveStore } from '../hooks/useActiveStore';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';

/** Platforms whose products and orders Finovo can import. */
const SYNC_SUPPORTED = ['shopify', 'woocommerce', 'bigcommerce', 'prestashop', 'wix', 'ebay', 'magento', 'opencart', 'oscommerce'];
const AUTHORIZED_ON_PLATFORM = ['shopify', 'woocommerce', 'bigcommerce', 'wix', 'ebay', 'prestashop', 'opencart'];
/** Platforms connected through a Finovo module installed on the shop; a connected shop can install its latest version. */
const MODULE_PLATFORMS = ['prestashop', 'opencart'];

/** Labels and order of the status filter (the server sends the same list). */
const CONNECTION_STATES: { value: StoreStatusFilter; label: string }[] = [
  { value: 'connected', label: 'Connected' },
  { value: 'awaiting_authorization', label: 'Awaiting Authorization' },
  { value: 'setup_incomplete', label: 'Setup Incomplete' },
  { value: 'disconnected', label: 'Disconnected' },
  { value: 'needs_attention', label: 'Needs Attention' },
];

const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? '' : 's'}`;

type Notice = { type: 'success' | 'error' | 'info'; message: string } | null;

/** The connecting animation on screen, and what happens when it ends. */
type Connecting = { mode: ConnectingMode; platform: string; storeName?: string | null; message?: string | null; then: () => void } | null;

export const StoresPage: React.FC = () => {
  const { user } = useAuth();
  const canConnect = can(user, 'stores.connect');
  const canSync = can(user, 'stores.sync');
  const canDelete = can(user, 'stores.delete');
  const [stores, setStores] = useState<StoreModel[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseModel[]>([]);
  const [kpis, setKpis] = useState<StoreKpis>({
    total_stores: 0,
    connected_stores: 0,
    not_connected: 0,
    sync_issues: 0,
    needs_attention: 0,
    orders_awaiting_sync: 0,
  });
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [connectionStates, setConnectionStates] = useState(CONNECTION_STATES);
  const [connecting, setConnecting] = useState<Connecting>(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [platformFilter, setPlatformFilter] = useState('');
  const [showAlert, setShowAlert] = useState(true);

  const [modalOpen, setModalOpen] = useState(false);
  const [storeToDelete, setStoreToDelete] = useState<StoreModel | null>(null);
  const [linkingStore, setLinkingStore] = useState<StoreModel | null>(null);
  const [notice, setNotice] = useState<Notice>(null);
  const [busyStoreId, setBusyStoreId] = useState<number | null>(null);
  const [searchParams, setSearchParams] = useSearchParams();
  const { activeStore, setActiveStore, refreshActiveStore } = useActiveStore();

  const fetchStores = async () => {
    try {
      setLoading(true);
      const res = await omsService.getStores({
        search: debouncedSearch || undefined,
        status: statusFilter || undefined,
        platform: platformFilter || undefined,
        page,
        per_page: perPage,
      });
      setStores(res.data);
      setKpis(res.kpis);
      if (res.connection_states?.length) setConnectionStates(res.connection_states);
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
  }, [page, perPage, statusFilter, platformFilter, debouncedSearch]);

  useEffect(() => {
    fetchWarehouses();
  }, []);

  // Live search: the list updates 300 ms after typing stops, from the first page.
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  // The platform sends the merchant back here after the approval step: play the connecting animation, then show the result.
  useEffect(() => {
    const connected = searchParams.get('connected');
    const error = searchParams.get('connection_error');
    if (!connected && !error) return;

    const platform = platformLabel(searchParams.get('platform') ?? connected ?? 'store');
    const storeName = searchParams.get('store');
    setSearchParams({}, { replace: true });
    setConnecting({
      mode: error ? 'failed' : 'connected',
      platform,
      storeName,
      message: error,
      then: () => {
        setNotice(error ? { type: 'error', message: error } : { type: 'success', message: `${storeName ?? `${platform} store`} is connected. Use Sync to import its products and orders.` });
        fetchStores();
        refreshActiveStore();
      },
    });
  }, [searchParams, setSearchParams]);

  /** Animate, then send the merchant to the platform's approval page. */
  const goToPlatform = (url: string, storeName: string, platform: string) => {
    setModalOpen(false);
    setConnecting({ mode: 'redirecting', platform: platformLabel(platform), storeName, then: () => window.location.assign(url) });
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
        goToPlatform(next_action.url, store.name, store.platform);
        return;
      }
      if (next_action.type === 'install_module') {
        // Starts the module download; the page stays open.
        window.location.assign(next_action.url);
      }
      setNotice({ type: 'info', message: next_action.message });
    } catch (err) {
      setNotice({ type: 'error', message: `${store.name}: ${err instanceof Error ? err.message : 'Could not start the authorization.'}` });
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
      setNotice({ type: 'error', message: `${store.name}: ${err instanceof Error ? err.message : 'Connection test failed.'}` });
    } finally {
      setBusyStoreId(null);
      fetchStores();
    }
  };

  /** Switch on automatic import: the store notifies Finovo of new orders and product changes. */
  const handleAutoImport = async (store: StoreModel) => {
    try {
      setBusyStoreId(store.id);
      const res = await omsService.enableStoreWebhooks(store.id);
      // "info": the store cannot notify Finovo as it is connected; its orders keep being read every minute.
      setNotice({ type: res.status === 'info' ? 'info' : 'success', message: `${store.name}: ${res.message}` });
    } catch (err) {
      setNotice({ type: 'error', message: `${store.name}: ${err instanceof Error ? err.message : 'Automatic import could not be switched on.'}` });
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
      setNotice({ type: 'error', message: `${store.name}: ${err instanceof Error ? err.message : 'Sync failed.'}` });
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

  /** What the user can do with a store, in the row's menu. */
  const rowActions = (store: StoreModel): RowAction[] => {
    const connected = store.status === 'active';
    const actions: RowAction[] = [];

    if (canConnect) {
      if (!connected && AUTHORIZED_ON_PLATFORM.includes(store.platform)) {
        actions.push({ label: 'Connect', icon: PlugZap, onSelect: () => handleAuthorize(store) });
      } else {
        actions.push({ label: 'Test connection', icon: ShieldCheck, onSelect: () => handleTestConnection(store) });
      }
    }
    if (canSync && connected && SYNC_SUPPORTED.includes(store.platform)) {
      actions.push({ label: 'Sync now', icon: RefreshCw, onSelect: () => handleSync(store) });
    }
    if (canConnect && connected && AUTO_IMPORT_PLATFORMS.includes(store.platform) && store.settings?.webhooks?.status !== 'active') {
      actions.push({ label: 'Turn on auto import', icon: PlugZap, onSelect: () => handleAutoImport(store) });
    }
    if (canConnect && connected && MODULE_PLATFORMS.includes(store.platform)) {
      actions.push({ label: 'Download module', icon: Download, onSelect: () => handleAuthorize(store) });
    }
    if (canConnect) {
      actions.push({ label: 'Warehouses', icon: WarehouseIcon, onSelect: () => setLinkingStore(store) });
    }
    if (connected && activeStore?.id !== store.id) {
      actions.push({ label: 'Manage this store', icon: Settings, onSelect: () => handleManage(store) });
    }
    if (canDelete) {
      actions.push({ label: 'Delete', icon: Trash2, onSelect: () => setStoreToDelete(store), danger: true });
    }

    return actions;
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
            row.warehouses.map((wh, index) => (
              <span key={wh.id} className="wh-tag" title={index === 0 ? 'Main warehouse: orders go here first' : `Used when the warehouses before it lack stock`}>
                {row.warehouses.length > 1 ? `${index + 1}. ` : ''}
                {wh.name}
              </span>
            ))
          ) : (
            <span style={{ fontSize: 12, color: '#94a3b8' }} title="Any active warehouse can fulfil its orders">
              Any warehouse
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => {
        const isActive = row.connection_state === 'connected';
        const label = connectionStates.find((state) => state.value === row.connection_state)?.label ?? row.connection_state;
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
      key: 'auto_import',
      header: 'Auto Import',
      render: (row) => {
        const webhooks = row.settings?.webhooks;
        if (row.connection_state === 'connected' && webhooks?.status === 'active') {
          return <span className="kpi-pill kpi-pill-green" title={webhooks.message}>On</span>;
        }
        return (
          <span style={{ fontSize: 12, color: '#94a3b8' }} title={webhooks?.message ?? 'New orders are imported every minute'}>
            Every minute
          </span>
        );
      },
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'right',
      render: (row) =>
        busyStoreId === row.id ? (
          <Loader2 size={18} className="animate-spin" color="#1e56ff" aria-label={`Working on ${row.name}`} />
        ) : (
          <RowActionsMenu label={`Actions for ${row.name}`} actions={rowActions(row)} />
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

          {canConnect && (
            <button onClick={() => setModalOpen(true)} className="btn-finovo-primary" style={{ height: 42 }}>
              <Plus size={16} />
              <span>Add Store</span>
            </button>
          )}
        </div>
      </div>

      {notice && <Alert type={notice.type} message={notice.message} className="store-notice" />}

      {/* Attention Alert Banner matching Screenshot 2 */}
      {showAlert && kpis.needs_attention > 0 && (
        <AlertBanner
          title={`${plural(kpis.needs_attention, 'store')} need${kpis.needs_attention === 1 ? 's' : ''} attention`}
          message={[
            kpis.not_connected ? `${plural(kpis.not_connected, 'store')} not connected` : '',
            kpis.sync_issues ? `${plural(kpis.sync_issues, 'store')} with a failed test or sync` : '',
          ]
            .filter(Boolean)
            .join(', ')
            .concat('. Open the store menu to test, connect or sync again.')}
          actionText="Show them"
          onAction={() => {
            setStatusFilter('needs_attention');
            setPage(1);
          }}
          onDismiss={() => setShowAlert(false)}
        />
      )}

      {/* KPI Cards Grid matching Screenshot 2 */}
      <div className="kpi-grid">
        <MetricCard
          title="Total Stores"
          value={kpis.total_stores}
          icon={<StoreIcon size={20} />}
          pillText="All Platforms"
          pillVariant="blue"
        />
        <MetricCard
          title="Connected Stores"
          value={kpis.connected_stores}
          icon={<LinkIcon size={20} />}
          pillText={kpis.not_connected === 0 ? 'All Connected' : `${kpis.not_connected} not connected`}
          pillVariant={kpis.not_connected === 0 ? 'green' : 'amber'}
        />
        <MetricCard
          title="Sync Issues"
          value={kpis.sync_issues}
          icon={<RefreshCw size={20} />}
          pillText={kpis.sync_issues === 0 ? 'Healthy' : 'Needs attention'}
          pillVariant={kpis.sync_issues === 0 ? 'green' : 'amber'}
        />
        <MetricCard
          title="Orders Awaiting Sync"
          value={kpis.orders_awaiting_sync}
          icon={<ShoppingCart size={20} />}
          pillText={kpis.orders_awaiting_sync === 0 ? 'Up to date' : 'Waiting'}
          pillVariant={kpis.orders_awaiting_sync === 0 ? 'green' : 'amber'}
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
            <div className="topbar-search-input" style={{ width: 220 }} role="search">
              <input
                type="search"
                placeholder="Search stores..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                aria-label="Search stores"
              />
            </div>

            <div style={{ width: 170 }}>
              <CustomDropdown
                value={statusFilter}
                onChange={(val) => {
                  setStatusFilter(String(val));
                  setPage(1);
                }}
                options={[{ value: '', label: 'All Statuses' }, ...connectionStates]}
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
          onRedirect={goToPlatform}
        />
      )}

      {connecting && (
        <StoreConnectingOverlay
          mode={connecting.mode}
          platform={connecting.platform}
          storeName={connecting.storeName}
          message={connecting.message}
          onComplete={() => {
            const then = connecting.then;
            // Leaving for the platform keeps the overlay up until the browser navigates away.
            if (connecting.mode !== 'redirecting') setConnecting(null);
            then();
          }}
        />
      )}

      {linkingStore && (
        <StoreWarehousesModal
          store={linkingStore}
          warehouses={warehouses}
          onClose={() => setLinkingStore(null)}
          onSaved={(message) => {
            setLinkingStore(null);
            setNotice({ type: 'success', message });
            fetchStores();
          }}
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
