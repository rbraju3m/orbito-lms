/**
 * The public site's wire contract. Source of truth: docs/API.md.
 *
 * Courses and webinars reuse the members-only shapes deliberately: the public
 * endpoints render the SAME resources, so a second set of types here would be
 * a second definition of a course that could drift from the one the server
 * sends.
 */

export interface PublicAcademy {
  slug: string;
  name: string;
  logo_url: string | null;
  support_email: string | null;
  /** Whether the site may offer a Sign up button at all. */
  registration_open: boolean;
}
