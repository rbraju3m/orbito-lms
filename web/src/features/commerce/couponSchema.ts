import { z } from 'zod';

/**
 * The coupon form, in what a person types — money in MAJOR units, dates as
 * `datetime-local` strings. `toCouponInput()` turns it into the API's shape.
 *
 * The rules mirror `SaveCouponRequest` so the form can say what is wrong
 * before sending; the server still decides.
 */
export const couponSchema = z
  .object({
    code: z
      .string()
      .trim()
      .min(3, 'At least three characters.')
      .max(64, 'At most 64 characters.')
      .regex(/^[A-Za-z0-9_-]+$/, 'Letters, digits, - and _ only.'),
    description: z.string().trim().max(255, 'Keep it under 255 characters.'),
    discount_type: z.enum(['percent', 'fixed']),
    percent_off: z.number().int('A whole number.').min(1, 'At least 1%.').max(100, 'At most 100%.').nullable(),
    amount_off: z.number().positive('More than nothing.').nullable(),
    currency: z.string().trim(),
    applies_to_all: z.boolean(),
    product_ids: z.array(z.string()),
    min_subtotal: z.number().positive('More than nothing.').nullable(),
    max_redemptions: z.number().int().min(1, 'At least one.').nullable(),
    max_redemptions_per_user: z.number().int().min(1, 'At least one.').nullable(),
    starts_at: z.string(),
    ends_at: z.string(),
    is_active: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (values.discount_type === 'percent' && values.percent_off === null) {
      ctx.addIssue({ code: 'custom', path: ['percent_off'], message: 'Enter a percentage.' });
    }

    if (values.discount_type === 'fixed' && values.amount_off === null) {
      ctx.addIssue({ code: 'custom', path: ['amount_off'], message: 'Enter an amount.' });
    }

    // Money without a currency is a number.
    const needsCurrency = values.discount_type === 'fixed' || values.min_subtotal !== null;
    if (needsCurrency && !/^[A-Za-z]{3}$/.test(values.currency)) {
      ctx.addIssue({ code: 'custom', path: ['currency'], message: 'A three-letter currency code, like BDT.' });
    }

    if (!values.applies_to_all && values.product_ids.length === 0) {
      ctx.addIssue({ code: 'custom', path: ['product_ids'], message: 'Pick at least one product.' });
    }

    if (values.starts_at !== '' && values.ends_at !== '' && values.ends_at <= values.starts_at) {
      ctx.addIssue({ code: 'custom', path: ['ends_at'], message: 'It ends before it starts.' });
    }
  });

export type CouponValues = z.infer<typeof couponSchema>;
