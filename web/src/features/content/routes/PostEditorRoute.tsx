import {
  Alert,
  Box,
  Button,
  Card,
  Container,
  FileInput,
  Group,
  Image,
  Modal,
  SegmentedControl,
  Stack,
  Text,
  Textarea,
  TextInput,
  Title,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconUpload } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router';
import { z } from 'zod';

import { useUploadMedia } from '@/features/media/api/queries';
import { ApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/lib/form';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  adminPostQuery,
  useDeletePost,
  usePublishPost,
  useUnpublishPost,
  useUpdatePost,
  type PostInput,
} from '../api/posts';
import type { AdminPost } from '../api/postTypes';
import { isFuture, postState, toPublishAt } from '../lib/posts';

/** Mirrors `UpdatePostRequest`; the server still decides. */
const postSchema = z.object({
  title: z
    .string()
    .trim()
    .min(1, 'A post needs a title.')
    .max(200, 'Keep it under 200 characters.'),
  slug: z
    .string()
    .trim()
    .max(190, 'Keep it under 190 characters.')
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Lower-case letters, digits and single hyphens.'),
  excerpt: z.string().trim().max(500, 'Keep it under 500 characters.'),
  body: z.string().max(200000, 'That post is too long.'),
  seo_title: z.string().trim().max(200, 'Keep it under 200 characters.'),
  seo_description: z.string().trim().max(300, 'Keep it under 300 characters.'),
});

type PostValues = z.infer<typeof postSchema>;

const FIELDS = ['title', 'slug', 'excerpt', 'body', 'seo_title', 'seo_description'] as const;

