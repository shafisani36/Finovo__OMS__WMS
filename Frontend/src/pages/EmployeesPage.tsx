import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Eye, EyeOff, KeyRound, Loader2, Lock, Pencil, Plus, RotateCcw, Search, ShieldCheck, SlidersHorizontal, Trash2, UserCheck, UserX, Users, Wand2, X } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import CustomDropdown from '../components/common/CustomDropdown';
import StatusPill from '../components/common/StatusPill';
import { Alert } from '../components/common/Alert';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import { useAuth } from '../hooks/useAuth';
import { ApiError } from '../services/apiClient';
import { errorMessage } from '../utils/errors';
import omsService from '../services/omsService';
import type {
  EmployeeKpis,
  EmployeeModel,
  EmployeeOptions,
  EmployeePayload,
  EmployeeRole,
  EmployeeStatus,
  PermissionEffect,
  PermissionRow,
  PermissionSheet,
  WarehouseAccess,
} from '../services/omsService';
import { can } from '../types/auth';
import type { Permission } from '../types/auth';
import { formatDate, initials } from '../utils/format';
import { useDebouncedValue } from '../hooks/useDebouncedValue';

const PASSWORD_MIN = 12;

const STATUS_TABS: { value: '' | EmployeeStatus; label: string; kpi: keyof EmployeeKpis }[] = [
  { value: '', label: 'All Employees', kpi: 'total' },
  { value: 'active', label: 'Active', kpi: 'active' },
  { value: 'suspended', label: 'Suspended', kpi: 'suspended' },
];

type EmployeeForm = Required<EmployeePayload>;

const EMPTY_FORM: EmployeeForm = { name: '', email: '', role: 'operator', password: '', password_confirmation: '', warehouse_access: 'all', warehouse_ids: [] };

const WAREHOUSE_ACCESS: { value: WarehouseAccess; label: string; description: string }[] = [
  { value: 'all', label: 'All warehouses', description: 'Works in every warehouse of the company' },
  { value: 'assigned', label: 'Only selected', description: 'Sees stock and orders of the chosen warehouses only' },
];

const EFFECTS: { value: PermissionEffect; label: string }[] = [
  { value: 'inherit', label: 'Inherit' },
  { value: 'allow', label: 'Allow' },
  { value: 'deny', label: 'Deny' },
];

/** A random password that meets the policy, for the admin to hand over. */
const generatePassword = () => {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*?';
  const bytes = crypto.getRandomValues(new Uint32Array(16));
  return Array.from(bytes, (n) => chars[n % chars.length]).join('');
};

/** Password and confirmation fields with show/hide and a generator. */
const PasswordFields: React.FC<{
  password: string;
  confirmation: string;
  onChange: (password: string, confirmation: string) => void;
}> = ({ password, confirmation, onChange }) => {
  const [visible, setVisible] = useState(false);
  const type = visible ? 'text' : 'password';
  const mismatch = confirmation.length > 0 && confirmation !== password;

  return (
    <>
      <div>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <label className="form-label">Password *</label>
          <button
            type="button"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, fontWeight: 600, color: '#1e56ff', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
            onClick={() => {
              const generated = generatePassword();
              onChange(generated, generated);
              setVisible(true);
            }}
          >
            <Wand2 size={13} />
            Generate
          </button>
        </div>
        <div style={{ position: 'relative' }}>
          <input
            type={type}
            className="form-input"
            style={{ width: '100%', paddingRight: 40, boxSizing: 'border-box' }}
            value={password}
            onChange={(e) => onChange(e.target.value, confirmation)}
            minLength={PASSWORD_MIN}
            maxLength={128}
            autoComplete="new-password"
            required
          />
          <button
            type="button"
            onClick={() => setVisible((v) => !v)}
            aria-label={visible ? 'Hide password' : 'Show password'}
            style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', cursor: 'pointer', color: '#64748b', display: 'flex' }}
          >
            {visible ? <EyeOff size={16} /> : <Eye size={16} />}
          </button>
        </div>
        <span style={{ fontSize: 12, color: password.length > 0 && password.length < PASSWORD_MIN ? '#dc2626' : '#64748b' }}>
          At least {PASSWORD_MIN} characters. Share it with the employee privately.
        </span>
      </div>
      <div>
        <label className="form-label">Confirm password *</label>
        <input
          type={type}
          className="form-input"
          style={{ width: '100%', boxSizing: 'border-box', borderColor: mismatch ? '#dc2626' : undefined }}
          value={confirmation}
          onChange={(e) => onChange(password, e.target.value)}
          autoComplete="new-password"
          required
        />
        {mismatch && <span style={{ fontSize: 12, color: '#dc2626' }}>The passwords do not match.</span>}
      </div>
    </>
  );
};

