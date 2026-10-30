import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import omsService from '../../services/omsService';
import type { StoreModel, WarehouseModel } from '../../services/omsService';
import { platformLabel } from '../stores/storePlatforms';
import { errorMessage } from '../../utils/errors';

interface Props {
  warehouse: WarehouseModel;
  onClose: () => void;
  onSaved: (message: string) => void;
}

/**
 * The stores a warehouse fulfils. A newly ticked store ships from this warehouse after its other warehouses; the
 * order is changed on the Stores page (store menu → Warehouses).
 */
export const WarehouseStoresModal: React.FC<Props> = ({ warehouse, onClose, onSaved }) => {
  const [stores, setStores] = useState<StoreModel[] | null>(null);
  const [selected, setSelected] = useState<number[]>(() => (warehouse.stores ?? []).map((s) => s.id));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    omsService
      .getStores({ per_page: 100 })
      .then((res) => setStores(res.data))
      .catch((err) => {
        setStores([]);
        setError(errorMessage(err, 'The stores could not be loaded.'));
      });
  }, []);

  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => e.key === 'Escape' && !saving && onClose();
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onClose, saving]);

  const toggle = (id: number) => setSelected((prev) => (prev.includes(id) ? prev.filter((s) => s !== id) : [...prev, id]));

  const save = async () => {
    try {
      setSaving(true);
      setError(null);
      const res = await omsService.setWarehouseStores(warehouse.id, selected);
      onSaved(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The stores could not be saved.'));
    } finally {
      setSaving(false);
    }
  };

  /** Where this warehouse is (or would be) in the store's list of warehouses. */
  const placeIn = (store: StoreModel) => {
    const index = store.warehouses.findIndex((w) => w.id === warehouse.id);
    if (index === 0) return 'Main warehouse';
    if (index > 0) return `Warehouse ${index + 1} of ${store.warehouses.length}`;
    return store.warehouses.length ? `Also ships from ${store.warehouses.map((w) => w.name).join(', ')}` : 'No warehouse yet';
  };

  return createPortal(
    <div className="modal-overlay" onClick={() => !saving && onClose()}>
      <div className="procurement-modal" style={{ maxWidth: 560 }} onClick={(e) => e.stopPropagation()} role="dialog" aria-labelledby="warehouse-stores-title">
        <div className="procurement-modal-header">
          <div>
            <h3 id="warehouse-stores-title" style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
              Stores served by {warehouse.name}
            </h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>Orders of the ticked stores can be fulfilled from this warehouse.</p>
          </div>
          <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close" disabled={saving}>
            <X size={18} />
          </button>
        </div>

        <div className="procurement-modal-body link-modal-body">
          {error && <Alert type="error" message={error} />}
          {stores === null ? (
            <Loader2 size={20} className="animate-spin" color="#1e56ff" />
          ) : stores.length === 0 ? (
            <p className="link-empty">No stores yet. Add one on the Stores page.</p>
          ) : (
            <div className="link-checks">
              {stores.map((store) => (
                <label key={store.id} className={selected.includes(store.id) ? 'is-selected' : ''}>
                  <input type="checkbox" checked={selected.includes(store.id)} onChange={() => toggle(store.id)} />
                  <span>
                    <strong>{store.name}</strong>
                    <small>
                      {platformLabel(store.platform)} · {placeIn(store)}
                    </small>
                  </span>
                </label>
              ))}
            </div>
          )}
        </div>

        <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn-outline" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button type="button" className="btn-primary" onClick={save} disabled={saving || stores === null}>
              {saving && <Loader2 size={16} className="animate-spin" />}
              <span>Save Stores</span>
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
};

export default WarehouseStoresModal;