/** Writing one post: the words, the address, the cover, and whether it is out. */
export function PostEditorRoute() {
  const { id = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(adminPostQuery(id));

  if (isPending) return <LoadingState rows={5} height={96} label="Loading post" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <PostEditor key={data.id} post={data} />;
}

function PostEditor({ post }: { post: AdminPost }) {
  const navigate = useNavigate();
  const update = useUpdatePost(post.id);
  const publish = usePublishPost(post.id);
  const unpublish = useUnpublishPost(post.id);
  const remove = useDeletePost();
  const upload = useUploadMedia();

  const [mode, setMode] = useState<'write' | 'preview'>('write');
  const [publishAt, setPublishAt] = useState('');
  const [deleting, setDeleting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isDirty, isSubmitting },
    reset,
  } = useForm<PostValues>({
    resolver: zodResolver(postSchema),
    defaultValues: {
      title: post.title,
      slug: post.slug,
      excerpt: post.excerpt ?? '',
      body: post.body ?? '',
      seo_title: post.seo_title ?? '',
      seo_description: post.seo_description ?? '',
    },
  });

  const save = handleSubmit(async (values) => {
    setFormError(null);

    const input: PostInput = {
      title: values.title,
      excerpt: values.excerpt || null,
      body: values.body || null,
      seo_title: values.seo_title || null,
      seo_description: values.seo_description || null,
      // The address is locked once the post has been out; sending it would 422.
      ...(post.can_edit_slug ? { slug: values.slug } : {}),
    };

    try {
      const saved = await update.mutateAsync(input);
      // What the server STORED — the body comes back sanitised.
      reset({
        title: saved.title,
        slug: saved.slug,
        excerpt: saved.excerpt ?? '',
        body: saved.body ?? '',
        seo_title: saved.seo_title ?? '',
        seo_description: saved.seo_description ?? '',
      });
    } catch (error) {
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  const onCover = (file: File | null) => {
    if (file === null) return;
    upload.mutate(
      { file, collection: 'course_thumbnail' },
      { onSuccess: (media) => update.mutate({ cover_media_id: media.ref }) },
    );
  };

  const state = postState(post);
  const lifecycleError = [publish.error, unpublish.error, upload.error, remove.error].find(
    (candidate) => candidate instanceof ApiError,
  );

  return (
    <Container size="md" py="lg">
      <PageHeader
        title={post.title}
        description={
          post.published_at
            ? `${state.label} · ${post.is_scheduled ? 'goes out' : 'out since'} ${new Date(post.published_at).toLocaleString()}`
            : state.label
        }
        actions={
          post.status === 'published' ? (
            <Button
              variant="light"
              loading={unpublish.isPending}
              onClick={() => unpublish.mutate()}
            >
              Unpublish
            </Button>
          ) : undefined
        }
      />

      <Stack gap="lg">
        {lifecycleError instanceof ApiError ? (
          <Alert color="red" role="alert">
            {lifecycleError.message}
          </Alert>
        ) : null}

        <form onSubmit={save} noValidate>
          <Card withBorder padding="lg">
            <Stack gap="md">
              <TextInput
                label="Title"
                required
                error={errors.title?.message}
                {...register('title')}
              />

              <TextInput
                label="Address"
                description={
                  post.can_edit_slug
                    ? 'The end of the post’s link. It cannot change once the post has been published.'
                    : 'Locked: the post has been published, and links to it must keep working.'
                }
                disabled={!post.can_edit_slug}
                error={errors.slug?.message}
                {...register('slug')}
              />

              <Textarea
                label="Excerpt"
                description="A sentence or two for the blog list."
                autosize
                minRows={2}
                error={errors.excerpt?.message}
                {...register('excerpt')}
              />

              <Stack gap="xs">
                <Group justify="space-between" align="flex-end">
                  <Text fw={500} size="sm">
                    Body
                  </Text>
                  <SegmentedControl
                    size="xs"
                    aria-label="Write or preview"
                    data={[
                      { value: 'write', label: 'Write' },
                      { value: 'preview', label: 'Preview' },
                    ]}
                    value={mode}
                    onChange={(value) => setMode(value === 'preview' ? 'preview' : 'write')}
                  />
                </Group>

                {mode === 'write' ? (
                  <Textarea
                    aria-label="Body"
                    description="HTML: headings, paragraphs, lists, links, images and tables. Anything else is removed when you save."
                    autosize
                    minRows={12}
                    styles={{ input: { fontFamily: 'var(--mantine-font-family-monospace)' } }}
                    error={errors.body?.message}
                    {...register('body')}
                  />
                ) : (
                  <Box>
                    <Text size="xs" c="dimmed" mb="xs">
                      The saved version, exactly as a visitor will see it.
                      {isDirty ? ' You have unsaved changes.' : ''}
                    </Text>
                    {post.body ? (
                      // Sanitised by the server when it was saved.
                      <Box
                        className="orbito-prose"
                        dangerouslySetInnerHTML={{ __html: post.body }}
                      />
                    ) : (
                      <Text c="dimmed">Nothing written yet.</Text>
                    )}
                  </Box>
                )}
              </Stack>

              <TextInput
                label="Search title"
                description="Optional. What a search result or a shared link shows instead of the title."
                error={errors.seo_title?.message}
                {...register('seo_title')}
              />
              <Textarea
                label="Search description"
                description="Optional. Falls back to the excerpt."
                autosize
                minRows={2}
                error={errors.seo_description?.message}
                {...register('seo_description')}
              />

              {formError !== null ? (
                <Alert color="red" role="alert">
                  {formError}
                </Alert>
              ) : null}

              <Group justify="flex-end">
                <Button type="submit" loading={isSubmitting} disabled={!isDirty}>
                  Save
                </Button>
              </Group>
            </Stack>
          </Card>
        </form>

        <Card withBorder padding="lg">
          <Stack gap="sm">
            <Title order={2} size="h4">
              Cover image
            </Title>
            {post.cover_url ? (
              <Image src={post.cover_url} alt="" radius="sm" mah={220} fit="cover" />
            ) : null}
            <Group gap="sm" align="flex-end">
              <FileInput
                label={post.cover_url ? 'Replace the cover' : 'Upload a cover'}
                accept="image/jpeg,image/png,image/webp,image/avif"
                leftSection={<IconUpload size={16} />}
                onChange={onCover}
                disabled={upload.isPending}
                clearable={false}
              />
              {post.cover_url ? (
                <Button
                  variant="subtle"
                  color="red"
                  onClick={() => update.mutate({ cover_media_id: null })}
                >
                  Remove cover
                </Button>
              ) : null}
            </Group>
          </Stack>
        </Card>

        <Card withBorder padding="lg">
          <Stack gap="sm">
            <Title order={2} size="h4">
              Publishing
            </Title>
            {post.status === 'draft' ? (
              <>
                <Text size="sm" c="dimmed">
                  Publish now, or choose a time and it will appear on the site on its own. Save your
                  changes first — publishing puts out the saved version.
                </Text>
                <Group gap="sm" align="flex-end">
                  <TextInput
                    type="datetime-local"
                    label="Publish at (optional)"
                    value={publishAt}
                    onChange={(event) => {
                      const { value } = event.currentTarget;
                      setPublishAt(value);
                    }}
                  />
                  <Button
                    loading={publish.isPending}
                    onClick={() => publish.mutate(toPublishAt(publishAt))}
                  >
                    {isFuture(publishAt) ? 'Schedule' : 'Publish now'}
                  </Button>
                </Group>
              </>
            ) : (
              <Text size="sm">
                {post.is_scheduled
                  ? 'Scheduled. It appears on the site on its own at the time above; unpublish to take it back to a draft.'
                  : 'Live on the site. Unpublish to take it down; it keeps its address and its date.'}
              </Text>
            )}
          </Stack>
        </Card>

        <Group justify="flex-end">
          <Button variant="subtle" color="red" onClick={() => setDeleting(true)}>
            Delete post
          </Button>
        </Group>
      </Stack>

      <Modal opened={deleting} onClose={() => setDeleting(false)} title="Delete this post?">
        <Stack gap="sm">
          <Text size="sm">
            <strong>{post.title}</strong> is deleted outright. If it has been published, links to it
            will stop working — unpublish instead if you only want it hidden.
          </Text>
          <Group justify="flex-end" gap="xs">
            <Button variant="default" onClick={() => setDeleting(false)}>
              Keep it
            </Button>
            <Button
              color="red"
              loading={remove.isPending}
              onClick={() =>
                remove.mutate(post.id, { onSuccess: () => void navigate('/admin/posts') })
              }
            >
              Delete
            </Button>
          </Group>
        </Stack>
      </Modal>
    </Container>
  );
}
