import React, { useEffect, useState } from 'react';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { Package, Tag, CheckCircle2, Eye, Layers, Plus } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import { Alert } from '../components/common/Alert';
import type { Column } from '../components/common/DataTable';
import omsService from '../services/omsService';
import { useActiveStore } from '../hooks/useActiveStore';
import type { ProductModel } from '../services/omsService';
import ProductThumb from '../components/catalog/ProductThumb';
import ProductFormModal from '../components/catalog/ProductFormModal';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { variantLabel } from '../utils/format';
import '../components/catalog/DetailModal.css';

export const ProductsPage: React.FC = () => {
  const { activeStore } = useActiveStore();
  const [products, setProducts] = useState<ProductModel[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [searchParams] = useSearchParams();
  const [search, setSearch] = useState(searchParams.get('search') ?? '');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const navigate = useNavigate();
  const { user } = useAuth();
  const [formOpen, setFormOpen] = useState(false);
  // A message handed over by the page that changed a product (e.g. after deleting it).
  const notice = (useLocation().state as { message?: string } | null)?.message;

  const fetchProducts = async () => {
    try {
      setLoading(true);
      const res = await omsService.getProducts({
        search: debouncedSearch || undefined,
        store_id: activeStore?.id,
        page,
        per_page: perPage,
      });
      setProducts(res.data);
      setTotal(res.meta.total);
      setLastPage(res.meta.last_page);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  // A search handed over in the address (e.g. from the top-bar search) fills the search box.
  useEffect(() => {
    const fromUrl = searchParams.get('search');
    if (fromUrl !== null) setSearch(fromUrl);
  }, [searchParams]);

  // Search 300 ms after the user stops typing, from the first page.
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
    fetchProducts();
  }, [page, perPage, debouncedSearch, activeStore?.id]);

  const columns: Column<ProductModel>[] = [
    {
      key: 'name',
      header: 'Product Name',
      render: (row) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <ProductThumb src={row.image_url} size={40} alt={row.name} />
          <div>
            <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{row.name}</div>
            <div style={{ fontSize: 11, color: '#94a3b8' }}>SKU: {row.sku}</div>
          </div>
        </div>
      ),
    },
    {
      key: 'variants',
      header: 'Variants',
      render: (row) => {
        const labels = (row.variants ?? []).map((v) => v.option_value).filter((v): v is string => Boolean(v));
        if (labels.length === 0) return <span style={{ fontSize: 12, color: '#94a3b8' }}>Single</span>;
        return (
          <div style={{ maxWidth: 220 }} title={(row.variants ?? []).map((v) => variantLabel(v) ?? v.sku).join('\n')}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 600, color: '#0f172a' }}>
              <Layers size={13} color="#64748b" />
              {row.variants_count ?? labels.length} variants
            </div>
            <div style={{ fontSize: 11, color: '#94a3b8', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {labels.slice(0, 3).join(', ')}
              {labels.length > 3 ? ` +${labels.length - 3}` : ''}
            </div>
          </div>
        );
      },
    },
    {
      key: 'category',
      header: 'Category',
      render: (row) => (
        <span className="wh-tag" style={{ color: '#475569', backgroundColor: '#f1f5f9', borderColor: '#e2e8f0' }}>
          {row.category || 'General'}
        </span>
      ),
    },
    {
      key: 'on_hand',
      header: 'On Hand',
      align: 'center',
      render: (row) => <span style={{ fontSize: 13, fontWeight: 600 }}>{row.stock?.on_hand ?? 0}</span>,
    },
    {
      key: 'reserved',
      header: 'Reserved',
      align: 'center',
      render: (row) => <span style={{ fontSize: 13, color: '#d97706', fontWeight: 600 }}>{row.stock?.reserved ?? 0}</span>,
    },
    {
      key: 'available',
      header: 'Available',
      align: 'center',
      render: (row) => (
        <span className="kpi-pill kpi-pill-green" style={{ fontSize: 12 }}>
          {row.stock?.available ?? 0} units
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <div className={`status-dot-badge ${row.is_active ? 'status-active' : 'status-warning'}`}>
          <span className="status-dot" />
          <span>{row.is_active ? 'Active' : 'Archived'}</span>
        </div>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'center',
      render: (row) => (
        <button
          onClick={(e) => {
            e.stopPropagation();
            navigate(`/products/${row.id}`);
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
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Products</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Products Catalog</h1>
            <span className="page-count-pill">{total}</span>
          </div>
          <p className="page-subtitle">
            {activeStore ? `Products sold in ${activeStore.name}.` : 'Unified product variants, barcodes, and multichannel inventory mappings.'}
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <InfoCalloutCard
            title="Catalog Master"
            description="Products added or edited here are also created or updated on your connected stores."
            icon={<Tag size={18} />}
          />
          {can(user, 'products.manage') && (
            <button type="button" className="btn-finovo-primary" style={{ height: 42 }} onClick={() => setFormOpen(true)}>
              <Plus size={16} />
              <span>Add Product</span>
            </button>
          )}
        </div>
      </div>

      {notice && <Alert type="success" message={notice} className="page-banner" />}

      <div className="kpi-grid">
        <MetricCard title="Total Catalog SKUs" value={total} icon={<Package size={20} />} pillText="In System" />
        <MetricCard title="Active Listings" value={products.filter((p) => p.is_active).length} icon={<CheckCircle2 size={20} />} pillText="Sellable" pillVariant="green" />
      </div>

      <div className="content-card">
        <div className="content-card-header">
          <h3 className="content-card-title">All Products</h3>
          <input
            type="text"
            placeholder="Search products or SKU..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="topbar-search-input"
            style={{ width: 220 }}
          />
        </div>

        <DataTable
          columns={columns}
          data={products}
          keyField="id"
          isLoading={loading}
          emptyMessage="No products created yet."
          onRowClick={(row) => navigate(`/products/${row.id}`)}
          pagination={{
            currentPage: page,
            lastPage,
            perPage,
            total,
            onPageChange: (p) => setPage(p),
          }}
        />
      </div>

      {formOpen && (
        <ProductFormModal
          onClose={() => setFormOpen(false)}
          onSaved={(message, productId) => navigate(`/products/${productId}`, { state: { message } })}
        />
      )}
    </div>
  );
};

export default ProductsPage;
