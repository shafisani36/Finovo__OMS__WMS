import React, { useEffect, useState } from 'react';
import { Package, Plus, Tag, CheckCircle2, X, Loader2, Eye, Layers } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import DataTable from '../components/common/DataTable';
import type { Column } from '../components/common/DataTable';
import omsService from '../services/omsService';
import { useActiveStore } from '../hooks/useActiveStore';
import type { ProductModel } from '../services/omsService';
import ProductThumb from '../components/catalog/ProductThumb';
import { variantLabel } from '../utils/format';
import ProductDetailModal from '../components/catalog/ProductDetailModal';
import '../components/catalog/DetailModal.css';

export const ProductsPage: React.FC = () => {
  const { activeStore } = useActiveStore();
  const [products, setProducts] = useState<ProductModel[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [perPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [viewProduct, setViewProduct] = useState<ProductModel | null>(null);

  // Modal
  const [modalOpen, setModalOpen] = useState(false);
  const [modalLoading, setModalLoading] = useState(false);
  const [name, setName] = useState('');
  const [sku, setSku] = useState('');
  const [category, setCategory] = useState('General');
  const [price, setPrice] = useState('29.99');

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

  const handleCreateProduct = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !sku.trim()) return;

    try {
      setModalLoading(true);
      await omsService.createProduct({
        name,
        sku,
        category,
        price: Number(price),
      });
      setModalOpen(false);
      setName('');
      setSku('');
      fetchProducts();
    } catch (err) {
      console.error(err);
    } finally {
      setModalLoading(false);
    }
  };

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
            setViewProduct(row);
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
            description="Manage internal SKU identifiers linked with remote channel listings."
            icon={<Tag size={18} />}
          />
          <button onClick={() => setModalOpen(true)} className="btn-finovo-primary">
            <Plus size={16} />
            <span>Add Product</span>
          </button>
        </div>
      </div>

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
          onRowClick={(row) => setViewProduct(row)}
          pagination={{
            currentPage: page,
            lastPage,
            perPage,
            total,
            onPageChange: (p) => setPage(p),
          }}
        />
      </div>

      {modalOpen && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setModalOpen(false)}>
          <div
            className="modal-container"
            style={{ maxWidth: 540, backgroundColor: '#ffffff', borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)', padding: 0 }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ padding: '20px 24px', borderBottom: '1px solid #e2e8f0', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700 }}>Add Product & SKU</h3>
                <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>Register new item identity and SKU in the master catalog.</p>
              </div>
              <button onClick={() => setModalOpen(false)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handleCreateProduct} style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Product Name *</label>
                <input
                  type="text"
                  placeholder="e.g. Mechanical Keyboard"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', boxSizing: 'border-box' }}
                  required
                />
              </div>

              <div className="finovo-form-grid-2">
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>SKU *</label>
                  <input
                    type="text"
                    placeholder="e.g. KB-RGB-01"
                    value={sku}
                    onChange={(e) => setSku(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', boxSizing: 'border-box' }}
                    required
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Price ($)</label>
                  <input
                    type="number"
                    step="0.01"
                    value={price}
                    onChange={(e) => setPrice(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Category</label>
                <input
                  type="text"
                  placeholder="e.g. Electronics"
                  value={category}
                  onChange={(e) => setCategory(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', boxSizing: 'border-box' }}
                />
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12, marginTop: 12 }}>
                <button type="button" onClick={() => setModalOpen(false)} className="btn-finovo-secondary">Cancel</button>
                <button type="submit" disabled={modalLoading} className="btn-finovo-primary">
                  {modalLoading && <Loader2 size={16} className="animate-spin" />}
                  <span>Save Product</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      {viewProduct && <ProductDetailModal product={viewProduct} onClose={() => setViewProduct(null)} />}
    </div>
  );
};

export default ProductsPage;
