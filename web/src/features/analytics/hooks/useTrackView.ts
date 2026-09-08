import { useEffect, useRef } from 'react';

import { trackEvents } from '../api/queries';
import type { TrackableEvent } from '../api/types';

const SESSION_KEY = 'orbito.analytics.session';

/**
 * A per-browser-session id, so anonymous-ish behaviour can be strung together
 * without identifying anybody.
 *
 * `sessionStorage`, not `localStorage`: it should die with the tab. A
 * permanent id in local storage would be a tracking cookie by another name,
 * which is not what a course-view count is worth. Wrapped in try/catch because
 * a private window can throw on the accessor itself.
 */
function sessionId(): string | undefined {
  try {
    const existing = sessionStorage.getItem(SESSION_KEY);
    if (existing) return existing;

    const fresh = crypto.randomUUID();
    sessionStorage.setItem(SESSION_KEY, fresh);
    return fresh;
  } catch {
    return undefined;
  }
}

/**
 * Raises one client event when `key` changes, and never twice for the same one.
 *
 * The ref guard is not defensive programming — React 19 runs effects twice in
 * development, and without it every view is counted double in exactly the
 * environment where somebody first looks at the numbers.
 *
 * Only the four names the server cannot see for itself can be raised here; the
 * API refuses the rest with a 422, which is the security boundary of the whole
 * ingest endpoint.
 */
export function useTrackView(
  name: TrackableEvent,
  key: string | null | undefined,
  payload: { courseId?: string; itemId?: string } = {},
): void {
  const sent = useRef<string | null>(null);

  useEffect(() => {
    if (!key || sent.current === key) return;

    sent.current = key;

    trackEvents([
      {
        name,
        occurred_at: new Date().toISOString(),
        ...(sessionId() ? { session_id: sessionId() } : {}),
        ...(payload.courseId ? { course_id: payload.courseId } : {}),
        ...(payload.itemId ? { course_item_id: payload.itemId } : {}),
        source: 'web',
      },
    ]);
    // `payload` is rebuilt every render; `key` is what identifies the view.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [name, key]);
}
