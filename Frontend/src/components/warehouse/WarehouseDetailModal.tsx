import React, { useState, useEffect } from 'react';
import {
  X,
  Warehouse,
  ShieldCheck,
  Layers,
  Users,
  PackagePlus,
  History,
  CheckCircle2,
  AlertTriangle,
  Plus,
  Trash2,
  Loader2,
  Power,
  PowerOff,
} from 'lucide-react';
import omsService from '../../services/omsService';
import type {
  WarehouseModel,
  WarehouseLocationModel,
  WarehouseStaffModel,
  WarehouseAuditLogModel,
  WarehouseReadinessModel,
} from '../../services/omsService';
import WarehouseStatusBadge from './WarehouseStatusBadge';
import WarehouseReadinessCard from './WarehouseReadinessCard';
import CustomDropdown from '../common/CustomDropdown';

interface WarehouseDetailModalProps {
  warehouseId: number;
  isOpen: boolean;
  onClose: () => void;
  onRefreshList: () => void;
}

type TabType = 'overview' | 'layout' | 'staff' | 'opening_stock' | 'audit_logs';

export const WarehouseDetailModal: React.FC<WarehouseDetailModalProps> = ({
  warehouseId,
  isOpen,
  onClose,
  onRefreshList,
}) => {
  const [activeTab, setActiveTab] = useState<TabType>('overview');
  const [warehouse, setWarehouse] = useState<WarehouseModel | null>(null);
  const [readiness, setReadiness] = useState<WarehouseReadinessModel | null>(null);
  const [locations, setLocations] = useState<WarehouseLocationModel[]>([]);
  const [staff, setStaff] = useState<WarehouseStaffModel[]>([]);
  const [auditLogs, setAuditLogs] = useState<WarehouseAuditLogModel[]>([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Layout Modal State
  const [showAddLocation, setShowAddLocation] = useState(false);
  const [locCode, setLocCode] = useState('');
  const [locName, setLocName] = useState('');
  const [locType, setLocType] = useState<'zone' | 'aisle' | 'rack' | 'shelf' | 'bin'>('bin');
  const [locParentId, setLocParentId] = useState<string>('');
  const [locPicking, setLocPicking] = useState(true);
  const [locPutaway, setLocPutaway] = useState(true);

  // Staff Modal State
  const [showAssignStaff, setShowAssignStaff] = useState(false);
  const [staffUserId, setStaffUserId] = useState<number>(1);
  const [staffRole, setStaffRole] = useState('picker');

  // Controlled Opening Stock State
  const [stockRows, setStockRows] = useState<
    Array<{ sku: string; location_code: string; quantity: number; lot_number: string; expiry_date: string }>
  >([{ sku: '', location_code: '', quantity: 1, lot_number: '', expiry_date: '' }]);
  const [previewResult, setPreviewResult] = useState<any | null>(null);
  const [stockCommitLoading, setStockCommitLoading] = useState(false);

  const fetchData = async () => {
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
      setLocations(locRes.data);
      setStaff(staffRes.data);
      setAuditLogs(logRes.data);
    } catch (err: any) {
      console.error(err);
      setMessage({ type: 'error', text: err.message || 'Failed to load warehouse data.' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (isOpen && warehouseId) {
      fetchData();
    }
  }, [isOpen, warehouseId]);

  if (!isOpen) return null;

  // Activation / Deactivation / Closure Handlers
  const handleActivate = async () => {
    try {
      setActionLoading(true);
      setMessage(null);
      await omsService.activateWarehouse(warehouseId);
      setMessage({ type: 'success', text: 'Warehouse successfully activated and marked operational.' });
      fetchData();
      onRefreshList();
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
      onRefreshList();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to deactivate warehouse.' });
    } finally {
      setActionLoading(false);
    }
  };

  const handleCloseFacility = async () => {
    if (!confirm('Are you sure you want to begin controlled facility closure?')) return;
    try {
      setActionLoading(true);
      setMessage(null);
      await omsService.closeWarehouse(warehouseId, 'Controlled decommissioning initiated by admin.');
      setMessage({ type: 'success', text: 'Controlled warehouse closure initiated.' });
      fetchData();
      onRefreshList();
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Closure blocked: check for open orders or stock.' });
    } finally {
      setActionLoading(false);
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
        parent_id: locParentId ? Number(locParentId) : null,
        picking_eligible: locPicking,
        putaway_eligible: locPutaway,
      });

      setShowAddLocation(false);
      setLocCode('');
      setLocName('');
      fetchData();
    } catch (err: any) {
      alert(err.message || 'Failed to create location.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleDeleteLocation = async (locId: number) => {
    if (!confirm('Are you sure you want to delete this location?')) return;
    try {
      setActionLoading(true);
      await omsService.deleteWarehouseLocation(warehouseId, locId);
      fetchData();
    } catch (err: any) {
      alert(err.message || 'Cannot delete location: child locations or active stock present.');
    } finally {
      setActionLoading(false);
    }
  };

  // Staff Handlers
  const handleAssignStaff = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setActionLoading(true);
      await omsService.assignWarehouseStaff(warehouseId, {
        user_id: staffUserId,
        role: staffRole,
      });
      setShowAssignStaff(false);
      fetchData();
    } catch (err: any) {
      alert(err.message || 'Failed to assign staff.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleRemoveStaff = async (membershipId: number) => {
    if (!confirm('Remove this staff assignment?')) return;
    try {
      setActionLoading(true);
      await omsService.removeWarehouseStaff(warehouseId, membershipId);
      fetchData();
    } catch (err: any) {
      alert(err.message || 'Failed to remove staff.');
    } finally {
      setActionLoading(false);
    }
  };

  // Controlled Opening Stock Handlers
  const handleStockRowChange = (index: number, field: string, val: any) => {
    const updated = [...stockRows];
    updated[index] = { ...updated[index], [field]: val };
    setStockRows(updated);
  };

  const addStockRow = () => {
    setStockRows([...stockRows, { sku: '', location_code: '', quantity: 1, lot_number: '', expiry_date: '' }]);
  };

  const removeStockRow = (index: number) => {
    if (stockRows.length <= 1) return;
    setStockRows(stockRows.filter((_, i) => i !== index));
  };

  const handlePreviewOpeningStock = async () => {
    try {
      setStockCommitLoading(true);
      const res = await omsService.previewOpeningStock(warehouseId, stockRows);
      setPreviewResult(res.data);
    } catch (err: any) {
      alert(err.message || 'Validation error during preview.');
    } finally {
      setStockCommitLoading(false);
    }
  };

  const handleCommitOpeningStock = async () => {
    if (!confirm('Commit this opening inventory? This will create immutable double-entry ledger entries.')) return;
    try {
      setStockCommitLoading(true);
      await omsService.commitOpeningStock(warehouseId, {
        items: stockRows,
        idempotency_key: `OS-${warehouseId}-${Date.now()}`,
      });
      setMessage({ type: 'success', text: 'Opening inventory committed successfully into ledger.' });
      setPreviewResult(null);
      setStockRows([{ sku: '', location_code: '', quantity: 1, lot_number: '', expiry_date: '' }]);
      fetchData();
    } catch (err: any) {
      alert(err.message || 'Failed to commit opening inventory.');
    } finally {
      setStockCommitLoading(false);
    }
  };

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div
        className="modal-dialog"
        onClick={(e) => e.stopPropagation()}
        style={{ maxWidth: 960, width: '100%', maxHeight: '92vh', height: '92vh' }}
      >
        {/* Header */}
        <div
          style={{
            padding: '20px 24px',
            borderBottom: '1px solid #e2e8f0',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            backgroundColor: '#ffffff',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <div
              style={{
                width: 42,
                height: 42,
                borderRadius: 12,
                backgroundColor: '#eff6ff',
                color: '#1e56ff',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Warehouse size={22} />
            </div>
            <div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#0f172a' }}>
                  {warehouse?.name || 'Loading Warehouse...'}
                </h2>
                {warehouse && <WarehouseStatusBadge status={warehouse.status} size="md" />}
              </div>
              <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>
                Code: <strong>{warehouse?.code}</strong> &bull; Priority #{warehouse?.priority} &bull;{' '}
                {warehouse?.city || 'No City'}, {warehouse?.country || 'No Country'}
              </div>
            </div>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            {warehouse && warehouse.status !== 'active' && (
              <button
                onClick={handleActivate}
                disabled={actionLoading}
                className="btn-finovo-primary"
                style={{ padding: '7px 14px', fontSize: 12 }}
              >
                <Power size={14} />
                <span>Activate</span>
              </button>
            )}

            {warehouse && warehouse.status === 'active' && (
              <button
                onClick={handleDeactivate}
                disabled={actionLoading}
                className="btn-finovo-secondary"
                style={{ padding: '7px 14px', fontSize: 12, color: '#b45309' }}
              >
                <PowerOff size={14} />
                <span>Deactivate</span>
              </button>
            )}

            <button
              onClick={onClose}
              style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}
            >
              <X size={20} />
            </button>
          </div>
        </div>

        {/* Tab Navigation */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 4,
            padding: '0 24px',
            backgroundColor: '#f8fafc',
            borderBottom: '1px solid #e2e8f0',
          }}
        >
          {[
            { id: 'overview', label: 'Overview & Readiness', icon: ShieldCheck },
            { id: 'layout', label: `Locations (${locations.length})`, icon: Layers },
            { id: 'staff', label: `Staff (${staff.length})`, icon: Users },
            { id: 'opening_stock', label: 'Opening Stock', icon: PackagePlus },
            { id: 'audit_logs', label: 'Audit Trail', icon: History },
          ].map((tab) => {
            const Icon = tab.icon;
            const isActive = activeTab === tab.id;
            return (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id as TabType)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 8,
                  padding: '12px 16px',
                  fontSize: 13,
                  fontWeight: isActive ? 600 : 500,
                  color: isActive ? '#1e56ff' : '#64748b',
                  backgroundColor: 'transparent',
                  border: 'none',
                  borderBottom: `2px solid ${isActive ? '#1e56ff' : 'transparent'}`,
                  cursor: 'pointer',
                  transition: 'all 0.15s ease',
                }}
              >
                <Icon size={16} />
                <span>{tab.label}</span>
              </button>
            );
          })}
        </div>

        {/* Modal Body */}
        <div style={{ padding: '24px', overflowY: 'auto', flex: 1, backgroundColor: '#ffffff' }}>
          {message && (
            <div
              style={{
                padding: '12px 16px',
                borderRadius: 10,
                backgroundColor: message.type === 'success' ? '#ecfdf5' : '#fef2f2',
                border: `1px solid ${message.type === 'success' ? '#a7f3d0' : '#fecaca'}`,
                color: message.type === 'success' ? '#065f46' : '#991b1b',
                fontSize: 13,
                marginBottom: 20,
                display: 'flex',
                alignItems: 'center',
                gap: 8,
              }}
            >
              {message.type === 'success' ? <CheckCircle2 size={16} /> : <AlertTriangle size={16} />}
              <span>{message.text}</span>
            </div>
          )}

          {loading ? (
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: 260 }}>
              <Loader2 size={32} className="animate-spin" color="#1e56ff" />
            </div>
          ) : (
            <>
              {/* TAB 1: OVERVIEW & READINESS */}
              {activeTab === 'overview' && warehouse && readiness && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                  <WarehouseReadinessCard
                    readiness={readiness}
                    onActivate={handleActivate}
                    isActivating={actionLoading}
                  />

                  {/* Summary Cards */}
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 16 }}>
                    <div style={{ padding: 18, borderRadius: 14, border: '1px solid #e2e8f0', backgroundColor: '#f8fafc' }}>
                      <span style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase' }}>
                        Facility Profile
                      </span>
                      <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
                        <div><strong>Type:</strong> {warehouse.type?.replace(/_/g, ' ')}</div>
                        <div><strong>Default Hub:</strong> {warehouse.is_default ? 'Yes (Primary)' : 'No'}</div>
                        <div><strong>Timezone:</strong> {warehouse.timezone}</div>
                        <div><strong>Address:</strong> {warehouse.address_line1 || 'No address'}, {warehouse.city}, {warehouse.country}</div>
                      </div>
                    </div>

                    <div style={{ padding: 18, borderRadius: 14, border: '1px solid #e2e8f0', backgroundColor: '#f8fafc' }}>
                      <span style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase' }}>
                        Domain Policies
                      </span>
                      <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
                        <div>
                          <strong>Negative Inventory:</strong>{' '}
                          {warehouse.inventory_policies?.allow_negative_inventory ? (
                            <span style={{ color: '#ef4444', fontWeight: 600 }}>Allowed</span>
                          ) : (
                            <span style={{ color: '#10b981', fontWeight: 600 }}>Strictly Blocked</span>
                          )}
                        </div>
                        <div><strong>Reservation:</strong> {warehouse.inventory_policies?.reservation_policy || 'immediate_at_order'}</div>
                        <div><strong>Picking Strategy:</strong> {warehouse.inventory_policies?.picking_strategy || 'FIFO'}</div>
                        <div><strong>Cycle Counting:</strong> {warehouse.inventory_policies?.cycle_count_frequency || 'weekly'}</div>
                      </div>
                    </div>
                  </div>

                  {/* Controlled Closure Section */}
                  {warehouse.status !== 'closed' && (
                    <div
                      style={{
                        padding: 16,
                        borderRadius: 14,
                        border: '1px solid #fed7aa',
                        backgroundColor: '#fff7ed',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                      }}
                    >
                      <div>
                        <div style={{ fontSize: 13, fontWeight: 700, color: '#9a3412' }}>
                          Decommission / Close Warehouse
                        </div>
                        <div style={{ fontSize: 12, color: '#c2410c' }}>
                          Initiate a controlled wind-down. Requires zero active stock and all open orders reconciled.
                        </div>
                      </div>
                      <button
                        onClick={handleCloseFacility}
                        disabled={actionLoading}
                        style={{
                          backgroundColor: '#ffffff',
                          border: '1px solid #ea580c',
                          color: '#ea580c',
                          padding: '6px 12px',
                          borderRadius: 8,
                          fontSize: 12,
                          fontWeight: 600,
                          cursor: 'pointer',
                        }}
                      >
                        Begin Closure
                      </button>
                    </div>
                  )}
                </div>
              )}

              {/* TAB 2: PHYSICAL LAYOUT & LOCATIONS */}
              {activeTab === 'layout' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <div>
                      <h3 style={{ margin: 0, fontSize: 15, fontWeight: 700, color: '#0f172a' }}>
                        Physical Storage Locations
                      </h3>
                      <span style={{ fontSize: 12, color: '#64748b' }}>
                        Hierarchy: Zone &rarr; Aisle &rarr; Rack &rarr; Shelf &rarr; Bin
                      </span>
                    </div>

                    <button
                      onClick={() => setShowAddLocation(true)}
                      className="btn-finovo-primary"
                      style={{ padding: '6px 12px', fontSize: 12 }}
                    >
                      <Plus size={14} />
                      <span>Add Location</span>
                    </button>
                  </div>

                  {/* Add Location Sub-modal */}
                  {showAddLocation && (
                    <form
                      onSubmit={handleCreateLocation}
                      style={{
                        padding: 16,
                        borderRadius: 12,
                        backgroundColor: '#f8fafc',
                        border: '1px solid #cbd5e1',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 12,
                      }}
                    >
                      <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>Create Location Unit</div>
                      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12 }}>
                        <div>
                          <label style={{ display: 'block', fontSize: 11, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                            Location Code *
                          </label>
                          <input
                            type="text"
                            placeholder="e.g. A-01-R1-S1-B1"
                            value={locCode}
                            onChange={(e) => setLocCode(e.target.value)}
                            style={{ width: '100%', padding: '6px 10px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13 }}
                            required
                          />
                        </div>

                        <div>
                          <CustomDropdown
                            label="Location Type"
                            value={locType}
                            onChange={(val) => setLocType(val as any)}
                            options={[
                              { value: 'zone', label: 'Zone' },
                              { value: 'aisle', label: 'Aisle' },
                              { value: 'rack', label: 'Rack' },
                              { value: 'shelf', label: 'Shelf' },
                              { value: 'bin', label: 'Bin' },
                            ]}
                            size="sm"
                            searchable={false}
                          />
                        </div>

                        <div>
                          <CustomDropdown
                            label="Parent Location (Optional)"
                            placeholder="No Parent (Root Zone)"
                            value={locParentId}
                            onChange={(val) => setLocParentId(val ? String(val) : '')}
                            options={[
                              { value: '', label: 'No Parent (Root Zone)' },
                              ...locations.map((l) => ({
                                value: String(l.id),
                                label: `${l.code} (${l.location_type})`,
                              })),
                            ]}
                            size="sm"
                            clearable
                          />
                        </div>
                      </div>

                      <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#334155', cursor: 'pointer' }}>
                          <input
                            type="checkbox"
                            checked={locPicking}
                            onChange={(e) => setLocPicking(e.target.checked)}
                          />
                          <span>Picking Eligible</span>
                        </label>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#334155', cursor: 'pointer' }}>
                          <input
                            type="checkbox"
                            checked={locPutaway}
                            onChange={(e) => setLocPutaway(e.target.checked)}
                          />
                          <span>Putaway Eligible</span>
                        </label>

                        <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                          <button
                            type="button"
                            onClick={() => setShowAddLocation(false)}
                            className="btn-finovo-secondary"
                            style={{ padding: '4px 10px', fontSize: 12 }}
                          >
                            Cancel
                          </button>
                          <button
                            type="submit"
                            disabled={actionLoading}
                            className="btn-finovo-primary"
                            style={{ padding: '4px 12px', fontSize: 12 }}
                          >
                            Save Location
                          </button>
                        </div>
                      </div>
                    </form>
                  )}

                  {/* Locations Table */}
                  <div style={{ border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                      <thead>
                        <tr style={{ backgroundColor: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left' }}>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Code</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Type</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Parent</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Picking</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Putaway</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569', textAlign: 'right' }}>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {locations.length === 0 ? (
                          <tr>
                            <td colSpan={6} style={{ padding: 24, textAlign: 'center', color: '#94a3b8' }}>
                              No storage locations configured. Create bins or generate starter layout.
                            </td>
                          </tr>
                        ) : (
                          locations.map((loc) => (
                            <tr key={loc.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                              <td style={{ padding: '10px 14px', fontWeight: 600, color: '#0f172a' }}>{loc.code}</td>
                              <td style={{ padding: '10px 14px' }}>
                                <span
                                  style={{
                                    padding: '2px 8px',
                                    borderRadius: 6,
                                    fontSize: 11,
                                    fontWeight: 600,
                                    textTransform: 'uppercase',
                                    backgroundColor: '#eff6ff',
                                    color: '#1e56ff',
                                  }}
                                >
                                  {loc.location_type}
                                </span>
                              </td>
                              <td style={{ padding: '10px 14px', color: '#64748b' }}>
                                {loc.parent ? loc.parent.code : '—'}
                              </td>
                              <td style={{ padding: '10px 14px' }}>
                                {loc.picking_eligible ? (
                                  <span style={{ color: '#059669', fontWeight: 600 }}>&check; Eligible</span>
                                ) : (
                                  <span style={{ color: '#94a3b8' }}>&times; No</span>
                                )}
                              </td>
                              <td style={{ padding: '10px 14px' }}>
                                {loc.putaway_eligible ? (
                                  <span style={{ color: '#059669', fontWeight: 600 }}>&check; Eligible</span>
                                ) : (
                                  <span style={{ color: '#94a3b8' }}>&times; No</span>
                                )}
                              </td>
                              <td style={{ padding: '10px 14px', textAlign: 'right' }}>
                                <button
                                  onClick={() => handleDeleteLocation(loc.id)}
                                  disabled={actionLoading}
                                  style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#dc2626' }}
                                  title="Delete Location"
                                >
                                  <Trash2 size={14} />
                                </button>
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {/* TAB 3: STAFF & ACCESS */}
              {activeTab === 'staff' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <div>
                      <h3 style={{ margin: 0, fontSize: 15, fontWeight: 700, color: '#0f172a' }}>
                        Warehouse Personnel & Roles
                      </h3>
                      <span style={{ fontSize: 12, color: '#64748b' }}>
                        Operators, pickers, and supervisors authorized for this physical center
                      </span>
                    </div>

                    <button
                      onClick={() => setShowAssignStaff(true)}
                      className="btn-finovo-primary"
                      style={{ padding: '6px 12px', fontSize: 12 }}
                    >
                      <Plus size={14} />
                      <span>Assign Staff</span>
                    </button>
                  </div>

                  {showAssignStaff && (
                    <form
                      onSubmit={handleAssignStaff}
                      style={{
                        padding: 16,
                        borderRadius: 12,
                        backgroundColor: '#f8fafc',
                        border: '1px solid #cbd5e1',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 12,
                      }}
                    >
                      <div style={{ flex: 1 }}>
                        <label style={{ display: 'block', fontSize: 11, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                          User ID / Operator
                        </label>
                        <input
                          type="number"
                          value={staffUserId}
                          onChange={(e) => setStaffUserId(Number(e.target.value))}
                          style={{ width: '100%', padding: '6px 10px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13 }}
                          required
                        />
                      </div>

                      <div style={{ flex: 1 }}>
                        <CustomDropdown
                          label="Assigned Role"
                          value={staffRole}
                          onChange={(val) => setStaffRole(val)}
                          options={[
                            { value: 'warehouse_manager', label: 'Warehouse Manager' },
                            { value: 'supervisor', label: 'Supervisor' },
                            { value: 'picker', label: 'Picker' },
                            { value: 'packer', label: 'Packer' },
                            { value: 'receiver', label: 'Inbound Receiver' },
                            { value: 'inventory_controller', label: 'Inventory Controller' },
                            { value: 'qa_inspector', label: 'QA Inspector' },
                          ]}
                          size="sm"
                          searchable={false}
                        />
                      </div>

                      <div style={{ display: 'flex', gap: 8, alignSelf: 'flex-end' }}>
                        <button
                          type="button"
                          onClick={() => setShowAssignStaff(false)}
                          className="btn-finovo-secondary"
                          style={{ padding: '6px 10px', fontSize: 12 }}
                        >
                          Cancel
                        </button>
                        <button
                          type="submit"
                          disabled={actionLoading}
                          className="btn-finovo-primary"
                          style={{ padding: '6px 12px', fontSize: 12 }}
                        >
                          Assign
                        </button>
                      </div>
                    </form>
                  )}

                  <div style={{ border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                      <thead>
                        <tr style={{ backgroundColor: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left' }}>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Name / Email</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Warehouse Role</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Status</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569', textAlign: 'right' }}>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {staff.length === 0 ? (
                          <tr>
                            <td colSpan={4} style={{ padding: 24, textAlign: 'center', color: '#94a3b8' }}>
                              No staff assigned yet. Assign operators or warehouse managers.
                            </td>
                          </tr>
                        ) : (
                          staff.map((s) => (
                            <tr key={s.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                              <td style={{ padding: '10px 14px' }}>
                                <div style={{ fontWeight: 600, color: '#0f172a' }}>{s.user?.name || `User #${s.user_id}`}</div>
                                <div style={{ fontSize: 11, color: '#94a3b8' }}>{s.user?.email}</div>
                              </td>
                              <td style={{ padding: '10px 14px' }}>
                                <span
                                  style={{
                                    padding: '2px 8px',
                                    borderRadius: 6,
                                    fontSize: 11,
                                    fontWeight: 600,
                                    textTransform: 'capitalize',
                                    backgroundColor: '#eff6ff',
                                    color: '#1e56ff',
                                  }}
                                >
                                  {s.role.replace(/_/g, ' ')}
                                </span>
                              </td>
                              <td style={{ padding: '10px 14px' }}>
                                <span style={{ color: '#059669', fontSize: 12, fontWeight: 600 }}>Active</span>
                              </td>
                              <td style={{ padding: '10px 14px', textAlign: 'right' }}>
                                <button
                                  onClick={() => handleRemoveStaff(s.id)}
                                  disabled={actionLoading}
                                  style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#dc2626' }}
                                  title="Remove Staff"
                                >
                                  <Trash2 size={14} />
                                </button>
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {/* TAB 4: CONTROLLED OPENING STOCK */}
              {activeTab === 'opening_stock' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                  <div
                    style={{
                      padding: 14,
                      borderRadius: 12,
                      backgroundColor: '#eff6ff',
                      border: '1px solid #bfdbfe',
                      fontSize: 13,
                      color: '#1e3a8a',
                    }}
                  >
                    <strong>Controlled Opening Stock Protocol</strong>: Initialize physical stock through preview and atomic transactional commit. Every committed unit creates an immutable double-entry ledger entry.
                  </div>

                  <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    {stockRows.map((row, idx) => (
                      <div
                        key={idx}
                        style={{
                          display: 'grid',
                          gridTemplateColumns: '1.5fr 1.2fr 1fr 1.2fr 1.2fr auto',
                          gap: 10,
                          alignItems: 'center',
                          padding: 10,
                          borderRadius: 8,
                          backgroundColor: '#f8fafc',
                          border: '1px solid #e2e8f0',
                        }}
                      >
                        <div>
                          <label style={{ display: 'block', fontSize: 10, fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>
                            SKU *
                          </label>
                          <input
                            type="text"
                            placeholder="e.g. KB-ERG-BLK"
                            value={row.sku}
                            onChange={(e) => handleStockRowChange(idx, 'sku', e.target.value)}
                            style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12 }}
                            required
                          />
                        </div>

                        <div>
                          <label style={{ display: 'block', fontSize: 10, fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>
                            Location Code *
                          </label>
                          <input
                            type="text"
                            placeholder="e.g. BIN-A1"
                            value={row.location_code}
                            onChange={(e) => handleStockRowChange(idx, 'location_code', e.target.value)}
                            style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12 }}
                            required
                          />
                        </div>

                        <div>
                          <label style={{ display: 'block', fontSize: 10, fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>
                            Quantity *
                          </label>
                          <input
                            type="number"
                            min="1"
                            value={row.quantity}
                            onChange={(e) => handleStockRowChange(idx, 'quantity', Number(e.target.value))}
                            style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12 }}
                            required
                          />
                        </div>

                        <div>
                          <label style={{ display: 'block', fontSize: 10, fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>
                            Lot / Batch #
                          </label>
                          <input
                            type="text"
                            placeholder="LOT-2026-001"
                            value={row.lot_number}
                            onChange={(e) => handleStockRowChange(idx, 'lot_number', e.target.value)}
                            style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12 }}
                          />
                        </div>

                        <div>
                          <label style={{ display: 'block', fontSize: 10, fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>
                            Expiry Date
                          </label>
                          <input
                            type="date"
                            value={row.expiry_date}
                            onChange={(e) => handleStockRowChange(idx, 'expiry_date', e.target.value)}
                            style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12 }}
                          />
                        </div>

                        <button
                          type="button"
                          onClick={() => removeStockRow(idx)}
                          style={{
                            marginTop: 14,
                            background: 'none',
                            border: 'none',
                            cursor: 'pointer',
                            color: '#dc2626',
                          }}
                        >
                          <Trash2 size={16} />
                        </button>
                      </div>
                    ))}

                    <button
                      type="button"
                      onClick={addStockRow}
                      className="btn-finovo-secondary"
                      style={{ alignSelf: 'flex-start', padding: '6px 12px', fontSize: 12 }}
                    >
                      <Plus size={14} />
                      <span>Add Stock Line</span>
                    </button>
                  </div>

                  {/* Preview Result Breakdown */}
                  {previewResult && (
                    <div
                      style={{
                        padding: 16,
                        borderRadius: 12,
                        backgroundColor: previewResult.can_commit ? '#ecfdf5' : '#fef2f2',
                        border: `1px solid ${previewResult.can_commit ? '#a7f3d0' : '#fecaca'}`,
                      }}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                        <div style={{ fontSize: 14, fontWeight: 700, color: previewResult.can_commit ? '#065f46' : '#991b1b' }}>
                          Validation Result: {previewResult.can_commit ? 'Verified & Valid' : 'Validation Errors Detected'}
                        </div>
                        <div style={{ fontSize: 12, fontWeight: 600, color: '#475569' }}>
                          Total Lines: {previewResult.valid_count} &bull; Total Units: {previewResult.total_units}
                        </div>
                      </div>

                      {previewResult.errors && previewResult.errors.length > 0 && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginTop: 8 }}>
                          {previewResult.errors.map((errItem: any, i: number) => (
                            <div key={i} style={{ fontSize: 12, color: '#b91c1c' }}>
                              Row #{errItem.index + 1} ({errItem.sku}): {errItem.errors.join(', ')}
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  )}

                  {/* Action buttons */}
                  <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end', marginTop: 10 }}>
                    <button
                      type="button"
                      onClick={handlePreviewOpeningStock}
                      disabled={stockCommitLoading}
                      className="btn-finovo-secondary"
                    >
                      {stockCommitLoading && <Loader2 size={14} className="animate-spin" />}
                      <span>Preview & Validate Lines</span>
                    </button>

                    <button
                      type="button"
                      onClick={handleCommitOpeningStock}
                      disabled={stockCommitLoading || (previewResult && !previewResult.can_commit)}
                      className="btn-finovo-primary"
                    >
                      {stockCommitLoading && <Loader2 size={14} className="animate-spin" />}
                      <span>Commit to Ledger</span>
                    </button>
                  </div>
                </div>
              )}

              {/* TAB 5: AUDIT TRAIL */}
              {activeTab === 'audit_logs' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div style={{ fontSize: 14, fontWeight: 700, color: '#0f172a' }}>
                    Immutable Facility Audit History
                  </div>

                  <div style={{ border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                      <thead>
                        <tr style={{ backgroundColor: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left' }}>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Timestamp</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>User</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Action</th>
                          <th style={{ padding: '10px 14px', fontWeight: 600, color: '#475569' }}>Changes Summary</th>
                        </tr>
                      </thead>
                      <tbody>
                        {auditLogs.length === 0 ? (
                          <tr>
                            <td colSpan={4} style={{ padding: 24, textAlign: 'center', color: '#94a3b8' }}>
                              No audit records recorded yet.
                            </td>
                          </tr>
                        ) : (
                          auditLogs.map((log) => (
                            <tr key={log.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                              <td style={{ padding: '10px 14px', fontSize: 12, color: '#64748b' }}>
                                {new Date(log.created_at).toLocaleString()}
                              </td>
                              <td style={{ padding: '10px 14px', fontWeight: 600, color: '#0f172a' }}>
                                {log.user?.name || `User #${log.user_id || 'System'}`}
                              </td>
                              <td style={{ padding: '10px 14px' }}>
                                <span
                                  style={{
                                    padding: '2px 8px',
                                    borderRadius: 6,
                                    fontSize: 11,
                                    fontWeight: 600,
                                    textTransform: 'uppercase',
                                    backgroundColor: '#eff6ff',
                                    color: '#1e56ff',
                                  }}
                                >
                                  {log.action}
                                </span>
                              </td>
                              <td style={{ padding: '10px 14px', fontSize: 12, color: '#475569' }}>
                                {log.changes?.after ? (
                                  <code>{Object.keys(log.changes.after).join(', ')}</code>
                                ) : (
                                  '—'
                                )}
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default WarehouseDetailModal;
