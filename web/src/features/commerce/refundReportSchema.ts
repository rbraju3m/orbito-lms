import { z } from 'zod';

/**
 * Resolving a refund report: what the person did, for whoever reads the
 * report next. Optional — "looked, nothing owed" is a resolution too.
 */
export const resolveReportSchema = z.object({
  note: z.string().max(500, 'Keep it under 500 characters.'),
});

export type ResolveReportValues = z.infer<typeof resolveReportSchema>;
