import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Users, UserPlus, Sparkles, Mail, Eye } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import omsService from '../services/omsService';
import type { CustomerModel } from '../services/omsService';
import { useActiveStore } from '../hooks/useActiveStore';
import { platformLabel } from '../components/stores/storePlatforms';
import { formatDate, formatMoneyByCurrency, initials } from '../utils/format';

export const CustomersPage: React.FC = () => {
  const navigate = useNavigate();
  const { activeStore } = useActiveStore();
  const [customers, setCustomers] = useState<CustomerModel[]>([]);
  const [kpis, setKpis] = useState({ total_customers: 0, repeat_buyers: 0, new_last_30_days: 0, with_email: 0 });
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
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
    setPage(1);
  }, [activeStore?.id]);

  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      try {
        setLoading(true);
        const res = await omsService.getCustomers({
          search: debouncedSearch || undefined,
          store_id: activeStore?.id,
          page,
          per_page: perPage,
        });
        if (cancelled) return;
        setCustomers(res.data);
        setKpis(res.kpis);
        setTotal(res.meta.total);
        setLastPage(res.meta.last_page);
      } catch (err) {
        console.error(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    };
    load();
    return () => {
      cancelled = true;
    };
  }, [page, perPage, debouncedSearch, activeStore?.id]);

  const openCustomer = (customer: CustomerModel) => navigate(`/customers/${customer.id}`);

  const columns: Column<CustomerModel>[] = [
    {
      key: 'customer',
      header: 'Customer',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div
            style={{
              width: 34,
              height: 34,
              borderRadius: 10,
              backgroundColor: '#eef2ff',
              color: '#4338ca',
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
            <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{row.name}</div>
            <div style={{ fontSize: 11, color: '#94a3b8' }}>{row.email || 'No email'}</div>
          </div>
        </div>
      ),
    },
    {
      key: 'phone',
      header: 'Phone',
      render: (row) => <span style={{ fontSize: 13, color: '#334155' }}>{row.phone || '—'}</span>,
    },
    {
      key: 'stores',
      header: 'Stores',
      render: (row) => (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
          {row.stores.map((store) => (
            <span key={store.id} className="kpi-pill kpi-pill-blue" title={store.name}>
              {platformLabel(store.platform)}
            </span>
          ))}
        </div>
      ),
    },
    {
      key: 'orders_count',
      header: 'Orders',
      align: 'center',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>{row.orders_count}</span>,
    },
    {
      key: 'spent',
      header: 'Total Spent',
      align: 'right',
      render: (row) => (
        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{formatMoneyByCurrency(row.spent)}</span>
      ),
    },
    {
      key: 'latest_order_at',
      header: 'Last Order',
      render: (row) => <span style={{ fontSize: 13, color: '#475569' }}>{formatDate(row.latest_order_at)}</span>,
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (row) => (
        <button
          onClick={(e) => {
            e.stopPropagation();
            openCustomer(row);
          }}
          className="btn-finovo-secondary"
          style={{ padding: '4px 10px', fontSize: 11 }}
        >
          <Eye size={13} />
          <span>View</span>
        </button>
      ),
    },
  ];

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Customers</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Customers</h1>
            <span className="page-count-pill">{kpis.total_customers}</span>
          </div>
          <p className="page-subtitle">
            {activeStore ? `Customers who ordered from ${activeStore.name}.` : 'Unified customer identities across all connected sales channels.'}
          </p>
        </div>

        <InfoCalloutCard
          title="Customer Identities"
          description="Built from imported orders. The same email across stores is one customer."
          icon={<Users size={18} />}
        />
      </div>

      <div className="kpi-grid">
        <MetricCard title="Total Customers" value={kpis.total_customers} icon={<Users size={20} />} pillText="Unified" />
        <MetricCard title="Repeat Buyers" value={kpis.repeat_buyers} icon={<UserPlus size={20} />} pillText="2+ orders" pillVariant="green" />
        <MetricCard title="New (30 days)" value={kpis.new_last_30_days} icon={<Sparkles size={20} />} pillText="First order" pillVariant="amber" />
        <MetricCard title="Reachable by Email" value={kpis.with_email} icon={<Mail size={20} />} pillText="Contact" />
      </div>

      <div className="content-card">
        <div className="content-card-header">
          <h3 className="content-card-title">All Customers</h3>
          <input
            type="text"
            placeholder="Search name, email or phone..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="topbar-search-input"
            style={{ width: 240 }}
          />
        </div>

        <DataTable
          columns={columns}
          data={customers}
          keyField="id"
          isLoading={loading}
          emptyMessage={
            debouncedSearch
              ? 'No customers match your search.'
              : 'No customers yet. They appear here after orders are imported from your stores.'
          }
          onRowClick={openCustomer}
          pagination={{
            currentPage: page,
            lastPage,
            perPage,
            total,
            onPageChange: (p) => setPage(p),
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

export default CustomersPage;
