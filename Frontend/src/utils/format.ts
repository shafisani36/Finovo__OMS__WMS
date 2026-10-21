/**
 * Display helpers shared by the customer, product and order screens.
 */

export interface AddressParts {
  name?: string;
  company?: string;
  address1?: string;
  address2?: string;
  city?: string;
  province?: string;
  zip?: string;
  country?: string;
  country_code?: string;
  phone?: string;
}

export interface VariantOptions {
  option_name?: string | null;
  option_value?: string | null;
}

/** "PKR 1,250.00" in the order's own currency; falls back to plain text for unknown currency codes. */
export const formatMoney = (amount: number | string | null | undefined, currency = 'USD'): string => {
  const value = Number(amount) || 0;
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD', maximumFractionDigits: 2 }).format(value);
  } catch {
    return `${currency} ${value.toFixed(2)}`;
  }
};

export const formatDate = (value: string | null | undefined, withTime = false): string => {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  });
};

/**
 * Amounts in several currencies, e.g. "PKR 5,400.00 + $220.00". Each currency is shown on its own because
 * stores may sell in different currencies and no exchange rate is applied.
 */
export const formatMoneyByCurrency = (
  amounts: Array<{ currency: string; amount: number }> | null | undefined,
  empty = '—',
): string => (amounts?.length ? amounts.map((entry) => formatMoney(entry.amount, entry.currency)).join(' + ') : empty);

/** "partially_shipped" → "partially shipped" */
export const humanize = (value: string): string => value.replace(/_/g, ' ');

export const initials = (name: string): string =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || '?';

/** Street, city and country lines of a shipping address, without empty parts. */
export const addressLines = (address: AddressParts | null | undefined): string[] => {
  if (!address) return [];
  const cityLine = [address.city, address.province, address.zip].filter(Boolean).join(', ');
  return [address.company, address.address1, address.address2, cityLine, address.country || address.country_code].filter(
    (line): line is string => Boolean(line && line.trim()),
  );
};

/** "Color: Black, Size: S" from option_name "Color / Size" and option_value "Black / S". */
export const variantLabel = (variant: VariantOptions | null | undefined): string | null => {
  if (!variant?.option_value) return null;
  if (!variant.option_name) return variant.option_value;
  const names = variant.option_name.split(' / ');
  const values = variant.option_value.split(' / ');
  return names.length === values.length
    ? names.map((name, i) => `${name}: ${values[i]}`).join(', ')
    : `${variant.option_name}: ${variant.option_value}`;
};

/** The variant label, unless the store already put it in the line's product name (PrestaShop does). */
export const variantNote = (productName: string, variant: VariantOptions | null | undefined): string | null => {
  const label = variantLabel(variant);
  return label && variant?.option_value && !productName.includes(variant.option_value) ? label : null;
};
