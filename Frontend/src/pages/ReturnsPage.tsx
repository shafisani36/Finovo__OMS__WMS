import React, { useCallback, useEffect, useState } from 'react';
import { Ban, CheckCircle2, ClipboardCheck, Eye, Loader2, PackageOpen, Plus, RotateCcw, Search, X } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import CustomDropdown from '../components/common/CustomDropdown';
import StatusPill from '../components/common/StatusPill';
import { Alert } from '../components/common/Alert';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import { useActiveStore } from '../hooks/useActiveStore';
import omsService from '../services/omsService';
import type { ReturnableOrder, ReturnKpis, ReturnModel } from '../services/omsService';
import { formatDate } from '../utils/format';

const REASONS = ['Wrong size or variant', 'Damaged in transit', 'Defective product', 'Not as described', 'Changed mind', 'Other'];

const DISPOSITIONS: { value: NonNullable<ReturnModel['disposition']>; label: string; description: string }[] = [
  { value: 'sellable', label: 'Sellable', description: 'Back into available stock' },
  { value: 'damaged', label: 'Damaged', description: 'On hand, not for sale' },
  { value: 'quarantine', label: 'Quarantine', description: 'Held for further checks' },
  { value: 'disposed', label: 'Disposed', description: 'Written off, no stock change' },
];

const STATUS_TABS: { value: string; label: string; kpi?: keyof ReturnKpis }[] = [
  { value: '', label: 'All Returns' },
  { value: 'requested', label: 'Requested', kpi: 'requested' },
  { value: 'received', label: 'Under Inspection', kpi: 'received' },
  { value: 'completed', label: 'Completed', kpi: 'completed' },
  { value: 'rejected', label: 'Rejected', kpi: 'rejected' },
];

const STATUS_LABELS: Record<ReturnModel['status'], string> = {
  requested: 'Requested',
  received: 'Under Inspection',
  completed: 'Completed',
  rejected: 'Rejected',
};

const errorMessage = (err: unknown, fallback: string) => (err instanceof Error && err.message ? err.message : fallback);

