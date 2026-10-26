import React, { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { AlertTriangle, Archive, ArchiveRestore, ArrowLeft, Boxes, History, Loader2, Package, PackageCheck, Pencil, ShoppingCart, Store as StoreIcon, Trash2, Warehouse } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import StatusPill from '../components/common/StatusPill';
import { Alert } from '../components/common/Alert';
import { DetailValue } from '../components/common/DetailValue';
import ProductThumb from '../components/catalog/ProductThumb';
import ProductFormModal, { productPayload } from '../components/catalog/ProductFormModal';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { errorMessage } from '../utils/errors';
import { BarcodeLabel } from '../components/barcode/BarcodeLabel';
import { platformLabel } from '../components/stores/storePlatforms';
import omsService from '../services/omsService';
import type { ProductDetail } from '../services/omsService';
import { formatDate, formatMoney, humanize } from '../utils/format';

/** Titled card used for each section of the page. */
const Section: React.FC<{ title: string; icon: React.ReactNode; subtitle?: string; children: React.ReactNode }> = ({ title, icon, subtitle, children }) => (
  <section className="content-card pd-section">
    <div className="content-card-header">
      <div className="content-card-title-group">
        <div className="pd-section-icon">{icon}</div>
        <div>
          <h3 className="content-card-title">{title}</h3>
          {subtitle && <p className="content-card-subtitle">{subtitle}</p>}
        </div>
      </div>
    </div>
    {children}
  </section>
);

/**
 * One product's page: photos, description and the store's own facts, a scannable label, stock in each
 * warehouse, every variant, the stores selling it (with each store's original record) and recent stock moves.
 */
export const ProductDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { confirm, dialog } = useConfirmDialog();
  const [product, setProduct] = useState<ProductDetail | null>(null);
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  // A message handed over by the page that saved the product (e.g. after adding it).
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(() => {
    const message = (location.state as { message?: string } | null)?.message;
    return message ? { type: 'success', text: message } : null;
  });
  const [error, setError] = useState<string | null>(null);
  const [photo, setPhoto] = useState<string | null>(null);
  const [labelVariantId, setLabelVariantId] = useState<number | null>(null);
  // Store photos that do not load (removed, or the store blocks showing them elsewhere) are left out.
  const [brokenImages, setBrokenImages] = useState<string[]>([]);
  const markBroken = (url: string) => setBrokenImages((prev) => (prev.includes(url) ? prev : [...prev, url]));

  useEffect(() => {
    let cancelled = false;
    setProduct(null);
    setError(null);
    omsService
      .getProduct(Number(id))
      .then((res) => {
        if (cancelled) return;
        setProduct(res.data);
        setPhoto(res.data.images[0] ?? null);
        setLabelVariantId(res.data.variants[0]?.id ?? null);
      })
      .catch((err: unknown) => !cancelled && setError(err instanceof Error ? err.message : 'The product could not be loaded.'));
    return () => {
      cancelled = true;
    };
  }, [id, reloadKey]);

  const reload = (message: string, type: 'success' | 'error' = 'success') => {
    setBanner({ type, text: message });
    setReloadKey((key) => key + 1);
  };

  const toggleArchived = async () => {
    if (!product) return;
    const archiving = product.is_active;
    const ok = await confirm({
      title: archiving ? `Archive ${product.name}?` : `Reactivate ${product.name}?`,
      message: archiving
        ? 'It stops being sold: it is saved as a draft on its stores. Stock and history stay as they are.'
        : 'It becomes active again here and on its stores.',
      confirmLabel: archiving ? 'Archive' : 'Reactivate',
      tone: archiving ? 'danger' : 'primary',
    });
    if (!ok) return;
    try {
      setBusy(true);
      const res = await omsService.updateProduct(product.id, productPayload(product, { is_active: !archiving }));
      reload(res.message);
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'The product could not be changed.') });
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!product) return;
    const ok = await confirm({
      title: `Delete ${product.name}?`,
      message: 'The product is deleted from Finovo and from every store it is on.',
      note: 'Products with stock, order or purchase history cannot be deleted; archive them instead.',
      confirmLabel: 'Delete Product',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      setBusy(true);
      const res = await omsService.deleteProduct(product.id);
      navigate('/products', { state: { message: res.message } });
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'The product could not be deleted.') });
      setBusy(false);
    }
  };

  if (error) {
    return (
      <div className="page-container">
        <Alert type="error" message={error} />
        <Link to="/products" className="btn-finovo-secondary" style={{ marginTop: 16, display: 'inline-flex' }}>
          <ArrowLeft size={14} /> Back to Products
        </Link>
      </div>
    );
  }

  if (!product) {
    return (
      <div className="page-container pd-loading">
        <Loader2 className="animate-spin" size={28} color="#1e56ff" />
      </div>
    );
  }

  const labelVariant = product.variants.find((v) => v.id === labelVariantId) ?? product.variants[0];
  const images = product.images.filter((url) => !brokenImages.includes(url));
  const shownPhoto = photo && images.includes(photo) ? photo : images[0];
  const details = Object.entries(product.details ?? {});

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <Link to="/products">Products</Link>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>{product.name}</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">{product.name}</h1>
            <StatusPill status={product.is_active ? 'active' : 'cancelled'} label={product.is_active ? 'Active' : 'Archived'} />
          </div>
          <p className="page-subtitle">
            SKU {product.sku}
            {product.category ? ` · ${product.category}` : ''} · Added {formatDate(product.created_at)} · Updated {formatDate(product.updated_at, true)}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <Link to="/products" className="btn-finovo-secondary">
            <ArrowLeft size={14} />
            <span>Back to Products</span>
          </Link>
          {can(user, 'products.manage') && (
            <>
              <button type="button" className="btn-finovo-secondary" onClick={toggleArchived} disabled={busy}>
                {product.is_active ? <Archive size={14} /> : <ArchiveRestore size={14} />}
                <span>{product.is_active ? 'Archive' : 'Reactivate'}</span>
              </button>
              <button type="button" className="btn-finovo-primary" onClick={() => setEditing(true)} disabled={busy}>
                <Pencil size={14} />
                <span>Edit Product</span>
              </button>
            </>
          )}
          {can(user, 'products.delete') && (
            <button type="button" className="btn-finovo-secondary" style={{ color: '#dc2626' }} onClick={remove} disabled={busy} aria-label="Delete product">
              <Trash2 size={14} />
            </button>
          )}
        </div>
      </div>

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      <div className="kpi-grid">
        <MetricCard title="On Hand" value={product.stock.on_hand} icon={<Boxes size={20} />} pillText={`${product.warehouses.length} warehouse(s)`} />
        <MetricCard title="Available" value={product.stock.available} icon={<PackageCheck size={20} />} pillText="Can be sold" pillVariant="green" />
        <MetricCard title="Reserved" value={product.stock.reserved} icon={<Package size={20} />} pillText="For open orders" pillVariant="amber" />
        <MetricCard title="Units Sold" value={product.sales.units} icon={<ShoppingCart size={20} />} pillText={`${product.sales.orders} order(s)`} />
      </div>

      <div className="pd-top">
        <section className="content-card pd-gallery">
          <div className="pd-photo">
            {shownPhoto ? <img src={shownPhoto} alt={product.name} onError={() => markBroken(shownPhoto)} /> : <ProductThumb size={160} alt={product.name} />}
          </div>
          {images.length > 1 && (
            <div className="pd-thumbs" role="list" aria-label="Product photos">
              {images.map((image, i) => (
                <button
                  key={image}
                  type="button"
                  role="listitem"
                  className={`pd-thumb ${image === shownPhoto ? 'is-active' : ''}`}
                  onClick={() => setPhoto(image)}
                  aria-label={`Photo ${i + 1}`}
                >
                  <img src={image} alt="" loading="lazy" onError={() => markBroken(image)} />
                </button>
              ))}
            </div>
          )}
        </section>

        <section className="content-card pd-info">
          <h3 className="pd-info-title">Description</h3>
          <p className="pd-description">{product.description || 'No description yet.'}</p>

          <dl className="pd-facts">
            <div>
              <dt>SKU</dt>
              <dd>{product.sku}</dd>
            </div>
            <div>
              <dt>Barcode</dt>
              <dd>{product.barcode || labelVariant?.barcode || '—'}</dd>
            </div>
            <div>
              <dt>Category</dt>
              <dd>{product.category || '—'}</dd>
            </div>
            <div>
              <dt>Variants</dt>
              <dd>{product.variants.length}</dd>
            </div>
            {details.map(([label, value]) => (
              <div key={label}>
                <dt>{label}</dt>
                <dd>
                  <DetailValue value={value} />
                </dd>
              </div>
            ))}
          </dl>
        </section>
      </div>

      {labelVariant && (
        <Section title="Barcode Label" icon={<Package size={18} />} subtitle="Scan the barcode with any scanner; a phone camera reads the QR code as text.">
          <div className="pd-label">
            {product.variants.length > 1 && (
              <div className="pd-label-variants" role="group" aria-label="Variant">
                {product.variants.map((variant) => (
                  <button
                    key={variant.id}
                    type="button"
                    className={variant.id === labelVariant.id ? 'is-active' : ''}
                    onClick={() => setLabelVariantId(variant.id)}
                  >
                    {variant.option_value || variant.sku}
                  </button>
                ))}
              </div>
            )}
            <BarcodeLabel
              value={labelVariant.sku}
              details={[
                product.name,
                labelVariant.option_value,
                `Price ${labelVariant.price.toFixed(2)}`,
                `Available ${labelVariant.stock.available} of ${labelVariant.stock.on_hand}`,
              ]}
              caption={[product.name, labelVariant.option_value].filter(Boolean).join(' · ')}
              printable
            />
          </div>
        </Section>
      )}

      <Section title="Stock by Warehouse" icon={<Warehouse size={18} />} subtitle="Where this product is kept and how much of it can be sold.">
        <div className="table-responsive-wrapper">
          <table className="finovo-table">
            <thead>
              <tr>
                <th>Warehouse</th>
                <th style={{ textAlign: 'center' }}>On Hand</th>
                <th style={{ textAlign: 'center' }}>Reserved</th>
                <th style={{ textAlign: 'center' }}>Available</th>
                <th style={{ textAlign: 'center' }}>Damaged</th>
                <th style={{ textAlign: 'center' }}>In Transit</th>
              </tr>
            </thead>
            <tbody>
              {product.warehouses.length === 0 && (
                <tr>
                  <td colSpan={6} className="pd-empty">
                    Not stocked in any warehouse yet.
                  </td>
                </tr>
              )}
              {product.warehouses.map((warehouse) => (
                <tr key={warehouse.id}>
                  <td>
                    <Link to={`/warehouses/${warehouse.id}`} className="pd-link">
                      {warehouse.name}
                    </Link>
                    <div className="pd-muted">{warehouse.code}</div>
                  </td>
                  <td style={{ textAlign: 'center', fontWeight: 600 }}>{warehouse.on_hand}</td>
                  <td style={{ textAlign: 'center', color: '#d97706' }}>{warehouse.reserved}</td>
                  <td style={{ textAlign: 'center' }}>
                    <span className="kpi-pill kpi-pill-green">{warehouse.available}</span>
                  </td>
                  <td style={{ textAlign: 'center' }}>{warehouse.damaged}</td>
                  <td style={{ textAlign: 'center' }}>{warehouse.in_transit}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Variants" icon={<Boxes size={18} />} subtitle="Each sellable version with its own SKU, barcode, price and stock.">
        <div className="table-responsive-wrapper">
          <table className="finovo-table">
            <thead>
              <tr>
                <th>Variant</th>
                <th>SKU / Barcode</th>
                <th style={{ textAlign: 'right' }}>Price</th>
                <th style={{ textAlign: 'right' }}>Cost</th>
                <th style={{ textAlign: 'center' }}>Available</th>
                <th>In Warehouses</th>
              </tr>
            </thead>
            <tbody>
              {product.variants.map((variant) => (
                <tr key={variant.id}>
                  <td>
                    <div className="pd-variant">
                      <ProductThumb src={variant.image_url ?? product.image_url} size={36} alt="" />
                      <div>
                        <div style={{ fontWeight: 600 }}>{variant.option_value || product.name}</div>
                        {variant.option_name && <div className="pd-muted">{variant.option_name}</div>}
                      </div>
                    </div>
                  </td>
                  <td>
                    <div style={{ fontWeight: 600 }}>{variant.sku}</div>
                    <div className="pd-muted">{variant.barcode || 'No barcode'}</div>
                  </td>
                  <td style={{ textAlign: 'right' }}>{formatMoney(variant.price)}</td>
                  <td style={{ textAlign: 'right' }}>{variant.cost_price !== null ? formatMoney(variant.cost_price) : '—'}</td>
                  <td style={{ textAlign: 'center' }}>
                    <span className="kpi-pill kpi-pill-green">
                      {variant.stock.available} of {variant.stock.on_hand}
                    </span>
                  </td>
                  <td className="pd-muted">
                    {variant.warehouses.length ? variant.warehouses.map((w) => `${w.name}: ${w.available}`).join(' · ') : 'None'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Sold In" icon={<StoreIcon size={18} />} subtitle="The connected stores selling this product, with each store's complete original record.">
        {product.stores.length === 0 ? (
          <p className="pd-empty">This product is not linked to a connected store.</p>
        ) : (
          <div className="pd-stores">
            {product.stores.map((store) => (
              <div key={store.id} className="pd-store">
                <div className="pd-store-head">
                  <strong>{store.name}</strong>
                  <span className="kpi-pill kpi-pill-blue">{store.platform ? platformLabel(store.platform) : 'Store'}</span>
                </div>
                <div className="pd-muted">
                  {store.listings.map((listing) => `${listing.external_sku || listing.external_name || 'Item'} (ID ${listing.external_id})`).join(' · ')}
                </div>
                {store.platform_data ? (
                  <details className="pd-raw">
                    <summary>All data from {store.name}</summary>
                    <pre>{JSON.stringify(store.platform_data, null, 2)}</pre>
                  </details>
                ) : (
                  <div className="pd-muted">Sync the store to load its full product record.</div>
                )}
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section title="Recent Stock Movements" icon={<History size={18} />} subtitle="The latest ten changes to this product's stock.">
        {product.recent_movements.length === 0 ? (
          <p className="pd-empty">
            <AlertTriangle size={14} /> No stock movements yet.
          </p>
        ) : (
          <div className="table-responsive-wrapper">
            <table className="finovo-table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Movement</th>
                  <th>Warehouse</th>
                  <th style={{ textAlign: 'right' }}>Quantity</th>
                  <th style={{ textAlign: 'right' }}>Balance After</th>
                  <th>Reference</th>
                </tr>
              </thead>
              <tbody>
                {product.recent_movements.map((movement) => (
                  <tr key={movement.id}>
                    <td className="pd-muted">{formatDate(movement.created_at, true)}</td>
                    <td style={{ textTransform: 'capitalize' }}>{humanize(movement.type)}</td>
                    <td>{movement.warehouse ?? '—'}</td>
                    <td style={{ textAlign: 'right', fontWeight: 600, color: movement.quantity < 0 ? '#dc2626' : '#059669' }}>
                      {movement.quantity > 0 ? `+${movement.quantity}` : movement.quantity}
                    </td>
                    <td style={{ textAlign: 'right' }}>{movement.balance_after ?? '—'}</td>
                    <td className="pd-muted">{movement.reference || movement.reason || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      {editing && (
        <ProductFormModal
          product={product}
          onClose={() => setEditing(false)}
          onSaved={(message) => {
            setEditing(false);
            reload(message);
          }}
        />
      )}
      {dialog}
    </div>
  );
};

export default ProductDetailPage;
