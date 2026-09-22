import { z } from 'zod';

/** Mirrors `SendInvitationRequest`; the server stays authoritative. */
export const invitationSchema = z.object({
  email: z.string().trim().min(1, 'Email is required.').email('Enter a valid email address.'),
  role: z.enum(['student', 'instructor']),
});
export type InvitationValues = z.infer<typeof invitationSchema>;
