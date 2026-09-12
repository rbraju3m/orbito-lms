import { z } from 'zod';

import { fromLocalInputValue } from '@/shared/lib/datetime';

import type { SessionInput } from './api/queries';

/**
 * "Everybody on the course", as a cohort choice. A named value rather than an
 * empty string, which a Select cannot reliably hold as an option.
 */
export const EVERYONE = 'everyone';

/**
 * The schedule-a-session form, in what a person types: times are
 * `datetime-local` strings in the scheduler's own zone. `toSessionInput()`
 * turns them into the API's shape. The API re-checks every rule here — these
 * exist so the form can say so before a round trip.
 *
 * A link is required only for a NEW pasted-link session. An edit never sees
 * the stored one (it is withheld until a session is joinable), so leaving the
 * box empty keeps it.
 */
export function sessionSchema(isNew: boolean) {
  return z
    .object({
      title: z.string().trim().min(1, 'Give the session a title.').max(200),
      description: z.string().max(5000),
      provider: z.enum(['manual', 'zoom', 'google_meet']),
      join_url: z.string().trim().max(2000),
      starts_at: z.string().min(1, 'When does it start?'),
      ends_at: z.string().min(1, 'When does it end?'),
      /** A cohort's id, or EVERYONE. */
      cohort_id: z.string(),
    })
    .refine(
      (values) =>
        values.provider !== 'manual' ||
        (values.join_url === '' ? !isNew : /^https?:\/\/\S+$/i.test(values.join_url)),
      { path: ['join_url'], message: 'Paste the link people will use to join.' },
    )
    .refine(
      (values) =>
        values.starts_at === '' ||
        values.ends_at === '' ||
        new Date(values.ends_at) > new Date(values.starts_at),
      { path: ['ends_at'], message: 'It has to end after it starts.' },
    );
}

export type SessionValues = z.infer<ReturnType<typeof sessionSchema>>;

/**
 * The API's shape. A NEW session records the scheduler's zone — "7pm Dhaka"
 * is what the instructor meant — and an edit keeps the zone it was scheduled
 * in rather than taking whoever edits it.
 */
export function toSessionInput(
  values: SessionValues,
  options: { isNew: boolean; timezone: string },
): SessionInput {
  const description = values.description.trim();

  return {
    title: values.title.trim(),
    description: description === '' ? null : description,
    provider: values.provider,
    // Sent only for a pasted-link session, and only when there is one: the
    // API refuses a link beside a provider that issues its own.
    ...(values.provider === 'manual' && values.join_url !== ''
      ? { join_url: values.join_url }
      : {}),
    starts_at: fromLocalInputValue(values.starts_at) ?? '',
    ends_at: fromLocalInputValue(values.ends_at) ?? '',
    ...(options.isNew ? { timezone: options.timezone } : {}),
    // The run is fixed once scheduled — a reschedule ignores it — so only a
    // new session sends one.
    ...(options.isNew && values.cohort_id !== EVERYONE ? { cohort_id: values.cohort_id } : {}),
  };
}
