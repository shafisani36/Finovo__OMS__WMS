import React, { useCallback, useEffect, useState } from 'react';
import { Loader2, Plus, Users, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import { useConfirmDialog } from '../common/ConfirmDialog';
import omsService from '../../services/omsService';
import type { WarehouseStaffModel } from '../../services/omsService';
import { formatDate, initials } from '../../utils/format';
import { errorMessage } from '../../utils/errors';

/** Roles a person can have inside one warehouse (the backend accepts the same list). */
const WAREHOUSE_ROLES = [
  { value: 'manager', label: 'Manager', description: 'Runs the warehouse' },
  { value: 'supervisor', label: 'Supervisor', description: 'Leads a shift' },
  { value: 'operator', label: 'Operator', description: 'General warehouse work' },
  { value: 'picker', label: 'Picker', description: 'Picks orders' },
  { value: 'packer', label: 'Packer', description: 'Packs and hands over parcels' },
  { value: 'receiver', label: 'Receiver', description: 'Receives incoming stock' },
];

type EligibleUser = { id: number; name: string; email: string; role: string };

interface Props {
  warehouseId: number;
  canAssign: boolean;
  onChanged: () => void;
}

/** Staff tab: who works in this warehouse, in which role, and who assigned them. */
export const WarehouseStaffTab: React.FC<Props> = ({ warehouseId, canAssign, onChanged }) => {
  const { confirm, dialog } = useConfirmDialog();
  const [staff, setStaff] = useState<WarehouseStaffModel[] | null>(null);
  const [eligible, setEligible] = useState<EligibleUser[]>([]);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [userId, setUserId] = useState<number | ''>('');
  const [role, setRole] = useState('picker');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await omsService.getWarehouseStaff(warehouseId);
      setStaff(res.data.assigned_staff);
      setEligible(res.data.eligible_users);
    } catch (err) {
      setStaff([]);
      setMessage({ type: 'error', text: errorMessage(err, 'Failed to load the staff.') });
    }
  }, [warehouseId]);

  useEffect(() => {
    load();
  }, [load]);

  const openForm = () => {
    setUserId(eligible[0]?.id ?? '');
    setRole('picker');
    setFormError(null);
    setFormOpen(true);
  };

  const assign = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!userId) return;
    try {
      setBusy(true);
      setFormError(null);
      await omsService.assignWarehouseStaff(warehouseId, { user_id: userId, role });
      setFormOpen(false);
      setMessage({ type: 'success', text: `${eligible.find((u) => u.id === userId)?.name ?? 'Employee'} was assigned.` });
      load();
      onChanged();
    } catch (err) {
      setFormError(errorMessage(err, 'Failed to assign the employee.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (member: WarehouseStaffModel) => {
    const name = member.user?.name ?? 'this employee';
    const ok = await confirm({
      title: `Remove ${name}?`,
      message: 'They stop working in this warehouse from their next action. The assignment stays in the activity log.',
      confirmLabel: 'Remove',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      setBusy(true);
      await omsService.removeWarehouseStaff(warehouseId, member.id);
      setMessage({ type: 'success', text: `${name} was removed from this warehouse.` });
      load();
      onChanged();
    } catch (err) {
      setMessage({ type: 'error', text: errorMessage(err, 'Failed to remove the employee.') });
    } finally {
      setBusy(false);
    }
  };

  if (staff === null) {
    return (
      <div className="wh-loading">
        <Loader2 className="animate-spin" size={28} />
      </div>
    );
  }

  return (
    <div className="wh-stack">
      {message && <Alert type={message.type} message={message.text} />}

      <section className="wh-card" aria-labelledby="wh-staff-title">
        <div className="wh-card-head">
          <div>
            <h2 id="wh-staff-title" className="wh-card-title">Warehouse Staff</h2>
            <p className="wh-card-subtitle">People who work here. Removed assignments are kept in the activity log.</p>
          </div>
          {canAssign && (
            <button type="button" className="btn-finovo-primary" onClick={openForm} disabled={busy || eligible.length === 0} title={eligible.length === 0 ? 'Everyone is already assigned' : undefined}>
              <Plus size={14} />
              <span>Assign Staff</span>
            </button>
          )}
        </div>

        {staff.length === 0 ? (
          <div className="wh-empty">
            <Users size={32} color="#cbd5e1" />
            <h4>No staff assigned</h4>
            <p>Assign at least one manager or operator; it is needed to activate the warehouse.</p>
          </div>
        ) : (
          <div className="wh-table-wrap">
            <table className="wh-table">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Role here</th>
                  <th>Account role</th>
                  <th>Assigned by</th>
                  <th>Since</th>
                  {canAssign && <th style={{ textAlign: 'right' }}>Action</th>}
                </tr>
              </thead>
              <tbody>
                {staff.map((member) => (
                  <tr key={member.id}>
                    <td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <span
                          aria-hidden="true"
                          style={{ width: 32, height: 32, borderRadius: 10, background: '#eef2ff', color: '#4338ca', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700 }}
                        >
                          {initials(member.user?.name ?? '?')}
                        </span>
                        <span>
                          <strong style={{ display: 'block' }}>{member.user?.name ?? `Employee #${member.user_id}`}</strong>
                          <span style={{ fontSize: 12, color: '#64748b' }}>{member.user?.email}</span>
                        </span>
                      </div>
                    </td>
                    <td>
                      <span className="wh-badge" style={{ textTransform: 'capitalize' }}>{member.role}</span>
                    </td>
                    <td style={{ color: '#475569' }}>{member.user?.role_label ?? '—'}</td>
                    <td style={{ color: '#475569' }}>{member.assigned_by ?? '—'}</td>
                    <td style={{ color: '#475569' }}>
                      {formatDate(member.assigned_at)}
                      {member.expires_at && <span style={{ display: 'block', fontSize: 11, color: '#b45309' }}>Until {formatDate(member.expires_at)}</span>}
                    </td>
                    {canAssign && (
                      <td style={{ textAlign: 'right' }}>
                        <button type="button" className="btn-finovo-secondary" style={{ padding: '6px 12px', fontSize: 12, color: '#b91c1c' }} onClick={() => remove(member)} disabled={busy}>
                          Remove
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {formOpen && (
        <div className="modal-overlay" onClick={() => !busy && setFormOpen(false)}>
          <form className="procurement-modal" style={{ maxWidth: 480, overflow: 'visible' }} onClick={(e) => e.stopPropagation()} onSubmit={assign}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Assign Staff</h3>
                <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>Give an employee work in this warehouse.</p>
              </div>
              <button type="button" className="topbar-icon-button" onClick={() => setFormOpen(false)} aria-label="Close" disabled={busy}>
                <X size={18} />
              </button>
            </div>
            <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 16, overflow: 'visible' }}>
              {formError && <Alert type="error" message={formError} />}
              <div>
                <label className="form-label">Employee *</label>
                <CustomDropdown
                  value={userId}
                  onChange={(val) => setUserId(Number(val))}
                  searchable
                  options={eligible.map((u) => ({ value: u.id, label: u.name, description: u.email }))}
                />
              </div>
              <div>
                <label className="form-label">Role in this warehouse *</label>
                <CustomDropdown value={role} onChange={(val) => setRole(String(val))} options={WAREHOUSE_ROLES} searchable={false} />
              </div>
            </div>
            <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="button" className="btn-outline" onClick={() => setFormOpen(false)} disabled={busy}>
                  Cancel
                </button>
                <button type="submit" className="btn-primary" disabled={busy || !userId}>
                  {busy && <Loader2 size={16} className="animate-spin" />}
                  <span>Assign</span>
                </button>
              </div>
            </div>
          </form>
        </div>
      )}

      {dialog}
    </div>
  );
};

export default WarehouseStaffTab;
