import React, { useEffect, useState } from 'react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { DemandForecastRow } from '../../services/omsService';

const PERIODS = [7, 14, 30, 60, 90].map((days) => ({ value: days, label: `Sales of the last ${days} days` }));
const COVERS = [14, 30, 60, 90].map((days) => ({ value: days, label: `Order for ${days} days of stock` }));

const STATUS: Record<DemandForecastRow['status'], { label: string; pill: string }> = {
  order_now: { label: 'Order now', pill: 'kpi-pill-red' },
  order_soon: { label: 'Order soon', pill: 'kpi-pill-amber' },
  healthy: { label: 'Healthy', pill: 'kpi-pill-blue' },
};

const formatDate = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

/**
 * Demand forecast: how fast each item sells, how many days its stock lasts, the day it runs out, and how much
 * to order so it lasts through the supplier's lead time plus the chosen number of days.
 */
export const DemandForecast: React.FC<{ warehouseId?: number }> = ({ warehouseId }) => {
  const [days, setDays] = useState(30);
  const [coverDays, setCoverDays] = useState(30);
  const [rows, setRows] = useState<DemandForecastRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    setLoading(true);
    omsService
      .getDemandForecast({ warehouse_id: warehouseId, days, cover_days: coverDays })
      .then((res) => {
        if (!current) return;
        setRows(res.data);
        setError(null);
      })
      .catch((err) => current && setError(err instanceof Error && err.message ? err.message : 'The forecast could not be loaded.'))
      .finally(() => current && setLoading(false));

    return () => {
      current = false;
    };
  }, [warehouseId, days, coverDays]);

  return (
    <div style={{ marginTop: 28 }}>
      <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 16, gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h3 className="tab-content-header-title">Demand Forecast</h3>
          <p className="tab-content-header-subtitle">
            Daily sales speed from shipped and open orders. The suggested quantity covers the supplier lead time (from the reorder rule, 7 days
            without one) plus the chosen days, after the stock in hand and on the way.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <div style={{ width: 210 }}>
            <CustomDropdown value={days} onChange={(val) => setDays(Number(val))} options={PERIODS} searchable={false} size="sm" />
          </div>
          <div style={{ width: 210 }}>
            <CustomDropdown value={coverDays} onChange={(val) => setCoverDays(Number(val))} options={COVERS} searchable={false} size="sm" />
          </div>
        </div>
      </div>

      {error && <Alert type="error" message={error} />}

      <div className="table-responsive-wrapper">
        <table className="finovo-table">
          <thead>
            <tr>
              <th>Product & SKU</th>
              <th>Warehouse</th>
              <th style={{ textAlign: 'right' }}>Sold</th>
              <th style={{ textAlign: 'right' }}>Per Day</th>
              <th style={{ textAlign: 'right' }}>Available</th>
              <th style={{ textAlign: 'right' }}>Days Left</th>
              <th>Runs Out</th>
              <th style={{ textAlign: 'right' }}>Suggested Order</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={9} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                  Loading forecast…
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={9} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                  No sales in the last {days} days, so there is nothing to forecast yet.
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={`${row.warehouse_id}-${row.product_variant_id}`}>
                  <td>
                    <div style={{ display: 'flex', flexDirection: 'column' }}>
                      <span style={{ fontWeight: 600 }}>
                        {row.product_name}
                        {row.variant ? ` · ${row.variant}` : ''}
                      </span>
                      <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{row.sku}</span>
                    </div>
                  </td>
                  <td>{row.warehouse_name}</td>
                  <td style={{ textAlign: 'right' }}>{row.sold}</td>
                  <td style={{ textAlign: 'right' }}>{row.daily_rate}</td>
                  <td style={{ textAlign: 'right' }}>
                    {row.available}
                    {row.in_transit > 0 && <span style={{ color: '#2563eb' }}> +{row.in_transit}</span>}
                  </td>
                  <td style={{ textAlign: 'right', fontWeight: 700, color: row.status === 'order_now' ? '#dc2626' : '#0f172a' }}>{row.days_of_cover}</td>
                  <td>{row.available > 0 ? formatDate(row.stockout_date) : 'Out of stock'}</td>
                  <td style={{ textAlign: 'right', fontWeight: 700, color: '#16a34a' }}>{row.suggested_quantity > 0 ? `${row.suggested_quantity} units` : '–'}</td>
                  <td>
                    <span className={`kpi-pill ${STATUS[row.status].pill}`} style={{ fontSize: 10, textTransform: 'uppercase' }}>
                      {STATUS[row.status].label}
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

export default DemandForecast;