/** Where the employee works: every warehouse, or only the ones picked (also adds them to those staff lists). */
const WarehouseAccessFields: React.FC<{
  warehouses: EmployeeOptions['warehouses'];
  access: WarehouseAccess;
  ids: number[];
  disabled: boolean;
  onChange: (access: WarehouseAccess, ids: number[]) => void;
}> = ({ warehouses, access, ids, disabled, onChange }) => (
  <div>
    <label className="form-label">Warehouse access *</label>
    <div className="employee-access-grid" role="radiogroup">
      {WAREHOUSE_ACCESS.map((option) => (
        <label key={option.value} className={`employee-access-option ${access === option.value ? 'is-selected' : ''} ${disabled ? 'is-disabled' : ''}`}>
          <input type="radio" name="warehouse_access" checked={access === option.value} disabled={disabled} onChange={() => onChange(option.value, ids)} />
          <span>
            <strong>{option.label}</strong>
            <small>{option.description}</small>
          </span>
        </label>
      ))}
    </div>
    {access === 'assigned' && (
      <div style={{ marginTop: 10 }}>
        <CustomDropdown
          multiple
          value={ids}
          onMultiChange={(next) => onChange(access, next as number[])}
          placeholder={warehouses.length ? 'Choose the warehouses they work in' : 'No warehouses yet'}
          disabled={disabled || warehouses.length === 0}
          options={warehouses.map((w) => ({ value: w.id, label: w.name, description: w.code ?? undefined }))}
        />
      </div>
    )}
    <span style={{ fontSize: 12, color: '#64748b' }}>
      {disabled ? 'You do not have permission to change warehouse assignments.' : 'Administrators always work in every warehouse.'}
    </span>
  </div>
);

/** Inherit / Allow / Deny switch for one action. */
const EffectSwitch: React.FC<{ value: PermissionEffect; disabled: boolean; label: string; onChange: (value: PermissionEffect) => void }> = ({
  value,
  disabled,
  label,
  onChange,
}) => (
  <div className="permission-switch" role="radiogroup" aria-label={label}>
    {EFFECTS.map((effect) => (
      <button
        key={effect.value}
        type="button"
        role="radio"
        aria-checked={value === effect.value}
        disabled={disabled}
        className={`permission-switch-btn is-${effect.value} ${value === effect.value ? 'is-active' : ''}`}
        onClick={() => onChange(effect.value)}
      >
        {effect.label}
      </button>
    ))}
  </div>
);

/** What a row ends up as: an allow or deny for this employee wins over the role's default. */
const isEffective = (row: PermissionRow, effect: PermissionEffect) => (effect === 'inherit' ? row.role_default : effect === 'allow');

