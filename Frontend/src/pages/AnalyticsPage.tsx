import React, { useEffect, useMemo, useState } from 'react';
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Legend,
  Line,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { ArrowDownRight, ArrowUpRight, Ban, Boxes, Clock, DollarSign, Package, RotateCcw, ShoppingCart, Truck } from 'lucide-react';
import { Alert } from '../components/common/Alert';
import CustomDropdown from '../components/common/CustomDropdown';
import omsService, { ANALYTICS_PERIODS } from '../services/omsService';
import type { AnalyticsFigure, AnalyticsPeriod, AnalyticsReport } from '../services/omsService';
import { CHART_COLORS, byPlatform, byWeekday, compactNumber, percentChange, shortDate } from '../components/analytics/analytics';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { formatMoney, humanize } from '../utils/format';
import { errorMessage } from '../utils/errors';
import '../components/analytics/AnalyticsPage.css';

const PERIOD_LABELS: Record<AnalyticsPeriod, string> = { 7: '7 days', 30: '30 days', 90: '90 days', 365: '12 months' };

const AXIS = { fontSize: 11, fill: '#64748b' };
const TOOLTIP_STYLE: React.CSSProperties = { borderRadius: 10, border: '1px solid #e2e8f0', boxShadow: '0 8px 24px rgba(15, 23, 42, 0.08)', fontSize: 12 };

interface KpiProps {
  title: string;
  figure: AnalyticsFigure;
  icon: React.ReactNode;
  format: (value: number) => string;
  /** For cancellations, returns and time to ship a fall is the good direction. */
  lowerIsBetter?: boolean;
}

const Kpi: React.FC<KpiProps> = ({ title, figure, icon, format, lowerIsBetter = false }) => {
  const change = percentChange(figure);
  const good = change !== null && change !== 0 && change > 0 !== lowerIsBetter;
  return (
    <div className="an-kpi">
      <div className="an-kpi-head">
        <span className="an-kpi-icon">{icon}</span>
        <span className="an-kpi-title">{title}</span>
      </div>
      <div className="an-kpi-value">{format(figure.value)}</div>
      <div className="an-kpi-foot">
        {change === null ? (
          <span className="an-kpi-previous">No earlier data to compare</span>
        ) : (
          <>
            <span className={`an-kpi-change ${change === 0 ? '' : good ? 'an-good' : 'an-bad'}`}>
              {change >= 0 ? <ArrowUpRight size={14} /> : <ArrowDownRight size={14} />}
              {Math.abs(change)}%
            </span>
            <span className="an-kpi-previous">vs {format(figure.previous)} before</span>
          </>
        )}
      </div>
    </div>
  );
};

const ChartCard: React.FC<{ title: string; subtitle?: string; empty?: boolean; wide?: boolean; children: React.ReactNode }> = ({ title, subtitle, empty = false, wide = false, children }) => (
  <section className={`an-card ${wide ? 'an-card-wide' : ''}`}>
    <header className="an-card-head">
      <h3>{title}</h3>
      {subtitle && <p>{subtitle}</p>}
    </header>
    {empty ? <div className="an-empty">No data for this period yet.</div> : children}
  </section>
);

/**
 * Sales, orders, stores, products, shipping, returns and stock over a chosen period, compared with the period
 * before it. Stock charts show only for staff who can see stock, and only for the warehouses they work in.
 */
