import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import {
  ArrowLeft,
  Warehouse as WarehouseIcon,
  ShieldCheck,
  Layers,
  Users,
  PackagePlus,
  History,
  Power,
  RefreshCw,
  Plus,
  Trash2,
  AlertTriangle,
  CheckCircle2,
  Sparkles,
  MapPin,
  Clock,
  Send,
  Loader2,
} from 'lucide-react';
import omsService from '../services/omsService';
import type {
  WarehouseModel,
  WarehouseLocationModel,
  WarehouseStaffModel,
  WarehouseAuditLogModel,
  WarehouseReadinessModel,
} from '../services/omsService';
import WarehouseStatusBadge from '../components/warehouse/WarehouseStatusBadge';
import CustomDropdown from '../components/common/CustomDropdown';
import ConfirmDialog, { useConfirmDialog } from '../components/common/ConfirmDialog';

type TabType = 'overview' | 'layout' | 'staff' | 'opening_stock' | 'audit_logs';

export const WarehouseDetailPage: React.FC = () => {
  const { confirm, dialog } = useConfirmDialog();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const warehouseId = Number(id);

  const [activeTab, setActiveTab] = useState<TabType>('overview');
  const [warehouse, setWarehouse] = useState<WarehouseModel | null>(null);
  const [readiness, setReadiness] = useState<WarehouseReadinessModel | null>(null);
  const [locations, setLocations] = useState<WarehouseLocationModel[]>([]);
  const [staff, setStaff] = useState<WarehouseStaffModel[]>([]);
  const [auditLogs, setAuditLogs] = useState<WarehouseAuditLogModel[]>([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Layout Sub-Form State
  const [showAddLocation, setShowAddLocation] = useState(false);
  const [locCode, setLocCode] = useState('');
  const [locName, setLocName] = useState('');
  const [locType, setLocType] = useState<'zone' | 'aisle' | 'rack' | 'shelf' | 'bin'>('bin');
  const [locParentId, setLocParentId] = useState<string>('');
  const [locPicking, setLocPicking] = useState(true);
  const [locPutaway, setLocPutaway] = useState(true);

  // Staff Sub-Form State
  const [showAssignStaff, setShowAssignStaff] = useState(false);
  const [staffUserId, setStaffUserId] = useState<number>(1);
  const [staffRole, setStaffRole] = useState('picker');
  const [eligibleUsers, setEligibleUsers] = useState<Array<{ id: number; name: string; email: string; role: string }>>([]);

  // Controlled Opening Stock State
  const [stockRows, setStockRows] = useState<
    Array<{ sku: string; location_code: string; quantity: number; lot_number: string; expiry_date: string }>
  >([{ sku: '', location_code: '', quantity: 1, lot_number: '', expiry_date: '' }]);
  const [previewResult, setPreviewResult] = useState<any | null>(null);
  const [stockCommitLoading, setStockCommitLoading] = useState(false);

  const fetchData = async () => {
    if (!warehouseId || isNaN(warehouseId)) return;
    try {
      setLoading(true);
      const [whRes, readRes, locRes, staffRes, logRes] = await Promise.all([
        omsService.getWarehouse(warehouseId),
        omsService.getWarehouseReadiness(warehouseId),
        omsService.getWarehouseLocations(warehouseId),
        omsService.getWarehouseStaff(warehouseId),
        omsService.getWarehouseAuditLogs(warehouseId),
      ]);

      setWarehouse(whRes.data);
      setReadiness(readRes.data);
      setLocations(Array.isArray(locRes.data) ? locRes.data : (locRes.data?.items || []));

      const staffData = staffRes.data;
      if (Array.isArray(staffData)) {
        setStaff(staffData);
      } else if (staffData && Array.isArray(staffData.assigned_staff)) {
        setStaff(staffData.assigned_staff);
        if (Array.isArray(staffData.eligible_users)) {
          setEligibleUsers(staffData.eligible_users);
          if (staffData.eligible_users.length > 0) {
            setStaffUserId(staffData.eligible_users[0].id);
          }
        }
      } else {
        setStaff([]);
      }

      setAuditLogs(Array.isArray(logRes.data) ? logRes.data : (logRes.data?.items || []));
    } catch (err: any) {
      console.error(err);
      setMessage({ type: 'error', text: err.message || 'Failed to load warehouse data.' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, [warehouseId]);

  // Activation / Deactivation Handlers
  const handleActivate = async () => {
    try {
      setActionLoading(true);
      setMessage(null);
      await omsService.activateWarehouse(warehouseId);
      setMessage({ type: 'success', text: 'Warehouse successfully activated and operational.' });
      fetchData();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to activate warehouse.' });
    } finally {
      setActionLoading(false);
    }
  };

  const handleDeactivate = async () => {
    try {
      setActionLoading(true);
      setMessage(null);
      await omsService.deactivateWarehouse(warehouseId);
      setMessage({ type: 'success', text: 'Warehouse deactivated.' });
      fetchData();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to deactivate warehouse.' });
    } finally {
      setActionLoading(false);
    }
  };

  const handleDeleteWarehouse = async () => {
    try {
      setActionLoading(true);
      setMessage(null);
      await omsService.deleteWarehouse(warehouseId);
      navigate('/warehouses');
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to delete warehouse.' });
      setActionLoading(false);
      setDeleteDialogOpen(false);
    }
  };

  // Location Handlers
  const handleCreateLocation = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!locCode.trim()) return;

    try {
      setActionLoading(true);
      await omsService.createWarehouseLocation(warehouseId, {
        code: locCode.trim().toUpperCase(),
        name: locName.trim() || undefined,
        location_type: locType,
        parent_id: locParentId ? Number(locParentId) : undefined,
        picking_eligible: locPicking,
        putaway_eligible: locPutaway,
        sort_order: 10,
        is_active: true,
      });

      setMessage({ type: 'success', text: `Location ${locCode.toUpperCase()} created successfully.` });
      setLocCode('');
      setLocName('');
      setShowAddLocation(false);
      fetchData();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to create location.' });
    } finally {
      setActionLoading(false);
    }
  };

  const handleDeleteLocation = async (locId: number, code: string) => {
    const ok = await confirm({
      title: 'Delete location?',
      message: <>Delete location <strong>{code}</strong>? All child bins will be removed.</>,
      confirmLabel: 'Delete Location',
      tone: 'danger',
    });
    if (!ok) return;

    try {
      setActionLoading(true);
      await omsService.deleteWarehouseLocation(warehouseId, locId);
      setMessage({ type: 'success', text: `Location ${code} deleted.` });
      fetchData();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to delete location.' });
    } finally {
      setActionLoading(false);
    }
  };

  const handleGenerateStarter = async () => {
    const ok = await confirm({
      title: 'Generate starter layout?',
      message: 'A starter layout with 1 zone and standard aisles and bins will be created for this warehouse.',
      confirmLabel: 'Generate Layout',
      tone: 'primary',
    });
    if (!ok) return;

    try {
      setActionLoading(true);
      await omsService.generateStarterLayout(warehouseId, { preview: false });
      setMessage({ type: 'success', text: 'Starter layout generated successfully.' });
      fetchData();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to generate layout.' });
    } finally {
      setActionLoading(false);
    }
  };

  // Staff Assignment Handlers
  const handleAssignStaff = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setActionLoading(true);
      await omsService.assignWarehouseStaff(warehouseId, {
        user_id: staffUserId,
        role: staffRole,
      });

      setMessage({ type: 'success', text: 'Staff member assigned successfully.' });
      setShowAssignStaff(false);
      fetchData();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to assign staff.' });
    } finally {
      setActionLoading(false);
    }
  };

  const handleRemoveStaff = async (staffId: number, name?: string) => {
    const ok = await confirm({
      title: 'Remove staff member?',
      message: <>Remove <strong>{name || 'this staff member'}</strong> from this warehouse?</>,
      confirmLabel: 'Remove',
      tone: 'danger',
    });
    if (!ok) return;

    try {
      setActionLoading(true);
      await omsService.removeWarehouseStaff(warehouseId, staffId);
      setMessage({ type: 'success', text: 'Staff member removed.' });
      fetchData();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to remove staff.' });
    } finally {
      setActionLoading(false);
    }
  };

  // Opening Stock Handlers
  const handleStockRowChange = (index: number, field: string, value: any) => {
    setStockRows((prev) => {
      const next = [...prev];
      next[index] = { ...next[index], [field]: value };
      return next;
    });
  };

  const handleAddStockRow = () => {
    setStockRows((prev) => [...prev, { sku: '', location_code: '', quantity: 1, lot_number: '', expiry_date: '' }]);
  };

  const handleRemoveStockRow = (index: number) => {
    if (stockRows.length === 1) return;
    setStockRows((prev) => prev.filter((_, i) => i !== index));
  };

  const handlePreviewOpeningStock = async () => {
    try {
      setStockCommitLoading(true);
      setMessage(null);
      const res = await omsService.previewOpeningStock(warehouseId, stockRows);
      setPreviewResult(res.data);
      setMessage({ type: 'success', text: 'Opening stock validation passed. Ready to commit.' });
    } catch (err: any) {
      setPreviewResult(null);
      setMessage({ type: 'error', text: err.message || 'Stock preview failed validation.' });
    } finally {
      setStockCommitLoading(false);
    }
  };

  const handleCommitOpeningStock = async () => {
    const ok = await confirm({
      title: 'Commit opening inventory?',
      message: 'Stock balances and stock movement records will be created from these rows.',
      note: 'This cannot be undone. Later changes need stock adjustments.',
      confirmLabel: 'Commit Stock',
      tone: 'primary',
    });
    if (!ok) return;

    try {
      setStockCommitLoading(true);
      setMessage(null);
      await omsService.commitOpeningStock(warehouseId, {
        items: stockRows,
      });
      setMessage({ type: 'success', text: 'Opening stock successfully committed into inventory ledger.' });
      setPreviewResult(null);
      setStockRows([{ sku: '', location_code: '', quantity: 1, lot_number: '', expiry_date: '' }]);
      fetchData();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to commit opening stock.' });
    } finally {
      setStockCommitLoading(false);
    }
  };

  if (loading && !warehouse) {
    return (
      <div className="page-container" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 400 }}>
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
          <Loader2 className="animate-spin" size={32} color="#1e56ff" />
          <span style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>Loading warehouse details...</span>
        </div>
      </div>
    );
  }

  return (
    <div className="page-container">
      {/* Breadcrumb Navigation */}
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ cursor: 'pointer', color: '#1e56ff' }} onClick={() => navigate('/warehouses')}>
          Warehouses
        </span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 600 }}>{warehouse?.name || `Warehouse #${warehouseId}`}</span>
      </nav>

      {/* Main Page Header */}
      <div className="page-header-row" style={{ marginBottom: 20 }}>
        <div className="page-title-section">
          <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
            <div
              style={{
                width: 46,
                height: 46,
                borderRadius: 12,
                backgroundColor: '#eff6ff',
                color: '#1e56ff',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                flexShrink: 0,
              }}
            >
              <WarehouseIcon size={24} />
            </div>
            <div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <h1 className="page-title" style={{ margin: 0 }}>
                  {warehouse?.name || 'Warehouse Facility'}
                </h1>
                {warehouse && <WarehouseStatusBadge status={warehouse.status} size="md" />}
                <span className="kpi-pill kpi-pill-blue" style={{ fontSize: 11 }}>
                  Priority #{warehouse?.priority}
                </span>
              </div>
              <p className="page-subtitle" style={{ marginTop: 3 }}>
                Code: <strong style={{ color: '#334155' }}>{warehouse?.code}</strong> &bull;{' '}
                {warehouse?.city || 'No City'}, {warehouse?.country || 'No Country'} &bull; Timezone:{' '}
                {warehouse?.timezone || 'UTC'}
              </p>
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <button
            type="button"
            onClick={() => navigate('/warehouses')}
            className="btn-finovo-secondary"
            style={{ padding: '9px 16px', fontSize: 13 }}
          >
            <ArrowLeft size={15} />
            <span>Back to Warehouses</span>
          </button>

          {warehouse?.status !== 'active' ? (
            <button
              type="button"
              onClick={handleActivate}
              disabled={actionLoading}
              className="btn-finovo-primary"
              style={{ padding: '9px 18px', fontSize: 13, backgroundColor: '#059669' }}
            >
              {actionLoading ? <Loader2 className="animate-spin" size={15} /> : <Power size={15} />}
              <span>Activate Facility</span>
            </button>
          ) : (
            <button
              type="button"
              onClick={handleDeactivate}
              disabled={actionLoading}
              className="btn-finovo-secondary"
              style={{ padding: '9px 16px', fontSize: 13, color: '#dc2626' }}
            >
              {actionLoading ? <Loader2 className="animate-spin" size={15} /> : <Power size={15} />}
              <span>Deactivate</span>
            </button>
          )}

          <button
            type="button"
            onClick={() => setDeleteDialogOpen(true)}
            disabled={actionLoading}
            className="btn-finovo-secondary"
            style={{ padding: '9px 16px', fontSize: 13, color: '#dc2626' }}
            title="Delete an unused warehouse permanently"
          >
            <Trash2 size={15} />
            <span>Delete</span>
          </button>

          <button
            type="button"
            onClick={fetchData}
            disabled={loading}
            className="btn-finovo-secondary"
            style={{ padding: '9px 12px' }}
            title="Refresh Facility State"
          >
            <RefreshCw size={15} className={loading ? 'animate-spin' : ''} />
          </button>
        </div>
      </div>

      {/* Global Status Message Alert */}
      {message && (
        <div
          style={{
            padding: '12px 18px',
            borderRadius: 10,
            backgroundColor: message.type === 'success' ? '#ecfdf5' : '#fef2f2',
            border: `1px solid ${message.type === 'success' ? '#a7f3d0' : '#fecaca'}`,
            color: message.type === 'success' ? '#065f46' : '#991b1b',
            fontSize: 13,
            marginBottom: 20,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            {message.type === 'success' ? <CheckCircle2 size={16} /> : <AlertTriangle size={16} />}
            <span>{message.text}</span>
          </div>
          <button
            onClick={() => setMessage(null)}
            style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'inherit', fontWeight: 700 }}
          >
            &times;
          </button>
        </div>
      )}

      {/* Readiness Engine Callout Banner (if setup incomplete or draft) */}
      {readiness && !readiness.ready_for_activation && warehouse?.status !== 'active' && (
        <div
          style={{
            padding: '16px 20px',
            borderRadius: 12,
            backgroundColor: '#fffbeb',
            border: '1px solid #fde68a',
            marginBottom: 24,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <AlertTriangle size={20} color="#d97706" />
              <div>
                <h2 style={{ fontSize: 14, fontWeight: 700, margin: 0, color: '#92400e' }}>
                  Readiness Score: {readiness.score}% — Action Required for Operational Activation
                </h2>
                <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#b45309' }}>
                  The facility must pass all structural layout and staff requirements before accepting inventory orders.
                </p>
              </div>
            </div>
            <span
              style={{
                fontSize: 12,
                fontWeight: 700,
                color: '#92400e',
                backgroundColor: '#fef3c7',
                padding: '4px 10px',
                borderRadius: 6,
              }}
            >
              {readiness.issues.length} Issues Remaining
            </span>
          </div>

          {readiness.issues.length > 0 && (
            <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 6 }}>
              {readiness.issues.map((iss, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, color: '#92400e' }}>
                  <span style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: '#d97706' }} />
                  <span>{iss.message}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Full Page Navigation Tabs */}
      <div className="finovo-tabs" style={{ marginBottom: 20 }}>
        {[
          { id: 'overview', label: 'Overview & Readiness', icon: ShieldCheck },
          { id: 'layout', label: `Locations & Hierarchy (${locations.length})`, icon: Layers },
          { id: 'staff', label: `Staff & Roles (${staff.length})`, icon: Users },
          { id: 'opening_stock', label: 'Controlled Opening Stock', icon: PackagePlus },
          { id: 'audit_logs', label: `Audit Trail (${auditLogs.length})`, icon: History },
        ].map((tab) => {
          const Icon = tab.icon;
          const isActive = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id as TabType)}
              className={`finovo-tab-item ${isActive ? 'active' : ''}`}
              style={{ border: 'none', background: 'none' }}
            >
              <Icon size={16} />
              <span>{tab.label}</span>
            </button>
          );
        })}
      </div>

      {/* TAB CONTENT CARDS */}
      <div className="content-card" style={{ padding: 24, backgroundColor: '#ffffff' }}>
        {/* TAB 1: OVERVIEW & READINESS */}
        {activeTab === 'overview' && warehouse && (
          <div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 20 }}>
              {/* Facility Metadata */}
              <div style={{ padding: 18, borderRadius: 12, border: '1px solid #e2e8f0', backgroundColor: '#f8fafc' }}>
                <h3 style={{ margin: '0 0 14px 0', fontSize: 14, fontWeight: 700, color: '#0f172a' }}>
                  Facility Identity & Classification
                </h3>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 13 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b' }}>Facility Type:</span>
                    <strong style={{ textTransform: 'capitalize' }}>{warehouse.type?.replace(/_/g, ' ') || 'DC'}</strong>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b' }}>Organization ID:</span>
                    <strong>#{(warehouse as any).organization_id || 1}</strong>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b' }}>Priority Rank:</span>
                    <strong style={{ color: '#1e56ff' }}>Priority #{warehouse.priority}</strong>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b' }}>Default Organization Node:</span>
                    <strong>{warehouse.is_default ? 'Yes (Primary)' : 'No'}</strong>
                  </div>
                </div>
              </div>

              {/* Geographic Coordinates & SLA */}
              <div style={{ padding: 18, borderRadius: 12, border: '1px solid #e2e8f0', backgroundColor: '#f8fafc' }}>
                <h3 style={{ margin: '0 0 14px 0', fontSize: 14, fontWeight: 700, color: '#0f172a' }}>
                  Address & Regional Timezone
                </h3>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 13 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <MapPin size={14} color="#64748b" />
                    <span>
                      {warehouse.address_line1 || 'No street specified'}
                      {warehouse.city && `, ${warehouse.city}`}
                      {warehouse.country && `, ${warehouse.country}`}
                    </span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <Clock size={14} color="#64748b" />
                    <span>Timezone: {warehouse.timezone || 'UTC'}</span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 4 }}>
                    <span style={{ color: '#64748b' }}>Operating Hours:</span>
                    <strong>08:00 - 17:00 (Mon - Fri)</strong>
                  </div>
                </div>
              </div>

              {/* Inventory & Tracking Policies */}
              <div style={{ padding: 18, borderRadius: 12, border: '1px solid #e2e8f0', backgroundColor: '#f8fafc' }}>
                <h3 style={{ margin: '0 0 14px 0', fontSize: 14, fontWeight: 700, color: '#0f172a' }}>
                  Active Fulfillment Policies
                </h3>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 13 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b' }}>Picking Strategy:</span>
                    <strong style={{ textTransform: 'uppercase' }}>
                      {warehouse.inventory_policies?.picking_strategy || 'FIFO'}
                    </strong>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b' }}>Negative Inventory:</span>
                    <strong>{warehouse.inventory_policies?.allow_negative_inventory ? 'Allowed' : 'Blocked'}</strong>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b' }}>Lot Tracking:</span>
                    <strong>{warehouse.inventory_policies?.lot_tracking ? 'Enforced' : 'Disabled'}</strong>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b' }}>Serial Tracking:</span>
                    <strong>{warehouse.inventory_policies?.serial_tracking ? 'Enforced' : 'Disabled'}</strong>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* TAB 2: LOCATIONS & HIERARCHY */}
        {activeTab === 'layout' && (
          <div>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                  Physical Storage Topology & Bins
                </h3>
                <p style={{ margin: '2px 0 0 0', fontSize: 13, color: '#64748b' }}>
                  5-level nesting: Zone &rarr; Aisle &rarr; Rack &rarr; Shelf &rarr; Bin.
                </p>
              </div>

              <div style={{ display: 'flex', gap: 10 }}>
                {locations.length === 0 && (
                  <button
                    type="button"
                    onClick={handleGenerateStarter}
                    disabled={actionLoading}
                    className="btn-finovo-secondary"
                    style={{ padding: '8px 14px', fontSize: 12 }}
                  >
                    <Sparkles size={14} color="#1e56ff" />
                    <span>Generate Starter Layout</span>
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => setShowAddLocation((prev) => !prev)}
                  className="btn-finovo-primary"
                  style={{ padding: '8px 14px', fontSize: 12 }}
                >
                  <Plus size={14} />
                  <span>{showAddLocation ? 'Hide Form' : 'Add Location'}</span>
                </button>
              </div>
            </div>

            {/* Inline Add Location Drawer */}
            {showAddLocation && (
              <form
                onSubmit={handleCreateLocation}
                style={{
                  padding: 20,
                  borderRadius: 12,
                  backgroundColor: '#f8fafc',
                  border: '1px solid #e2e8f0',
                  marginBottom: 24,
                }}
              >
                <h4 style={{ margin: '0 0 14px 0', fontSize: 14, fontWeight: 700, color: '#0f172a' }}>
                  Create New Physical Location Node
                </h4>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14 }}>
                  <div>
                    <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
                      Location Code *
                    </label>
                    <input
                      type="text"
                      placeholder="e.g. A-01-R01-S01-B01"
                      value={locCode}
                      onChange={(e) => setLocCode(e.target.value.toUpperCase())}
                      style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                      required
                    />
                  </div>

                  <div>
                    <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
                      Location Type *
                    </label>
                    <CustomDropdown
                      value={locType}
                      onChange={(val) => setLocType(val as any)}
                      options={[
                        { value: 'zone', label: 'Zone' },
                        { value: 'aisle', label: 'Aisle' },
                        { value: 'rack', label: 'Rack' },
                        { value: 'shelf', label: 'Shelf' },
                        { value: 'bin', label: 'Bin (Pickable Storage Unit)' },
                      ]}
                      size="sm"
                    />
                  </div>

                  <div>
                    <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
                      Parent Node (Optional)
                    </label>
                    <CustomDropdown
                      value={locParentId}
                      onChange={(val) => setLocParentId(val)}
                      options={[
                        { value: '', label: 'None (Root Node)' },
                        ...locations
                          .filter((l) => l.location_type !== 'bin')
                          .map((l) => ({ value: String(l.id), label: `${l.code} (${l.location_type})` })),
                      ]}
                      searchable
                      size="sm"
                    />
                  </div>

                  <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginTop: 22 }}>
                    <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, cursor: 'pointer' }}>
                      <input
                        type="checkbox"
                        checked={locPicking}
                        onChange={(e) => setLocPicking(e.target.checked)}
                        style={{ accentColor: '#1e56ff' }}
                      />
                      <span>Picking Eligible</span>
                    </label>
                    <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, cursor: 'pointer' }}>
                      <input
                        type="checkbox"
                        checked={locPutaway}
                        onChange={(e) => setLocPutaway(e.target.checked)}
                        style={{ accentColor: '#1e56ff' }}
                      />
                      <span>Putaway Eligible</span>
                    </label>
                  </div>
                </div>

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}>
                  <button
                    type="button"
                    onClick={() => setShowAddLocation(false)}
                    className="btn-finovo-secondary"
                    style={{ padding: '6px 14px', fontSize: 12 }}
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={actionLoading}
                    className="btn-finovo-primary"
                    style={{ padding: '6px 16px', fontSize: 12 }}
                  >
                    {actionLoading ? 'Creating...' : 'Create Location'}
                  </button>
                </div>
              </form>
            )}

            {/* Locations Hierarchy Table */}
            {locations.length === 0 ? (
              <div style={{ padding: 40, textAlign: 'center', color: '#64748b' }}>
                <Layers size={36} color="#cbd5e1" style={{ margin: '0 auto 12px auto' }} />
                <h4 style={{ margin: 0, fontSize: 15, color: '#334155' }}>No Storage Locations Defined</h4>
                <p style={{ margin: '4px 0 16px 0', fontSize: 13 }}>
                  Create zones and bins manually or auto-generate a starter topology.
                </p>
                <button onClick={handleGenerateStarter} className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 12 }}>
                  <Sparkles size={14} />
                  <span>Generate Starter Topology</span>
                </button>
              </div>
            ) : (
              <div className="table-responsive-wrapper">
                <table className="finovo-table">
                  <thead>
                    <tr>
                      <th>Node Code</th>
                      <th>Type</th>
                      <th>Parent Node</th>
                      <th>Capabilities</th>
                      <th>Status</th>
                      <th style={{ textAlign: 'right' }}>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {locations.map((loc) => (
                      <tr key={loc.id}>
                        <td>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <span
                              style={{
                                display: 'inline-flex',
                                width: 8,
                                height: 8,
                                borderRadius: '50%',
                                backgroundColor: loc.location_type === 'zone' ? '#1e56ff' : loc.location_type === 'bin' ? '#10b981' : '#64748b',
                              }}
                            />
                            <strong style={{ fontSize: 13, color: '#0f172a' }}>{loc.code}</strong>
                            {loc.name && <span style={{ fontSize: 12, color: '#64748b' }}>({loc.name})</span>}
                          </div>
                        </td>
                        <td>
                          <span
                            style={{
                              fontSize: 11,
                              fontWeight: 700,
                              textTransform: 'uppercase',
                              padding: '2px 6px',
                              borderRadius: 4,
                              backgroundColor: '#f1f5f9',
                              color: '#475569',
                            }}
                          >
                            {loc.location_type}
                          </span>
                        </td>
                        <td style={{ fontSize: 12, color: '#64748b' }}>
                          {loc.parent ? `${loc.parent.code} (${loc.parent.location_type})` : '—'}
                        </td>
                        <td>
                          <div style={{ display: 'flex', gap: 6 }}>
                            {loc.picking_eligible && (
                              <span style={{ fontSize: 10, padding: '1px 5px', borderRadius: 4, backgroundColor: '#eff6ff', color: '#1e56ff', fontWeight: 600 }}>
                                Pick
                              </span>
                            )}
                            {loc.putaway_eligible && (
                              <span style={{ fontSize: 10, padding: '1px 5px', borderRadius: 4, backgroundColor: '#ecfdf5', color: '#059669', fontWeight: 600 }}>
                                Putaway
                              </span>
                            )}
                          </div>
                        </td>
                        <td>
                          <span style={{ fontSize: 12, color: loc.is_active ? '#059669' : '#94a3b8' }}>
                            {loc.is_active ? 'Active' : 'Disabled'}
                          </span>
                        </td>
                        <td style={{ textAlign: 'right' }}>
                          <button
                            type="button"
                            onClick={() => handleDeleteLocation(loc.id, loc.code)}
                            style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444', padding: 4 }}
                            title="Delete Node"
                          >
                            <Trash2 size={14} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {/* TAB 3: STAFF & ROLES */}
        {activeTab === 'staff' && (
          <div>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                  Warehouse Personnel & Authorizations
                </h3>
                <p style={{ margin: '2px 0 0 0', fontSize: 13, color: '#64748b' }}>
                  Assign operators, supervisors, pickers, and inventory controllers.
                </p>
              </div>

              <button
                type="button"
                onClick={() => setShowAssignStaff((prev) => !prev)}
                className="btn-finovo-primary"
                style={{ padding: '8px 14px', fontSize: 12 }}
              >
                <Plus size={14} />
                <span>{showAssignStaff ? 'Hide Form' : 'Assign Staff'}</span>
              </button>
            </div>

            {/* Inline Staff Assignment Drawer */}
            {showAssignStaff && (
              <form
                onSubmit={handleAssignStaff}
                style={{
                  padding: 20,
                  borderRadius: 12,
                  backgroundColor: '#f8fafc',
                  border: '1px solid #e2e8f0',
                  marginBottom: 24,
                }}
              >
                <h4 style={{ margin: '0 0 14px 0', fontSize: 14, fontWeight: 700, color: '#0f172a' }}>
                  Assign User to Facility
                </h4>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
                  <div>
                    <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
                      User Account *
                    </label>
                    {eligibleUsers.length > 0 ? (
                      <CustomDropdown
                        value={String(staffUserId)}
                        onChange={(val) => setStaffUserId(Number(val))}
                        options={eligibleUsers.map((u) => ({
                          value: String(u.id),
                          label: `${u.name} (${u.email})`,
                        }))}
                        size="sm"
                      />
                    ) : (
                      <input
                        type="number"
                        min="1"
                        value={staffUserId}
                        onChange={(e) => setStaffUserId(Number(e.target.value))}
                        style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                        required
                      />
                    )}
                  </div>

                  <div>
                    <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
                      Operating Role *
                    </label>
                    <CustomDropdown
                      value={staffRole}
                      onChange={(val) => setStaffRole(val)}
                      options={[
                        { value: 'manager', label: 'Facility Manager (Full Operational Authority)' },
                        { value: 'supervisor', label: 'Shift Supervisor' },
                        { value: 'picker', label: 'Order Picker' },
                        { value: 'packer', label: 'Packer & Dispatch Handler' },
                        { value: 'receiver', label: 'Receiving Dock Operator' },
                      ]}
                      size="sm"
                    />
                  </div>
                </div>

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}>
                  <button
                    type="button"
                    onClick={() => setShowAssignStaff(false)}
                    className="btn-finovo-secondary"
                    style={{ padding: '6px 14px', fontSize: 12 }}
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={actionLoading}
                    className="btn-finovo-primary"
                    style={{ padding: '6px 16px', fontSize: 12 }}
                  >
                    {actionLoading ? 'Assigning...' : 'Confirm Assignment'}
                  </button>
                </div>
              </form>
            )}

            {/* Staff Table */}
            {staff.length === 0 ? (
              <div style={{ padding: 40, textAlign: 'center', color: '#64748b' }}>
                <Users size={36} color="#cbd5e1" style={{ margin: '0 auto 12px auto' }} />
                <h4 style={{ margin: 0, fontSize: 15, color: '#334155' }}>No Staff Assigned to this Facility</h4>
                <p style={{ margin: '4px 0 16px 0', fontSize: 13 }}>
                  Assign at least one warehouse manager or operator to satisfy activation readiness.
                </p>
                <button onClick={() => setShowAssignStaff(true)} className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 12 }}>
                  <Plus size={14} />
                  <span>Assign Staff Member</span>
                </button>
              </div>
            ) : (
              <div className="table-responsive-wrapper">
                <table className="finovo-table">
                  <thead>
                    <tr>
                      <th>Staff Member</th>
                      <th>Facility Role</th>
                      <th>Status</th>
                      <th style={{ textAlign: 'right' }}>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {staff.map((m) => (
                      <tr key={m.id}>
                        <td>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                            <div
                              style={{
                                width: 32,
                                height: 32,
                                borderRadius: '50%',
                                backgroundColor: '#eff6ff',
                                color: '#1e56ff',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontSize: 12,
                                fontWeight: 700,
                              }}
                            >
                              {m.user?.name ? m.user.name.charAt(0).toUpperCase() : 'U'}
                            </div>
                            <div>
                              <strong style={{ fontSize: 13, color: '#0f172a' }}>{m.user?.name || `User #${m.user_id}`}</strong>
                              <div style={{ fontSize: 11, color: '#64748b' }}>{m.user?.email || 'Registered Employee'}</div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <span
                            style={{
                              fontSize: 11,
                              fontWeight: 700,
                              textTransform: 'capitalize',
                              padding: '2px 8px',
                              borderRadius: 6,
                              backgroundColor: m.role === 'manager' ? '#eff6ff' : '#f8fafc',
                              color: m.role === 'manager' ? '#1e56ff' : '#475569',
                              border: `1px solid ${m.role === 'manager' ? '#bfdbfe' : '#e2e8f0'}`,
                            }}
                          >
                            {m.role}
                          </span>
                        </td>
                        <td>
                          <span style={{ fontSize: 12, color: m.is_active ? '#059669' : '#94a3b8' }}>
                            {m.is_active ? 'Active' : 'Disabled'}
                          </span>
                        </td>
                        <td style={{ textAlign: 'right' }}>
                          <button
                            type="button"
                            onClick={() => handleRemoveStaff(m.id, m.user?.name)}
                            style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444', padding: 4 }}
                            title="Remove Staff"
                          >
                            <Trash2 size={14} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {/* TAB 4: CONTROLLED OPENING STOCK */}
        {activeTab === 'opening_stock' && (
          <div>
            <div style={{ marginBottom: 20 }}>
              <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                Controlled Opening Stock Initialization
              </h3>
              <p style={{ margin: '2px 0 0 0', fontSize: 13, color: '#64748b' }}>
                Initialize starting stock balances with batch tracking and atomic ledger commitment.
              </p>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginBottom: 20 }}>
              {stockRows.map((row, idx) => (
                <div
                  key={idx}
                  style={{
                    display: 'grid',
                    gridTemplateColumns: '1.5fr 1.5fr 1fr 1fr 1fr auto',
                    gap: 12,
                    alignItems: 'center',
                    padding: 12,
                    borderRadius: 10,
                    backgroundColor: '#f8fafc',
                    border: '1px solid #e2e8f0',
                  }}
                >
                  <div>
                    <label style={{ display: 'block', fontSize: 11, fontWeight: 600, color: '#64748b', marginBottom: 4 }}>
                      Product SKU *
                    </label>
                    <input
                      type="text"
                      placeholder="e.g. SKU-1001"
                      value={row.sku}
                      onChange={(e) => handleStockRowChange(idx, 'sku', e.target.value.toUpperCase())}
                      style={{ width: '100%', padding: '6px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                      required
                    />
                  </div>

                  <div>
                    <label style={{ display: 'block', fontSize: 11, fontWeight: 600, color: '#64748b', marginBottom: 4 }}>
                      Location / Bin Code *
                    </label>
                    <input
                      type="text"
                      placeholder="e.g. ZONE-A"
                      value={row.location_code}
                      onChange={(e) => handleStockRowChange(idx, 'location_code', e.target.value.toUpperCase())}
                      style={{ width: '100%', padding: '6px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                      required
                    />
                  </div>

                  <div>
                    <label style={{ display: 'block', fontSize: 11, fontWeight: 600, color: '#64748b', marginBottom: 4 }}>
                      Quantity *
                    </label>
                    <input
                      type="number"
                      min="1"
                      value={row.quantity}
                      onChange={(e) => handleStockRowChange(idx, 'quantity', Number(e.target.value))}
                      style={{ width: '100%', padding: '6px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                      required
                    />
                  </div>

                  <div>
                    <label style={{ display: 'block', fontSize: 11, fontWeight: 600, color: '#64748b', marginBottom: 4 }}>
                      Lot / Batch #
                    </label>
                    <input
                      type="text"
                      placeholder="Optional"
                      value={row.lot_number}
                      onChange={(e) => handleStockRowChange(idx, 'lot_number', e.target.value)}
                      style={{ width: '100%', padding: '6px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                    />
                  </div>

                  <div>
                    <label style={{ display: 'block', fontSize: 11, fontWeight: 600, color: '#64748b', marginBottom: 4 }}>
                      Expiry Date
                    </label>
                    <input
                      type="date"
                      value={row.expiry_date}
                      onChange={(e) => handleStockRowChange(idx, 'expiry_date', e.target.value)}
                      style={{ width: '100%', padding: '6px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                    />
                  </div>

                  <div style={{ marginTop: 18 }}>
                    <button
                      type="button"
                      onClick={() => handleRemoveStockRow(idx)}
                      disabled={stockRows.length === 1}
                      style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}
                      title="Remove Line"
                    >
                      <Trash2 size={16} />
                    </button>
                  </div>
                </div>
              ))}
            </div>

            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
              <button
                type="button"
                onClick={handleAddStockRow}
                className="btn-finovo-secondary"
                style={{ padding: '8px 14px', fontSize: 12 }}
              >
                <Plus size={14} />
                <span>Add Another Line</span>
              </button>

              <div style={{ display: 'flex', gap: 10 }}>
                <button
                  type="button"
                  onClick={handlePreviewOpeningStock}
                  disabled={stockCommitLoading}
                  className="btn-finovo-secondary"
                  style={{ padding: '8px 16px', fontSize: 12 }}
                >
                  {stockCommitLoading ? <Loader2 className="animate-spin" size={14} /> : <CheckCircle2 size={14} />}
                  <span>Validate Preview</span>
                </button>
                <button
                  type="button"
                  onClick={handleCommitOpeningStock}
                  disabled={stockCommitLoading}
                  className="btn-finovo-primary"
                  style={{ padding: '8px 18px', fontSize: 12, backgroundColor: '#059669' }}
                >
                  {stockCommitLoading ? <Loader2 className="animate-spin" size={14} /> : <Send size={14} />}
                  <span>Commit Ledger Balances</span>
                </button>
              </div>
            </div>

            {/* Preview Validation Result Box */}
            {previewResult && (
              <div
                style={{
                  marginTop: 20,
                  padding: 16,
                  borderRadius: 10,
                  backgroundColor: '#ecfdf5',
                  border: '1px solid #a7f3d0',
                }}
              >
                <h4 style={{ margin: '0 0 6px 0', fontSize: 13, fontWeight: 700, color: '#065f46' }}>
                  Preview Validation Summary
                </h4>
                <div style={{ fontSize: 12, color: '#047857' }}>
                  Total Validated Lines: <strong>{previewResult.validated_lines || stockRows.length}</strong> &bull; Total Units:{' '}
                  <strong>{previewResult.total_quantity || stockRows.reduce((a, c) => a + Number(c.quantity || 0), 0)}</strong>
                </div>
              </div>
            )}
          </div>
        )}

        {/* TAB 5: AUDIT TRAIL */}
        {activeTab === 'audit_logs' && (
          <div>
            <div style={{ marginBottom: 20 }}>
              <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                Facility Audit Trail & Security Events
              </h3>
              <p style={{ margin: '2px 0 0 0', fontSize: 13, color: '#64748b' }}>
                Immutable event stream tracking creation, structural modifications, readiness checks, and status changes.
              </p>
            </div>

            {auditLogs.length === 0 ? (
              <div style={{ padding: 40, textAlign: 'center', color: '#64748b' }}>
                <History size={36} color="#cbd5e1" style={{ margin: '0 auto 12px auto' }} />
                <h4 style={{ margin: 0, fontSize: 15, color: '#334155' }}>No Audit Records Found</h4>
                <p style={{ margin: '4px 0 0 0', fontSize: 13 }}>
                  Events are automatically logged whenever changes occur.
                </p>
              </div>
            ) : (
              <div className="table-responsive-wrapper">
                <table className="finovo-table">
                  <thead>
                    <tr>
                      <th>Timestamp</th>
                      <th>Action Event</th>
                      <th>User</th>
                      <th>IP Address</th>
                      <th>Details</th>
                    </tr>
                  </thead>
                  <tbody>
                    {auditLogs.map((log) => (
                      <tr key={log.id}>
                        <td style={{ fontSize: 12, color: '#64748b', whiteSpace: 'nowrap' }}>
                          {new Date(log.created_at).toLocaleString()}
                        </td>
                        <td>
                          <span
                            style={{
                              fontSize: 11,
                              fontWeight: 700,
                              fontFamily: 'monospace',
                              padding: '2px 6px',
                              borderRadius: 4,
                              backgroundColor: '#f1f5f9',
                              color: '#1e293b',
                            }}
                          >
                            {log.action}
                          </span>
                        </td>
                        <td>
                          <span style={{ fontSize: 12, fontWeight: 600, color: '#334155' }}>
                            {log.user?.name || `User #${log.user_id || 'System'}`}
                          </span>
                        </td>
                        <td style={{ fontSize: 11, color: '#94a3b8' }}>{log.ip_address || 'Internal'}</td>
                        <td style={{ fontSize: 11, color: '#64748b', maxWidth: 300, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                          {log.changes ? JSON.stringify(log.changes) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>

      <ConfirmDialog
        open={deleteDialogOpen}
        title="Delete warehouse?"
        message={<>Delete <strong>{warehouse?.name}</strong> permanently, with its locations and staff assignments?</>}
        note="Only warehouses without stores, stock or purchase documents can be deleted. Use Deactivate for a warehouse that has been in use."
        confirmLabel="Delete Warehouse"
        loading={actionLoading}
        onConfirm={handleDeleteWarehouse}
        onCancel={() => setDeleteDialogOpen(false)}
      />
      {dialog}
    </div>
  );
};

export default WarehouseDetailPage;
