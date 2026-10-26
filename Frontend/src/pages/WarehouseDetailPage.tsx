import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Loader2, MoreHorizontal, Power, RefreshCw, Trash2 } from 'lucide-react';
import omsService from '../services/omsService';
import type { WarehouseModel, WarehouseReadinessModel } from '../services/omsService';
import WarehouseStatusBadge from '../components/warehouse/WarehouseStatusBadge';
import WarehouseOrders from '../components/warehouse/WarehouseOrders';
import WarehouseOverviewTab from '../components/warehouse/WarehouseOverviewTab';
import WarehouseLocationsTab from '../components/warehouse/WarehouseLocationsTab';
import WarehouseStaffTab from '../components/warehouse/WarehouseStaffTab';
import WarehouseOpeningStockTab from '../components/warehouse/WarehouseOpeningStockTab';
import WarehouseActivityTab from '../components/warehouse/WarehouseActivityTab';
import { errorText, isWarehouseTab } from '../components/warehouse/warehouseTabs';
import type { WarehouseTab } from '../components/warehouse/warehouseTabs';
import { Alert } from '../components/common/Alert';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { humanize } from '../utils/format';
import '../components/warehouse/WarehouseDetail.css';

/**
 * One warehouse, split into tabs: Overview, Orders, Locations, Staff, Opening Stock and Activity.
 * The open tab is kept in the address (?tab=) so a refresh or a shared link opens the same tab.
 */
