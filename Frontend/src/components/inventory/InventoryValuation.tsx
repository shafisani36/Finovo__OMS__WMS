import React, { useEffect, useState } from 'react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { ValuationMethod, ValuationMode, ValuationReport } from '../../services/omsService';
import { useAuth } from '../../hooks/useAuth';
import { can } from '../../types/auth';
import { errorMessage } from '../../utils/errors';

const METHODS: { value: ValuationMethod; label: string; description: string }[] = [
  { value: 'fifo', label: 'FIFO: first in, first out', description: 'The oldest units leave first; the stock left is valued at the latest purchase costs.' },
  { value: 'lifo', label: 'LIFO: last in, first out', description: 'The newest units leave first; the stock left is valued at the earliest purchase costs.' },
  { value: 'avco', label: 'AVCO: weighted average cost', description: 'Every unit carries the average cost of all units bought.' },
];

const MODES: { value: ValuationMode; label: string; description: string }[] = [
  { value: 'perpetual', label: 'Perpetual', description: 'Cost is taken at every sale, as it happens' },
  { value: 'periodic', label: 'Periodic', description: 'Cost is worked out once, at the end of the period' },
];

const PERIODS = [1, 3, 6, 12].map((months) => ({ value: months, label: months === 12 ? 'Last 12 months' : `Last ${months} month${months > 1 ? 's' : ''}` }));

const amount = (value: number) => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const day = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

/**
 * Inventory valuation: opening stock, received, cost of goods sold and closing stock per product with FIFO, LIFO
 * or AVCO, perpetual or periodic, over the last 1 to 12 months. Choosing a method saves it for the company.
 */
export const InventoryValuation: React.FC<{ warehouseId?: number; storeId?: number }> = ({ warehouseId, storeId }) => {
  const { user } = useAuth();
  const canSaveMethod = can(user, 'inventory.adjust');
  const [method, setMethod] = useState<ValuationMethod | undefined>();
  const [mode, setMode] = useState<ValuationMode>('perpetual');
  const [months, setMonths] = useState(1);
  const [report, setReport] = useState<ValuationReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    setLoading(true);
    omsService
      .getInventoryValuation({ method, mode, months, warehouse_id: warehouseId, store_id: storeId })
      .then((res) => {
        if (!current) return;
        setReport(res);
        setError(null);
      })
      .catch((err) => current && setError(errorMessage(err, 'The valuation could not be loaded.')))
      .finally(() => current && setLoading(false));

    return () => {
      current = false;
    };
  }, [method, mode, months, warehouseId, storeId]);

  const chooseMethod = async (value: ValuationMethod) => {
    setMethod(value);
    setNotice(null);
    if (!canSaveMethod) return;
    try {
      setNotice((await omsService.setValuationMethod(value)).message);
    } catch (err) {
      setError(errorMessage(err, 'The valuation method could not be saved.'));
    }
  };

  const shown = report?.method ?? method ?? 'fifo';
  const totals = report?.totals;

  return (
    <div>
      <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 16, gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h3 className="tab-content-header-title">Inventory Valuation</h3>
          <p className="tab-content-header-subtitle">
            {METHODS.find((m) => m.value === shown)?.description}{' '}
            {report && `${day(report.from)} to ${day(report.to)}.`}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <div style={{ width: 240 }}>
            <CustomDropdown value={shown} onChange={(val) => chooseMethod(val as ValuationMethod)} options={METHODS} searchable={false} size="sm" />
          </div>
          <div style={{ width: 170 }}>
            <CustomDropdown value={mode} onChange={(val) => setMode(val as ValuationMode)} options={MODES} searchable={false} size="sm" />
          </div>
          <div style={{ width: 170 }}>
            <CustomDropdown value={months} onChange={(val) => setMonths(Number(val))} options={PERIODS} searchable={false} size="sm" />
          </div>
        </div>
      </div>

      {error && <Alert type="error" message={error} />}
      {notice && <Alert type="success" message={notice} />}

      {totals && (
        <div className="valuation-summary">
          <div><span>Opening stock</span><strong>{amount(totals.opening_value)}</strong><small>{totals.opening_quantity} units</small></div>
          <div><span>Received</span><strong>{amount(totals.in_value)}</strong><small>{totals.in_quantity} units</small></div>
          <div><span>Cost of goods sold</span><strong>{amount(totals.cost_of_goods_sold)}</strong><small>{totals.out_quantity} units out</small></div>
          <div><span>Closing stock</span><strong>{amount(totals.closing_value)}</strong><small>{totals.closing_quantity} units</small></div>
        </div>
      )}

      <div className="table-responsive-wrapper">
        <table className="finovo-table">
          <thead>
            <tr>
              <th>Product & SKU</th>
              <th style={{ textAlign: 'right' }}>Opening</th>
              <th style={{ textAlign: 'right' }}>Received</th>
              <th style={{ textAlign: 'right' }}>Out</th>
              <th style={{ textAlign: 'right' }}>Cost of Goods Sold</th>
              <th style={{ textAlign: 'right' }}>Closing</th>
              <th style={{ textAlign: 'right' }}>Unit Cost</th>
              <th style={{ textAlign: 'right' }}>Closing Value</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={8} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                  Loading valuation…
                </td>
              </tr>
            ) : !report || report.data.length === 0 ? (
              <tr>
                <td colSpan={8} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                  No stock and no movements in this period.
                </td>
              </tr>
            ) : (
              report.data.map((row) => (
                <tr key={row.variant_id}>
                  <td>
                    <div style={{ display: 'flex', flexDirection: 'column' }}>
                      <span style={{ fontWeight: 600 }}>{row.product_name}</span>
                      <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{row.sku}</span>
                    </div>
                  </td>
                  <td style={{ textAlign: 'right' }}>{row.opening_quantity}</td>
                  <td style={{ textAlign: 'right' }}>{row.in_quantity}</td>
                  <td style={{ textAlign: 'right' }}>{row.out_quantity}</td>
                  <td style={{ textAlign: 'right' }}>{amount(row.cost_of_goods_sold)}</td>
                  <td style={{ textAlign: 'right' }}>{row.closing_quantity}</td>
                  <td style={{ textAlign: 'right' }}>{amount(row.unit_cost)}</td>
                  <td style={{ textAlign: 'right', fontWeight: 700 }}>{amount(row.closing_value)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default InventoryValuation;
