import type { AnalyticsFigure, AnalyticsReport } from '../../services/omsService';

/** Series colours, matching the app's blue first. */
export const CHART_COLORS = ['#1e56ff', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899', '#64748b'];

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Change from the previous period in percent (null when there was nothing before to compare with). */
export const percentChange = ({ value, previous }: AnalyticsFigure): number | null =>
  previous === 0 ? null : Math.round(((value - previous) / previous) * 1000) / 10;

/** Orders and sales by day of the week, Monday first. */
export const byWeekday = (daily: AnalyticsReport['daily']) => {
  const totals = WEEKDAYS.map((day) => ({ day, orders: 0, revenue: 0 }));
  for (const row of daily) {
    // Dates are calendar days; read them as UTC so the weekday never shifts with the viewer's time zone.
    const index = (new Date(`${row.date}T00:00:00Z`).getUTCDay() + 6) % 7;
    totals[index].orders += row.orders;
    totals[index].revenue = Math.round((totals[index].revenue + row.revenue) * 100) / 100;
  }
  return totals;
};

/** Sales per platform (all Shopify stores together, and so on). */
export const byPlatform = (stores: AnalyticsReport['stores']) => {
  const totals = new Map<string, { platform: string; orders: number; revenue: number }>();
  for (const store of stores) {
    const row = totals.get(store.platform) ?? { platform: store.platform, orders: 0, revenue: 0 };
    row.orders += store.orders;
    row.revenue = Math.round((row.revenue + store.revenue) * 100) / 100;
    totals.set(store.platform, row);
  }
  return [...totals.values()].sort((a, b) => b.revenue - a.revenue);
};

/** "Nov 6" for daily axes. */
export const shortDate = (date: string): string =>
  new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' });

/** 1.2K, 3.4M: short numbers for chart axes. */
export const compactNumber = (value: number): string => new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
