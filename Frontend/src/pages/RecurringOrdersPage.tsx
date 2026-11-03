import React, { useCallback, useEffect, useState } from 'react';
import { Loader2, Pause, Play, Repeat, Send, Trash2 } from 'lucide-react';
import { Alert } from '../components/common/Alert';
import StatusPill from '../components/common/StatusPill';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import omsService, { RECURRING_INTERVAL_LABELS } from '../services/omsService';
import type { RecurringInterval, RecurringOrderModel } from '../services/omsService';
import { formatDate, formatMoney } from '../utils/format';
import { errorMessage } from '../utils/errors';

/**
 * Orders placed again on a schedule. A plan is made from an order (Repeat Order in its details); here it is
 * paused, moved to another interval, placed at once or deleted.
 */
export const RecurringOrdersPage: React.FC = () => {
  const { confirm, dialog } = useConfirmDialog();
  const [plans, setPlans] = useState<RecurringOrderModel[] | null>(null);
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(() => {
    omsService
      .getRecurringOrders()
      .then(setPlans)
      .catch((err) => setBanner({ type: 'error', text: errorMessage(err, 'Could not load the recurring orders.') }));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const act = async (plan: RecurringOrderModel, action: () => Promise<{ message: string }>) => {
    setBusyId(plan.id);
    try {
      setBanner({ type: 'success', text: (await action()).message });
      load();
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'The recurring order could not be changed.') });
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (plan: RecurringOrderModel) => {
    const ok = await confirm({ title: 'Delete this recurring order?', message: 'No more orders are placed from it. Orders it already placed are kept.', confirmLabel: 'Delete', tone: 'danger' });
    if (ok) act(plan, () => omsService.deleteRecurringOrder(plan.id));
  };

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Recurring Orders</span>
      </nav>
      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">Recurring Orders</h1>
          <p className="page-subtitle">Orders placed again on a schedule. To add one, open an order and choose Repeat Order.</p>
        </div>
      </div>

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      <div className="content-card">
        <div className="table-responsive-wrapper">
          <table className="finovo-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th style={{ textAlign: 'right' }}>Order Total</th>
                <th>How Often</th>
                <th>Next Order</th>
                <th>Placed</th>
                <th>Status</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {!plans && (
                <tr>
                  <td colSpan={7} style={{ textAlign: 'center', padding: 24 }}>
                    <Loader2 size={18} className="animate-spin" />
                  </td>
                </tr>
              )}
              {plans?.length === 0 && (
                <tr>
                  <td colSpan={7} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>
                    No recurring orders yet.
                  </td>
                </tr>
              )}
              {plans?.map((plan) => (
                <tr key={plan.id}>
                  <td>
                    <div style={{ fontWeight: 600 }}>{plan.customer_name}</div>
                    <div style={{ fontSize: 11, color: '#94a3b8' }}>
                      {plan.items.reduce((sum, line) => sum + line.quantity, 0)} unit(s){plan.customer_email ? ` · ${plan.customer_email}` : ''}
                    </div>
                  </td>
                  <td style={{ textAlign: 'right', fontWeight: 600 }}>{formatMoney(plan.total, plan.currency ?? undefined)}</td>
                  <td>
                    <select
                      className="cf-input"
                      value={plan.interval}
                      disabled={busyId === plan.id}
                      aria-label="How often"
                      onChange={(e) => act(plan, () => omsService.updateRecurringOrder(plan.id, { interval: e.target.value as RecurringInterval }))}
                    >
                      {Object.entries(RECURRING_INTERVAL_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>
                          {label}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td>{plan.active ? formatDate(plan.next_run_at) : '—'}</td>
                  <td>
                    {plan.orders_count}
                    {plan.last_run_at && <div style={{ fontSize: 11, color: '#94a3b8' }}>Last {formatDate(plan.last_run_at, true)}</div>}
                  </td>
                  <td>
                    <StatusPill status={plan.active ? 'active' : 'draft'} label={plan.active ? 'Active' : 'Paused'} />
                    {plan.last_error && <div style={{ fontSize: 11, color: '#dc2626', maxWidth: 220 }}>{plan.last_error}</div>}
                  </td>
                  <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <button type="button" className="table-action-btn" title="Place an order now" aria-label="Place an order now" disabled={busyId === plan.id} onClick={() => act(plan, () => omsService.runRecurringOrder(plan.id))}>
                      <Send size={15} />
                    </button>
                    <button
                      type="button"
                      className="table-action-btn"
                      title={plan.active ? 'Pause' : 'Resume'}
                      aria-label={plan.active ? 'Pause' : 'Resume'}
                      disabled={busyId === plan.id}
                      onClick={() => act(plan, () => omsService.updateRecurringOrder(plan.id, { active: !plan.active }))}
                    >
                      {plan.active ? <Pause size={15} /> : <Play size={15} />}
                    </button>
                    <button type="button" className="table-action-btn is-danger" title="Delete" aria-label="Delete" disabled={busyId === plan.id} onClick={() => remove(plan)}>
                      <Trash2 size={15} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
      <p className="page-subtitle" style={{ marginTop: 12 }}>
        <Repeat size={13} style={{ verticalAlign: -2 }} /> Each order is created like an order taken by hand: unpaid, on the same store, then allocated as usual.
      </p>
      {dialog}
    </div>
  );
};

export default RecurringOrdersPage;
