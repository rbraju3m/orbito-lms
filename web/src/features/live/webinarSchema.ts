import { z } from 'zod';

import { fromLocalInputValue } from '@/shared/lib/datetime';

import type { WebinarInput } from './api/queries';

/**
 * The schedule-a-webinar form, in what a person types.
 *
 * A CREATE carries the session — a webinar with no time has nothing to attend
 * and cannot be published — and an edit does not: moving it goes through the
 * session endpoint, which resets the reminder and tells the provider. The API
 * re-checks every rule here; these exist so the form can say so first.
 */
export function webinarSchema(isNew: boolean) {
  return z
    .object({
      title: z.string().trim().min(1, 'Give the webinar a title.').max(200),
      description: z.string().max(5000),
      /** Empty means uncapped, which is not the same as no places left. */
      capacity: z.string(),
      /** Whether a place has to be bought. The price is set after saving. */
      is_paid: z.boolean(),
      provider: z.enum(['manual', 'zoom', 'google_meet']),
      join_url: z.string().trim().max(2000),
      starts_at: z.string(),
      ends_at: z.string(),
    })
    .refine((values) => !isNew || values.starts_at !== '', {
      path: ['starts_at'],
      message: 'When does it start?',
    })
    .refine((values) => !isNew || values.ends_at !== '', {
      path: ['ends_at'],
      message: 'When does it end?',
    })
    .refine(
      (values) =>
        !isNew || values.provider !== 'manual' || /^https?:\/\/\S+$/i.test(values.join_url),
      { path: ['join_url'], message: 'Paste the link people will use to join.' },
    )
    .refine(
      (values) =>
        values.starts_at === '' ||
        values.ends_at === '' ||
        new Date(values.ends_at) > new Date(values.starts_at),
      { path: ['ends_at'], message: 'It has to end after it starts.' },
    )
    .refine((values) => values.capacity === '' || Number(values.capacity) >= 1, {
      path: ['capacity'],
      message: 'Leave it empty for no limit.',
    });
}

export type WebinarValues = z.infer<ReturnType<typeof webinarSchema>>;

export function toWebinarInput(
  values: WebinarValues,
  options: { isNew: boolean; timezone: string },
): WebinarInput {
  const description = values.description.trim();

  const webinar: WebinarInput = {
    title: values.title.trim(),
    description: description === '' ? null : description,
    // Present-and-null CLEARS: an emptied box is "no limit", not "unchanged".
    capacity: values.capacity === '' ? null : Number(values.capacity),
    is_paid: values.is_paid,
  };

  if (!options.isNew) return webinar;

  return {
    ...webinar,
    provider: values.provider,
    // Refused beside a provider that issues its own link.
    ...(values.provider === 'manual' ? { join_url: values.join_url } : {}),
    starts_at: fromLocalInputValue(values.starts_at) ?? '',
    ends_at: fromLocalInputValue(values.ends_at) ?? '',
    timezone: options.timezone,
  };
}
