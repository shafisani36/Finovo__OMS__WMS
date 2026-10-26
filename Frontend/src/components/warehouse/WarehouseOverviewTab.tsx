import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { Alert } from '../common/Alert';
import omsService from '../../services/omsService';
import type { WarehouseModel, WarehouseOverview, WarehouseReadinessModel } from '../../services/omsService';
import { useAuth } from '../../hooks/useAuth';
import { can } from '../../types/auth';
import { humanize } from '../../utils/format';
import type { WarehouseTab } from './warehouseTabs';

const DONUT_RADIUS = 68;
const DONUT_LENGTH = 2 * Math.PI * DONUT_RADIUS;

const number = (value: number) => value.toLocaleString();

interface Props {
  warehouse: WarehouseModel;
  readiness: WarehouseReadinessModel | null;
  onOpenTab: (tab: WarehouseTab) => void;
}

/**
 * Overview tab: stock figures, stock by category, bin capacity, quick links to the other tabs and the
 * facility details. Every number comes from the warehouse's stock balances and locations.
 */
export const WarehouseOverviewTab: React.FC<Props> = ({ warehouse, readiness, onOpenTab }) => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [overview, setOverview] = useState<WarehouseOverview | null>(null);
  const [ordersToPick, setOrdersToPick] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    omsService
      .getWarehouseOverview(warehouse.id)
      .then((res) => active && setOverview(res.data))
      .catch((err) => active && setError(err instanceof Error ? err.message : 'Failed to load the overview.'));

    if (can(user, 'orders.view')) {
      omsService
        .getOrders({ warehouse_id: warehouse.id, status: 'allocated', per_page: 1 })
        .then((res) => active && setOrdersToPick(res.meta.total))
        .catch(() => active && setOrdersToPick(null));
    }

    return () => {
      active = false;
    };
  }, [warehouse.id, user]);

  if (error) return <Alert type="error" message={error} />;
  if (!overview) {
    return (
      <div className="wh-loading">
        <Loader2 className="animate-spin" size={28} />
      </div>
    );
  }

  const { kpis, categories, capacity, zones } = overview;
  const tallest = Math.max(...categories.items.map((item) => item.percent), 1);
  const fullestZone = zones.filter((zone) => zone.percent !== null).sort((a, b) => (b.percent ?? 0) - (a.percent ?? 0))[0];
  const shortStock = kpis.low_stock + kpis.out_of_stock;
  const address = [warehouse.address, warehouse.city, warehouse.state, warehouse.country].filter(Boolean).join(', ');

  const kpiCards = [
    { label: 'Total SKUs', value: number(kpis.total_skus), unit: 'in stock', badge: shortStock ? `${shortStock} low or out` : 'All stocked', tone: shortStock ? 'is-amber' : 'is-green' },
    { label: 'Quantity on Hand', value: number(kpis.on_hand), unit: 'units', badge: `${number(kpis.available)} available`, tone: '' },
    { label: 'Reserved for Orders', value: number(kpis.reserved), unit: 'units', badge: ordersToPick === null ? 'Held for orders' : `${ordersToPick} to pick`, tone: '' },
    { label: 'Capacity Usage', value: `${kpis.capacity_percent}%`, unit: 'of bins', badge: `${number(kpis.empty_bins)} empty`, tone: kpis.capacity_percent >= 85 ? 'is-red' : '' },
  ];

  const shortcuts: { title: string; text: string; action: () => void; show: boolean }[] = [
    {
      title: 'Orders to pick',
      text: ordersToPick ? `${ordersToPick} order${ordersToPick === 1 ? ' is' : 's are'} waiting to be picked here` : 'No orders are waiting to be picked',
      action: () => onOpenTab('orders'),
      show: can(user, 'orders.view'),
    },
    {
      title: 'Fullest zone',
      text: fullestZone ? `${fullestZone.code} is ${fullestZone.percent}% full (${fullestZone.bins_free} free bins)` : 'No bins set up yet',
      action: () => onOpenTab('locations'),
      show: true,
    },
    {
      title: 'Low stock',
      text: shortStock ? `${kpis.low_stock} running low, ${kpis.out_of_stock} out of stock` : 'Nothing is running low',
      action: () => navigate('/inventory'),
      show: can(user, 'inventory.view'),
    },
  ];

  return (
    <div className="wh-stack">
      {readiness && !readiness.ready_for_activation && warehouse.status !== 'active' && (
        <div className="wh-readiness" role="status">
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <AlertTriangle size={18} aria-hidden="true" />
            Setup {readiness.score}% complete — finish these steps to activate the warehouse
          </h2>
          {readiness.issues.length > 0 && (
            <ul>
              {readiness.issues.map((issue, index) => (
                <li key={`${issue.check}-${index}`}>{issue.message}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      <div className="wh-grid wh-grid-kpis">
        {kpiCards.map((kpi) => (
          <div key={kpi.label} className="wh-card wh-kpi">
            <span className="wh-kpi-label">{kpi.label}</span>
            <div className="wh-kpi-row">
              <span className="wh-kpi-value">
                {kpi.value} <span className="wh-kpi-unit">{kpi.unit}</span>
              </span>
              <span className={`wh-badge ${kpi.tone}`}>{kpi.badge}</span>
            </div>
          </div>
        ))}
      </div>

      <div className="wh-grid wh-grid-main">
        <section className="wh-card" aria-labelledby="wh-categories-title">
          <div className="wh-card-head">
            <div>
              <h2 id="wh-categories-title" className="wh-card-title">Stock by Category</h2>
              <div style={{ marginTop: 6 }}>
                <span className="wh-kpi-value">{number(categories.total)}</span> <span className="wh-kpi-unit">units on hand</span>
              </div>
            </div>
            {can(user, 'inventory.view') && (
              <button type="button" className="wh-link" onClick={() => navigate('/inventory')}>
                Open inventory
              </button>
            )}
          </div>
          {categories.items.length === 0 ? (
            <div className="wh-empty">No stock in this warehouse yet.</div>
          ) : (
            <div className="wh-bars" style={{ '--bars': categories.items.length } as React.CSSProperties}>
              {categories.items.map((item, index) => (
                <div key={item.name} className="wh-bar">
                  <span className="wh-bar-name" title={item.name}>{item.name}</span>
                  <div className="wh-bar-track">
                    <div className={`wh-bar-fill tone-${index % 6}`} style={{ height: `${Math.max((item.percent / tallest) * 100, 4)}%` }} />
                  </div>
                  <span className="wh-bar-value">
                    <strong>{item.percent}%</strong> <span style={{ color: '#64748b' }}>· {number(item.units)}</span>
                  </span>
                </div>
              ))}
            </div>
          )}
        </section>

        <section className="wh-card wh-capacity" aria-labelledby="wh-capacity-title">
          <div className="wh-card-head" style={{ marginBottom: 0 }}>
            <h2 id="wh-capacity-title" className="wh-card-title">Capacity Usage</h2>
            <span style={{ fontSize: 11, color: '#cbd5e1' }}>by bins</span>
          </div>
          <div className="wh-donut">
            <svg width="190" height="190" viewBox="0 0 170 170" role="img" aria-label={`${capacity.percent} percent of bins in use`}>
              <circle cx="85" cy="85" r={DONUT_RADIUS} fill="none" stroke="#ffffff" strokeWidth="16" />
              {capacity.percent > 0 && (
                <circle
                  cx="85"
                  cy="85"
                  r={DONUT_RADIUS}
                  fill="none"
                  stroke="#3b82f6"
                  strokeWidth="16"
                  strokeLinecap="round"
                  strokeDasharray={`${(capacity.percent / 100) * DONUT_LENGTH} ${DONUT_LENGTH}`}
                  transform="rotate(-90 85 85)"
                />
              )}
            </svg>
            <div className="wh-donut-center">
              <span>In use</span>
              <strong>{capacity.percent}%</strong>
            </div>
          </div>
          <div className="wh-capacity-legend">
            <div>
              <span>Occupied</span>
              <strong>{number(capacity.occupied)} bins</strong>
            </div>
            <div style={{ textAlign: 'right' }}>
              <span>Empty</span>
              <strong>{number(capacity.empty)} bins</strong>
            </div>
          </div>
        </section>
      </div>

      <div className="wh-grid wh-grid-thirds">
        {shortcuts
          .filter((shortcut) => shortcut.show)
          .map((shortcut) => (
            <button key={shortcut.title} type="button" className="wh-card wh-shortcut" onClick={shortcut.action}>
              <span>
                <strong>{shortcut.title}</strong>
                <span>{shortcut.text}</span>
              </span>
              <em>Open</em>
            </button>
          ))}
      </div>

      <section className="wh-card" aria-labelledby="wh-details-title">
        <div className="wh-card-head">
          <h2 id="wh-details-title" className="wh-card-title">Facility Details</h2>
        </div>
        <dl className="wh-details">
          <div>
            <dt>Type</dt>
            <dd style={{ textTransform: 'capitalize' }}>{warehouse.warehouse_type ? humanize(warehouse.warehouse_type) : '—'}</dd>
          </div>
          <div>
            <dt>Address</dt>
            <dd>{address || '—'}</dd>
          </div>
          <div>
            <dt>Timezone</dt>
            <dd>{warehouse.timezone || 'UTC'}</dd>
          </div>
          <div>
            <dt>Contact</dt>
            <dd>{[warehouse.contact_name, warehouse.contact_phone, warehouse.contact_email].filter(Boolean).join(' · ') || '—'}</dd>
          </div>
          <div>
            <dt>Picking strategy</dt>
            <dd style={{ textTransform: 'uppercase' }}>{warehouse.inventory_policies?.picking_strategy || 'FIFO'}</dd>
          </div>
          <div>
            <dt>Negative stock</dt>
            <dd>{warehouse.inventory_policies?.allow_negative_inventory ? 'Allowed' : 'Blocked'}</dd>
          </div>
        </dl>
      </section>
    </div>
  );
};

export default WarehouseOverviewTab;
