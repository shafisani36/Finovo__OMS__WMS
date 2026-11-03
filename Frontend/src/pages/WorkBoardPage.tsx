import React, { useCallback, useEffect, useState } from 'react';
import { ClipboardList, PackageCheck, Send, Truck, Users } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import CustomDropdown from '../components/common/CustomDropdown';
import StatusPill from '../components/common/StatusPill';
import { Alert } from '../components/common/Alert';
import { authService } from '../services/authService';
import omsService from '../services/omsService';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import type { AdminOverviewData, OperatorTasksData, WorkTask } from '../types/auth';
import { formatDate, humanize } from '../utils/format';
import { errorMessage } from '../utils/errors';

const TaskTable: React.FC<{ tasks: WorkTask[]; empty: string; assign?: (task: WorkTask) => React.ReactNode }> = ({ tasks, empty, assign }) => (
  <div className="table-responsive-wrapper">
    <table className="finovo-table">
      <thead>
        <tr>
          <th>Order</th>
          <th>Warehouse</th>
          <th style={{ textAlign: 'right' }}>Units</th>
          <th>Status</th>
          <th>Waiting Since</th>
          {assign && <th>Worker</th>}
        </tr>
      </thead>
      <tbody>
        {tasks.length === 0 ? (
          <tr>
            <td colSpan={assign ? 6 : 5} style={{ textAlign: 'center', padding: '24px 16px', color: '#64748b' }}>
              {empty}
            </td>
          </tr>
        ) : (
          tasks.map((task) => (
            <tr key={task.id}>
              <td style={{ fontWeight: 700 }}>{task.order_number}</td>
              <td>{task.warehouse ?? '—'}</td>
              <td style={{ textAlign: 'right' }}>{task.items_count}</td>
              <td>
                <StatusPill status={task.status === 'not_started' ? 'pending' : task.status} label={humanize(task.status)} />
              </td>
              <td>{formatDate(task.created_at, true)}</td>
              {assign && <td style={{ minWidth: 200 }}>{assign(task)}</td>}
            </tr>
          ))
        )}
      </tbody>
    </table>
  </div>
);

/**
 * Worker task dashboard: every worker sees the pick tasks given to them; admins also see each worker's open and
 * finished work today and give waiting tasks to workers.
 */
export const WorkBoardPage: React.FC = () => {
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';
  const canAssign = isAdmin && can(user, 'warehouse.employees.assign');
  const [mine, setMine] = useState<OperatorTasksData | null>(null);
  const [board, setBoard] = useState<AdminOverviewData | null>(null);
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      setMine((await authService.getOperatorTasks()).data);
      if (isAdmin) setBoard((await authService.getAdminOverview()).data);
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'The work board could not be loaded.') });
    }
  }, [isAdmin]);

  useEffect(() => {
    load();
  }, [load]);

  const assign = async (task: WorkTask, userId: number) => {
    try {
      setBanner({ type: 'success', text: (await omsService.assignPickTask(task.id, userId)).message });
      load();
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'The task could not be assigned.') });
    }
  };

  const workerOptions = [{ value: 0, label: 'Choose a worker' }, ...(board?.workers ?? []).map((w) => ({ value: w.id, label: `${w.name} (${w.open_tasks} open)` }))];

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Work Board</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">Work Board</h1>
          <p className="page-subtitle">{isAdmin ? 'Give picking work to your team and follow what each worker is doing today.' : 'The orders given to you to pick and pack.'}</p>
        </div>
      </div>

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      {board && (
        <div className="kpi-grid">
          <MetricCard title="Open Pick Tasks" value={board.totals.open_pick_tasks} icon={<ClipboardList size={20} />} pillText={`${board.unassigned.length} unassigned`} pillVariant="amber" />
          <MetricCard title="Open Orders" value={board.totals.open_orders} icon={<PackageCheck size={20} />} pillText="Not shipped" />
          <MetricCard title="Ready to Dispatch" value={board.totals.ready_to_dispatch} icon={<Send size={20} />} pillText="Packed" pillVariant="blue" />
          <MetricCard title="In Transit" value={board.totals.in_transit} icon={<Truck size={20} />} pillText="With couriers" pillVariant="green" />
        </div>
      )}

      <div className="content-card" style={{ padding: 24, marginBottom: 24 }}>
        <h3 className="tab-content-header-title" style={{ marginBottom: 12 }}>
          My Tasks {mine ? `(${mine.my_tasks.length})` : ''}
        </h3>
        <TaskTable tasks={mine?.my_tasks ?? []} empty={`Nothing is given to you right now${mine?.unassigned ? `; ${mine.unassigned} task(s) in your warehouses are open for anyone` : ''}.`} />
      </div>

      {board && (
        <>
          <div className="content-card" style={{ padding: 24, marginBottom: 24 }}>
            <h3 className="tab-content-header-title" style={{ marginBottom: 12, display: 'flex', alignItems: 'center', gap: 8 }}>
              <Users size={18} /> Team Today
            </h3>
            <div className="table-responsive-wrapper">
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th>Worker</th>
                    <th>Role</th>
                    <th style={{ textAlign: 'right' }}>Open Tasks</th>
                    <th style={{ textAlign: 'right' }}>Done Today</th>
                    <th style={{ textAlign: 'right' }}>Scan Errors Today</th>
                  </tr>
                </thead>
                <tbody>
                  {board.workers.map((worker) => (
                    <tr key={worker.id}>
                      <td style={{ fontWeight: 600 }}>{worker.name}</td>
                      <td style={{ textTransform: 'capitalize' }}>{worker.role}</td>
                      <td style={{ textAlign: 'right' }}>{worker.open_tasks}</td>
                      <td style={{ textAlign: 'right' }}>{worker.done_today}</td>
                      <td style={{ textAlign: 'right', color: worker.scan_errors_today ? '#dc2626' : undefined }}>{worker.scan_errors_today}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="content-card" style={{ padding: 24 }}>
            <h3 className="tab-content-header-title" style={{ marginBottom: 12 }}>
              Waiting for a Worker ({board.unassigned.length})
            </h3>
            <TaskTable
              tasks={board.unassigned}
              empty="Every open task has a worker."
              assign={
                canAssign
                  ? (task) => <CustomDropdown value={0} onChange={(val) => Number(val) > 0 && assign(task, Number(val))} options={workerOptions} searchable={false} size="sm" />
                  : undefined
              }
            />
          </div>
        </>
      )}
    </div>
  );
};

export default WorkBoardPage;