/** Modal shell shared by the employee and password forms. */
const FormModal: React.FC<{
  title: string;
  subtitle: string;
  submitLabel: string;
  submitting: boolean;
  submitDisabled?: boolean;
  maxWidth?: number;
  error: string | null;
  onClose: () => void;
  onSubmit: () => void;
  footerStart?: React.ReactNode;
  children: React.ReactNode;
}> = ({ title, subtitle, submitLabel, submitting, submitDisabled = false, maxWidth = 520, error, onClose, onSubmit, footerStart, children }) => (
  <div className="modal-overlay" onClick={() => !submitting && onClose()}>
    <form
      className="procurement-modal"
      style={{ maxWidth }}
      onClick={(e) => e.stopPropagation()}
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit();
      }}
    >
      <div className="procurement-modal-header">
        <div>
          <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>{title}</h3>
          <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>{subtitle}</p>
        </div>
        <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close" disabled={submitting}>
          <X size={18} />
        </button>
      </div>

      <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {error && <Alert type="error" message={error} />}
        {children}
      </div>

      <div className="procurement-modal-footer" style={{ justifyContent: footerStart ? 'space-between' : 'flex-end' }}>
        {footerStart}
        <div style={{ display: 'flex', gap: 8 }}>
          <button type="button" onClick={onClose} className="btn-outline" disabled={submitting}>
            Cancel
          </button>
          <button type="submit" className="btn-primary" disabled={submitting || submitDisabled}>
            {submitting && <Loader2 size={16} className="animate-spin" />}
            <span>{submitLabel}</span>
          </button>
        </div>
      </div>
    </form>
  </div>
);

/**
 * One employee's permissions, grouped by section. Each action follows the role (Inherit) or is allowed or denied
 * for this employee alone. Only changed rows are sent, with the version the screen was loaded at, so a save
 * never overwrites changes someone else made in the meantime.
 */
const PermissionsModal: React.FC<{ employee: EmployeeModel; onClose: () => void; onSaved: (message: string) => void }> = ({
  employee,
  onClose,
  onSaved,
}) => {
  const [sheet, setSheet] = useState<PermissionSheet | null>(null);
  const [effects, setEffects] = useState<Record<string, PermissionEffect>>({});
  const [error, setError] = useState<string | null>(null);
  const [stale, setStale] = useState(false);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    try {
      setError(null);
      setStale(false);
      const res = await omsService.getEmployeePermissions(employee.id);
      setSheet(res.data);
      setEffects(
        Object.fromEntries(res.data.modules.flatMap((m) => m.permissions.map((row) => [row.key, row.override ?? 'inherit'])))
      );
    } catch (err) {
      setError(errorMessage(err, 'Failed to load the permissions.'));
    }
  }, [employee.id]);

  useEffect(() => {
    load();
  }, [load]);

  const rows = useMemo(() => sheet?.modules.flatMap((m) => m.permissions) ?? [], [sheet]);
  const changed = rows.filter((row) => (row.override ?? 'inherit') !== effects[row.key]);
  const custom = rows.filter((row) => effects[row.key] !== 'inherit' && row.can_change);

  const save = async () => {
    if (!sheet || changed.length === 0) return;
    try {
      setSaving(true);
      setError(null);
      const res = await omsService.updateEmployeePermissions(employee.id, {
        expected_version: sheet.version,
        overrides: changed.map((row) => ({ permission: row.key as Permission, effect: effects[row.key] })),
      });
      onSaved(res.message);
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) setStale(true);
      setError(errorMessage(err, 'Failed to save the permissions.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormModal
      title={`Permissions for ${employee.name}`}
      subtitle={`${employee.role_label}. Inherit follows the role; Allow or Deny applies to this employee only.`}
      submitLabel={changed.length ? `Save ${changed.length} change${changed.length === 1 ? '' : 's'}` : 'Save Permissions'}
      submitting={saving}
      submitDisabled={!sheet?.editable || changed.length === 0 || stale}
      maxWidth={720}
      error={error}
      onClose={onClose}
      onSubmit={save}
      footerStart={
        stale ? (
          <button type="button" className="btn-outline" onClick={load} disabled={saving}>
            <RotateCcw size={15} />
            <span>Reload</span>
          </button>
        ) : sheet?.editable && custom.length > 0 ? (
          <button
            type="button"
            className="btn-outline"
            disabled={saving}
            onClick={() => setEffects((prev) => ({ ...prev, ...Object.fromEntries(custom.map((row) => [row.key, 'inherit'])) }))}
          >
            <RotateCcw size={15} />
            <span>Reset to role defaults</span>
          </button>
        ) : undefined
      }
    >
      {!sheet && !error && (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 32 }}>
          <Loader2 size={22} className="animate-spin" color="#64748b" />
        </div>
      )}
      {sheet && !sheet.editable && (
        <Alert
          type="info"
          message={employee.role === 'admin' ? 'Administrators always have every permission. Change the role to limit what this employee can do.' : 'You cannot change your own permissions.'}
        />
      )}
      {sheet?.modules.map((module) => (
        <section key={module.module} className="permission-module">
          <h4 className="permission-module-title">{module.module}</h4>
          {module.permissions.map((row) => {
            const effect = effects[row.key] ?? 'inherit';
            const allowed = isEffective(row, effect);
            const locked = !sheet.editable || !row.can_change;
            return (
              <div key={row.key} className={`permission-row ${effect !== 'inherit' ? 'is-custom' : ''}`}>
                <div className="permission-row-text">
                  <span className="permission-row-label">
                    {row.label}
                    {locked && sheet.editable && (
                      <span title={row.sensitive ? 'Only an administrator can change this' : 'You can only hand out permissions you have'}>
                        <Lock size={12} color="#94a3b8" />
                      </span>
                    )}
                  </span>
                  <span className="permission-row-meta">
                    Role default: {row.role_default ? 'Allowed' : 'Not allowed'}
                    <span className={`permission-result ${allowed ? 'is-on' : 'is-off'}`}>{allowed ? 'Can do' : 'Cannot do'}</span>
                  </span>
                </div>
                <EffectSwitch
                  value={effect}
                  disabled={locked || saving}
                  label={row.label}
                  onChange={(value) => setEffects((prev) => ({ ...prev, [row.key]: value }))}
                />
              </div>
            );
          })}
        </section>
      ))}
    </FormModal>
  );
};

