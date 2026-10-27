import React, { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Building2, CheckCircle2, Download, Eye, FileText, Pencil, Plus, Receipt, Trash2, Wallet } from 'lucide-react';
import { Alert } from '../components/common/Alert';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import CustomDropdown from '../components/common/CustomDropdown';
import StatusPill from '../components/common/StatusPill';
import ConfirmDialog from '../components/common/ConfirmDialog';
import RowActionsMenu from '../components/common/RowActionsMenu';
import type { RowAction } from '../components/common/RowActionsMenu';
import { ChargesModal, ClientFormModal, InvoiceModal, InvoiceStatus, NewInvoiceModal } from '../components/billing/BillingModals';
import omsService from '../services/omsService';
import type { BillingStore, ClientInvoiceModel, ClientModel, MoneyByCurrency } from '../services/omsService';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { platformLabel } from '../components/stores/storePlatforms';
import { formatDate, formatMoney, formatMoneyByCurrency } from '../utils/format';

type Tab = 'clients' | 'invoices';

const INVOICE_FILTERS = [
  { value: '', label: 'All Invoices' },
  { value: 'draft', label: 'Draft' },
  { value: 'issued', label: 'Unpaid' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'paid', label: 'Paid' },
  { value: 'void', label: 'Void' },
];

/** "Order 50 · Unit 10 · Storage 0.5/day": the client's main rates in one line. */
const rateSummary = (client: ClientModel) =>
  [
    client.order_fee ? `Order ${client.order_fee}` : null,
    client.additional_unit_fee ? `Extra unit ${client.additional_unit_fee}` : null,
    client.storage_per_unit_day ? `Storage ${client.storage_per_unit_day}/unit-day` : null,
    client.receiving_per_unit ? `Receiving ${client.receiving_per_unit}/unit` : null,
    client.return_fee ? `Return ${client.return_fee}` : null,
    client.monthly_minimum ? `Minimum ${client.monthly_minimum}/month` : null,
  ]
    .filter(Boolean)
    .join(' · ') || 'No rates set';

/**
 * 3PL billing: the brands the company stores and ships for, their rate cards, running charges and invoices.
 */
