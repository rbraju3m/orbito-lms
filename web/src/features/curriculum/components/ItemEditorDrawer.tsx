import {
  Alert,
  Button,
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
import { useState } from 'react';

import { AssignmentBuilder } from '@/features/assignment/components/AssignmentBuilder';
import { QuizBuilder } from '@/features/quiz/components/QuizBuilder';
import { ApiError } from '@/shared/api/errors';

import { useUpdateLesson } from '../api/queries';
import type { CourseItem, LessonContent, VideoProvider } from '../api/types';

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
  const lesson = (item.content ?? {}) as Partial<LessonContent>;

  const [title, setTitle] = useState(item.title);
  const [content, setContent] = useState(lesson.content ?? '');
  const [provider, setProvider] = useState<VideoProvider>(lesson.video_provider ?? 'none');
  const [videoUrl, setVideoUrl] = useState(lesson.video_url ?? '');
  const [duration, setDuration] = useState<number>(
    Math.round((lesson.video_duration_seconds ?? item.duration_seconds) / 60),
  );
  const [isPreview, setIsPreview] = useState(item.is_preview);
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
        description="A rich text editor replaces this field in Phase 6."
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
        description="Anyone can watch this without enrolling."
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
