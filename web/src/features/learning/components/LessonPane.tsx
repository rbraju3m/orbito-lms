import { AspectRatio, Box, Stack, Text, Title } from '@mantine/core';
import { useEffect, useRef } from 'react';

import type { ItemPayload, LessonPayload } from '../api/types';
import { useWatchHeartbeat } from '../hooks/useWatchHeartbeat';

export interface LessonPaneProps {
  item: ItemPayload;
  /** Where the learner had reached, so playback resumes there. */
  resumeAt: number;
}

export function LessonPane({ item, resumeAt }: LessonPaneProps) {
  const lesson = item.content as Partial<LessonPayload>;
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const { report } = useWatchHeartbeat(item.id);

  useEffect(() => {
    const video = videoRef.current;
    if (video && resumeAt > 0 && Number.isFinite(resumeAt)) {
      video.currentTime = resumeAt;
    }
  }, [item.id, resumeAt]);

  const embedUrl = toEmbedUrl(lesson.video_provider, lesson.video_url);

  return (
    <Stack gap="lg">
      <Title order={2}>{item.title}</Title>

      {lesson.video_signed_url ? (
        <AspectRatio ratio={16 / 9}>
          <video
            ref={videoRef}
            src={lesson.video_signed_url}
            controls
            preload="metadata"
            // timeupdate fires many times a second; the heartbeat throttles it
            // to one request per 15s.
            onTimeUpdate={(event) => report(event.currentTarget.currentTime)}
            style={{ width: '100%', borderRadius: 'var(--mantine-radius-md)' }}
          />
        </AspectRatio>
      ) : null}

      {!lesson.video_signed_url && embedUrl ? (
        <AspectRatio ratio={16 / 9}>
          <iframe
            src={embedUrl}
            title={item.title}
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture"
            allowFullScreen
            style={{ border: 0, borderRadius: 'var(--mantine-radius-md)' }}
          />
        </AspectRatio>
      ) : null}

      {lesson.body ? (
        // The server sanitises rich text on write; this renders what it stored.
        <Box className="orbito-prose" dangerouslySetInnerHTML={{ __html: lesson.body }} />
      ) : (
        <Text c="dimmed" size="sm">
          This lesson has no written content.
        </Text>
      )}
    </Stack>
  );
}

/**
 * Turns a watch URL into an embeddable one. Returns null for anything
 * unrecognised rather than injecting an arbitrary URL into an iframe.
 */
function toEmbedUrl(provider: string | undefined, url: string | null | undefined): string | null {
  if (!url) return null;

  if (provider === 'youtube') {
    const id = url.match(/(?:youtu\.be\/|[?&]v=|\/embed\/)([\w-]{11})/)?.[1];
    return id ? `https://www.youtube-nocookie.com/embed/${id}` : null;
  }

  if (provider === 'vimeo') {
    const id = url.match(/vimeo\.com\/(?:video\/)?(\d+)/)?.[1];
    return id ? `https://player.vimeo.com/video/${id}` : null;
  }

  return null;
}
