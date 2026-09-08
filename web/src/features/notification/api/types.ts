/**
 * Notification wire contract. Source of truth: docs/API.md.
 */

export interface AppNotification {
  id: string;
  /** A stable key like `announcement.published`, never a PHP class name. */
  type: string;
  title: string;
  body: string;
  action_label: string | null;
  /**
   * RELATIVE, and routed on internally. The server deliberately does not
   * store an absolute URL: an academy can change address, and a thousand
   * stored links would rot silently.
   */
  action_path: string | null;
  meta: Record<string, unknown>;
  read_at: string | null;
  is_read: boolean;
  created_at: string | null;
}

export interface NotificationChannelSetting {
  channel: 'database' | 'mail';
  label: string;
  enabled: boolean;
  /**
   * The in-app record cannot be switched off. Returned rather than omitted so
   * the UI renders a disabled switch that explains itself — a missing switch
   * reads as a bug.
   */
  locked: boolean;
}

export interface NotificationTypeSetting {
  key: string;
  label: string;
  description: string;
  group: string;
  channels: NotificationChannelSetting[];
}

export interface NotificationPreferenceGroup {
  key: string;
  label: string;
  types: NotificationTypeSetting[];
}

export interface NotificationPreferences {
  groups: NotificationPreferenceGroup[];
}

/** A single switch that moved — never the whole matrix. */
export interface PreferenceChange {
  type: string;
  channel: 'mail';
  enabled: boolean;
}
