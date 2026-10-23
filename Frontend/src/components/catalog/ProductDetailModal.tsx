import React from 'react';
import { X } from 'lucide-react';
import type { ProductModel } from '../../services/omsService';
import { platformLabel } from '../stores/storePlatforms';
import ProductThumb from './ProductThumb';
import StatusPill from '../common/StatusPill';
import { variantLabel } from '../../utils/format';
import { BarcodeLabel } from '../barcode/BarcodeLabel';
import './DetailModal.css';

export const ProductDetailModal: React.FC<{ product: ProductModel; onClose: () => void }> = ({ product, onClose }) => {
  const variants = product.variants ?? [];

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-dialog detail-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label={product.name}>
        <div className="detail-modal-head">
          <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700 }}>Product Details</h3>
          <button onClick={onClose} className="btn-finovo-secondary" style={{ padding: 6 }} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        <div className="detail-modal-body">
          <div className="detail-modal-hero">
            <ProductThumb src={product.image_url} size={120} alt={product.name} />
            <div style={{ minWidth: 0 }}>
              <h2 style={{ margin: 0, fontSize: 20, fontWeight: 700, color: '#0f172a' }}>{product.name}</h2>
              <div className="detail-muted" style={{ marginTop: 4 }}>SKU: {product.sku}</div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>
                <StatusPill status={product.is_active ? 'active' : 'pending'} label={product.is_active ? 'Active' : 'Archived'} />
                {product.category && <span className="kpi-pill kpi-pill-blue">{product.category}</span>}
                {(product.stores ?? []).map((store) => (
                  <span key={store.id} className="detail-variant-chip" style={{ marginTop: 0 }}>
                    {store.name} · {platformLabel(store.platform)}
                  </span>
                ))}
              </div>
              {product.description && (
                <p className="detail-description">{product.description}</p>
              )}
            </div>
          </div>

          <div className="detail-modal-facts">
            <div>
              <div className="detail-fact-label">On Hand</div>
              <div className="detail-fact-value">{product.stock?.on_hand ?? 0}</div>
            </div>
            <div>
              <div className="detail-fact-label">Reserved</div>
              <div className="detail-fact-value">{product.stock?.reserved ?? 0}</div>
            </div>
            <div>
              <div className="detail-fact-label">Available</div>
              <div className="detail-fact-value">{product.stock?.available ?? 0}</div>
            </div>
          </div>

          <h4 className="detail-section-title">Variants ({variants.length})</h4>
          <div className="table-responsive-wrapper">
            <table className="detail-lines">
              <thead>
                <tr>
                  <th>Variant</th>
                  <th>SKU</th>
                  <th>Barcode</th>
                  <th style={{ textAlign: 'right' }}>Price</th>
                  <th style={{ textAlign: 'center' }}>On Hand</th>
                  <th style={{ textAlign: 'center' }}>Available</th>
                </tr>
              </thead>
              <tbody>
                {variants.length === 0 && (
                  <tr>
                    <td colSpan={6} className="detail-muted">No variants.</td>
                  </tr>
                )}
                {variants.map((variant) => (
                  <tr key={variant.id}>
                    <td style={{ fontWeight: 600 }}>{variantLabel(variant) ?? 'Default'}</td>
                    <td>{variant.sku}</td>
                    <td>{variant.barcode || '—'}</td>
                    <td style={{ textAlign: 'right' }}>{(Number(variant.price) || 0).toFixed(2)}</td>
                    <td style={{ textAlign: 'center' }}>{variant.stock?.on_hand ?? 0}</td>
                    <td style={{ textAlign: 'center' }}>
                      <span className="kpi-pill kpi-pill-green">{variant.stock?.available ?? 0}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {variants.length > 0 && (
            <>
              <h4 className="detail-section-title">Barcode Labels</h4>
              <div className="barcode-panel">
                {variants.map((variant) => (
                  <BarcodeLabel key={variant.id} value={variant.sku} caption={`${product.name}${variantLabel(variant) ? ` · ${variantLabel(variant)}` : ''}`} printable />
                ))}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default ProductDetailModal;
