import type { QuotationItemInput } from '../../services/omsService';

/** Same maths as the server (QuotationService::lineAmounts) so the form shows the totals the PDF will show. */
export const lineAmounts = (item: Pick<QuotationItemInput, 'quantity' | 'unit_price' | 'discount_percent' | 'tax_percent'>) => {
  const round = (value: number) => Math.round(value * 100) / 100;
  const gross = (Number(item.quantity) || 0) * (Number(item.unit_price) || 0);
  const discount = round((gross * (Number(item.discount_percent) || 0)) / 100);
  const tax = round(((gross - discount) * (Number(item.tax_percent) || 0)) / 100);
  return { subtotal: gross, discount, tax, total: round(gross - discount + tax) };
};

export const quotationTotals = (items: QuotationItemInput[]) =>
  items.reduce(
    (sum, item) => {
      const line = lineAmounts(item);
      return {
        subtotal: sum.subtotal + line.subtotal,
        discount: sum.discount + line.discount,
        tax: sum.tax + line.tax,
        total: sum.total + line.total,
      };
    },
    { subtotal: 0, discount: 0, tax: 0, total: 0 },
  );

/** The message to show for a failed API call, including the first validation error. */
export const apiErrorMessage = (error: unknown, fallback = 'Something went wrong. Please try again.'): string => {
  if (error && typeof error === 'object') {
    const { message, errors } = error as { message?: string; errors?: Record<string, string[]> | null };
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;
    return first || message || fallback;
  }
  return fallback;
};

export const todayIso = (): string => new Date().toISOString().slice(0, 10);

export const addDaysIso = (iso: string, days: number): string => {
  const date = new Date(`${iso}T00:00:00`);
  date.setDate(date.getDate() + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};
