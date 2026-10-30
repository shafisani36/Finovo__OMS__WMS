import React, { useCallback, useEffect, useState } from 'react';
import { ArrowRight, Loader2 } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import omsService from '../../services/omsService';
import type { SlottingAnalysis, SlottingArea, SlottingMove } from '../../services/omsService';
import { useAuth } from '../../hooks/useAuth';
import { can } from '../../types/auth';
import { errorMessage } from '../../utils/errors';

const PERIODS = [30, 90, 180, 365].map((days) => ({ value: String(days), label: `Sales of the last ${days} days` }));

const AREA_LABELS: Record<SlottingArea, string> = { picking: 'Picking area', bulk: 'Bulk storage', general: 'General storage' };

const CLASS_BADGE: Record<'A' | 'B' | 'C', string> = { A: 'is-green', B: '', C: 'is-gray' };

const moveKey = (move: SlottingMove) => `${move.product_variant_id}-${move.from.id}-${move.lot_number ?? ''}`;

/**
 * Slotting tab: products ranked A, B or C by sales value, where their stock is, and the moves that put fast
 * sellers in the picking area (next to packing) and slow sellers in bulk storage.
 */
export const WarehouseSlottingTab: React.FC<{ warehouseId: number }> = ({ warehouseId }) => {
  const { user } = useAuth();
  const canMove = can(user, 'inventory.transfer');
  const [days, setDays] = useState(90);
  const [data, setData] = useState<SlottingAnalysis | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [moving, setMoving] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      setData(await omsService.getWarehouseSlotting(warehouseId, days));
    } catch (err) {
      setError(errorMessage(err, 'Failed to load the slotting analysis.'));
    }
  }, [warehouseId, days]);

  useEffect(() => {
    setData(null);
    load();
  }, [load]);

  const move = async (item: SlottingMove) => {
    try {
      setMoving(moveKey(item));
      setNotice(null);
      const res = await omsService.moveWarehouseStock(warehouseId, {
        product_variant_id: item.product_variant_id,
        from_location_id: item.from.id,
        to_location_id: item.to.id,
        quantity: item.quantity,
        lot_number: item.lot_number,
      });
      setNotice(res.message);
      await load();
    } catch (err) {
      setError(errorMessage(err, 'The stock could not be moved.'));
    } finally {
      setMoving(null);
    }
  };

  if (error && !data) return <Alert type="error" message={error} />;
  if (!data) {
    return (
      <div className="wh-loading">
        <Loader2 size={22} className="animate-spin" />
      </div>
    );
  }

  return (
    <div className="wh-stack">
      <div className="wh-card">
        <div className="wh-card-head" style={{ marginBottom: 0 }}>
          <div>
            <h3 className="wh-card-title">ABC Slotting</h3>
            <p className="wh-card-subtitle">
              A items make up the first 80% of sales value, B the next 15%, C the rest and items that did not sell. A items belong in the picking
              area next to packing; C items in bulk storage.
            </p>
          </div>
          <div style={{ width: 210 }}>
            <CustomDropdown value={String(days)} onChange={(value) => setDays(Number(value))} options={PERIODS} searchable={false} size="sm" />
          </div>
        </div>
        <div className="wh-perf-summary">
          <span className="wh-badge is-green">{data.summary.a_items} A items</span>
          <span className="wh-badge">{data.summary.b_items} B items</span>
          <span className="wh-badge is-gray">{data.summary.c_items} C items</span>
          <span className={`wh-badge ${data.summary.misplaced_units ? 'is-amber' : 'is-green'}`}>{data.summary.misplaced_units} units in the wrong area</span>
        </div>
        {data.summary.picking_bins === 0 && (
          <Alert
            type="info"
            className="wh-slot-note"
            message="This warehouse has no picking area yet. Add a top-level location of type Picking area with bins under it, so fast sellers can be placed next to packing."
          />
        )}
      </div>

      {error && <Alert type="error" message={error} />}
      {notice && <Alert type="success" message={notice} />}

      <div className="wh-card">
        <div className="wh-card-head">
          <div>
            <h3 className="wh-card-title">Suggested Moves</h3>
            <p className="wh-card-subtitle">Each move goes to a bin with room. Doing it records a bin-to-bin movement in the stock ledger.</p>
          </div>
        </div>
        {data.moves.length === 0 ? (
          <div className="wh-empty">Every product is already in the right area.</div>
        ) : (
          <div className="wh-table-wrap">
            <table className="wh-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Class</th>
                  <th>Move</th>
                  <th style={{ textAlign: 'right' }}>Units</th>
                  <th>Why</th>
                  {canMove && <th />}
                </tr>
              </thead>
              <tbody>
                {data.moves.map((item) => (
                  <tr key={moveKey(item)}>
                    <td>
                      <strong>{item.name}</strong>
                      <div className="wh-slot-muted">
                        {item.sku}
                        {item.lot_number ? ` · Lot ${item.lot_number}` : ''}
                      </div>
                    </td>
                    <td>
                      <span className={`wh-badge ${CLASS_BADGE[item.abc_class]}`}>{item.abc_class}</span>
                    </td>
                    <td>
                      <span className="wh-slot-move">
                        {item.from.code} <ArrowRight size={13} /> {item.to.code}
                      </span>
                      <div className="wh-slot-muted">
                        {AREA_LABELS[item.from.area]} to {AREA_LABELS[item.to.area].toLowerCase()}
                      </div>
                    </td>
                    <td style={{ textAlign: 'right', fontWeight: 700 }}>{item.quantity}</td>
                    <td className="wh-slot-wrap">{item.reason}</td>
                    {canMove && (
                      <td>
                        <button type="button" className="btn-finovo-secondary" onClick={() => move(item)} disabled={moving !== null}>
                          {moving === moveKey(item) ? <Loader2 size={13} className="animate-spin" /> : null}
                          <span>Move</span>
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="wh-card">
        <div className="wh-card-head">
          <div>
            <h3 className="wh-card-title">Products by Class</h3>
            <p className="wh-card-subtitle">Sales from this warehouse over the last {data.days} days, highest value first.</p>
          </div>
        </div>
        {data.items.length === 0 ? (
          <div className="wh-empty">No sales or stock in this warehouse yet.</div>
        ) : (
          <div className="wh-table-wrap">
            <table className="wh-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Class</th>
                  <th style={{ textAlign: 'right' }}>Units Sold</th>
                  <th style={{ textAlign: 'right' }}>Share of Sales</th>
                  <th style={{ textAlign: 'right' }}>In Stock</th>
                  <th>Bins</th>
                  <th>Belongs In</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((item) => (
                  <tr key={item.product_variant_id}>
                    <td>
                      <strong>{item.name}</strong>
                      <div className="wh-slot-muted">
                        {item.sku}
                        {item.variant ? ` · ${item.variant}` : ''}
                      </div>
                    </td>
                    <td>
                      <span className={`wh-badge ${CLASS_BADGE[item.abc_class]}`}>{item.abc_class}</span>
                    </td>
                    <td style={{ textAlign: 'right' }}>{item.units_sold.toLocaleString()}</td>
                    <td style={{ textAlign: 'right' }}>{item.value_share}%</td>
                    <td style={{ textAlign: 'right' }}>{item.on_hand.toLocaleString()}</td>
                    <td className="wh-slot-wrap">{item.bins.length ? item.bins.map((bin) => `${bin.code} (${bin.quantity})`).join(', ') : '—'}</td>
                    <td>
                      {AREA_LABELS[item.preferred_area]}
                      {item.misplaced_units > 0 && <div className="wh-slot-warning">{item.misplaced_units} units elsewhere</div>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};

export default WarehouseSlottingTab;