export const ReturnsPage: React.FC = () => {
  const { activeStore } = useActiveStore();
  const { confirm, notify, dialog } = useConfirmDialog();

  const [returns, setReturns] = useState<ReturnModel[]>([]);
  const [kpis, setKpis] = useState<ReturnKpis>({ requested: 0, received: 0, completed: 0, rejected: 0, restocked_units: 0 });
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('');
  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  // New return popup
  const [createOpen, setCreateOpen] = useState(false);
  const [orders, setOrders] = useState<ReturnableOrder[]>([]);
  const [ordersLoading, setOrdersLoading] = useState(false);
  const [orderId, setOrderId] = useState<number | null>(null);
  const [quantities, setQuantities] = useState<Record<number, number>>({});
  const [reason, setReason] = useState(REASONS[0]);
  const [otherReason, setOtherReason] = useState('');
  const [createNotes, setCreateNotes] = useState('');

  // Inspection and rejection popups
  const [inspecting, setInspecting] = useState<ReturnModel | null>(null);
  const [disposition, setDisposition] = useState<NonNullable<ReturnModel['disposition']>>('sellable');
  const [inspectionNotes, setInspectionNotes] = useState('');
  const [rejecting, setRejecting] = useState<ReturnModel | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const fetchReturns = useCallback(async () => {
    try {
      setLoading(true);
      const res = await omsService.getReturns({
        store_id: activeStore?.id,
        status: statusFilter || undefined,
        search: appliedSearch || undefined,
        page,
        per_page: perPage,
      });
      setReturns(res.data);
      setKpis(res.kpis);
      setMeta({ total: res.meta.total, last_page: res.meta.last_page });
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'Failed to load returns.') });
    } finally {
      setLoading(false);
    }
  }, [activeStore?.id, statusFilter, appliedSearch, page, perPage]);

  useEffect(() => {
    fetchReturns();
  }, [fetchReturns]);

  const selectedOrder = orders.find((o) => o.id === orderId) ?? null;

  const openCreate = async () => {
    setCreateOpen(true);
    setOrderId(null);
    setQuantities({});
    setReason(REASONS[0]);
    setOtherReason('');
    setCreateNotes('');
    setFormError(null);
    try {
      setOrdersLoading(true);
      const res = await omsService.getReturnableOrders({ store_id: activeStore?.id });
      setOrders(res.data);
    } catch (err) {
      setFormError(errorMessage(err, 'Failed to load shipped orders.'));
    } finally {
      setOrdersLoading(false);
    }
  };

  const selectOrder = (id: number) => {
    setOrderId(id);
    const order = orders.find((o) => o.id === id);
    // Start with everything that can still come back; the user lowers what is not being returned.
    setQuantities(Object.fromEntries((order?.items ?? []).map((item) => [item.id, item.returnable_quantity])));
  };

  const closeForms = () => {
    if (submitting) return;
    setCreateOpen(false);
    setInspecting(null);
    setRejecting(null);
  };

  const runAction = async (action: () => Promise<{ message: string }>, fallback: string) => {
    try {
      setSubmitting(true);
      const res = await action();
      setCreateOpen(false);
      setInspecting(null);
      setRejecting(null);
      setBanner({ type: 'success', text: res.message });
      fetchReturns();
    } catch (err) {
      setFormError(errorMessage(err, fallback));
    } finally {
      setSubmitting(false);
    }
  };

  const handleCreate = (e: React.FormEvent) => {
    e.preventDefault();
    const items = Object.entries(quantities)
      .filter(([, qty]) => qty > 0)
      .map(([id, qty]) => ({ order_item_id: Number(id), quantity: qty }));
    const reasonText = reason === 'Other' ? otherReason.trim() : reason;

    if (!orderId || items.length === 0) {
      setFormError('Choose the order and at least one item to return.');
      return;
    }
    if (!reasonText) {
      setFormError('Enter the reason for the return.');
      return;
    }

    runAction(
      () => omsService.createReturn({ order_id: orderId, reason: reasonText, notes: createNotes.trim() || undefined, items }),
      'Failed to create the return.'
    );
  };

  const handleReceive = async (row: ReturnModel) => {
    const ok = await confirm({
      title: 'Mark parcel as received?',
      message: (
        <>
          Confirm that the parcel for <strong>{row.return_number}</strong> ({row.items_count} unit(s) from {row.customer}) has arrived at{' '}
          {row.warehouse ?? 'the warehouse'}.
        </>
      ),
      note: 'It moves to Under Inspection. Stock changes only after inspection.',
      confirmLabel: 'Mark Received',
      tone: 'primary',
    });
    if (!ok) return;

    try {
      setBusyId(row.id);
      const res = await omsService.receiveReturn(row.id);
      setBanner({ type: 'success', text: res.message });
      fetchReturns();
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'Failed to update the return.') });
    } finally {
      setBusyId(null);
    }
  };

  const showDetails = (row: ReturnModel) =>
    notify({
      title: `Return ${row.return_number}`,
      message: (
        <>
          Order <strong>{row.order_number}</strong> · {row.customer}
          {row.items.map((item, i) => (
            <span key={i} style={{ display: 'block' }}>
              {item.quantity} × {item.product_name}
            </span>
          ))}
        </>
      ),
      note: [`Reason: ${row.reason ?? 'Not recorded'}`, row.disposition && `Result: ${row.disposition}`, row.notes].filter(Boolean).join('\n'),
      confirmLabel: 'Close',
    });

  const columns: Column<ReturnModel>[] = [
    {
      key: 'return_number',
      header: 'RETURN',
      width: '12%',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.return_number}</span>,
    },
    {
      key: 'order',
      header: 'ORDER / CUSTOMER',
      width: '17%',
      render: (row) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span style={{ fontSize: 13, color: '#0f172a', fontWeight: 500 }}>{row.order_number}</span>
          <span style={{ fontSize: 12, color: '#64748b' }}>{row.customer}</span>
        </div>
      ),
    },
    {
      key: 'items',
      header: 'ITEMS',
      width: '21%',
      render: (row) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          {row.items.map((item, i) => (
            <span key={i} style={{ fontSize: 13, color: '#334155' }}>
              <strong style={{ color: '#0f172a' }}>{item.quantity} ×</strong> {item.product_name}
            </span>
          ))}
        </div>
      ),
    },
    {
      key: 'reason',
      header: 'REASON',
      width: '15%',
      render: (row) => <span style={{ fontSize: 13, color: '#334155' }}>{row.reason}</span>,
    },
    {
      key: 'status',
      header: 'STATUS',
      width: '13%',
      render: (row) => (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 4 }}>
          <StatusPill status={row.status} label={STATUS_LABELS[row.status]} />
          {row.disposition && <span style={{ fontSize: 12, color: '#64748b', textTransform: 'capitalize' }}>{row.disposition}</span>}
        </div>
      ),
    },
    {
      key: 'date',
      header: 'DATE',
      width: '9%',
      render: (row) => <span style={{ fontSize: 12, color: '#64748b' }}>{formatDate(row.completed_at ?? row.received_at ?? row.created_at)}</span>,
    },
    {
      key: 'action',
      header: 'ACTION',
      width: '13%',
      align: 'right',
      render: (row) => (
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <button type="button" className="table-action-btn" onClick={() => showDetails(row)} title="View details" aria-label="View details">
            <Eye size={15} />
          </button>
          {(row.status === 'requested' || row.status === 'received') && (
            <button
              type="button"
              className="table-action-btn is-danger"
              onClick={() => {
                setRejecting(row);
                setRejectReason('');
                setFormError(null);
              }}
              title="Reject return"
              aria-label="Reject return"
            >
              <Ban size={15} />
            </button>
          )}
          {row.status === 'requested' && (
            <button type="button" className="table-action-primary" onClick={() => handleReceive(row)} disabled={busyId === row.id}>
              {busyId === row.id ? <Loader2 size={14} className="animate-spin" /> : <PackageOpen size={14} />}
              <span>Receive</span>
            </button>
          )}
          {row.status === 'received' && (
            <button
              type="button"
              className="table-action-primary is-success"
              onClick={() => {
                setInspecting(row);
                setDisposition('sellable');
                setInspectionNotes('');
                setFormError(null);
              }}
            >
              <ClipboardCheck size={14} />
              <span>Inspect</span>
            </button>
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
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Returns</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Returns & Restocking</h1>
            <span className="page-count-pill">{kpis.requested + kpis.received}</span>
          </div>
          <p className="page-subtitle">Log customer returns for shipped orders, receive the parcels, inspect them and restock.</p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <InfoCalloutCard
            title="Safe Restocking"
            description="Only items marked Sellable after inspection go back into available stock."
            icon={<RotateCcw size={18} />}
          />
          <button type="button" className="btn-finovo-primary" onClick={openCreate}>
            <Plus size={16} />
            <span>New Return</span>
          </button>
        </div>
      </div>

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      <div className="kpi-grid">
        <MetricCard title="Requested Returns" value={kpis.requested} icon={<RotateCcw size={20} />} pillText="Awaiting parcel" pillVariant="amber" />
        <MetricCard title="Under Inspection" value={kpis.received} icon={<ClipboardCheck size={20} />} pillText="At warehouse" />
        <MetricCard title="Completed Returns" value={kpis.completed} icon={<CheckCircle2 size={20} />} pillText="Closed" pillVariant="green" />
        <MetricCard title="Restocked Units" value={kpis.restocked_units} icon={<PackageOpen size={20} />} pillText="Sellable" pillVariant="green" />
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
                {tab.kpi && <span className="procurement-tab-count">{kpis[tab.kpi]}</span>}
              </button>
            ))}
          </div>
        </div>

        <div style={{ padding: '12px 24px', borderBottom: '1px solid #f1f5f9' }}>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              setPage(1);
              setAppliedSearch(search.trim());
            }}
          >
            <div className="topbar-search-input" style={{ width: '100%', boxSizing: 'border-box' }}>
              <Search size={16} color="#94a3b8" />
              <input
                type="text"
                placeholder="Search return number, order number, customer or reason..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          </form>
        </div>

        <DataTable
          columns={columns}
          data={returns}
          keyField="id"
          isLoading={loading}
          emptyMessage="No returns yet. Use New Return when a customer sends back items from a shipped order."
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

      {createOpen && (
        <div className="modal-overlay" onClick={closeForms}>
          <form className="procurement-modal" style={{ maxWidth: 600 }} onClick={(e) => e.stopPropagation()} onSubmit={handleCreate}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>New Return</h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>Record items a customer is sending back from a shipped order.</p>
              </div>
              <button type="button" onClick={closeForms} className="topbar-icon-button" aria-label="Close">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {formError && <Alert type="error" message={formError} />}

              <div>
                <label className="form-label">Order *</label>
                <CustomDropdown
                  value={orderId ? String(orderId) : null}
                  onChange={(val) => selectOrder(Number(val))}
                  placeholder={ordersLoading ? 'Loading shipped orders...' : orders.length ? 'Select a shipped order' : 'No shipped orders to return'}
                  disabled={ordersLoading || orders.length === 0}
                  searchable
                  options={orders.map((o) => ({ value: String(o.id), label: o.order_number, description: o.customer }))}
                />
              </div>

              {selectedOrder && (
                <div>
                  <label className="form-label">Items being returned *</label>
                  <div style={{ border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                    {selectedOrder.items.map((item, i) => (
                      <div
                        key={item.id}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          gap: 12,
                          padding: '10px 14px',
                          borderTop: i ? '1px solid #f1f5f9' : 'none',
                        }}
                      >
                        <div style={{ minWidth: 0 }}>
                          <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{item.product_name}</div>
                          <div style={{ fontSize: 12, color: '#64748b' }}>
                            {item.sku ? `${item.sku} · ` : ''}up to {item.returnable_quantity} of {item.shipped_quantity} shipped
                          </div>
                        </div>
                        <input
                          type="number"
                          className="form-input"
                          style={{ width: 84, textAlign: 'center' }}
                          min={0}
                          max={item.returnable_quantity}
                          value={quantities[item.id] ?? 0}
                          onChange={(e) =>
                            setQuantities((prev) => ({
                              ...prev,
                              [item.id]: Math.min(item.returnable_quantity, Math.max(0, Number(e.target.value) || 0)),
                            }))
                          }
                          aria-label={`Quantity of ${item.product_name} to return`}
                        />
                      </div>
                    ))}
                  </div>
                </div>
              )}

              <div>
                <label className="form-label">Reason *</label>
                <CustomDropdown value={reason} onChange={(val) => setReason(String(val))} options={REASONS.map((r) => ({ value: r, label: r }))} />
              </div>

              {reason === 'Other' && (
                <input
                  type="text"
                  className="form-input"
                  value={otherReason}
                  onChange={(e) => setOtherReason(e.target.value)}
                  placeholder="Describe the reason"
                  maxLength={255}
                />
              )}

              <div>
                <label className="form-label">Notes</label>
                <textarea
                  className="form-input"
                  style={{ height: 70, width: '100%' }}
                  value={createNotes}
                  onChange={(e) => setCreateNotes(e.target.value)}
                  placeholder="e.g. Customer will drop the parcel with TCS"
                  maxLength={1000}
                />
              </div>
            </div>

            <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="button" onClick={closeForms} className="btn-outline" disabled={submitting}>
                  Cancel
                </button>
                <button type="submit" className="btn-primary" disabled={submitting || !selectedOrder}>
                  {submitting && <Loader2 size={16} className="animate-spin" />}
                  <span>Create Return</span>
                </button>
              </div>
            </div>
          </form>
        </div>
      )}

      {inspecting && (
        <div className="modal-overlay" onClick={closeForms}>
          <form
            className="procurement-modal"
            style={{ maxWidth: 520 }}
            onClick={(e) => e.stopPropagation()}
            onSubmit={(e) => {
              e.preventDefault();
              runAction(
                () => omsService.completeReturn(inspecting.id, { disposition, notes: inspectionNotes.trim() || undefined }),
                'Failed to complete the return.'
              );
            }}
          >
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Inspect {inspecting.return_number}</h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  {inspecting.items.map((item) => `${item.quantity} × ${item.product_name}`).join(', ')}
                </p>
              </div>
              <button type="button" onClick={closeForms} className="topbar-icon-button" aria-label="Close">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {formError && <Alert type="error" message={formError} />}

              <div>
                <label className="form-label">Condition of the returned items *</label>
                <div className="return-disposition-grid" role="radiogroup">
                  {DISPOSITIONS.map((option) => (
                    <button
                      key={option.value}
                      type="button"
                      role="radio"
                      aria-checked={disposition === option.value}
                      className={`return-disposition-option ${disposition === option.value ? 'is-selected' : ''}`}
                      onClick={() => setDisposition(option.value)}
                    >
                      <strong>{option.label}</strong>
                      <span>{option.description}</span>
                    </button>
                  ))}
                </div>
              </div>

              <div>
                <label className="form-label">Inspection notes</label>
                <textarea
                  className="form-input"
                  style={{ height: 70, width: '100%' }}
                  value={inspectionNotes}
                  onChange={(e) => setInspectionNotes(e.target.value)}
                  placeholder="e.g. Box opened, item unused"
                  maxLength={1000}
                />
              </div>
            </div>

            <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="button" onClick={closeForms} className="btn-outline" disabled={submitting}>
                  Cancel
                </button>
                <button type="submit" className="btn-primary" disabled={submitting}>
                  {submitting && <Loader2 size={16} className="animate-spin" />}
                  <span>Complete Return</span>
                </button>
              </div>
            </div>
          </form>
        </div>
      )}

      {rejecting && (
        <div className="modal-overlay" onClick={closeForms}>
          <form
            className="procurement-modal"
            style={{ maxWidth: 480 }}
            onClick={(e) => e.stopPropagation()}
            onSubmit={(e) => {
              e.preventDefault();
              if (rejectReason.trim().length < 3) {
                setFormError('Enter the reason for rejecting this return.');
                return;
              }
              runAction(() => omsService.rejectReturn(rejecting.id, rejectReason.trim()), 'Failed to reject the return.');
            }}
          >
            <div className="procurement-modal-header">
              <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: '#dc2626' }}>Reject Return {rejecting.return_number}</h3>
              <button type="button" onClick={closeForms} className="topbar-icon-button" aria-label="Close">
                <X size={16} />
              </button>
            </div>

            <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {formError && <Alert type="error" message={formError} />}
              <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>
                {rejecting.status === 'received'
                  ? 'The parcel is not accepted into stock and should be sent back to the customer.'
                  : 'The items stay with the customer. They can raise a new return later if needed.'}
              </p>
              <textarea
                className="form-input"
                style={{ height: 90, width: '100%' }}
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                placeholder="e.g. Outside the 14-day return window"
                maxLength={1000}
                autoFocus
              />
            </div>

            <div className="procurement-modal-footer">
              <button type="button" onClick={closeForms} className="btn-outline" disabled={submitting}>
                Keep Return
              </button>
              <button type="submit" className="btn-primary is-danger" disabled={submitting}>
                {submitting ? 'Rejecting...' : 'Reject Return'}
              </button>
            </div>
          </form>
        </div>
      )}

      {dialog}
    </div>
  );
};

export default ReturnsPage;
