import { z } from 'zod';

/**
 * The refund form, in what a person types (money in MAJOR units). Whether the
 * amount is still available is the server's answer — it depends on other
 * refunds, and is checked under a lock.
 */
export const refundSchema = z
  .object({
    amount: z.number().positive('More than nothing.').nullable(),
    method: z.enum(['gateway', 'external']),
    reason: z.string().trim().max(500, 'Keep it under 500 characters.'),
    revoke_access: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (values.amount === null) {
      ctx.addIssue({ code: 'custom', path: ['amount'], message: 'Enter an amount.' });
    }
  });

export type RefundValues = z.infer<typeof refundSchema>;
