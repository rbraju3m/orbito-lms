import type { Webinar } from '@/features/live/api/types';
import { ApiError } from '@/shared/api/errors';

/**
 * Whether the event page offers a guest place. A courtesy, not the rule: the
 * server refuses a paid place when asked, and says a room is full or an event
 * has closed when the link from the mail is followed.
 */
export function offersGuestPlace(webinar: Webinar, now: Date = new Date()): boolean {
  if (webinar.is_paid || webinar.places_remaining === 0) {
    return false;
  }

  return webinar.session == null || new Date(webinar.session.ends_at) > now;
}

/**
 * What a guest can be told about a refused link or place, keyed on the
 * API's code. Null for anything else, which the caller shows as an error.
 */
export function guestProblem(error: unknown): string | null {
  if (!(error instanceof ApiError)) {
    return null;
  }

  switch (error.code) {
    case 'guest_link_invalid':
      return 'This link has expired or is not valid. You can ask for a new one from the event page.';
    case 'webinar_full':
      return 'Sorry — the last place went while your link was waiting.';
    case 'webinar_closed':
      return 'This event is no longer taking registrations.';
    case 'live_session_not_joinable':
      return 'The meeting is not open right now.';
    case 'webinar_place_purchased':
      return 'A place that was bought is given up through a refund. Ask the academy.';
    default:
      return null;
  }
}
