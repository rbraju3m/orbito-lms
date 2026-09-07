import { z } from 'zod';

/**
 * These mirror the server's Form Requests. The server is authoritative; these
 * exist so the user gets an answer before a round trip, and so the form's
 * TypeScript type is derived rather than hand-written.
 */

const email = z.string().min(1, 'Email is required.').email('Enter a valid email address.');

const password = z.string().min(8, 'Use at least 8 characters.');

export const loginSchema = z.object({
  email,
  password: z.string().min(1, 'Password is required.'),
  remember: z.boolean().optional(),
});
export type LoginValues = z.infer<typeof loginSchema>;

export const registerSchema = z
  .object({
    name: z.string().min(2, 'Enter your name.').max(120),
    email,
    password,
    password_confirmation: z.string(),
    wants_to_teach: z.boolean().optional(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  });
export type RegisterValues = z.infer<typeof registerSchema>;

export const forgotPasswordSchema = z.object({ email });
export type ForgotPasswordValues = z.infer<typeof forgotPasswordSchema>;

export const resetPasswordSchema = z
  .object({
    password,
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  });
export type ResetPasswordValues = z.infer<typeof resetPasswordSchema>;

export const profileSchema = z.object({
  name: z.string().min(2, 'Enter your name.').max(120),
  headline: z.string().max(160).nullable().optional(),
  bio: z.string().max(2000).nullable().optional(),
  timezone: z.string().min(1),
  locale: z.string().min(2).max(5),
});
export type ProfileValues = z.infer<typeof profileSchema>;

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, 'Enter your current password.'),
    password,
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })
  .refine((values) => values.password !== values.current_password, {
    message: 'Choose a password different from your current one.',
    path: ['password'],
  });
export type ChangePasswordValues = z.infer<typeof changePasswordSchema>;
