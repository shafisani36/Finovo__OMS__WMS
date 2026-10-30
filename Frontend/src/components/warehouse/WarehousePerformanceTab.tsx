import React, { useCallback, useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { WarehousePerformance, WarehousePerformanceMeasure } from '../../services/omsService';
import { formatDate } from '../../utils/format';
import { errorMessage } from '../../utils/errors';

const PERIODS = [7, 30, 90].map((days) => ({ value: String(days), label: `Last ${days} days` }));

const isoDaysAgo = (days: number) => {
  const date = new Date();
  date.setDate(date.getDate() - days + 1);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const formatValue = (value: number, unit: string) => (unit === '%' ? `${value}%` : `${value.toLocaleString()} ${unit}`);

/** Where the measure stands against best-in-class: green when met, amber when below, grey without data. */
const Standing: React.FC<{ measure: WarehousePerformanceMeasure }> = ({ measure }) => {
  if (measure.value === null) return <span className="wh-badge is-gray">No data yet</span>;
  if (measure.meets === null) return <span className="wh-badge is-gray">For information</span>;
  return measure.meets ? <span className="wh-badge is-green">Best-in-class</span> : <span className="wh-badge is-amber">Below best-in-class</span>;
};

/**
 * Performance tab: the warehouse's WERC measures (accuracy, speed, capacity, receiving quality) for a period,
 * each next to the best-in-class value.
 */
export const WarehousePerformanceTab: React.FC<{ warehouseId: number }> = ({ warehouseId }) => {
  const [days, setDays] = useState(30);
  const [data, setData] = useState<WarehousePerformance | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      setData(await omsService.getWarehousePerformance(warehouseId, { from: isoDaysAgo(days) }));
    } catch (err) {
      setError(errorMessage(err, 'Failed to load the performance measures.'));
    }
  }, [warehouseId, days]);

  useEffect(() => {
    setData(null);
    load();
  }, [load]);

  return (
    <div className="wh-stack">
      <div className="wh-card">
        <div className="wh-card-head" style={{ marginBottom: 0 }}>
          <div>
            <h3 className="wh-card-title">Warehouse Performance</h3>
            <p className="wh-card-subtitle">
              Measured from scans, receipts, shipments and counts, against WERC 2025 best-in-class values.
              {data && ` ${formatDate(data.period.from)} – ${formatDate(data.period.to)}.`}
            </p>
          </div>
          <div style={{ width: 170 }}>
            <CustomDropdown value={String(days)} onChange={(value) => setDays(Number(value))} options={PERIODS} searchable={false} size="sm" />
          </div>
        </div>
        {data && (
          <div className="wh-perf-summary">
            <span className="wh-badge is-green">{data.summary.meeting_benchmark} at best-in-class</span>
            <span className="wh-badge is-amber">{data.summary.below_benchmark} below</span>
            <span className="wh-badge is-gray">{data.summary.no_data} without data yet</span>
          </div>
        )}
      </div>

      {error && <Alert type="error" message={error} />}
      {!data && !error && (
        <div className="wh-loading">
          <Loader2 size={22} className="animate-spin" />
        </div>
      )}

      {data && (
        <div className="wh-grid wh-grid-thirds">
          {data.measures.map((measure) => (
            <div key={measure.key} className="wh-card wh-perf-card">
              <div className="wh-kpi">
                <div className="wh-kpi-row">
                  <span className="wh-kpi-label">{measure.label}</span>
                  <Standing measure={measure} />
                </div>
                <span className="wh-kpi-value">{measure.value === null ? '—' : formatValue(measure.value, measure.unit)}</span>
                <span className="wh-perf-description">{measure.description}</span>
                <div className="wh-perf-foot">
                  <span>
                    {measure.benchmark !== null
                      ? `Best-in-class: ${measure.better === 'higher' ? 'at least' : 'at most'} ${formatValue(measure.benchmark, measure.unit)}`
                      : 'No industry benchmark'}
                  </span>
                  <span>
                    Based on {measure.sample.toLocaleString()} {measure.sample_label}
                  </span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default WarehousePerformanceTab;
