/**
 * Gamification wire contract. Source of truth: docs/API.md.
 */

export type BadgeTier = 'bronze' | 'silver' | 'gold';

export interface GamificationProfile {
  points_total: number;
  current_streak_days: number;
  longest_streak_days: number;
  last_active_date: string | null;
  /**
   * Whether they appear on leaderboards. Opting out stops the publication and
   * nothing else — points, badges and the streak are all still theirs.
   */
  is_ranked: boolean;
}

export interface BadgeSummary {
  id: string;
  name: string;
  description: string | null;
  tier: BadgeTier;
  tier_label: string;
  icon_url: string | null;
  /** Rendered even when unheld: a requirement kept secret is a lottery. */
  criteria: { type: string | null; threshold: number | null };
  is_held: boolean;
  awarded_at: string | null;
}

export interface PointEntry {
  points: number;
  reason: string | null;
  awarded_at: string;
}

export interface Achievements {
  profile: GamificationProfile;
  badges: BadgeSummary[];
  recent: PointEntry[];
}

export type LeaderboardPeriod = 'weekly' | 'monthly' | 'all_time';

export interface LeaderboardEntry {
  rank: number;
  name: string;
  points: number;
  is_you: boolean;
}

export interface Leaderboard {
  period: LeaderboardPeriod;
  period_label: string;
  period_start: string | null;
  /** A snapshot, so how stale it is is part of the payload. */
  computed_at: string | null;
  entries: LeaderboardEntry[];
  /**
   * The caller's own row, even when they are off the bottom of the board.
   * Null means "not ranked yet" OR "opted out" — the API does not
   * distinguish, and the client already knows its own setting.
   */
  me: { rank: number; points: number } | null;
  course?: { id: string; title: string };
}
