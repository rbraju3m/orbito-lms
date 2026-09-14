import { z } from 'zod';

/**
 * The lead form, mirroring `SubmitLeadRequest` so it can say what is wrong
 * before sending. The server still decides.
 *
 * `website` is the honeypot. It is in the schema only so the form carries it;
 * nothing here may reject it, because a submission the page refused would
 * tell a script the field is a trap.
 */
export const leadSchema = z.object({
  email: z
    .string()
    .trim()
    .min(1, 'Enter your email address.')
    .max(254, 'That address is too long.')
    .email('That does not look like an email address.'),
  name: z.string().trim().max(120, 'Keep it under 120 characters.'),
  consent: z.boolean().refine((value) => value, 'Tick the box to agree to be contacted.'),
  website: z.string(),
});

export type LeadValues = z.infer<typeof leadSchema>;
