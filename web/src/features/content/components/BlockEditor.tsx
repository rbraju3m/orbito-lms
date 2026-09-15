import { FileInput, MultiSelect, Select, Stack, Text, Textarea, TextInput } from '@mantine/core';
import { IconUpload } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { useUploadMedia } from '@/features/media/api/queries';
import { publicCoursesQuery } from '@/features/publicsite/api/queries';

import type { EditableBlock } from '../api/pages';

export interface BlockEditorProps {
  block: EditableBlock;
  /** The academy's slug, for picking courses from its public catalogue. */
  academy: string;
  onChange: (props: Record<string, unknown>) => void;
}

const LIMITS = ['1', '2', '3', '4', '5', '6'].map((value) => ({ value, label: value }));

function text(props: Record<string, unknown>, key: string): string {
  const value = props[key];
  return typeof value === 'string' ? value : '';
}

/** Empty is "not set": the server stores null for an optional text it is not given. */
function optional(value: string): string | null {
  return value.trim() === '' ? null : value;
}

/**
 * The form for one block's props. Every field mirrors the server's rules for
 * that type (`BlockType::rules()`), and the server still decides when the page
 * is saved.
 */
export function BlockEditor({ block, academy, onChange }: BlockEditorProps) {
  const { props } = block;
  const set = (key: string, value: unknown) => onChange({ ...props, [key]: value });

  const upload = useUploadMedia();
  // Only a courses block needs the catalogue, and only once there is an
  // academy to read it from.
  const courses = useQuery({
    ...publicCoursesQuery(academy),
    enabled: block.type === 'courses' && academy !== '',
  });

  switch (block.type) {
    case 'heading':
      return (
        <Stack gap="xs" mt="sm">
          <TextInput
            label="Heading"
            value={text(props, 'text')}
            onChange={(event) => set('text', event.currentTarget.value)}
          />
          <Select
            label="Size"
            data={[
              { value: '2', label: 'Large' },
              { value: '3', label: 'Small' },
            ]}
            value={String(props['level'] ?? 2)}
            allowDeselect={false}
            onChange={(value) => set('level', value === '3' ? 3 : 2)}
          />
        </Stack>
      );

    case 'text':
      return (
        <Textarea
          mt="sm"
          label="Text"
          description="HTML: paragraphs, headings, lists, links, images and tables. Anything else is removed when you save."
          autosize
          minRows={6}
          styles={{ input: { fontFamily: 'var(--mantine-font-family-monospace)' } }}
          value={text(props, 'html')}
          onChange={(event) => set('html', event.currentTarget.value)}
        />
      );

    case 'image':
      return (
        <Stack gap="xs" mt="sm">
          <FileInput
            label={props['media_ref'] ? 'Replace the image' : 'Upload an image'}
            accept="image/jpeg,image/png,image/webp,image/avif"
            leftSection={<IconUpload size={16} />}
            disabled={upload.isPending}
            clearable={false}
            onChange={(file) => {
              if (file === null) return;
              upload.mutate(
                { file, collection: 'course_thumbnail' },
                { onSuccess: (media) => set('media_ref', media.ref) },
              );
            }}
          />
          {upload.isError ? (
            <Text size="sm" c="red" role="alert">
              The image could not be uploaded.
            </Text>
          ) : null}
          <TextInput
            label="Description"
            description="For people who cannot see the image."
            value={text(props, 'alt')}
            onChange={(event) => set('alt', optional(event.currentTarget.value))}
          />
          <TextInput
            label="Caption"
            description="Optional."
            value={text(props, 'caption')}
            onChange={(event) => set('caption', optional(event.currentTarget.value))}
          />
        </Stack>
      );

    case 'button':
      return (
        <Stack gap="xs" mt="sm">
          <TextInput
            label="Label"
            value={text(props, 'label')}
            onChange={(event) => set('label', event.currentTarget.value)}
          />
          <TextInput
            label="Link"
            description="A page on this site, starting with /, or a full https:// address."
            value={text(props, 'url')}
            onChange={(event) => set('url', event.currentTarget.value)}
          />
        </Stack>
      );

    case 'courses': {
      const selected = Array.isArray(props['course_ids'])
        ? props['course_ids'].filter((id): id is string => typeof id === 'string')
        : [];

      return (
        <Stack gap="xs" mt="sm">
          <TextInput
            label="Title"
            description="Optional."
            value={text(props, 'title')}
            onChange={(event) => set('title', optional(event.currentTarget.value))}
          />
          <MultiSelect
            label="Courses"
            description="Public, published courses. One that is unpublished later disappears from the page on its own."
            searchable
            maxValues={12}
            data={(courses.data?.data ?? []).map((course) => ({
              value: course.id,
              label: course.title,
            }))}
            value={selected}
            onChange={(ids) => set('course_ids', ids)}
            nothingFoundMessage={courses.isPending ? 'Loading courses…' : 'No courses found'}
          />
        </Stack>
      );
    }

    case 'webinars':
    case 'posts':
      return (
        <Stack gap="xs" mt="sm">
          <TextInput
            label="Title"
            description="Optional."
            value={text(props, 'title')}
            onChange={(event) => set('title', optional(event.currentTarget.value))}
          />
          <Select
            label="How many"
            data={LIMITS}
            value={String(props['limit'] ?? 3)}
            allowDeselect={false}
            onChange={(value) => set('limit', Number(value ?? 3))}
          />
        </Stack>
      );

    case 'lead_form':
      return (
        <Stack gap="xs" mt="sm">
          <TextInput
            label="Title"
            description="Optional. “Stay in touch” when empty."
            value={text(props, 'title')}
            onChange={(event) => set('title', optional(event.currentTarget.value))}
          />
          <Textarea
            label="Description"
            description="Optional."
            autosize
            minRows={2}
            value={text(props, 'description')}
            onChange={(event) => set('description', optional(event.currentTarget.value))}
          />
        </Stack>
      );
  }
}
