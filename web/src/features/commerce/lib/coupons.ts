import { formatMinor, toMajor, toMinor } from '@/shared/lib/money';

import type { Coupon, CouponInput } from '../api/coupons';
import type { CouponValues } from '../couponSchema';

/** ISO instant → the local "YYYY-MM-DDTHH:mm" a `datetime-local` input shows. */
export function toDateTimeLocal(iso: string | null | undefined): string {
  if (!iso) return '';

  const date = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** The input's local time → an ISO instant in UTC, as the API stores it. */
export function fromDateTimeLocal(value: string): string | null {
  return value === '' ? null : new Date(value).toISOString();
}

/** A coupon as the form edits it — or a fresh 20%-off one. */
export function couponFormDefaults(coupon: Coupon | null): CouponValues {
  const currency = coupon?.currency ?? '';

  return {
    code: coupon?.code ?? '',
    description: coupon?.description ?? '',
    discount_type: coupon?.discount_type ?? 'percent',
    percent_off: coupon ? coupon.percent_off : 20,
    amount_off:
      coupon?.amount_off_minor != null && currency !== '' ? toMajor(coupon.amount_off_minor, currency) : null,
    currency,
    applies_to_all: coupon?.applies_to_all ?? true,
    product_ids: coupon?.products?.map((product) => product.id) ?? [],
    min_subtotal:
      coupon?.min_subtotal_minor != null && currency !== ''
        ? toMajor(coupon.min_subtotal_minor, currency)
        : null,
    max_redemptions: coupon?.max_redemptions ?? null,
    max_redemptions_per_user: coupon?.max_redemptions_per_user ?? null,
    starts_at: toDateTimeLocal(coupon?.starts_at),
    ends_at: toDateTimeLocal(coupon?.ends_at),
    is_active: coupon?.is_active ?? true,
  };
}

/**
 * The form's values in the API's shape: minor units, UTC instants, and only
 * the value the discount type uses. The WHOLE coupon — saving replaces it.
 */
export function toCouponInput(values: CouponValues): CouponInput {
  const currency = values.currency.trim().toUpperCase() || null;

  return {
    code: values.code.trim(),
    description: values.description.trim() || null,
    discount_type: values.discount_type,
    percent_off: values.discount_type === 'percent' ? values.percent_off : null,
    amount_off_minor:
      values.discount_type === 'fixed' && values.amount_off !== null && currency !== null
        ? toMinor(values.amount_off, currency)
        : null,
    currency,
    applies_to_all: values.applies_to_all,
    product_ids: values.applies_to_all ? [] : values.product_ids,
    min_subtotal_minor:
      values.min_subtotal !== null && currency !== null ? toMinor(values.min_subtotal, currency) : null,
    max_redemptions: values.max_redemptions,
    max_redemptions_per_user: values.max_redemptions_per_user,
    starts_at: fromDateTimeLocal(values.starts_at),
    ends_at: fromDateTimeLocal(values.ends_at),
    is_active: values.is_active,
  };
}

/** "20% off" / "BDT 500.00 off" — what the coupon is worth, in one phrase. */
export function describeDiscount(coupon: Coupon): string {
  if (coupon.discount_type === 'percent') {
    return `${coupon.percent_off ?? 0}% off`;
  }

  return `${formatMinor(coupon.amount_off_minor ?? 0, coupon.currency ?? 'USD')} off`;
}

/** "Everything" / "2 products" — what it applies to. */
export function describeScope(coupon: Coupon): string {
  if (coupon.applies_to_all) {
    return 'Everything';
  }

  const count = coupon.products?.length ?? 0;

  return `${count} ${count === 1 ? 'product' : 'products'}`;
}

/** "Used 4 of 100 times" / "Used once" — paid orders only, as the server counts them. */
export function describeUsage(coupon: Coupon): string {
  const times = coupon.times_used === 1 ? 'once' : `${coupon.times_used} times`;

  if (coupon.max_redemptions === null) {
    return `Used ${times}`;
  }

  return `Used ${coupon.times_used} of ${coupon.max_redemptions} ${coupon.max_redemptions === 1 ? 'time' : 'times'}`;
}
