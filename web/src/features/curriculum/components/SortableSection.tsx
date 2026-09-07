import {
  ActionIcon,
  Badge,
  Box,
  Button,
  Collapse,
  Group,
  Menu,
  Stack,
  Text,
  TextInput,
} from '@mantine/core';
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { useDroppable } from '@dnd-kit/core';
import {
  IconChevronDown,
  IconChevronRight,
  IconCopy,
  IconDotsVertical,
  IconGripVertical,
  IconPlus,
  IconTrash,
} from '@tabler/icons-react';
import { useState } from 'react';

import type { CourseItem, CourseSection, ItemType } from '../api/types';
import { formatDuration, sectionDomId } from '../hooks/useCurriculumTree';

import { SortableItemRow, type SortableItemRowProps } from './SortableItemRow';

export interface SortableSectionProps extends Omit<SortableItemRowProps, 'item'> {
  section: CourseSection;
  collapsed: boolean;
  onToggleCollapse: (id: number) => void;
  onRenameSection: (section: CourseSection, title: string) => void;
  onDeleteSection: (section: CourseSection) => void;
  onDuplicateSection: (section: CourseSection) => void;
  onAddItem: (section: CourseSection, type: ItemType) => void;
}

export function SortableSection({
  section,
  collapsed,
  onToggleCollapse,
  onRenameSection,
  onDeleteSection,
  onDuplicateSection,
  onAddItem,
  ...itemHandlers
}: SortableSectionProps) {
  const domId = sectionDomId(section.id);
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: domId,
  });
  // A separate droppable so an item can be dropped into an EMPTY section,
  // which has no item to hover over.
  const { setNodeRef: setDropRef, isOver } = useDroppable({ id: domId });

  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(section.title);

  const commit = () => {
    setEditing(false);
    const next = draft.trim();
    if (next && next !== section.title) onRenameSection(section, next);
    else setDraft(section.title);
  };

  const duration = section.items.reduce((total, item) => total + item.duration_seconds, 0);

  return (
    <Box
      ref={setNodeRef}
      style={{
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
      }}
      bd="1px solid var(--mantine-color-default-border)"
      bdrs="md"
      data-testid={`section-${section.id}`}
    >
      <Group justify="space-between" wrap="nowrap" p="sm" bg="var(--mantine-color-default-hover)">
        <Group gap="xs" wrap="nowrap" style={{ flex: 1, minWidth: 0 }}>
          <ActionIcon
            variant="subtle"
            color="gray"
            size="sm"
            style={{ cursor: 'grab', touchAction: 'none' }}
            aria-label={`Reorder ${section.title}`}
            {...attributes}
            {...listeners}
          >
            <IconGripVertical size={16} />
          </ActionIcon>

          <ActionIcon
            variant="subtle"
            color="gray"
            size="sm"
            onClick={() => onToggleCollapse(section.id)}
            aria-label={collapsed ? `Expand ${section.title}` : `Collapse ${section.title}`}
            aria-expanded={!collapsed}
          >
            {collapsed ? <IconChevronRight size={16} /> : <IconChevronDown size={16} />}
          </ActionIcon>

          {editing ? (
            <TextInput
              value={draft}
              onChange={(event) => setDraft(event.currentTarget.value)}
              onBlur={commit}
              onKeyDown={(event) => {
                if (event.key === 'Enter') commit();
                if (event.key === 'Escape') {
                  setDraft(section.title);
                  setEditing(false);
                }
              }}
              size="xs"
              autoFocus
              aria-label="Section title"
              style={{ flex: 1 }}
            />
          ) : (
            <Text
              fw={600}
              size="sm"
              lineClamp={1}
              style={{ cursor: 'text' }}
              onClick={() => setEditing(true)}
            >
              {section.title}
            </Text>
          )}
        </Group>

        <Group gap="xs" wrap="nowrap">
          <Badge size="xs" variant="light" color="gray">
            {section.items.length} {section.items.length === 1 ? 'item' : 'items'}
          </Badge>
          {duration > 0 ? (
            <Text size="xs" c="dimmed">
              {formatDuration(duration)}
            </Text>
          ) : null}

          <Menu position="bottom-end" withinPortal>
            <Menu.Target>
              <ActionIcon
                variant="subtle"
                color="gray"
                size="sm"
                aria-label={`Actions for ${section.title}`}
              >
                <IconDotsVertical size={15} />
              </ActionIcon>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Item
                leftSection={<IconCopy size={14} />}
                onClick={() => onDuplicateSection(section)}
              >
                Duplicate section
              </Menu.Item>
              <Menu.Divider />
              <Menu.Item
                color="danger"
                leftSection={<IconTrash size={14} />}
                onClick={() => onDeleteSection(section)}
              >
                Delete section
              </Menu.Item>
            </Menu.Dropdown>
          </Menu>
        </Group>
      </Group>

      <Collapse expanded={!collapsed}>
        <Stack
          ref={setDropRef}
          gap={6}
          p="sm"
          mih={56}
          bg={isOver ? 'var(--mantine-color-default-hover)' : undefined}
        >
          <SortableContext
            items={section.items.map((i) => i.id)}
            strategy={verticalListSortingStrategy}
          >
            {section.items.map((item: CourseItem) => (
              <SortableItemRow key={item.id} item={item} {...itemHandlers} />
            ))}
          </SortableContext>

          {section.items.length === 0 ? (
            <Text size="sm" c="dimmed" ta="center" py="xs">
              Nothing here yet — add a lesson to get started.
            </Text>
          ) : null}

          <Group gap="xs">
            <Button
              size="compact-xs"
              variant="light"
              leftSection={<IconPlus size={13} />}
              onClick={() => onAddItem(section, 'lesson')}
            >
              Lesson
            </Button>
            <Button
              size="compact-xs"
              variant="subtle"
              leftSection={<IconPlus size={13} />}
              onClick={() => onAddItem(section, 'resource')}
            >
              Resource
            </Button>
          </Group>
        </Stack>
      </Collapse>
    </Box>
  );
}
