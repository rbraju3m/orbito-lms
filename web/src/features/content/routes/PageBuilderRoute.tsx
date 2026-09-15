import {
  Alert,
  Badge,
  Button,
  Card,
  Container,
  Group,
  Menu,
  Modal,
  SegmentedControl,
  Stack,
  Switch,
  Text,
  Textarea,
  TextInput,
  Title,
} from '@mantine/core';
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router';
import { z } from 'zod';

import { useSession } from '@/features/auth/hooks/useSession';
import { PageBlocks } from '@/features/publicsite/components/PageBlocks';
import { ApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/lib/form';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  adminPageQuery,
  useClearHomePage,
  useDeletePage,
  usePublishPage,
  useSavePageBlocks,
  useSetHomePage,
  useUnpublishPage,
  useUpdatePage,
  type EditableBlock,
} from '../api/pages';
import type { AdminPage } from '../api/pageTypes';
import { BlockEditor } from '../components/BlockEditor';
import { SortableBlockRow } from '../components/SortableBlockRow';
import { BLOCK_TYPES, newBlock, toEditable } from '../lib/blocks';

/** Mirrors `UpdatePageRequest`; the server still decides. */
const settingsSchema = z.object({
  title: z
    .string()
    .trim()
    .min(1, 'A page needs a title.')
    .max(200, 'Keep it under 200 characters.'),
  slug: z
    .string()
    .trim()
    .max(190, 'Keep it under 190 characters.')
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Lower-case letters, digits and single hyphens.'),
  seo_title: z.string().trim().max(200, 'Keep it under 200 characters.'),
  seo_description: z.string().trim().max(300, 'Keep it under 300 characters.'),
});

type SettingsValues = z.infer<typeof settingsSchema>;

const SETTINGS_FIELDS = ['title', 'slug', 'seo_title', 'seo_description'] as const;

