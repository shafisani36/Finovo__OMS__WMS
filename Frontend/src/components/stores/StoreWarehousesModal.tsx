import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { ArrowDown, ArrowUp, Loader2, Plus, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { StoreModel, WarehouseModel } from '../../services/omsService';
import { errorMessage } from '../../utils/errors';

interface Props {
  store: StoreModel;
  /** Every warehouse of the company. */
  warehouses: WarehouseModel[];
  onClose: () => void;
  onSaved: (message: string) => void;
}

/**
 * The warehouses a store ships from, in order: an order goes to the first warehouse that has all its stock. The
 * first one is the main warehouse, which also receives the stock the store's sync imports.
 */
export const StoreWarehousesModal: React.FC<Props> = ({ store, warehouses, onClose, onSaved }) => {
  const [ids, setIds] = useState<number[]>(() => store.warehouses.map((w) => w.id));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => e.key === 'Escape' && !saving && onClose();
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onClose, saving]);

  const nameOf = (id: number) => warehouses.find((w) => w.id === id) ?? store.warehouses.find((w) => w.id === id);
  const available = warehouses.filter((w) => !ids.includes(w.id));

  const move = (index: number, by: -1 | 1) =>
    setIds((prev) => {
      const next = [...prev];
      [next[index], next[index + by]] = [next[index + by], next[index]];
      return next;
    });

  const save = async () => {
    try {
      setSaving(true);
      setError(null);
      const res = await omsService.setStoreWarehouses(store.id, ids);
      onSaved(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The warehouses could not be saved.'));
    } finally {
      setSaving(false);
    }
  };

  return createPortal(
    <div className="modal-overlay" onClick={() => !saving && onClose()}>
      <div className="procurement-modal" style={{ maxWidth: 560 }} onClick={(e) => e.stopPropagation()} role="dialog" aria-labelledby="store-warehouses-title">
        <div className="procurement-modal-header">
          <div>
            <h3 id="store-warehouses-title" style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
              Warehouses for {store.name}
            </h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>Orders go to the first warehouse in the list that has all the stock.</p>
          </div>
          <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close" disabled={saving}>
            <X size={18} />
          </button>
        </div>

        <div className="procurement-modal-body link-modal-body">
          {error && <Alert type="error" message={error} />}

          {ids.length === 0 ? (
            <p className="link-empty">No warehouse chosen: any active warehouse of the company can fulfil this store's orders.</p>
          ) : (
            <ol className="link-list">
              {ids.map((id, index) => {
                const warehouse = nameOf(id);
                return (
                  <li key={id}>
                    <span className="link-rank">{index + 1}</span>
                    <div className="link-main">
                      <strong>{warehouse?.name ?? `Warehouse ${id}`}</strong>
                      <span>
                        {warehouse?.code}
                        {index === 0 ? ' · Main warehouse' : ''}
                        {warehouse && warehouse.is_active === false ? ' · Inactive' : ''}
                      </span>
                    </div>
                    <div className="link-actions">
                      <button type="button" className="topbar-icon-button" onClick={() => move(index, -1)} disabled={index === 0} aria-label={`Move ${warehouse?.name} up`}>
                        <ArrowUp size={15} />
                      </button>
                      <button
                        type="button"
                        className="topbar-icon-button"
                        onClick={() => move(index, 1)}
                        disabled={index === ids.length - 1}
                        aria-label={`Move ${warehouse?.name} down`}
                      >
                        <ArrowDown size={15} />
                      </button>
                      <button type="button" className="topbar-icon-button" onClick={() => setIds(ids.filter((w) => w !== id))} aria-label={`Remove ${warehouse?.name}`}>
                        <X size={15} />
                      </button>
                    </div>
                  </li>
                );
              })}
            </ol>
          )}

          {available.length > 0 && (
            <CustomDropdown
              value={null}
              onChange={(value) => value && setIds([...ids, Number(value)])}
              options={available.map((w) => ({ value: w.id, label: w.name, description: w.code }))}
              placeholder="Add a warehouse"
              icon={<Plus size={14} />}
              searchable={available.length > 6}
            />
          )}
        </div>

        <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn-outline" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button type="button" className="btn-primary" onClick={save} disabled={saving}>
              {saving && <Loader2 size={16} className="animate-spin" />}
              <span>Save Warehouses</span>
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
};

export default StoreWarehousesModal;
