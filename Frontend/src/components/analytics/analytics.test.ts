import { describe, expect, it } from 'vitest';
import { byPlatform, byWeekday, percentChange } from './analytics';

describe('analytics helpers', () => {
  it('compares a figure with the previous period', () => {
    expect(percentChange({ value: 150, previous: 100 })).toBe(50);
    expect(percentChange({ value: 50, previous: 200 })).toBe(-75);
    expect(percentChange({ value: 10, previous: 0 })).toBeNull();
  });

  it('adds up days by weekday, Monday first', () => {
    const totals = byWeekday([
      { date: '2026-11-02', orders: 2, revenue: 10.1, shipped: 0 }, // Monday
      { date: '2026-11-08', orders: 3, revenue: 5, shipped: 1 }, // Sunday
      { date: '2026-11-09', orders: 1, revenue: 0.2, shipped: 0 }, // Monday
    ]);
    expect(totals[0]).toEqual({ day: 'Mon', orders: 3, revenue: 10.3 });
    expect(totals[6]).toEqual({ day: 'Sun', orders: 3, revenue: 5 });
    expect(totals.map((row) => row.day)).toHaveLength(7);
  });

  it('adds up stores of the same platform', () => {
    expect(
      byPlatform([
        { name: 'A', platform: 'shopify', orders: 2, revenue: 100 },
        { name: 'B', platform: 'wix', orders: 5, revenue: 300 },
        { name: 'C', platform: 'shopify', orders: 1, revenue: 50 },
      ])
    ).toEqual([
      { platform: 'wix', orders: 5, revenue: 300 },
      { platform: 'shopify', orders: 3, revenue: 150 },
    ]);
  });
});
