/** Sections of the warehouse detail page; also used in the page URL (?tab=). */
export type WarehouseTab = 'overview' | 'orders' | 'locations' | 'staff' | 'opening_stock' | 'activity';

export const WAREHOUSE_TABS: WarehouseTab[] = ['overview', 'orders', 'locations', 'staff', 'opening_stock', 'activity'];

export const isWarehouseTab = (value: string | null): value is WarehouseTab =>
  value !== null && (WAREHOUSE_TABS as string[]).includes(value);

/** Readable error text from a failed request. */
export const errorText = (err: unknown, fallback: string): string =>
  err instanceof Error && err.message ? err.message : fallback;
