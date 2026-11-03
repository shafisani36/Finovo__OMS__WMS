import React, { useCallback, useEffect, useState } from 'react';
import { CalendarClock, Loader2, Trash2 } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { CycleCountSchedule, WarehouseModel } from '../../services/omsService';
import { formatDate } from '../../utils/format';
import { errorMessage } from '../../utils/errors';

const SCOPES = [
  { value: 'full', label: 'Whole warehouse' },
  { value: 'zone', label: 'One zone (bin code prefix, e.g. A01)' },
  { value: 'location', label: 'One bin' },
];

const EVERY = [1, 7, 14, 30, 90].map((days) => ({ value: days, label: days === 1 ? 'Every day' : `Every ${days} days` }));

const today = () => new Date().toISOString().slice(0, 10);

/**
 * Counts that start by themselves: a zone every week, the whole warehouse every month and so on, so stock is
 * checked without closing the warehouse.
 */
export const CycleCountSchedules: React.FC<{ warehouses: WarehouseModel[]; warehouseId?: number; canManage: boolean }> = ({ warehouses, warehouseId, canManage }) => {
  const [schedules, setSchedules] = useState<CycleCountSchedule[]>([]);
  const [form, setForm] = useState({ warehouse_id: warehouseId ?? warehouses[0]?.id ?? 0, title: '', scope_type: 'zone' as CycleCountSchedule['scope_type'], scope_value: '', every_days: 7, next_run_on: today() });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const load = useCallback(() => {
    omsService
      .getCycleCountSchedules(warehouseId)
      .then(setSchedules)
      .catch((err) => setMessage({ type: 'error', text: errorMessage(err, 'The schedules could not be loaded.') }));
  }, [warehouseId]);

  useEffect(() => {
    load();
  }, [load]);

  const run = async (action: () => Promise<{ message: string }>) => {
    try {
      setBusy(true);
      setMessage({ type: 'success', text: (await action()).message });
      load();
    } catch (err) {
      setMessage({ type: 'error', text: errorMessage(err, 'The schedule could not be saved.') });
    } finally {
      setBusy(false);
    }
  };

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    run(async () => {
      const res = await omsService.createCycleCountSchedule({ ...form, scope_value: form.scope_type === 'full' ? null : form.scope_value.trim() });
      setForm((current) => ({ ...current, title: '', scope_value: '' }));
      return res;
    });
  };

  return (
    <div style={{ marginTop: 28 }}>
      <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 12 }}>
        <div>
          <h3 className="tab-content-header-title">Scheduled Counts</h3>
          <p className="tab-content-header-subtitle">These counts start by themselves at 05:00 on their date, then again after their interval.</p>
        </div>
      </div>

      {message && <Alert type={message.type} message={message.text} />}

      {canManage && (
        <form className="count-schedule-form" onSubmit={add}>
          <CustomDropdown value={form.warehouse_id} onChange={(val) => setForm({ ...form, warehouse_id: Number(val) })} options={warehouses.map((w) => ({ value: w.id, label: w.name }))} searchable={false} size="sm" />
          <input className="form-input" placeholder="Name, e.g. Weekly zone A01" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} maxLength={150} required />
          <CustomDropdown value={form.scope_type} onChange={(val) => setForm({ ...form, scope_type: val as CycleCountSchedule['scope_type'] })} options={SCOPES} searchable={false} size="sm" />
          {form.scope_type !== 'full' && (
            <input className="form-input" placeholder={form.scope_type === 'zone' ? 'Zone code, e.g. A01' : 'Bin code'} value={form.scope_value} onChange={(e) => setForm({ ...form, scope_value: e.target.value })} maxLength={100} required />
          )}
          <CustomDropdown value={form.every_days} onChange={(val) => setForm({ ...form, every_days: Number(val) })} options={EVERY} searchable={false} size="sm" />
          <input className="form-input" type="date" min={today()} value={form.next_run_on} onChange={(e) => setForm({ ...form, next_run_on: e.target.value })} required />
          <button type="submit" className="btn-primary" disabled={busy || !form.warehouse_id}>
            {busy ? <Loader2 size={16} className="animate-spin" /> : <CalendarClock size={16} />}
            <span>Schedule</span>
          </button>
        </form>
      )}

      <div className="table-responsive-wrapper">
        <table className="finovo-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Warehouse</th>
              <th>Area</th>
              <th>Repeats</th>
              <th>Next Count</th>
              {canManage && <th />}
            </tr>
          </thead>
          <tbody>
            {schedules.length === 0 ? (
              <tr>
                <td colSpan={canManage ? 6 : 5} style={{ textAlign: 'center', padding: '24px 16px', color: '#64748b' }}>
                  No counts are scheduled yet.
                </td>
              </tr>
            ) : (
              schedules.map((schedule) => (
                <tr key={schedule.id}>
                  <td style={{ fontWeight: 600 }}>{schedule.title}</td>
                  <td>{schedule.warehouse?.name}</td>
                  <td>{schedule.scope_type === 'full' ? 'Whole warehouse' : `${schedule.scope_type === 'zone' ? 'Zone' : 'Bin'} ${schedule.scope_value}`}</td>
                  <td>Every {schedule.every_days} day(s)</td>
                  <td>{formatDate(schedule.next_run_on)}</td>
                  {canManage && (
                    <td style={{ textAlign: 'right' }}>
                      <button type="button" className="topbar-icon-button" aria-label={`Remove ${schedule.title}`} onClick={() => run(() => omsService.deleteCycleCountSchedule(schedule.id))} disabled={busy}>
                        <Trash2 size={16} />
                      </button>
                    </td>
                  )}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default CycleCountSchedules;
