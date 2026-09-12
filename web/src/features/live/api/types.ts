/**
 * Live-learning wire contract. Source of truth: docs/API.md.
 */

export type LiveProvider = 'manual' | 'zoom' | 'google_meet';
export type SessionStatus = 'scheduled' | 'live' | 'ended' | 'cancelled';
export type CohortStatus = 'draft' | 'open' | 'running' | 'completed' | 'cancelled';

export interface LiveSession {
  id: string;
  title: string;
  description: string | null;

  provider: LiveProvider;
  provider_label: string;

  /** Derived from the clock on every read, never a swept column. */
  status: SessionStatus;
  status_label: string;

  starts_at: string;
  ends_at: string;
  /** The IANA zone it was SCHEDULED in, so the UI can show both. */
  timezone: string;

  host: { name?: string };
  course?: { id: string; title: string } | null;
  cohort?: { id: string; name: string } | null;

  /**
   * Present only while the session is joinable AND the caller is in the
   * audience. A link rendered a week early ends up in a group chat.
   *
   * There is no `host_url` here or anywhere: on Zoom the start link opens the
   * meeting AS the host.
   */
  join_url: string | null;
  can_join: boolean;
  /** False for a placeholder the author has not finished. */
  has_link: boolean;

  recording_url?: string | null;
  created_at: string | null;
}

export interface Cohort {
  id: string;
  name: string;
  starts_at: string;
  ends_at: string | null;
  timezone: string;
  status: CohortStatus;
  status_label: string;
  capacity: number | null;
  /** Null means uncapped — not the same as zero left. */
  places_remaining: number | null;
  enrollment_deadline: string | null;
  /** Status, deadline and capacity answered as one, by the server. */
  is_joinable: boolean;
  session_count?: number;
  enrollment_count?: number;
  /**
   * False once the run has sessions or learners: deleting would cascade them
   * away, so it is cancelled instead. The server's answer, not a guess from
   * the counts.
   */
  is_deletable?: boolean;
}

/** A provider, and whether this academy can schedule with it right now. */
export interface LiveProviderOption {
  value: LiveProvider;
  label: string;
  available: boolean;
}

/** One box on the connect form — the server's declaration, not the SPA's. */
export interface CredentialField {
  key: string;
  label: string;
  help: string;
  required: boolean;
  /** A password input. Every credential is encrypted either way. */
  secret: boolean;
}

/**
 * Whether the academy has connected a meeting provider — never with what.
 *
 * The API returns no credential under any key, so the form starts empty even
 * for a connected provider and an empty box means "keep what is stored".
 */
export interface LiveProviderAccount {
  provider: LiveProvider;
  label: string;
  /** False for `manual`, which is why a fresh academy can schedule on day one. */
  needs_account: boolean;
  is_connected: boolean;
  is_active: boolean;
  fields: CredentialField[];
  setup_url: string | null;
  /** What a disconnect would strand: still joinable, no longer reschedulable. */
  upcoming_sessions: number;
  updated_at: string | null;
}

export interface Webinar {
  id: string;
  slug: string;
  title: string;
  description: string | null;
  status: 'draft' | 'published' | 'cancelled';
  status_label: string;
  capacity: number | null;
  places_remaining: number | null;
  is_paid: boolean;
  session?: {
    id: string;
    starts_at: string;
    ends_at: string;
    timezone: string;
    status: SessionStatus;
  } | null;
  is_registered: boolean;
  registration_count?: number;
}

export interface CalendarResponse {
  range: { from: string; to: string };
  sessions: LiveSession[];
}

export interface RosterEntry {
  user_id: number;
  name: string;
  attended: boolean;
  joined_at: string | null;
  duration_seconds: number | null;
  /** Which kind of evidence — a click, a host's word, or the provider's. */
  source: 'self' | 'host' | 'provider' | null;
}

export interface Roster {
  session: { id: string; title: string };
  expected: number;
  present: number;
  roster: RosterEntry[];
}
