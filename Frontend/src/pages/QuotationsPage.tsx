import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FileSignature, Plus, Clock, CheckCircle2, XCircle, Download, Eye } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import CustomDropdown from '../components/common/CustomDropdown';
import StatusPill from '../components/common/StatusPill';
import omsService from '../services/omsService';
import type { QuotationModel } from '../services/omsService';
import { useActiveStore } from '../hooks/useActiveStore';
import { formatDate, formatMoney } from '../utils/format';

const STATUS_FILTERS = [
  { value: '', label: 'All statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'sent', label: 'Sent' },
  { value: 'accepted', label: 'Accepted' },
  { value: 'converted', label: 'Converted to order' },
  { value: 'rejected', label: 'Rejected' },
];

export const QuotationsPage: React.FC = () => {
  const navigate = useNavigate();
  const { activeStore } = useActiveStore();
  const [quotations, setQuotations] = useState<QuotationModel[]>([]);
  const [kpis, setKpis] = useState({ total: 0, open: 0, accepted: 0, rejected: 0 });
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    omsService
      .getQuotations({ search: debouncedSearch || undefined, status: status || undefined, store_id: activeStore?.id, page, per_page: perPage })
      .then((res) => {
        if (cancelled) return;
        setQuotations(res.data);
        setKpis(res.kpis);
        setTotal(res.meta.total);
        setLastPage(res.meta.last_page);
      })
      .catch((err) => console.error(err))
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, [page, perPage, status, debouncedSearch, activeStore?.id]);

  const download = (row: QuotationModel) =>
    omsService.downloadQuotationPdf(row.id, row.quotation_number).catch((err) => console.error(err));

  const columns: Column<QuotationModel>[] = [
    {
      key: 'quotation_number',
      header: 'Quotation #',
      render: (row) => (
        <div>
          <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.quotation_number}</div>
          <div style={{ fontSize: 11, color: '#94a3b8' }}>{formatDate(row.issue_date)}</div>
        </div>
      ),
    },
    {
      key: 'customer',
      header: 'Customer',
      render: (row) => (
        <div>
          <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{row.customer_name}</div>
          <div style={{ fontSize: 11, color: '#94a3b8' }}>{row.customer_email || row.customer_phone || 'No contact details'}</div>
        </div>
      ),
    },
    {
      key: 'store',
      header: 'Store',
      render: (row) => <span style={{ fontSize: 13, color: '#334155' }}>{row.store?.name ?? '—'}</span>,
    },
    {
      key: 'items_count',
      header: 'Items',
      align: 'center',
      render: (row) => <span style={{ fontSize: 13 }}>{row.items_count ?? 0}</span>,
    },
    {
      key: 'total_amount',
      header: 'Total',
      align: 'right',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{formatMoney(row.total_amount, row.currency)}</span>,
    },
    {
      key: 'valid_until',
      header: 'Valid Until',
      render: (row) => (
        <span style={{ fontSize: 13, color: row.is_expired ? '#be123c' : '#475569', fontWeight: row.is_expired ? 600 : 400 }}>
          {formatDate(row.valid_until)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <StatusPill status={row.is_expired ? 'expired' : row.status} />,
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (row) => (
        <div style={{ display: 'flex', gap: 6, justifyContent: 'center' }} onClick={(e) => e.stopPropagation()}>
          <button className="btn-finovo-secondary" style={{ padding: '4px 8px', fontSize: 11 }} onClick={() => navigate(`/quotations/${row.id}`)}>
            <Eye size={13} />
            <span>View</span>
          </button>
          <button className="btn-finovo-secondary" style={{ padding: '4px 8px', fontSize: 11 }} onClick={() => download(row)} aria-label={`Download ${row.quotation_number} PDF`}>
            <Download size={13} />
            <span>PDF</span>
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Quotations</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Quotations</h1>
            <span className="page-count-pill">{kpis.total}</span>
          </div>
          <p className="page-subtitle">
            {activeStore ? `Quotations for ${activeStore.name}.` : 'Price offers to customers, downloadable as PDF and convertible into orders.'}
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <InfoCalloutCard
            title="Quote to Order"
            description="Send a PDF quotation, mark it accepted, then convert it into an order in one click."
            icon={<FileSignature size={18} />}
          />
          <button className="btn-finovo-primary" onClick={() => navigate('/quotations/new')}>
            <Plus size={16} />
            <span>New Quotation</span>
          </button>
        </div>
      </div>

      <div className="kpi-grid">
        <MetricCard title="Total Quotations" value={kpis.total} icon={<FileSignature size={20} />} pillText="All" />
        <MetricCard title="Open" value={kpis.open} icon={<Clock size={20} />} pillText="Draft & sent" pillVariant="amber" />
        <MetricCard title="Accepted" value={kpis.accepted} icon={<CheckCircle2 size={20} />} pillText="Won" pillVariant="green" />
        <MetricCard title="Rejected" value={kpis.rejected} icon={<XCircle size={20} />} pillText="Lost" />
      </div>

      <div className="content-card">
        <div className="content-card-header">
          <h3 className="content-card-title">All Quotations</h3>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <div style={{ width: 190 }}>
              <CustomDropdown
                value={status}
                onChange={(value) => {
                  setStatus(String(value));
                  setPage(1);
                }}
                options={STATUS_FILTERS}
                size="sm"
              />
            </div>
            <input
              type="text"
              placeholder="Search number or customer..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="topbar-search-input"
              style={{ width: 230 }}
            />
          </div>
        </div>

        <DataTable
          columns={columns}
          data={quotations}
          keyField="id"
          isLoading={loading}
          emptyMessage={debouncedSearch || status ? 'No quotations match your filters.' : 'No quotations yet. Click "New Quotation" to create one.'}
          onRowClick={(row) => navigate(`/quotations/${row.id}`)}
          pagination={{
            currentPage: page,
            lastPage,
            perPage,
            total,
            onPageChange: setPage,
            onPerPageChange: (n) => {
              setPerPage(n);
              setPage(1);
            },
          }}
        />
      </div>
    </div>
  );
};

export default QuotationsPage;