export const WarehouseDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const warehouseId = Number(id);
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { user } = useAuth();
  const { confirm, dialog } = useConfirmDialog();

  const permissions = {
    manage: can(user, 'warehouses.manage'),
    remove: can(user, 'warehouses.delete'),
    orders: can(user, 'orders.view'),
    staff: can(user, 'warehouse.employees.view'),
    assignStaff: can(user, 'warehouse.employees.assign'),
  };

  const [warehouse, setWarehouse] = useState<WarehouseModel | null>(null);
  const [readiness, setReadiness] = useState<WarehouseReadinessModel | null>(null);
  const [ordersCount, setOrdersCount] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  const tabs: { id: WarehouseTab; label: string; count?: number | null; show: boolean }[] = [
    { id: 'overview', label: 'Overview', show: true },
    { id: 'orders', label: 'Orders', count: ordersCount, show: permissions.orders },
    { id: 'locations', label: 'Locations', count: warehouse?.locations_count, show: true },
    { id: 'staff', label: 'Staff', count: warehouse?.memberships_count, show: permissions.staff },
    { id: 'opening_stock', label: 'Opening Stock', show: permissions.manage },
    { id: 'activity', label: 'Activity', show: true },
  ];
  const visibleTabs = tabs.filter((tab) => tab.show);
  const requested = searchParams.get('tab');
  const activeTab: WarehouseTab = isWarehouseTab(requested) && visibleTabs.some((tab) => tab.id === requested) ? requested : 'overview';

  const openTab = (tab: WarehouseTab) => setSearchParams(tab === 'overview' ? {} : { tab }, { replace: true });

  const load = useCallback(async () => {
    if (!warehouseId) return;
    try {
      const [detail, check] = await Promise.all([omsService.getWarehouse(warehouseId), omsService.getWarehouseReadiness(warehouseId)]);
      setWarehouse(detail.data);
      setReadiness(check.data);
      if (permissions.orders) {
        omsService.getOrders({ warehouse_id: warehouseId, per_page: 1 }).then((res) => setOrdersCount(res.meta.total)).catch(() => setOrdersCount(null));
      }
    } catch (err) {
      setMessage({ type: 'error', text: errorText(err, 'Failed to load the warehouse.') });
    } finally {
      setLoading(false);
    }
  }, [warehouseId, permissions.orders]);

  useEffect(() => {
    load();
  }, [load]);

  // Close the actions menu on an outside click.
  useEffect(() => {
    if (!menuOpen) return;
    const close = (event: MouseEvent) => {
      if (!menuRef.current?.contains(event.target as Node)) setMenuOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [menuOpen]);

  const runAction = async (action: () => Promise<unknown>, success: string, failure: string) => {
    setMenuOpen(false);
    try {
      setBusy(true);
      setMessage(null);
      await action();
      setMessage({ type: 'success', text: success });
      load();
    } catch (err) {
      setMessage({ type: 'error', text: errorText(err, failure) });
    } finally {
      setBusy(false);
    }
  };

  const toggleActive = async () => {
    if (!warehouse) return;
    if (warehouse.status === 'active') {
      const ok = await confirm({
        title: `Deactivate ${warehouse.name}?`,
        message: 'New orders will no longer be sent to this warehouse. Its stock and history stay as they are.',
        confirmLabel: 'Deactivate',
        tone: 'danger',
      });
      if (ok) runAction(() => omsService.deactivateWarehouse(warehouseId), `${warehouse.name} was deactivated.`, 'Failed to deactivate the warehouse.');
      return;
    }
    runAction(() => omsService.activateWarehouse(warehouseId), `${warehouse.name} is active and can receive orders.`, 'Failed to activate the warehouse.');
  };

  const removeWarehouse = async () => {
    if (!warehouse) return;
    setMenuOpen(false);
    const ok = await confirm({
      title: `Delete ${warehouse.name}?`,
      message: 'The warehouse is deleted permanently with its locations and staff assignments.',
      note: 'Only warehouses without stores, stock or purchase documents can be deleted. Deactivate a warehouse that has been in use.',
      confirmLabel: 'Delete Warehouse',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      setBusy(true);
      await omsService.deleteWarehouse(warehouseId);
      navigate('/warehouses');
    } catch (err) {
      setMessage({ type: 'error', text: errorText(err, 'Failed to delete the warehouse.') });
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="page-container">
        <div className="wh-loading" style={{ minHeight: 360, alignItems: 'center' }}>
          <Loader2 className="animate-spin" size={32} />
        </div>
      </div>
    );
  }

  if (!warehouse) {
    return (
      <div className="page-container">
        <Alert type="error" message={message?.text ?? 'This warehouse could not be found.'} />
      </div>
    );
  }

  const canActivate = permissions.manage && (warehouse.status === 'active' || readiness?.ready_for_activation);

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <Link to="/warehouses" style={{ color: '#1e56ff' }}>
          Warehouses
        </Link>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 600 }}>{warehouse.name}</span>
      </nav>

      <div className="wh-header">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          <div className="wh-title-row">
            <h1 className="wh-title">{warehouse.name}</h1>
            <WarehouseStatusBadge status={warehouse.status} size="md" />
          </div>
          <div className="wh-title-row">
            <span className="wh-meta-pill">{warehouse.code}</span>
            {warehouse.warehouse_type && <span className="wh-meta-pill" style={{ textTransform: 'capitalize' }}>{humanize(warehouse.warehouse_type)}</span>}
            <span className="wh-meta-pill">Priority #{warehouse.priority}</span>
            {warehouse.city && <span className="wh-meta-pill">{[warehouse.city, warehouse.country].filter(Boolean).join(', ')}</span>}
          </div>
        </div>

        <div className="wh-header-actions">
          <div className="wh-tabs" role="tablist" aria-label="Warehouse sections">
            {visibleTabs.map((tab) => (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={activeTab === tab.id}
                className={`wh-tab ${activeTab === tab.id ? 'is-active' : ''}`}
                onClick={() => openTab(tab.id)}
              >
                {tab.label}
                {typeof tab.count === 'number' && <span className="wh-tab-count">{tab.count}</span>}
              </button>
            ))}
          </div>

          <div className="wh-menu" ref={menuRef}>
            <button
              type="button"
              className="wh-icon-btn"
              aria-label="Warehouse actions"
              aria-haspopup="menu"
              aria-expanded={menuOpen}
              onClick={() => setMenuOpen((open) => !open)}
              disabled={busy}
            >
              {busy ? <Loader2 size={16} className="animate-spin" /> : <MoreHorizontal size={18} />}
            </button>
            {menuOpen && (
              <div className="wh-menu-list" role="menu">
                <button type="button" role="menuitem" className="wh-menu-item" onClick={() => runAction(load, 'Warehouse refreshed.', 'Failed to refresh.')}>
                  <RefreshCw size={15} /> Refresh
                </button>
                {permissions.manage && (
                  <button
                    type="button"
                    role="menuitem"
                    className={`wh-menu-item ${warehouse.status === 'active' ? 'is-danger' : ''}`}
                    onClick={toggleActive}
                    disabled={!canActivate}
                    title={canActivate ? undefined : 'Finish the setup steps on the Overview tab first'}
                  >
                    <Power size={15} /> {warehouse.status === 'active' ? 'Deactivate' : 'Activate'}
                  </button>
                )}
                {permissions.remove && (
                  <button type="button" role="menuitem" className="wh-menu-item is-danger" onClick={removeWarehouse}>
                    <Trash2 size={15} /> Delete warehouse
                  </button>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {message && <Alert type={message.type} message={message.text} className="page-banner" />}

      <div role="tabpanel" aria-label={tabs.find((tab) => tab.id === activeTab)?.label}>
        {activeTab === 'overview' && <WarehouseOverviewTab warehouse={warehouse} readiness={readiness} onOpenTab={openTab} />}
        {activeTab === 'orders' && (
          <div className="wh-card">
            <WarehouseOrders warehouseId={warehouseId} onCountChange={setOrdersCount} />
          </div>
        )}
        {activeTab === 'locations' && <WarehouseLocationsTab warehouseId={warehouseId} canManage={permissions.manage} onChanged={load} />}
        {activeTab === 'staff' && <WarehouseStaffTab warehouseId={warehouseId} canAssign={permissions.assignStaff} onChanged={load} />}
        {activeTab === 'opening_stock' && <WarehouseOpeningStockTab warehouseId={warehouseId} onChanged={load} />}
        {activeTab === 'activity' && <WarehouseActivityTab warehouseId={warehouseId} />}
      </div>

      {dialog}
    </div>
  );
};

export default WarehouseDetailPage;