export const AnalyticsPage: React.FC = () => {
  const { user } = useAuth();
  const [days, setDays] = useState<AnalyticsPeriod>(30);
  const [storeId, setStoreId] = useState<number | null>(null);
  const [warehouseId, setWarehouseId] = useState<number | null>(null);
  const [stores, setStores] = useState<{ value: number; label: string }[]>([]);
  const [warehouses, setWarehouses] = useState<{ value: number; label: string }[]>([]);
  const [report, setReport] = useState<AnalyticsReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (can(user, 'stores.view')) {
      omsService
        .getStores({ per_page: 100 })
        .then((res: { data: { id: number; name: string }[] }) => setStores(res.data.map((s) => ({ value: s.id, label: s.name }))))
        .catch(() => setStores([]));
    }
    if (can(user, 'inventory.view')) {
      omsService
        .getWarehouses()
        .then((res: { data: { id: number; name: string }[] }) => setWarehouses(res.data.map((w) => ({ value: w.id, label: w.name }))))
        .catch(() => setWarehouses([]));
    }
  }, [user]);

  useEffect(() => {
    let current = true;
    setLoading(true);
    omsService
      .getAnalytics({ days, store_id: storeId, warehouse_id: warehouseId })
      .then((data) => {
        if (!current) return;
        setReport(data);
        setError(null);
      })
      .catch((err) => current && setError(errorMessage(err, 'The analytics could not be loaded.')))
      .finally(() => current && setLoading(false));
    return () => {
      current = false;
    };
  }, [days, storeId, warehouseId]);

  const money = (value: number) => formatMoney(value, report?.currency);
  const weekdays = useMemo(() => (report ? byWeekday(report.daily) : []), [report]);
  const platforms = useMemo(() => (report ? byPlatform(report.stores) : []), [report]);
  const totalStatuses = report?.statuses.reduce((sum, row) => sum + row.count, 0) ?? 0;
  const hasStock = can(user, 'inventory.view') && (report?.warehouses.length ?? 0) > 0;

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Analytics</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">Analytics</h1>
          <p className="page-subtitle">
            Sales, orders, stores, products, shipping, returns and stock
            {report ? ` from ${shortDate(report.period.from)} to ${shortDate(report.period.to)}, compared with the ${report.period.days} days before.` : '.'}
          </p>
        </div>
      </div>

      <div className="an-filters">
        <div className="an-periods" role="group" aria-label="Period">
          {ANALYTICS_PERIODS.map((period) => (
            <button key={period} type="button" className={period === days ? 'active' : ''} aria-pressed={period === days} onClick={() => setDays(period)}>
              {PERIOD_LABELS[period]}
            </button>
          ))}
        </div>
        {stores.length > 0 && (
          <CustomDropdown size="sm" fullWidth={false} style={{ width: 220 }} clearable placeholder="All stores" value={storeId} options={stores} onChange={(value) => setStoreId(value ?? null)} />
        )}
        {warehouses.length > 0 && (
          <CustomDropdown size="sm" fullWidth={false} style={{ width: 220 }} clearable placeholder="All warehouses" value={warehouseId} options={warehouses} onChange={(value) => setWarehouseId(value ?? null)} />
        )}
        {loading && report && <span className="an-refreshing">Updating…</span>}
      </div>

      {error && <Alert type="error" message={error} className="page-banner" />}
      {!report && loading && <div className="an-empty an-loading">Loading analytics…</div>}

      {report && (
        <>
          <div className="an-kpis">
            <Kpi title="Revenue" figure={report.kpis.revenue} icon={<DollarSign size={16} />} format={money} />
            <Kpi title="Orders" figure={report.kpis.orders} icon={<ShoppingCart size={16} />} format={(v) => v.toLocaleString()} />
            <Kpi title="Average order value" figure={report.kpis.average_order_value} icon={<Package size={16} />} format={money} />
            <Kpi title="Units sold" figure={report.kpis.units_sold} icon={<Boxes size={16} />} format={(v) => v.toLocaleString()} />
            <Kpi title="Delivered" figure={report.kpis.delivered} icon={<Truck size={16} />} format={(v) => v.toLocaleString()} />
            <Kpi title="Hours to ship" figure={report.kpis.hours_to_ship} icon={<Clock size={16} />} format={(v) => `${v} h`} lowerIsBetter />
            <Kpi title="Cancellation rate" figure={report.kpis.cancellation_rate} icon={<Ban size={16} />} format={(v) => `${v}%`} lowerIsBetter />
            <Kpi title="Return rate" figure={report.kpis.return_rate} icon={<RotateCcw size={16} />} format={(v) => `${v}%`} lowerIsBetter />
          </div>

          <div className="an-grid">
            <ChartCard title="Revenue and orders" subtitle="Sales (area) and number of orders (line) per day" wide empty={report.kpis.orders.value === 0}>
              <ResponsiveContainer width="100%" height={300}>
                <ComposedChart data={report.daily} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <defs>
                    <linearGradient id="an-revenue" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor={CHART_COLORS[0]} stopOpacity={0.25} />
                      <stop offset="100%" stopColor={CHART_COLORS[0]} stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid stroke="#f1f5f9" vertical={false} />
                  <XAxis dataKey="date" tickFormatter={shortDate} tick={AXIS} tickLine={false} axisLine={false} minTickGap={24} />
                  <YAxis yAxisId="revenue" tickFormatter={compactNumber} tick={AXIS} tickLine={false} axisLine={false} width={48} />
                  <YAxis yAxisId="orders" orientation="right" allowDecimals={false} tick={AXIS} tickLine={false} axisLine={false} width={32} />
                  <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={(label) => shortDate(String(label))} formatter={(value, name) => (name === 'Revenue' ? money(Number(value)) : value)} />
                  <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                  <Area yAxisId="revenue" type="monotone" dataKey="revenue" name="Revenue" stroke={CHART_COLORS[0]} strokeWidth={2} fill="url(#an-revenue)" />
                  <Line yAxisId="orders" type="monotone" dataKey="orders" name="Orders" stroke={CHART_COLORS[1]} strokeWidth={2} dot={false} />
                </ComposedChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Order status" subtitle={`${totalStatuses.toLocaleString()} orders in the period`} empty={totalStatuses === 0}>
              <ResponsiveContainer width="100%" height={260}>
                <PieChart>
                  <Pie data={report.statuses} dataKey="count" nameKey="status" innerRadius={60} outerRadius={95} paddingAngle={2}>
                    {report.statuses.map((row, index) => (
                      <Cell key={row.status} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip contentStyle={TOOLTIP_STYLE} formatter={(value, name) => [value, humanize(String(name))]} />
                  <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} formatter={(value) => humanize(String(value))} />
                </PieChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Sales by channel" subtitle="Revenue per platform" empty={platforms.length === 0}>
              <ResponsiveContainer width="100%" height={260}>
                <PieChart>
                  <Pie data={platforms} dataKey="revenue" nameKey="platform" outerRadius={95} label={({ percent }) => `${Math.round((percent ?? 0) * 100)}%`} labelLine={false}>
                    {platforms.map((row, index) => (
                      <Cell key={row.platform} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip contentStyle={TOOLTIP_STYLE} formatter={(value, name) => [money(Number(value)), humanize(String(name))]} />
                  <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} formatter={(value) => humanize(String(value))} />
                </PieChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Revenue by store" subtitle="Orders typed into Finovo show as Finovo" empty={report.stores.length === 0}>
              <ResponsiveContainer width="100%" height={Math.max(220, report.stores.length * 40)}>
                <BarChart data={report.stores} layout="vertical" margin={{ top: 4, right: 16, left: 8, bottom: 4 }}>
                  <CartesianGrid stroke="#f1f5f9" horizontal={false} />
                  <XAxis type="number" tickFormatter={compactNumber} tick={AXIS} tickLine={false} axisLine={false} />
                  <YAxis type="category" dataKey="name" tick={AXIS} tickLine={false} axisLine={false} width={110} />
                  <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: '#f8fafc' }} formatter={(value, name) => (name === 'Revenue' ? money(Number(value)) : value)} />
                  <Bar dataKey="revenue" name="Revenue" fill={CHART_COLORS[0]} radius={[0, 6, 6, 0]} barSize={18} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Top products" subtitle="Best sellers by units sold" empty={report.top_products.length === 0}>
              <ResponsiveContainer width="100%" height={Math.max(220, report.top_products.length * 34)}>
                <BarChart data={report.top_products} layout="vertical" margin={{ top: 4, right: 16, left: 8, bottom: 4 }}>
                  <CartesianGrid stroke="#f1f5f9" horizontal={false} />
                  <XAxis type="number" allowDecimals={false} tick={AXIS} tickLine={false} axisLine={false} />
                  <YAxis type="category" dataKey="sku" tick={AXIS} tickLine={false} axisLine={false} width={110} />
                  <Tooltip
                    contentStyle={TOOLTIP_STYLE}
                    cursor={{ fill: '#f8fafc' }}
                    labelFormatter={(sku) => report.top_products.find((p) => p.sku === sku)?.name ?? String(sku)}
                    formatter={(value, name) => (name === 'Revenue' ? money(Number(value)) : value)}
                  />
                  <Bar dataKey="quantity" name="Units" fill={CHART_COLORS[1]} radius={[0, 6, 6, 0]} barSize={16} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Busiest days of the week" subtitle="Orders placed on each weekday" empty={report.kpis.orders.value === 0}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={weekdays} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid stroke="#f1f5f9" vertical={false} />
                  <XAxis dataKey="day" tick={AXIS} tickLine={false} axisLine={false} />
                  <YAxis allowDecimals={false} tick={AXIS} tickLine={false} axisLine={false} width={32} />
                  <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: '#f8fafc' }} />
                  <Bar dataKey="orders" name="Orders" fill={CHART_COLORS[3]} radius={[6, 6, 0, 0]} barSize={28} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Parcels shipped" subtitle="Parcels handed to couriers per day" empty={report.daily.every((row) => row.shipped === 0)}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={report.daily} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid stroke="#f1f5f9" vertical={false} />
                  <XAxis dataKey="date" tickFormatter={shortDate} tick={AXIS} tickLine={false} axisLine={false} minTickGap={24} />
                  <YAxis allowDecimals={false} tick={AXIS} tickLine={false} axisLine={false} width={32} />
                  <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: '#f8fafc' }} labelFormatter={(label) => shortDate(String(label))} />
                  <Bar dataKey="shipped" name="Parcels" fill={CHART_COLORS[5]} radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Couriers" subtitle="Parcels sent and delivered by each courier" empty={report.carriers.length === 0}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={report.carriers} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid stroke="#f1f5f9" vertical={false} />
                  <XAxis dataKey="carrier" tick={AXIS} tickLine={false} axisLine={false} />
                  <YAxis allowDecimals={false} tick={AXIS} tickLine={false} axisLine={false} width={32} />
                  <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: '#f8fafc' }} />
                  <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                  <Bar dataKey="shipments" name="Sent" fill={CHART_COLORS[0]} radius={[4, 4, 0, 0]} />
                  <Bar dataKey="delivered" name="Delivered" fill={CHART_COLORS[1]} radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Return reasons" subtitle="Why customers sent items back" empty={report.return_reasons.length === 0}>
              <ResponsiveContainer width="100%" height={260}>
                <PieChart>
                  <Pie data={report.return_reasons} dataKey="count" nameKey="reason" innerRadius={55} outerRadius={95} paddingAngle={2}>
                    {report.return_reasons.map((row, index) => (
                      <Cell key={row.reason} fill={CHART_COLORS[(index + 4) % CHART_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip contentStyle={TOOLTIP_STYLE} />
                  <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                </PieChart>
              </ResponsiveContainer>
            </ChartCard>

            {hasStock && (
              <>
                <ChartCard title="Stock movement" subtitle="Units coming in (received, returned, transferred in) and going out per day" wide empty={report.stock_flow.every((row) => row.inbound === 0 && row.outbound === 0)}>
                  <ResponsiveContainer width="100%" height={280}>
                    <AreaChart data={report.stock_flow} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                      <CartesianGrid stroke="#f1f5f9" vertical={false} />
                      <XAxis dataKey="date" tickFormatter={shortDate} tick={AXIS} tickLine={false} axisLine={false} minTickGap={24} />
                      <YAxis tickFormatter={compactNumber} tick={AXIS} tickLine={false} axisLine={false} width={40} />
                      <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={(label) => shortDate(String(label))} />
                      <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                      <Area type="monotone" dataKey="inbound" name="In" stroke={CHART_COLORS[1]} fill={CHART_COLORS[1]} fillOpacity={0.15} strokeWidth={2} />
                      <Area type="monotone" dataKey="outbound" name="Out" stroke={CHART_COLORS[4]} fill={CHART_COLORS[4]} fillOpacity={0.12} strokeWidth={2} />
                    </AreaChart>
                  </ResponsiveContainer>
                </ChartCard>

                <ChartCard title="Stock by warehouse" subtitle="Units free to sell and units held for orders">
                  <ResponsiveContainer width="100%" height={260}>
                    <BarChart data={report.warehouses} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                      <CartesianGrid stroke="#f1f5f9" vertical={false} />
                      <XAxis dataKey="name" tick={AXIS} tickLine={false} axisLine={false} />
                      <YAxis tickFormatter={compactNumber} tick={AXIS} tickLine={false} axisLine={false} width={40} />
                      <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: '#f8fafc' }} />
                      <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                      <Bar dataKey="available" name="Available" stackId="stock" fill={CHART_COLORS[0]} />
                      <Bar dataKey="reserved" name="Reserved" stackId="stock" fill={CHART_COLORS[2]} radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </ChartCard>

                <ChartCard title="Warehouse summary" subtitle="Stock value at cost and parcels sent in the period">
                  <div className="table-responsive-wrapper">
                    <table className="finovo-table an-table">
                      <thead>
                        <tr>
                          <th>Warehouse</th>
                          <th>On hand</th>
                          <th>Stock value</th>
                          <th>Low stock</th>
                          <th>Parcels</th>
                        </tr>
                      </thead>
                      <tbody>
                        {report.warehouses.map((row) => (
                          <tr key={row.name}>
                            <td>{row.name}</td>
                            <td>{row.on_hand.toLocaleString()}</td>
                            <td>{money(row.stock_value)}</td>
                            <td className={row.low_stock > 0 ? 'an-warn' : ''}>{row.low_stock}</td>
                            <td>{row.shipments}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </ChartCard>
              </>
            )}
          </div>
        </>
      )}
    </div>
  );
};

export default AnalyticsPage;