export const BillingPage: React.FC = () => {
  const { user } = useAuth();
  const canManage = can(user, 'billing.manage');
  const [tab, setTab] = useState<Tab>('clients');
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const [clients, setClients] = useState<ClientModel[]>([]);
  const [stores, setStores] = useState<BillingStore[]>([]);
  const [clientsLoading, setClientsLoading] = useState(true);

  const [invoices, setInvoices] = useState<ClientInvoiceModel[]>([]);
  const [invoiceKpis, setInvoiceKpis] = useState<{ outstanding: MoneyByCurrency; overdue: MoneyByCurrency; paid_this_month: MoneyByCurrency; drafts: number }>({
    outstanding: [],
    overdue: [],
    paid_this_month: [],
    drafts: 0,
  });
  const [invoicesLoading, setInvoicesLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('');
  const [clientFilter, setClientFilter] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });

  const [editing, setEditing] = useState<ClientModel | 'new' | null>(null);
  const [chargesFor, setChargesFor] = useState<ClientModel | null>(null);
  const [deleting, setDeleting] = useState<ClientModel | null>(null);
  const [newInvoice, setNewInvoice] = useState(false);
  const [openInvoiceId, setOpenInvoiceId] = useState<number | null>(null);

  const showError = (err: unknown, fallback: string) => setBanner({ type: 'error', text: err instanceof Error && err.message ? err.message : fallback });

  const loadClients = useCallback(async () => {
    try {
      setClientsLoading(true);
      const res = await omsService.getClients();
      setClients(res.data);
      setStores(res.stores);
    } catch (err) {
      showError(err, 'Failed to load clients.');
    } finally {
      setClientsLoading(false);
    }
  }, []);

  const loadInvoices = useCallback(async () => {
    try {
      setInvoicesLoading(true);
      const res = await omsService.getClientInvoices({ status: statusFilter || undefined, client_id: clientFilter || undefined, page, per_page: perPage });
      setInvoices(res.data);
      setInvoiceKpis(res.kpis);
      setMeta({ total: res.meta.total, last_page: res.meta.last_page });
    } catch (err) {
      showError(err, 'Failed to load invoices.');
    } finally {
      setInvoicesLoading(false);
    }
  }, [statusFilter, clientFilter, page, perPage]);

  useEffect(() => {
    loadClients();
  }, [loadClients]);

  useEffect(() => {
    loadInvoices();
  }, [loadInvoices]);

  /** Something changed money owed: refresh both lists (outstanding amounts show on clients too). */
  const refresh = (message?: string) => {
    if (message) setBanner({ type: 'success', text: message });
    loadClients();
    loadInvoices();
  };

  const openCreatedInvoice = (invoice: ClientInvoiceModel, message: string) => {
    setChargesFor(null);
    setNewInvoice(false);
    setTab('invoices');
    setOpenInvoiceId(invoice.id);
    refresh(message);
  };

  const download = async (invoice: ClientInvoiceModel) => {
    try {
      await omsService.downloadClientInvoice(invoice.id, invoice.invoice_number);
    } catch (err) {
      showError(err, 'Could not download the invoice.');
    }
  };

  const deleteClient = async () => {
    if (!deleting) return;
    try {
      const res = await omsService.deleteClient(deleting.id);
      refresh(res.message);
    } catch (err) {
      showError(err, 'The client could not be removed.');
    } finally {
      setDeleting(null);
    }
  };

  const clientColumns: Column<ClientModel>[] = [
    {
      key: 'name',
      header: 'Client',
      render: (row) => (
        <div style={{ minWidth: 150 }}>
          <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.name}</div>
          <div style={{ fontSize: 11, color: '#94a3b8' }}>
            {row.code}
            {row.contact_name ? ` · ${row.contact_name}` : ''}
          </div>
        </div>
      ),
    },
    {
      key: 'stores',
      header: 'Stores',
      render: (row) =>
        row.stores.length ? (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
            {row.stores.map((store) => (
              <span key={store.id} className="kpi-pill kpi-pill-blue" title={platformLabel(store.platform)}>
                {store.name}
              </span>
            ))}
          </div>
        ) : (
          <span style={{ fontSize: 12, color: '#b45309' }}>No stores yet</span>
        ),
    },
    {
      key: 'rates',
      header: `Rate Card`,
      render: (row) => (
        <span className="billing-rate-summary">
          {row.currency} · {rateSummary(row)}
        </span>
      ),
    },
    {
      key: 'outstanding',
      header: 'Outstanding',
      align: 'right',
      render: (row) => (
        <div>
          <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{formatMoney(row.outstanding, row.currency)}</div>
          {row.overdue_invoices > 0 && <div style={{ fontSize: 11, color: '#dc2626' }}>{row.overdue_invoices} overdue</div>}
        </div>
      ),
    },
    {
      key: 'billed_until',
      header: 'Billed Until',
      render: (row) => <span style={{ fontSize: 13, color: '#475569' }}>{formatDate(row.billed_until)}</span>,
    },
    {
      key: 'is_active',
      header: 'Status',
      render: (row) => <StatusPill status={row.is_active ? 'active' : 'cancelled'} label={row.is_active ? 'active' : 'inactive'} />,
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (row) => {
        const actions: RowAction[] = [{ label: 'View Charges', icon: Eye, onSelect: () => setChargesFor(row) }];
        if (canManage) {
          actions.push({ label: 'Edit Client', icon: Pencil, onSelect: () => setEditing(row) });
          actions.push({ label: 'Remove Client', icon: Trash2, onSelect: () => setDeleting(row), danger: true });
        }
        return <RowActionsMenu actions={actions} label={`Actions for ${row.name}`} />;
      },
    },
  ];

  const invoiceColumns: Column<ClientInvoiceModel>[] = [
    {
      key: 'invoice_number',
      header: 'Invoice',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.invoice_number}</span>,
    },
    {
      key: 'client',
      header: 'Client',
      render: (row) => <span style={{ fontSize: 13, color: '#334155' }}>{row.client?.name ?? '—'}</span>,
    },
    {
      key: 'period',
      header: 'Period',
      render: (row) => (
        <span style={{ fontSize: 13, color: '#475569' }}>
          {formatDate(row.period_start)} – {formatDate(row.period_end)}
        </span>
      ),
    },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{formatMoney(row.total, row.currency)}</span>,
    },
    {
      key: 'due_date',
      header: 'Due',
      render: (row) => <span style={{ fontSize: 13, color: row.is_overdue ? '#dc2626' : '#475569' }}>{formatDate(row.due_date)}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <InvoiceStatus invoice={row} />,
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (row) => (
        <RowActionsMenu
          label={`Actions for ${row.invoice_number}`}
          actions={[
            { label: 'Open Invoice', icon: FileText, onSelect: () => setOpenInvoiceId(row.id) },
            { label: 'Download PDF', icon: Download, onSelect: () => download(row) },
          ]}
        />
      ),
    },
  ];

  const activeClients = clients.filter((c) => c.is_active).length;

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Client Billing</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Client Billing</h1>
            <span className="page-count-pill">{activeClients}</span>
          </div>
          <p className="page-subtitle">Bill the brands you store and ship for: storage, receiving, orders and returns.</p>
        </div>

        <InfoCalloutCard
          title="How charges are worked out"
          description="Each client's stores decide its orders and products. Storage counts the units in stock each day; the rest counts the work done in the period."
          icon={<Receipt size={18} />}
        />
      </div>

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      <div className="kpi-grid">
        <MetricCard title="Active Clients" value={activeClients} icon={<Building2 size={20} />} pillText={`${clients.length} total`} />
        <MetricCard title="Outstanding" value={formatMoneyByCurrency(invoiceKpis.outstanding, '0')} icon={<Wallet size={20} />} pillText="Unpaid" pillVariant="amber" />
        <MetricCard title="Overdue" value={formatMoneyByCurrency(invoiceKpis.overdue, '0')} icon={<AlertTriangle size={20} />} pillText="Past due" pillVariant="amber" />
        <MetricCard title="Paid This Month" value={formatMoneyByCurrency(invoiceKpis.paid_this_month, '0')} icon={<CheckCircle2 size={20} />} pillText="Received" pillVariant="green" />
      </div>

      <div className="content-card">
        <div className="finovo-tabs-wrapper" role="tablist">
          <button type="button" role="tab" aria-selected={tab === 'clients'} className={`finovo-tab-btn ${tab === 'clients' ? 'active' : ''}`} onClick={() => setTab('clients')}>
            <Building2 size={16} />
            <span>Clients</span>
            <span className="finovo-tab-badge">{clients.length}</span>
          </button>
          <button type="button" role="tab" aria-selected={tab === 'invoices'} className={`finovo-tab-btn ${tab === 'invoices' ? 'active' : ''}`} onClick={() => setTab('invoices')}>
            <FileText size={16} />
            <span>Invoices</span>
            <span className={`finovo-tab-badge ${invoiceKpis.drafts > 0 ? 'badge-amber' : ''}`}>{invoiceKpis.drafts > 0 ? `${invoiceKpis.drafts} Draft` : meta.total}</span>
          </button>
        </div>

        {tab === 'clients' ? (
          <>
            <div className="content-card-header">
              <h3 className="content-card-title">Clients & Rate Cards</h3>
              {canManage && (
                <button type="button" className="btn-finovo-primary" onClick={() => setEditing('new')}>
                  <Plus size={14} />
                  <span>Add Client</span>
                </button>
              )}
            </div>
            <DataTable
              columns={clientColumns}
              data={clients}
              keyField="id"
              isLoading={clientsLoading}
              emptyMessage="No clients yet. Add the brands you store and ship for, choose their stores and set their rates."
              onRowClick={(row) => setChargesFor(row)}
            />
          </>
        ) : (
          <>
            <div className="content-card-header">
              <h3 className="content-card-title">Invoices</h3>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <div style={{ width: 190 }}>
                  <CustomDropdown
                    value={String(clientFilter)}
                    onChange={(val) => {
                      setClientFilter(val === '' ? '' : Number(val));
                      setPage(1);
                    }}
                    options={[{ value: '', label: 'All Clients' }, ...clients.map((c) => ({ value: String(c.id), label: c.name }))]}
                    searchable={clients.length > 6}
                  />
                </div>
                <div style={{ width: 160 }}>
                  <CustomDropdown
                    value={statusFilter}
                    onChange={(val) => {
                      setStatusFilter(String(val));
                      setPage(1);
                    }}
                    options={INVOICE_FILTERS}
                    searchable={false}
                  />
                </div>
                {canManage && (
                  <button type="button" className="btn-finovo-primary" onClick={() => setNewInvoice(true)} disabled={activeClients === 0}>
                    <Plus size={14} />
                    <span>New Invoice</span>
                  </button>
                )}
              </div>
            </div>
            <DataTable
              columns={invoiceColumns}
              data={invoices}
              keyField="id"
              isLoading={invoicesLoading}
              emptyMessage={statusFilter || clientFilter ? 'No invoices match these filters.' : 'No invoices yet. Create one for a client and period.'}
              onRowClick={(row) => setOpenInvoiceId(row.id)}
              pagination={{
                currentPage: page,
                lastPage: meta.last_page,
                perPage,
                total: meta.total,
                onPageChange: setPage,
                onPerPageChange: (n) => {
                  setPerPage(n);
                  setPage(1);
                },
              }}
            />
          </>
        )}
      </div>

      {editing && (
        <ClientFormModal
          client={editing === 'new' ? null : editing}
          stores={stores}
          onClose={() => setEditing(null)}
          onSaved={(message) => {
            setEditing(null);
            refresh(message);
          }}
        />
      )}
      {chargesFor && <ChargesModal client={chargesFor} canManage={canManage} onClose={() => setChargesFor(null)} onInvoiced={openCreatedInvoice} />}
      {newInvoice && <NewInvoiceModal clients={clients} onClose={() => setNewInvoice(false)} onCreated={openCreatedInvoice} />}
      {openInvoiceId !== null && <InvoiceModal invoiceId={openInvoiceId} canManage={canManage} onClose={() => setOpenInvoiceId(null)} onChanged={(message) => refresh(message)} />}

      <ConfirmDialog
        open={deleting !== null}
        title={`Remove ${deleting?.name ?? 'client'}?`}
        message="Its stores stay connected but are no longer billed to anyone. A client with invoices is kept; make it inactive instead."
        confirmLabel="Remove Client"
        onCancel={() => setDeleting(null)}
        onConfirm={deleteClient}
      />
    </div>
  );
};

export default BillingPage;
