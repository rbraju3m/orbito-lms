import { z } from 'zod';

import { fromLocalInputValue } from '@/shared/lib/datetime';

import type { CohortInput } from './api/queries';

/**
 * A run of the course, in what a person types. Empty optional dates mean
 * "none"; an empty capacity means uncapped, which is not the same as zero —
 * a cohort nobody can join is not something anybody means to create.
 */
export const cohortSchema = z
  .object({
    name: z.string().trim().min(1, 'Name the run.').max(160),
    starts_at: z.string().min(1, 'When does the run start?'),
    ends_at: z.string(),
    enrollment_deadline: z.string(),
    capacity: z
      .number()
      .int('Places are whole numbers.')
      .min(1, 'At least one place — or leave it empty for no limit.')
      .max(100000)
      .nullable(),
    status: z.enum(['draft', 'open', 'running', 'completed', 'cancelled']),
  })
  .refine(
    (values) =>
      values.ends_at === '' ||
      values.starts_at === '' ||
      new Date(values.ends_at) > new Date(values.starts_at),
    { path: ['ends_at'], message: 'It has to end after it starts.' },
  );

export type CohortValues = z.infer<typeof cohortSchema>;

export function toCohortInput(
  values: CohortValues,
  options: { isNew: boolean; timezone: string },
): CohortInput {
  return {
    name: values.name.trim(),
    starts_at: fromLocalInputValue(values.starts_at) ?? '',
    ends_at: fromLocalInputValue(values.ends_at),
    enrollment_deadline: fromLocalInputValue(values.enrollment_deadline),
    capacity: values.capacity,
    status: values.status,
    ...(options.isNew ? { timezone: options.timezone } : {}),
  };
}