export const EmployeesPage: React.FC = () => {
  const { user } = useAuth();
  const { confirm, dialog } = useConfirmDialog();

  const [employees, setEmployees] = useState<EmployeeModel[]>([]);
  const [kpis, setKpis] = useState<EmployeeKpis>({ total: 0, active: 0, suspended: 0, admins: 0 });
  const [options, setOptions] = useState<EmployeeOptions>({ roles: [], warehouses: [] });
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<'' | EmployeeStatus>('');
  const [roleFilter, setRoleFilter] = useState<'' | EmployeeRole>('');
  const [search, setSearch] = useState('');
  // Live search: the list reloads 300 ms after typing stops.
  const appliedSearch = useDebouncedValue(search.trim());
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  // Add / edit form (editing === null while adding) and the password reset form
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<EmployeeModel | null>(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [resetting, setResetting] = useState<EmployeeModel | null>(null);
  const [permissionsFor, setPermissionsFor] = useState<EmployeeModel | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const isAdmin = user?.role === 'admin';
  const allowed = {
    create: can(user, 'employees.create'),
    update: can(user, 'employees.update'),
    assignRole: can(user, 'employees.assign_role'),
    suspend: can(user, 'employees.suspend'),
    resetPassword: can(user, 'employees.reset_password'),
    remove: can(user, 'employees.delete'),
    permissions: can(user, 'employees.manage_permissions'),
    warehouses: can(user, 'warehouse.employees.assign'),
  };
  // Only an administrator may give the admin role or act on an administrator's account.
  const roleOptions = options.roles.filter((role) => isAdmin || role.value !== 'admin');
  const canActOn = (employee: EmployeeModel) => isAdmin || employee.role !== 'admin';

  // A new search starts from the first page.
  useEffect(() => setPage(1), [appliedSearch]);

  const fetchEmployees = useCallback(async () => {
    try {
      setLoading(true);
      const res = await omsService.getEmployees({
        search: appliedSearch || undefined,
        role: roleFilter || undefined,
        status: statusFilter || undefined,
        page,
        per_page: perPage,
      });
      setEmployees(res.data);
      setKpis(res.kpis);
      setOptions(res.options);
      setMeta({ total: res.meta.total, last_page: res.meta.last_page });
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'Failed to load employees.') });
    } finally {
      setLoading(false);
    }
  }, [appliedSearch, roleFilter, statusFilter, page, perPage]);

  useEffect(() => {
    fetchEmployees();
  }, [fetchEmployees]);

  const openForm = (employee: EmployeeModel | null) => {
    setEditing(employee);
    setForm(
      employee
        ? {
            ...EMPTY_FORM,
            name: employee.name,
            email: employee.email,
            role: employee.role,
            warehouse_access: employee.warehouse_access,
            warehouse_ids: employee.warehouses.map((w) => w.id),
          }
        : EMPTY_FORM
    );
    setFormError(null);
    setFormOpen(true);
  };

  const openReset = (employee: EmployeeModel) => {
    setResetting(employee);
    setForm({ ...EMPTY_FORM, password: '', password_confirmation: '' });
    setFormError(null);
  };

  const closeForms = () => {
    if (submitting) return;
    setFormOpen(false);
    setResetting(null);
  };

  /** Runs a save from a form; the form closes on success and shows the error otherwise. */
  const submit = async (action: () => Promise<{ message: string }>, fallback: string) => {
    if (form.password && form.password !== form.password_confirmation) {
      setFormError('The passwords do not match.');
      return;
    }
    if (formOpen && form.role !== 'admin' && form.warehouse_access === 'assigned' && form.warehouse_ids.length === 0) {
      setFormError('Choose at least one warehouse, or give access to all warehouses.');
      return;
    }
    try {
      setSubmitting(true);
      setFormError(null);
      const res = await action();
      setFormOpen(false);
      setResetting(null);
      setBanner({ type: 'success', text: res.message });
      fetchEmployees();
    } catch (err) {
      setFormError(errorMessage(err, fallback));
    } finally {
      setSubmitting(false);
    }
  };

  /** Runs a row action after it is confirmed; the result shows in the page banner. */
  const rowAction = async (employee: EmployeeModel, action: () => Promise<{ message: string }>, fallback: string) => {
    try {
      setBusyId(employee.id);
      const res = await action();
      setBanner({ type: 'success', text: res.message });
      fetchEmployees();
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, fallback) });
    } finally {
      setBusyId(null);
    }
  };

  const toggleStatus = async (employee: EmployeeModel) => {
    const suspending = employee.status === 'active';
    const ok = await confirm({
      title: suspending ? `Suspend ${employee.name}?` : `Reactivate ${employee.name}?`,
      message: suspending ? (
        <>
          <strong>{employee.email}</strong> will be signed out of all devices and cannot sign in until reactivated.
        </>
      ) : (
        <>
          <strong>{employee.email}</strong> will be able to sign in again with their current password.
        </>
      ),
      note: suspending ? 'Their work and records stay as they are.' : undefined,
      confirmLabel: suspending ? 'Suspend' : 'Reactivate',
      tone: suspending ? 'danger' : 'primary',
    });
    if (!ok) return;
    rowAction(employee, () => omsService.setEmployeeStatus(employee.id, suspending ? 'suspended' : 'active'), 'Failed to update the account.');
  };

  const remove = async (employee: EmployeeModel) => {
    const ok = await confirm({
      title: `Remove ${employee.name}?`,
      message: (
        <>
          The account <strong>{employee.email}</strong> is deleted permanently.
        </>
      ),
      note: 'Employees who created purchase, receiving or stock count records cannot be removed. Suspend them instead.',
      confirmLabel: 'Remove',
      tone: 'danger',
    });
    if (!ok) return;
    rowAction(employee, () => omsService.deleteEmployee(employee.id), 'Failed to remove the employee.');
  };

  const columns: Column<EmployeeModel>[] = [
    {
      key: 'name',
      header: 'EMPLOYEE',
      width: '28%',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div
            style={{
              width: 34,
              height: 34,
              borderRadius: 10,
              backgroundColor: row.status === 'active' ? '#eef2ff' : '#f1f5f9',
              color: row.status === 'active' ? '#4338ca' : '#94a3b8',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 12,
              fontWeight: 700,
              flexShrink: 0,
            }}
          >
            {initials(row.name)}
          </div>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', display: 'flex', alignItems: 'center', gap: 6 }}>
              {row.name}
              {row.is_you && <span className="kpi-pill kpi-pill-blue">You</span>}
            </div>
            <div style={{ fontSize: 12, color: '#64748b', overflow: 'hidden', textOverflow: 'ellipsis' }}>{row.email}</div>
          </div>
        </div>
      ),
    },
    {
      key: 'role',
      header: 'ROLE & ACCESS',
      width: '18%',
      render: (row) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#334155', fontWeight: 500 }}>
            {row.role === 'admin' && <ShieldCheck size={14} color="#1e56ff" />}
            {row.role_label}
          </span>
          <span style={{ fontSize: 12, color: row.custom_permissions ? '#b45309' : '#64748b' }}>
            {row.role === 'admin'
              ? 'Full access'
              : row.custom_permissions
                ? `Role defaults + ${row.custom_permissions} custom`
                : 'Role defaults'}
          </span>
        </div>
      ),
    },
    {
      key: 'warehouses',
      header: 'WAREHOUSES',
      width: '16%',
      render: (row) =>
        row.role === 'admin' || row.warehouse_access === 'all' ? (
          <span style={{ fontSize: 12, color: '#334155' }}>All warehouses</span>
        ) : row.warehouses.length ? (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
            {row.warehouses.map((w) => (
              <span key={w.id} className="kpi-pill kpi-pill-blue">
                {w.name}
              </span>
            ))}
          </div>
        ) : (
          <span style={{ fontSize: 12, color: '#dc2626' }}>None assigned</span>
        ),
    },
    {
      key: 'status',
      header: 'STATUS',
      width: '10%',
      render: (row) => <StatusPill status={row.status} label={row.status === 'active' ? 'Active' : 'Suspended'} />,
    },
    {
      key: 'last_login_at',
      header: 'LAST SIGN-IN',
      width: '14%',
      render: (row) => <span style={{ fontSize: 12, color: '#64748b', whiteSpace: 'nowrap' }}>{row.last_login_at ? formatDate(row.last_login_at, true) : 'Never'}</span>,
    },
    {
      key: 'action',
      header: 'ACTION',
      width: '14%',
      align: 'right',
      render: (row) => (
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          {busyId === row.id ? (
            <Loader2 size={16} className="animate-spin" color="#64748b" />
          ) : (
            <>
              {allowed.update && canActOn(row) && (
                <button type="button" className="table-action-btn" onClick={() => openForm(row)} title="Edit details" aria-label="Edit details">
                  <Pencil size={15} />
                </button>
              )}
              {allowed.permissions && !row.is_you && row.role !== 'admin' && (
                <button type="button" className="table-action-btn" onClick={() => setPermissionsFor(row)} title="Permissions" aria-label="Permissions">
                  <SlidersHorizontal size={15} />
                </button>
              )}
              {!row.is_you && canActOn(row) && (
                <>
                  {allowed.resetPassword && (
                    <button type="button" className="table-action-btn" onClick={() => openReset(row)} title="Set a new password" aria-label="Set a new password">
                      <KeyRound size={15} />
                    </button>
                  )}
                  {allowed.suspend && (
                    <button
                      type="button"
                      className={`table-action-btn ${row.status === 'active' ? 'is-danger' : ''}`}
                      onClick={() => toggleStatus(row)}
                      title={row.status === 'active' ? 'Suspend' : 'Reactivate'}
                      aria-label={row.status === 'active' ? 'Suspend' : 'Reactivate'}
                    >
                      {row.status === 'active' ? <UserX size={15} /> : <UserCheck size={15} />}
                    </button>
                  )}
                  {allowed.remove && (
                    <button type="button" className="table-action-btn is-danger" onClick={() => remove(row)} title="Remove" aria-label="Remove">
                      <Trash2 size={15} />
                    </button>
                  )}
                </>
              )}
            </>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Employees</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Employees</h1>
            <span className="page-count-pill">{kpis.total}</span>
          </div>
          <p className="page-subtitle">Add staff accounts, set their role, permissions and warehouses, and suspend access when someone leaves.</p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <InfoCalloutCard
            title="Instant Lockout"
            description="Suspending an employee or resetting their password signs them out everywhere."
            icon={<ShieldCheck size={18} />}
          />
          {allowed.create && (
            <button type="button" className="btn-finovo-primary" onClick={() => openForm(null)}>
              <Plus size={16} />
              <span>Add Employee</span>
            </button>
          )}
        </div>
      </div>

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      <div className="kpi-grid">
        <MetricCard title="Total Employees" value={kpis.total} icon={<Users size={20} />} pillText="All accounts" />
        <MetricCard title="Active" value={kpis.active} icon={<UserCheck size={20} />} pillText="Can sign in" pillVariant="green" />
        <MetricCard title="Suspended" value={kpis.suspended} icon={<UserX size={20} />} pillText="Access blocked" pillVariant="amber" />
        <MetricCard title="Administrators" value={kpis.admins} icon={<ShieldCheck size={20} />} pillText="Full access" />
      </div>

      <div className="content-card">
        <div className="content-card-header" style={{ flexWrap: 'wrap', gap: 12 }}>
          <div className="procurement-tabs" role="tablist" style={{ margin: 0, borderBottom: 'none' }}>
            {STATUS_TABS.map((tab) => (
              <button
                key={tab.value}
                type="button"
                role="tab"
                aria-selected={statusFilter === tab.value}
                className={`procurement-tab-btn ${statusFilter === tab.value ? 'is-active' : ''}`}
                onClick={() => {
                  setStatusFilter(tab.value);
                  setPage(1);
                }}
              >
                <span>{tab.label}</span>
                <span className="procurement-tab-count">{kpis[tab.kpi]}</span>
              </button>
            ))}
          </div>
        </div>

        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, padding: '12px 24px', borderBottom: '1px solid #f1f5f9' }}>
          <form
            style={{ flex: '1 1 260px' }}
            onSubmit={(e) => {
              e.preventDefault();
            }}
          >
            <div className="topbar-search-input" style={{ width: '100%', boxSizing: 'border-box' }}>
              <Search size={16} color="#94a3b8" />
              <input type="text" placeholder="Search name or email..." value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>
          </form>
          <div style={{ flex: '0 1 220px' }}>
            <CustomDropdown
              value={roleFilter}
              onChange={(val) => {
                setRoleFilter(val as '' | EmployeeRole);
                setPage(1);
              }}
              options={[{ value: '', label: 'All roles' }, ...options.roles.map((r) => ({ value: r.value, label: r.label }))]}
            />
          </div>
        </div>

        <DataTable
          columns={columns}
          data={employees}
          keyField="id"
          isLoading={loading}
          emptyMessage={appliedSearch || roleFilter || statusFilter ? 'No employees match these filters.' : 'No employees yet.'}
          pagination={{
            currentPage: page,
            lastPage: meta.last_page,
            perPage,
            total: meta.total,
            onPageChange: setPage,
            onPerPageChange: (pp) => {
              setPerPage(pp);
              setPage(1);
            },
          }}
        />
      </div>

      {formOpen && (
        <FormModal
          title={editing ? `Edit ${editing.name}` : 'Add Employee'}
          subtitle={editing ? 'Update their details, role and warehouses.' : 'Create a sign-in for a new member of staff.'}
          submitLabel={editing ? 'Save Changes' : 'Add Employee'}
          submitting={submitting}
          error={formError}
          onClose={closeForms}
          onSubmit={() =>
            submit(
              () => {
                const payload: EmployeePayload = {
                  name: form.name,
                  email: form.email,
                  role: form.role,
                  warehouse_access: form.role === 'admin' ? 'all' : form.warehouse_access,
                  // With access to all warehouses the current staff assignments are kept as they are.
                  ...(form.role !== 'admin' && form.warehouse_access === 'assigned' ? { warehouse_ids: form.warehouse_ids } : {}),
                };
                return editing ? omsService.updateEmployee(editing.id, payload) : omsService.createEmployee({ ...payload, password: form.password, password_confirmation: form.password_confirmation });
              },
              editing ? 'Failed to save the changes.' : 'Failed to add the employee.'
            )
          }
        >
          <div>
            <label className="form-label">Full name *</label>
            <input
              type="text"
              className="form-input"
              style={{ width: '100%', boxSizing: 'border-box' }}
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="e.g. Ali Raza"
              maxLength={255}
              required
              autoFocus
            />
          </div>
          <div>
            <label className="form-label">Work email *</label>
            <input
              type="email"
              className="form-input"
              style={{ width: '100%', boxSizing: 'border-box' }}
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
              placeholder="name@company.com"
              maxLength={255}
              autoComplete="off"
              required
            />
          </div>
          <div>
            <label className="form-label">Role *</label>
            <CustomDropdown
              value={form.role}
              onChange={(val) => setForm({ ...form, role: val as EmployeeRole })}
              disabled={editing ? editing.is_you || !allowed.assignRole : false}
              options={roleOptions.map((r) => ({ value: r.value, label: r.label, description: r.description }))}
            />
            {editing?.is_you ? (
              <span style={{ fontSize: 12, color: '#64748b' }}>You cannot change your own role.</span>
            ) : (
              editing && !allowed.assignRole && <span style={{ fontSize: 12, color: '#64748b' }}>You do not have permission to change roles.</span>
            )}
          </div>
          {form.role === 'admin' ? (
            <Alert type="info" message="Administrators can do everything in every warehouse, including stores, settings and employees." />
          ) : (
            <WarehouseAccessFields
              warehouses={options.warehouses}
              access={form.warehouse_access}
              ids={form.warehouse_ids}
              disabled={!allowed.warehouses}
              onChange={(warehouse_access, warehouse_ids) => setForm({ ...form, warehouse_access, warehouse_ids })}
            />
          )}
          {!editing && (
            <PasswordFields
              password={form.password}
              confirmation={form.password_confirmation}
              onChange={(password, password_confirmation) => setForm({ ...form, password, password_confirmation })}
            />
          )}
        </FormModal>
      )}

      {resetting && (
        <FormModal
          title={`New password for ${resetting.name}`}
          subtitle="They are signed out of all devices and sign in with this password."
          submitLabel="Set Password"
          submitting={submitting}
          error={formError}
          onClose={closeForms}
          onSubmit={() =>
            submit(() => omsService.resetEmployeePassword(resetting.id, form.password, form.password_confirmation), 'Failed to change the password.')
          }
        >
          <PasswordFields
            password={form.password}
            confirmation={form.password_confirmation}
            onChange={(password, password_confirmation) => setForm({ ...form, password, password_confirmation })}
          />
        </FormModal>
      )}

      {permissionsFor && (
        <PermissionsModal
          employee={permissionsFor}
          onClose={() => setPermissionsFor(null)}
          onSaved={(message) => {
            setPermissionsFor(null);
            setBanner({ type: 'success', text: message });
            fetchEmployees();
          }}
        />
      )}

      {dialog}
    </div>
  );
};

export default EmployeesPage;
