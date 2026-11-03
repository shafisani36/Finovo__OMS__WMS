import { describe, expect, it } from 'vitest';
import { addressLines, formatMoney, humanize, initials, variantLabel } from './format';

describe('format helpers', () => {
  it('lists only the filled address lines, with city, province and zip on one line', () => {
    expect(addressLines({ address1: 'House 2', address2: ' ', city: 'Karachi', province: 'Sindh', zip: '75500', country: 'Pakistan' })).toEqual([
      'House 2',
      'Karachi, Sindh, 75500',
      'Pakistan',
    ]);
    expect(addressLines(null)).toEqual([]);
  });

  it('turns status keys into words', () => {
    expect(humanize('partially_allocated')).toBe('partially allocated');
  });

  it('makes initials from up to two names', () => {
    expect(initials('mahir ali khan')).toBe('MA');
    expect(initials('   ')).toBe('?');
  });

  it('formats money and falls back for an unknown currency code', () => {
    expect(formatMoney(1250, 'PKR')).toContain('1,250');
    expect(formatMoney('abc', 'PKR')).toContain('0');
    expect(formatMoney(5, 'NOT-A-CODE')).toBe('NOT-A-CODE 5.00');
  });

  it('pairs variant option names with their values', () => {
    expect(variantLabel({ option_name: 'Color / Size', option_value: 'Black / S' })).toBe('Color: Black, Size: S');
  });
});
