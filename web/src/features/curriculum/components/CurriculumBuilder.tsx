import { Button, Group, Loader, Stack, Text, TextInput } from '@mantine/core';
import { modals } from '@mantine/modals';
import {
  closestCenter,
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
  type DragStartEvent,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { IconAlertTriangle, IconCheck, IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import {
  curriculumQuery,
  useCreateItem,
  useCreateSection,
  useDeleteItem,
  useDeleteSection,
  useDuplicateItem,
  useDuplicateSection,
  useReorderCurriculum,
  useUpdateItem,
  useUpdateSection,
} from '../api/queries';
import type { CourseItem, CourseSection, ItemType } from '../api/types';
import { applyDrag, sectionDomId, toReorderPayload } from '../hooks/useCurriculumTree';

import { SortableSection } from './SortableSection';

export interface CurriculumBuilderProps {
  courseId: string;
  onEditItem: (item: CourseItem) => void;
}

type SaveState = 'idle' | 'saving' | 'saved' | 'error';

export function CurriculumBuilder({ courseId, onEditItem }: CurriculumBuilderProps) {
  const { data, isPending, isError, error, refetch } = useQuery(curriculumQuery(courseId));

  const reorder = useReorderCurriculum(courseId);
  const createSection = useCreateSection(courseId);
  const updateSection = useUpdateSection(courseId);
  const deleteSection = useDeleteSection(courseId);
  const duplicateSection = useDuplicateSection(courseId);
  const createItem = useCreateItem(courseId);
  const updateItem = useUpdateItem(courseId);
  const deleteItem = useDeleteItem(courseId);
  const duplicateItem = useDuplicateItem(courseId);

  const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
  const [activeId, setActiveId] = useState<string | null>(null);
  const [newSectionTitle, setNewSectionTitle] = useState('');
  const [saveState, setSaveState] = useState<SaveState>('idle');

  // No local copy of the tree. The query cache is the single source of truth
  // and `useReorderCurriculum` updates it optimistically in onMutate, so a
  // drag is visible immediately without a second place to keep in sync.
  const tree: CourseSection[] = data ?? [];

  const sensors = useSensors(
    // A small distance threshold so a click to rename is not read as a drag.
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const persist = (next: CourseSection[]) => {
    setSaveState('saving');
    reorder.mutate(toReorderPayload(next), {
      onSuccess: () => setSaveState('saved'),
      onError: () => setSaveState('error'),
    });
  };

  const handleDragStart = (event: DragStartEvent) => setActiveId(String(event.active.id));

  const handleDragEnd = (event: DragEndEvent) => {
    setActiveId(null);
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    const next = applyDrag(tree, String(active.id), String(over.id));
    if (next === tree) return;

    persist(next);
  };

  const confirmDestructive = (title: string, message: string, onConfirm: () => void) =>
    modals.openConfirmModal({
      title,
      children: <Text size="sm">{message}</Text>,
      labels: { confirm: 'Delete', cancel: 'Keep it' },
      confirmProps: { color: 'danger' },
      onConfirm,
    });

  if (isPending) return <LoadingState rows={3} height={90} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return (
    <Stack gap="md">
      <Group justify="space-between">
        <Group gap="xs">
          <Button
            size="xs"
            variant="subtle"
            onClick={() => setCollapsed(new Set(tree.map((s) => s.id)))}
            disabled={tree.length === 0}
          >
            Collapse all
          </Button>
          <Button
            size="xs"
            variant="subtle"
            onClick={() => setCollapsed(new Set())}
            disabled={tree.length === 0}
          >
            Expand all
          </Button>
        </Group>

        {/* One status indicator for the whole surface, not a toast per drag. */}
        <Group gap={6} aria-live="polite">
          {saveState === 'saving' ? (
            <>
              <Loader size={14} />
              <Text size="xs" c="dimmed">
                Saving…
              </Text>
            </>
          ) : null}
          {saveState === 'saved' ? (
            <>
              <IconCheck size={14} color="var(--mantine-color-success-6)" />
              <Text size="xs" c="dimmed">
                Saved
              </Text>
            </>
          ) : null}
          {saveState === 'error' ? (
            <>
              <IconAlertTriangle size={14} color="var(--mantine-color-danger-6)" />
              <Button
                size="compact-xs"
                variant="subtle"
                color="danger"
                onClick={() => persist(tree)}
              >
                Retry save
              </Button>
            </>
          ) : null}
        </Group>
      </Group>

      {tree.length === 0 ? (
        <EmptyState
          title="A course needs at least one section"
          description="Sections group your lessons. Add the first one to get going."
        />
      ) : (
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          onDragStart={handleDragStart}
          onDragEnd={handleDragEnd}
        >
          <SortableContext
            items={tree.map((section) => sectionDomId(section.id))}
            strategy={verticalListSortingStrategy}
          >
            <Stack gap="sm">
              {tree.map((section) => (
                <SortableSection
                  key={section.id}
                  section={section}
                  collapsed={collapsed.has(section.id)}
                  onToggleCollapse={(id) =>
                    setCollapsed((current) => {
                      const next = new Set(current);

                      if (next.has(id)) {
                        next.delete(id);
                      } else {
                        next.add(id);
                      }

                      return next;
                    })
                  }
                  onRenameSection={(s, title) => updateSection.mutate({ id: s.id, title })}
                  onDuplicateSection={(s) => duplicateSection.mutate(s.id)}
                  onDeleteSection={(s) =>
                    s.items.length === 0
                      ? deleteSection.mutate(s.id)
                      : confirmDestructive(
                          `Delete "${s.title}"?`,
                          `This also deletes its ${s.items.length} item${s.items.length === 1 ? '' : 's'}.`,
                          () => deleteSection.mutate(s.id),
                        )
                  }
                  onAddItem={(s, type: ItemType) =>
                    createItem.mutate({
                      sectionId: s.id,
                      type,
                      title: type === 'lesson' ? 'New lesson' : 'New resource',
                    })
                  }
                  onOpen={onEditItem}
                  onRename={(item, title) => updateItem.mutate({ id: item.id, title })}
                  onTogglePreview={(item) =>
                    updateItem.mutate({ id: item.id, is_preview: !item.is_preview })
                  }
                  onTogglePublished={(item) =>
                    updateItem.mutate({ id: item.id, is_published: !item.is_published })
                  }
                  onDuplicate={(item) => duplicateItem.mutate(item.id)}
                  onDelete={(item) =>
                    confirmDestructive(`Delete "${item.title}"?`, 'This cannot be undone.', () =>
                      deleteItem.mutate(item.id),
                    )
                  }
                />
              ))}
            </Stack>
          </SortableContext>

          <DragOverlay>{activeId ? <Text size="sm">Moving…</Text> : null}</DragOverlay>
        </DndContext>
      )}

      <Group gap="xs" align="flex-end">
        <TextInput
          value={newSectionTitle}
          onChange={(event) => setNewSectionTitle(event.currentTarget.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter' && newSectionTitle.trim()) {
              createSection.mutate(newSectionTitle.trim());
              setNewSectionTitle('');
            }
          }}
          placeholder="New section title"
          aria-label="New section title"
          style={{ flex: 1 }}
        />
        <Button
          leftSection={<IconPlus size={16} />}
          loading={createSection.isPending}
          disabled={!newSectionTitle.trim()}
          onClick={() => {
            createSection.mutate(newSectionTitle.trim());
            setNewSectionTitle('');
          }}
        >
          Add section
        </Button>
      </Group>
    </Stack>
  );
}
