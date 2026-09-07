import { useCallback, useEffect, useRef } from 'react';

import { useRecordWatch } from '../api/queries';

const HEARTBEAT_SECONDS = 15;

/**
 * Reports the video position at most once every 15 seconds, plus once on
 * unmount.
 *
 * A `timeupdate` event fires 4-60 times a second; posting that would be a
 * denial of service on our own API. The unmount flush is what makes "resume
 * where I left off" work when someone closes the tab mid-lesson.
 */
export function useWatchHeartbeat(itemId: string | null) {
  const { mutate } = useRecordWatch();
  const lastSent = useRef(0);
  const latestPosition = useRef(0);
  const currentItem = useRef(itemId);

  useEffect(() => {
    currentItem.current = itemId;
    lastSent.current = 0;
    latestPosition.current = 0;
  }, [itemId]);

  const report = useCallback(
    (position: number) => {
      latestPosition.current = position;

      const now = Date.now();
      if (now - lastSent.current < HEARTBEAT_SECONDS * 1000) return;

      lastSent.current = now;
      if (currentItem.current) mutate({ itemId: currentItem.current, position });
    },
    [mutate],
  );

  const flush = useCallback(() => {
    if (currentItem.current && latestPosition.current > 0) {
      mutate({ itemId: currentItem.current, position: latestPosition.current });
    }
  }, [mutate]);

  useEffect(() => () => flush(), [flush]);

  return { report, flush };
}
