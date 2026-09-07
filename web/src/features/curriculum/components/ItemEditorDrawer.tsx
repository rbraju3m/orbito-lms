import {
  Alert,
  Button,
  Divider,
  Drawer,
  Group,
  NumberInput,
  Select,
  Stack,
  Switch,
  Textarea,
  TextInput,
} from '@mantine/core';
import { IconAlertCircle, IconCheck } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { AssignmentBuilder } from '@/features/assignment/components/AssignmentBuilder';
import type { DripMode } from '@/features/catalog/api/types';
import { studioCourseQuery } from '@/features/studio/api/queries';
import { QuizBuilder } from '@/features/quiz/components/QuizBuilder';
import { ApiError } from '@/shared/api/errors';

import { curriculumQuery, useUpdateItem, useUpdateLesson } from '../api/queries';
import type { CourseItem, LessonContent, VideoProvider } from '../api/types';
import type { DripValues } from './DripFields';
import { DripFields } from './DripFields';

export interface ItemEditorDrawerProps {
  courseId: string;
  item: CourseItem | null;
  onClose: () => void;
}

const PROVIDERS: Array<{ value: VideoProvider; label: string }> = [
  { value: 'none', label: 'No video' },
  { value: 'youtube', label: 'YouTube' },
  { value: 'vimeo', label: 'Vimeo' },
  { value: 'external', label: 'External URL' },
];

/**
 * A drawer, not a modal: editing a lesson is a secondary flow and the
 * curriculum should stay visible behind it (docs/DESIGN_SYSTEM.md §4).
 */
export function ItemEditorDrawer({ courseId, item, onClose }: ItemEditorDrawerProps) {
  return (
    <Drawer
      opened={item !== null}
      onClose={onClose}
      title={item?.type_label ?? 'Item'}
      position="right"
      size={item?.type === 'quiz' ? 'xl' : 'lg'}
      padding="lg"
    >
      {/* Keyed by item id: switching items remounts the form with the right
          initial values, so no effect has to re-sync state into it. */}
      {item === null ? null : item.type === 'quiz' ? (
        <QuizBuilder key={item.id} itemId={item.id} />
      ) : item.type === 'assignment' ? (
        <AssignmentBuilder key={item.id} itemId={item.id} />
      ) : (
        <ItemForm key={item.id} courseId={courseId} item={item} onClose={onClose} />
      )}
    </Drawer>
  );
}

function ItemForm({
  courseId,
  item,
  onClose,
}: {
  courseId: string;
  item: CourseItem;
  onClose: () => void;
}) {
  const { mutateAsync, isPending } = useUpdateLesson(courseId);
  const { mutateAsync: updateItem } = useUpdateItem(courseId);
  const lesson = (item.content ?? {}) as Partial<LessonContent>;

  /*
   * The drip mode is a COURSE setting, and the sibling list comes from the
   * curriculum — both already in the cache the builder reads, so this costs no
   * extra request.
   */
  const course = useQuery(studioCourseQuery(courseId));
  const tree = useQuery(curriculumQuery(courseId));

  const dripMode: DripMode = course.data?.settings?.drip_mode ?? 'none';
  const siblings = (tree.data ?? [])
    .flatMap((section) => section.items)
    .filter((candidate) => candidate.ref !== item.ref && candidate.is_completable);

  const [title, setTitle] = useState(item.title);
  const [content, setContent] = useState(lesson.content ?? '');
  const [provider, setProvider] = useState<VideoProvider>(lesson.video_provider ?? 'none');
  const [videoUrl, setVideoUrl] = useState(lesson.video_url ?? '');
  const [duration, setDuration] = useState<number>(
    Math.round((lesson.video_duration_seconds ?? item.duration_seconds) / 60),
  );
  const [isPreview, setIsPreview] = useState(item.is_preview);
  const [drip, setDrip] = useState<DripValues>({
    drip_available_at: item.drip_available_at,
    drip_after_days: item.drip_after_days,
    drip_after_item_id: item.drip_after_item_id,
  });
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const save = async () => {
    setError(null);
    setSaved(false);

    try {
      await mutateAsync({
        id: item.id,
        title,
        content,
        content_format: 'html',
        video_provider: provider,
        ...(provider === 'none' ? {} : { video_url: videoUrl }),
        video_duration_seconds: Math.max(0, Math.round(duration * 60)),
        is_preview: isPreview,
      });

      // Two endpoints, deliberately: the lesson body and the item's own
      // scheduling are different resources. Drip goes second so a failure here
      // cannot lose the author's writing.
      await updateItem({ id: item.id, ...drip });

      setSaved(true);
    } catch (err) {
      setError(
        err instanceof ApiError
          ? (Object.values(err.fieldErrors())[0] ?? err.message)
          : 'Something went wrong.',
      );
    }
  };

  return (
    <Stack gap="md">
      {error ? (
        <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
          {error}
        </Alert>
      ) : null}

      {saved ? (
        <Alert color="success" icon={<IconCheck size={16} />} role="status">
          Saved.
        </Alert>
      ) : null}

      <TextInput
        value={title}
        onChange={(event) => setTitle(event.currentTarget.value)}
        label="Title"
        required
      />

      <Textarea
        value={content}
        onChange={(event) => setContent(event.currentTarget.value)}
        label="Lesson content"
        description="Sanitised on the server. A rich text editor is still to come."
        autosize
        minRows={6}
        maxRows={18}
      />

      <Select
        data={PROVIDERS}
        value={provider}
        onChange={(value) => setProvider((value as VideoProvider) ?? 'none')}
        label="Video"
      />

      {provider !== 'none' ? (
        <TextInput
          value={videoUrl}
          onChange={(event) => setVideoUrl(event.currentTarget.value)}
          label="Video URL"
          placeholder="https://www.youtube.com/watch?v=…"
          required
        />
      ) : null}

      <NumberInput
        value={duration}
        onChange={(value) => setDuration(typeof value === 'number' ? value : 0)}
        label="Length (minutes)"
        description="Shown in the curriculum and used for the course total."
        min={0}
        max={1440}
      />

      <Switch
        checked={isPreview}
        onChange={(event) => setIsPreview(event.currentTarget.checked)}
        label="Free preview"
        description="Any member of your academy can watch this without enrolling."
      />

      <Divider label="Release schedule" labelPosition="left" />

      <DripFields
        mode={dripMode}
        values={drip}
        onChange={(patch) => setDrip((current) => ({ ...current, ...patch }))}
        siblings={siblings}
        isPreview={isPreview}
      />

      <Group justify="flex-end">
        <Button variant="subtle" onClick={onClose}>
          Close
        </Button>
        <Button loading={isPending} onClick={() => void save()}>
          Save
        </Button>
      </Group>
    </Stack>
  );
}
