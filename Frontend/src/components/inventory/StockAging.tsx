import React, { useEffect, useState } from 'react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { AgingBuckets, StockAgingRow, StockMovementClass } from '../../services/omsService';
import { formatDate } from '../../utils/format';
import { errorMessage } from '../../utils/errors';

const BUCKETS: { key: keyof AgingBuckets; label: string }[] = [
  { key: '0_30', label: '0–30 days' },
  { key: '31_60', label: '31–60 days' },
  { key: '61_90', label: '61–90 days' },
  { key: 'over_90', label: 'Over 90 days' },
];

const MOVEMENT: Record<StockMovementClass, { label: string; pill: string }> = {
  fast: { label: 'Fast moving', pill: 'kpi-pill-green' },
  slow: { label: 'Slow moving', pill: 'kpi-pill-amber' },
  non_moving: { label: 'Not moving', pill: 'kpi-pill-red' },
};

const FILTERS = [{ value: '', label: 'All products' }, ...Object.entries(MOVEMENT).map(([value, { label }]) => ({ value, label }))];

/**
 * Stock aging: how long the units on hand have been in the warehouse, when each product last sold and whether it
 * moves fast (sold at least its stock in 90 days), slowly or not at all.
 */
export const StockAging: React.FC<{ warehouseId?: number; storeId?: number }> = ({ warehouseId, storeId }) => {
  const [movement, setMovement] = useState<StockMovementClass | ''>('');
  const [rows, setRows] = useState<StockAgingRow[]>([]);
  const [totals, setTotals] = useState<{ on_hand: number; buckets: AgingBuckets } | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    setLoading(true);
    omsService
      .getStockAging({ warehouse_id: warehouseId, store_id: storeId, movement: movement || undefined })
      .then((res) => {
        if (!current) return;
        setRows(res.data);
        setTotals(res.totals);
        setError(null);
      })
      .catch((err) => current && setError(errorMessage(err, 'Stock aging could not be loaded.')))
      .finally(() => current && setLoading(false));

    return () => {
      current = false;
    };
  }, [warehouseId, storeId, movement]);

  return (
    <div style={{ marginTop: 28 }}>
      <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 16, gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h3 className="tab-content-header-title">Stock Aging</h3>
          <p className="tab-content-header-subtitle">Units on hand by how long they have been in stock, with the last sale and sales of the last 90 days.</p>
        </div>
        <div style={{ width: 200 }}>
          <CustomDropdown value={movement} onChange={(val) => setMovement(val as StockMovementClass | '')} options={FILTERS} searchable={false} size="sm" />
        </div>
      </div>

      {error && <Alert type="error" message={error} />}

      {totals && (
        <div className="valuation-summary">
          {BUCKETS.map((bucket) => (
            <div key={bucket.key}>
              <span>{bucket.label}</span>
              <strong>{totals.buckets[bucket.key]}</strong>
              <small>units</small>
            </div>
          ))}
        </div>
      )}

      <div className="table-responsive-wrapper">
        <table className="finovo-table">
          <thead>
            <tr>
              <th>Product & SKU</th>
              <th>Warehouse</th>
              <th style={{ textAlign: 'right' }}>On Hand</th>
              {BUCKETS.map((bucket) => (
                <th key={bucket.key} style={{ textAlign: 'right' }}>
                  {bucket.label}
                </th>
              ))}
              <th style={{ textAlign: 'right' }}>Oldest</th>
              <th>Last Sold</th>
              <th style={{ textAlign: 'right' }}>Sold (90 days)</th>
              <th>Movement</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={11} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                  Loading stock aging…
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={11} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                  No stock on hand{movement ? ' in this group' : ''}.
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={`${row.warehouse}-${row.product_variant_id}`}>
                  <td>
                    <div style={{ display: 'flex', flexDirection: 'column' }}>
                      <span style={{ fontWeight: 600 }}>{row.product_name}</span>
                      <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{row.sku}</span>
                    </div>
                  </td>
                  <td>{row.warehouse}</td>
                  <td style={{ textAlign: 'right', fontWeight: 700 }}>{row.on_hand}</td>
                  {BUCKETS.map((bucket) => (
                    <td key={bucket.key} style={{ textAlign: 'right', color: row.buckets[bucket.key] ? '#0f172a' : '#cbd5e1' }}>
                      {row.buckets[bucket.key]}
                    </td>
                  ))}
                  <td style={{ textAlign: 'right' }}>{row.oldest_days} days</td>
                  <td>{row.last_sold_at ? formatDate(row.last_sold_at) : 'Never'}</td>
                  <td style={{ textAlign: 'right' }}>{row.sold_90_days}</td>
                  <td>
                    <span className={`kpi-pill ${MOVEMENT[row.movement].pill}`} style={{ fontSize: 10, textTransform: 'uppercase' }}>
                      {MOVEMENT[row.movement].label}
                    </span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default StockAging;
