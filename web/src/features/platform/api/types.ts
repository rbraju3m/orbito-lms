/**
 * The academy registry, as the platform operator sees it.
 *
 * Source of truth: `docs/API.md` §"Platform administration". These endpoints
 * are CENTRAL — they are about academies rather than inside one — which is why
 * nothing here resembles the rest of the app's types.
 */

export type TenantStatus = 'pending' | 'active' | 'suspended' | 'rejected';

/** The transitions the server will accept. Never invent one client-side. */
export type TenantAction = 'approve' | 'reject' | 'suspend' | 'reactivate';

export interface PlanSummary {
  slug: string;
  name: string;
  price_minor: number;
  currency: string;
  limits: Record<string, number | null>;
}

export interface TenantSubscription {
  status: string;
  status_label: string;
  /** Whether the academy's own staff can currently SAVE anything. */
  permits_writes: boolean;
  trial_ends_at: string | null;
  current_period_ends_at: string | null;
  cover_ends_at: string | null;
  grace_ends_at: string | null;
  canceled_at: string | null;
  plan?: PlanSummary | null;
}

export interface Tenant {
  id: string;
  slug: string;
  name: string;

  status: TenantStatus;
  status_label: string;
  is_active: boolean;
  is_open: boolean;

  /**
   * What this academy may be asked to do right now, computed by the server
   * from the same rule `ChangeTenantStatus` enforces. Render buttons from
   * this and a row can never offer a transition that would 409.
   */
  available_actions: TenantAction[];

  support_email: string | null;
  approved_at: string | null;
  created_at: string;

  /**
   * Who may sign up. READ-ONLY here — the academy's own admin owns this
   * decision, at `/admin/academy`. The operator sees it so support can answer
   * "why can nobody join?" without asking them to look.
   */
  registration_mode: 'open' | 'invite' | 'closed';
  registration_mode_label: string;

  suspended_reason: string | null;
  rejected_reason: string | null;

  subscription?: TenantSubscription | null;
}

export interface Plan {
  slug: string;
  name: string;
  price_minor: number;
  currency: string;
  billing_period: string;
  trial_days: number;
  grace_days: number;
  /**
   * What the plan ENTITLES an academy to. Counted in `usage_counters` and not
   * yet enforced anywhere (Phase 16) — the UI says so rather than implying a
   * cap that does not bite.
   */
  limits: Record<string, number | null>;
  features: Record<string, unknown>;
  is_active: boolean;
}

export interface NewAcademyInput {
  slug: string;
  name: string;
  owner_name: string;
  owner_email: string;
  owner_password: string;
  support_email?: string;
  plan?: string;
}
