import { describe, expect, it } from 'vitest';

import type { Coupon } from '../api/coupons';
import {
  couponFormDefaults,
  describeDiscount,
  describeScope,
  describeUsage,
  fromDateTimeLocal,
  toCouponInput,
  toDateTimeLocal,
} from './coupons';

function coupon(overrides: Partial<Coupon> = {}): Coupon {
  return {
    id: 'c-1',
    code: 'LAUNCH20',
    description: null,
    discount_type: 'percent',
    percent_off: 20,
    amount_off_minor: null,
    currency: null,
    applies_to_all: true,
    products: [],
    min_subtotal_minor: null,
    max_redemptions: null,
    max_redemptions_per_user: null,
    starts_at: null,
    ends_at: null,
    is_active: true,
    state: 'active',
    state_label: 'Active',
    times_used: 0,
    created_at: '2026-09-10T00:00:00Z',
    ...overrides,
  };
}

describe('describing a coupon', () => {
  it('says what it takes off', () => {
    expect(describeDiscount(coupon())).toBe('20% off');
    expect(describeDiscount(coupon({ discount_type: 'fixed', amount_off_minor: 50_000, currency: 'USD' }))).toBe(
      '$500.00 off',
    );
  });

  it('says what it covers', () => {
    expect(describeScope(coupon())).toBe('Everything');
    expect(describeScope(coupon({ applies_to_all: false, products: [{ id: 'p', title: 'Poetry', type: 'course' }] }))).toBe(
      '1 product',
    );
  });

  it('says how much of it is left', () => {
    expect(describeUsage(coupon({ times_used: 1 }))).toBe('Used once');
    expect(describeUsage(coupon({ times_used: 4, max_redemptions: 100 }))).toBe('Used 4 of 100 times');
  });
});

describe('the form and the API', () => {
  it('sends a fixed amount in minor units, and only the value its type uses', () => {
    const input = toCouponInput({
      ...couponFormDefaults(null),
      code: ' spring ',
      discount_type: 'fixed',
      percent_off: 20,
      amount_off: 12.5,
      currency: 'bdt',
    });

    expect(input).toMatchObject({
      code: 'spring',
      discount_type: 'fixed',
      percent_off: null,
      amount_off_minor: 1250,
      currency: 'BDT',
      description: null,
    });
  });

  it('round-trips an existing coupon through the form unchanged', () => {
    const existing = coupon({
      discount_type: 'fixed',
      percent_off: null,
      amount_off_minor: 1999,
      currency: 'BDT',
      min_subtotal_minor: 5000,
    });

    expect(toCouponInput(couponFormDefaults(existing))).toMatchObject({
      amount_off_minor: 1999,
      min_subtotal_minor: 5000,
      currency: 'BDT',
    });
  });

  it('keeps a scoped coupon\'s products, and drops them when it covers everything', () => {
    const scoped = { ...couponFormDefaults(null), applies_to_all: false, product_ids: ['p1'] };

    expect(toCouponInput(scoped).product_ids).toEqual(['p1']);
    expect(toCouponInput({ ...scoped, applies_to_all: true }).product_ids).toEqual([]);
  });

  it('turns a local date-time into an instant and back', () => {
    const iso = fromDateTimeLocal('2026-10-01T09:30');

    expect(iso).not.toBeNull();
    expect(toDateTimeLocal(iso)).toBe('2026-10-01T09:30');
    expect(fromDateTimeLocal('')).toBeNull();
  });
});
