import React, { useCallback, useEffect, useState } from 'react';
import { ArrowLeftRight, History, Loader2, ShoppingBag, Wrench } from 'lucide-react';
import { Alert } from '../common/Alert';
import omsService from '../../services/omsService';
import type { WarehouseActivityItem, WarehouseActivityType } from '../../services/omsService';
import { formatDate } from '../../utils/format';
import { errorText } from './warehouseTabs';

const FILTERS: { value: WarehouseActivityType; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'stock', label: 'Stock' },
  { value: 'orders', label: 'Orders' },
  { value: 'setup', label: 'Setup & staff' },
];

const ICONS: Record<WarehouseActivityItem['kind'], React.ElementType> = {
  stock: ArrowLeftRight,
  orders: ShoppingBag,
  setup: Wrench,
  staff: Wrench,
};

const KIND_LABELS: Record<WarehouseActivityItem['kind'], string> = { stock: 'Stock', orders: 'Orders', setup: 'Setup', staff: 'Staff' };

/** Activity tab: stock movements, order reservations and setup changes of the warehouse, newest first. */
export const WarehouseActivityTab: React.FC<{ warehouseId: number }> = ({ warehouseId }) => {
  const [type, setType] = useState<WarehouseActivityType>('all');
  const [items, setItems] = useState<WarehouseActivityItem[] | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(
    async (nextPage: number) => {
      try {
        setError(null);
        const res = await omsService.getWarehouseActivity(warehouseId, { type, page: nextPage, per_page: 20 });
        setItems((prev) => (nextPage === 1 ? res.data : [...(prev ?? []), ...res.data]));
        setPage(res.meta.current_page);
        setLastPage(res.meta.last_page);
      } catch (err) {
        setError(errorText(err, 'Failed to load the activity.'));
        setItems((prev) => prev ?? []);
      }
    },
    [warehouseId, type]
  );

  useEffect(() => {
    setItems(null);
    load(1);
  }, [load]);

  const loadMore = async () => {
    setLoadingMore(true);
    await load(page + 1);
    setLoadingMore(false);
  };

  return (
    <section className="wh-card" aria-labelledby="wh-activity-title">
      <div className="wh-card-head">
        <div>
          <h2 id="wh-activity-title" className="wh-card-title">Activity Log</h2>
          <p className="wh-card-subtitle">Every stock movement and setup change in this warehouse.</p>
        </div>
        <div className="wh-tabs is-muted" role="tablist" aria-label="Activity type">
          {FILTERS.map((filter) => (
            <button
              key={filter.value}
              type="button"
              role="tab"
              aria-selected={type === filter.value}
              className={`wh-tab ${type === filter.value ? 'is-active' : ''}`}
              onClick={() => setType(filter.value)}
            >
              {filter.label}
            </button>
          ))}
        </div>
      </div>

      {error && <Alert type="error" message={error} />}

      {items === null ? (
        <div className="wh-loading">
          <Loader2 className="animate-spin" size={28} />
        </div>
      ) : items.length === 0 ? (
        <div className="wh-empty">
          <History size={32} color="#cbd5e1" />
          <h4>Nothing here yet</h4>
          <p>Stock movements and changes to this warehouse will show up here.</p>
        </div>
      ) : (
        <>
          {items.map((item) => {
            const Icon = ICONS[item.kind];
            return (
              <div key={item.id} className="wh-activity">
                <span className={`wh-activity-icon is-${item.kind}`} aria-hidden="true">
                  <Icon size={15} />
                </span>
                <div className="wh-activity-body">
                  <strong>{item.who}</strong> · {item.text}
                  <div className="wh-activity-time">{formatDate(item.at, true)}</div>
                </div>
                <span className="wh-badge is-gray" style={{ alignSelf: 'flex-start' }}>{KIND_LABELS[item.kind]}</span>
              </div>
            );
          })}
          {page < lastPage && (
            <div style={{ display: 'flex', justifyContent: 'center', marginTop: 12 }}>
              <button type="button" className="btn-finovo-secondary" onClick={loadMore} disabled={loadingMore}>
                {loadingMore && <Loader2 size={14} className="animate-spin" />}
                <span>Show more</span>
              </button>
            </div>
          )}
        </>
      )}
    </section>
  );
};

export default WarehouseActivityTab;
