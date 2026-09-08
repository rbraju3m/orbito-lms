/**
 * Certification wire contract. Source of truth: docs/API.md.
 *
 * Note the two shapes for one model. `Certificate` is what a HOLDER sees;
 * `CertificateVerification` is what a stranger checking a claim sees, and it
 * deliberately carries less — no token, no course link, no PDF. They are
 * separate types for the same reason they are separate resources on the
 * server (ADR-06): one type with optional fields is one mistaken check away
 * from rendering something the public page never sends.
 */

export type CertificateStatus = 'issued' | 'revoked';

export interface Certificate {
  id: string;
  number: string;
  status: CertificateStatus;
  status_label: string;

  /** Three separate facts, because they answer different questions. */
  is_valid: boolean;
  has_expired: boolean;

  issued_at: string;
  expires_at: string | null;
  revoked_at: string | null;
  revoked_reason: string | null;

  learner_name: string | null;
  course_title: string | null;
  academy_name: string | null;
  completed_at: string | null;

  course?: { id: string; slug: string; title: string };

  /** False while the render is queued. The certificate is still VALID. */
  has_pdf: boolean;
  verification_url: string;
}

/** What a stranger is told. Strictly less than the above. */
export interface CertificateVerification {
  number: string;
  status: CertificateStatus;
  is_valid: boolean;
  has_expired: boolean;
  is_revoked: boolean;
  issued_at: string;
  expires_at: string | null;
  revoked_at: string | null;
  learner_name: string | null;
  course_title: string | null;
  academy_name: string | null;
  completed_at: string | null;
}

export interface CertificateTemplateLayout {
  heading: string;
  body: string;
  signature_name: string;
  signature_title: string;
  accent_colour: string;
  show_score: boolean;
  show_qr: boolean;
}

export interface CertificateTemplate {
  id: string;
  name: string;
  orientation: 'landscape' | 'portrait';
  orientation_label: string;
  background_media_id: number | null;
  layout: CertificateTemplateLayout;
  is_default: boolean;
  is_active: boolean;
  updated_at: string | null;
}