/** Building one page: its blocks, its settings, and whether visitors see it. */
export function PageBuilderRoute() {
  const { id = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(adminPageQuery(id));

  if (isPending) return <LoadingState rows={5} height={96} label="Loading page" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <PageBuilder key={data.id} page={data} />;
}

/** "Block 3: Link must be…" — a save's field errors, named by position. */
function describeBlockErrors(error: unknown): string[] {
  if (!(error instanceof ApiError)) return [];
  if (!error.isValidation) return [error.message];

  return error.details.map((detail) => {
    const position = /^blocks\.(\d+)\./.exec(detail.field ?? '');
    return position ? `Block ${Number(position[1]) + 1}: ${detail.message}` : detail.message;
  });
}

function PageBuilder({ page }: { page: AdminPage }) {
  const navigate = useNavigate();
  const { session } = useSession();
  const academy = session?.academy?.slug ?? '';

  /*
   * The UNSAVED list the author is arranging — form state, like the fields of
   * any form, not a mirror of the server's copy. Saving replaces it with what
   * the server stored (normalised), and the preview only ever shows that.
   */
  const [blocks, setBlocks] = useState<EditableBlock[]>(() => toEditable(page.blocks));
  const [editingId, setEditingId] = useState<string | null>(null);
  const [mode, setMode] = useState<'build' | 'preview'>('build');
  const [deleting, setDeleting] = useState(false);
  const [settingsError, setSettingsError] = useState<string | null>(null);

  const save = useSavePageBlocks(page.id);
  const update = useUpdatePage(page.id);
  const publish = usePublishPage(page.id);
  const unpublish = useUnpublishPage(page.id);
  const setHome = useSetHomePage(page.id);
  const clearHome = useClearHomePage(page.id);
  const remove = useDeletePage();

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isDirty, isSubmitting },
    reset,
  } = useForm<SettingsValues>({
    resolver: zodResolver(settingsSchema),
    defaultValues: {
      title: page.title,
      slug: page.slug,
      seo_title: page.seo_title ?? '',
      seo_description: page.seo_description ?? '',
    },
  });

  const blocksChanged = JSON.stringify(blocks) !== JSON.stringify(toEditable(page.blocks));

  const move = (from: number, to: number) => {
    if (to < 0 || to >= blocks.length) return;
    setBlocks((current) => arrayMove(current, from, to));
  };

  const onDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const from = blocks.findIndex((block) => block.id === active.id);
    const to = blocks.findIndex((block) => block.id === over.id);
    if (from !== -1 && to !== -1) move(from, to);
  };

  const addBlock = (type: EditableBlock['type']) => {
    const block = newBlock(type);
    setBlocks((current) => [...current, block]);
    setEditingId(block.id);
  };

  const saveBlocks = () => {
    save.mutate(blocks, { onSuccess: (saved) => setBlocks(toEditable(saved.blocks)) });
  };

  const saveSettings = handleSubmit(async (values) => {
    setSettingsError(null);
    try {
      const saved = await update.mutateAsync({
        title: values.title,
        seo_title: values.seo_title || null,
        seo_description: values.seo_description || null,
        ...(page.can_edit_slug ? { slug: values.slug } : {}),
      });
      reset({
        title: saved.title,
        slug: saved.slug,
        seo_title: saved.seo_title ?? '',
        seo_description: saved.seo_description ?? '',
      });
    } catch (error) {
      setSettingsError(applyServerErrors(error, setError, SETTINGS_FIELDS));
    }
  });

  const blockErrors = describeBlockErrors(save.error);
  const lifecycleError = [
    publish.error,
    unpublish.error,
    setHome.error,
    clearHome.error,
    remove.error,
  ].find((candidate) => candidate instanceof ApiError);

  return (
    <Container size="md" py="lg">
      <PageHeader
        title={page.title}
        description={`${page.status_label}${page.is_home ? ' · front page' : ''}${page.show_in_nav ? ' · in the header' : ''}`}
        actions={
          page.status === 'published' ? (
            <Button
              variant="light"
              loading={unpublish.isPending}
              onClick={() => unpublish.mutate()}
            >
              Unpublish
            </Button>
          ) : (
            <Button
              loading={publish.isPending}
              disabled={blocksChanged}
              onClick={() => publish.mutate()}
            >
              Publish
            </Button>
          )
        }
      />

      <Stack gap="lg">
        {lifecycleError instanceof ApiError ? (
          <Alert color="red" role="alert">
            {lifecycleError.message}
          </Alert>
        ) : null}

        <Card withBorder padding="lg">
          <Stack gap="md">
            <Group justify="space-between" align="flex-end">
              <Title order={2} size="h4">
                Blocks
              </Title>
              <SegmentedControl
                size="xs"
                aria-label="Build or preview"
                data={[
                  { value: 'build', label: 'Build' },
                  { value: 'preview', label: 'Preview' },
                ]}
                value={mode}
                onChange={(value) => setMode(value === 'preview' ? 'preview' : 'build')}
              />
            </Group>

            {mode === 'preview' ? (
              <Stack gap="xs">
                <Text size="xs" c="dimmed">
                  The saved page, exactly as a visitor will see it.
                  {blocksChanged ? ' You have unsaved changes.' : ''}
                </Text>
                {page.blocks.length > 0 ? (
                  <PageBlocks academy={academy} blocks={page.blocks} />
                ) : (
                  <Text c="dimmed">Nothing on this page yet.</Text>
                )}
              </Stack>
            ) : (
              <>
                {blocks.length === 0 ? (
                  <Text c="dimmed">
                    No blocks yet. Add a heading, some text or a course grid to start.
                  </Text>
                ) : (
                  <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={onDragEnd}
                  >
                    <SortableContext
                      items={blocks.map((block) => block.id)}
                      strategy={verticalListSortingStrategy}
                    >
                      <Stack gap="xs">
                        {blocks.map((block, index) => (
                          <SortableBlockRow
                            key={block.id}
                            block={block}
                            index={index}
                            count={blocks.length}
                            editing={editingId === block.id}
                            onEdit={() => setEditingId(editingId === block.id ? null : block.id)}
                            onMove={(to) => move(index, to)}
                            onRemove={() =>
                              setBlocks((current) => current.filter((b) => b.id !== block.id))
                            }
                          >
                            <BlockEditor
                              block={block}
                              academy={academy}
                              onChange={(props) =>
                                setBlocks((current) =>
                                  current.map((b) => (b.id === block.id ? { ...b, props } : b)),
                                )
                              }
                            />
                          </SortableBlockRow>
                        ))}
                      </Stack>
                    </SortableContext>
                  </DndContext>
                )}

                {blockErrors.length > 0 ? (
                  <Alert color="red" role="alert" title="The page was not saved">
                    <Stack gap={2}>
                      {blockErrors.map((message) => (
                        <Text key={message} size="sm">
                          {message}
                        </Text>
                      ))}
                    </Stack>
                  </Alert>
                ) : null}

                <Group justify="space-between">
                  <Menu position="bottom-start">
                    <Menu.Target>
                      <Button
                        variant="light"
                        leftSection={<IconPlus size={16} />}
                        disabled={blocks.length >= 50}
                      >
                        Add block
                      </Button>
                    </Menu.Target>
                    <Menu.Dropdown>
                      {BLOCK_TYPES.map((option) => (
                        <Menu.Item key={option.type} onClick={() => addBlock(option.type)}>
                          <Text size="sm">{option.label}</Text>
                          <Text size="xs" c="dimmed">
                            {option.description}
                          </Text>
                        </Menu.Item>
                      ))}
                    </Menu.Dropdown>
                  </Menu>

                  <Button loading={save.isPending} disabled={!blocksChanged} onClick={saveBlocks}>
                    Save blocks
                  </Button>
                </Group>

                {page.status === 'draft' && blocksChanged ? (
                  <Text size="xs" c="dimmed">
                    Save your blocks before publishing — publishing puts out the saved page.
                  </Text>
                ) : null}
              </>
            )}
          </Stack>
        </Card>

        <form onSubmit={saveSettings} noValidate>
          <Card withBorder padding="lg">
            <Stack gap="md">
              <Title order={2} size="h4">
                Settings
              </Title>
              <TextInput
                label="Title"
                required
                error={errors.title?.message}
                {...register('title')}
              />
              <TextInput
                label="Address"
                description={
                  page.can_edit_slug
                    ? `The page lives at /a/${academy}/p/… — this cannot change once it has been published.`
                    : 'Locked: the page has been published, and links to it must keep working.'
                }
                disabled={!page.can_edit_slug}
                error={errors.slug?.message}
                {...register('slug')}
              />
              <TextInput
                label="Search title"
                description="Optional. What a search result or a shared link shows instead of the title."
                error={errors.seo_title?.message}
                {...register('seo_title')}
              />
              <Textarea
                label="Search description"
                description="Optional."
                autosize
                minRows={2}
                error={errors.seo_description?.message}
                {...register('seo_description')}
              />
              {settingsError !== null ? (
                <Alert color="red" role="alert">
                  {settingsError}
                </Alert>
              ) : null}
              <Group justify="flex-end">
                <Button type="submit" loading={isSubmitting} disabled={!isDirty}>
                  Save settings
                </Button>
              </Group>
            </Stack>
          </Card>
        </form>

        <Card withBorder padding="lg">
          <Stack gap="sm">
            <Title order={2} size="h4">
              On the site
            </Title>

            <Switch
              label="Link to this page from the site's header"
              checked={page.show_in_nav}
              disabled={update.isPending}
              onChange={(event) => update.mutate({ show_in_nav: event.currentTarget.checked })}
            />

            <Group gap="sm" align="center">
              {page.is_home ? (
                <>
                  <Badge color="blue" variant="light">
                    Front page
                  </Badge>
                  <Button
                    variant="subtle"
                    size="xs"
                    loading={clearHome.isPending}
                    onClick={() => clearHome.mutate()}
                  >
                    Stop using as the front page
                  </Button>
                </>
              ) : (
                <Button
                  variant="light"
                  size="xs"
                  loading={setHome.isPending}
                  onClick={() => setHome.mutate()}
                >
                  Use as the front page
                </Button>
              )}
            </Group>

            {page.is_home && page.status === 'draft' ? (
              <Text size="sm" c="dimmed">
                Visitors see the standard front page until this one is published.
              </Text>
            ) : null}
          </Stack>
        </Card>

        <Group justify="flex-end">
          <Button variant="subtle" color="red" onClick={() => setDeleting(true)}>
            Delete page
          </Button>
        </Group>
      </Stack>

      <Modal opened={deleting} onClose={() => setDeleting(false)} title="Delete this page?">
        <Stack gap="sm">
          <Text size="sm">
            <strong>{page.title}</strong> is deleted outright. Links to it will stop working
            {page.is_home ? ', and the site goes back to its standard front page' : ''}.
          </Text>
          <Group justify="flex-end" gap="xs">
            <Button variant="default" onClick={() => setDeleting(false)}>
              Keep it
            </Button>
            <Button
              color="red"
              loading={remove.isPending}
              onClick={() =>
                remove.mutate(page.id, { onSuccess: () => void navigate('/admin/pages') })
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
