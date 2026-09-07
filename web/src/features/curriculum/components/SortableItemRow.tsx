import { ActionIcon, Badge, Group, Menu, Text, TextInput, Tooltip } from '@mantine/core';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  IconCopy,
  IconDotsVertical,
  IconEye,
  IconEyeOff,
  IconFileText,
  IconGripVertical,
  IconPaperclip,
  IconTrash,
} from '@tabler/icons-react';
import { useState } from 'react';

import type { CourseItem } from '../api/types';
import { formatDuration } from '../hooks/useCurriculumTree';

const TYPE_ICON = {
  lesson: IconFileText,
  resource: IconPaperclip,
  quiz: IconFileText,
  assignment: IconFileText,
  live_session: IconFileText,
} as const;

export interface SortableItemRowProps {
  item: CourseItem;
  onOpen: (item: CourseItem) => void;
  onRename: (item: CourseItem, title: string) => void;
  onTogglePreview: (item: CourseItem) => void;
  onTogglePublished: (item: CourseItem) => void;
  onDuplicate: (item: CourseItem) => void;
  onDelete: (item: CourseItem) => void;
}

export function SortableItemRow({
  item,
  onOpen,
  onRename,
  onTogglePreview,
  onTogglePublished,
  onDuplicate,
  onDelete,
}: SortableItemRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: item.id,
  });
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(item.title);

  const Icon = TYPE_ICON[item.type];

  const commit = () => {
    setEditing(false);
    const next = draft.trim();
    if (next && next !== item.title) onRename(item, next);
    else setDraft(item.title);
  };

  return (
    <Group
      ref={setNodeRef}
      style={{
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.4 : 1,
      }}
      wrap="nowrap"
      gap="xs"
      px="sm"
      py={6}
      bd="1px solid var(--mantine-color-default-border)"
      bdrs="sm"
      bg="var(--mantine-color-body)"
      data-testid={`item-${item.id}`}
    >
      {/* An explicit handle, not long-press on the row: on a phone the whole
          row must stay tappable and scrollable. */}
      <ActionIcon
        variant="subtle"
        color="gray"
        size="sm"
        style={{ cursor: 'grab', touchAction: 'none' }}
        aria-label={`Reorder ${item.title}`}
        {...attributes}
        {...listeners}
      >
        <IconGripVertical size={16} />
      </ActionIcon>

      <Icon size={16} stroke={1.5} style={{ flexShrink: 0 }} />

      {editing ? (
        <TextInput
          value={draft}
          onChange={(event) => setDraft(event.currentTarget.value)}
          onBlur={commit}
          onKeyDown={(event) => {
            if (event.key === 'Enter') commit();
            if (event.key === 'Escape') {
              setDraft(item.title);
              setEditing(false);
            }
          }}
          size="xs"
          autoFocus
          aria-label="Item title"
          style={{ flex: 1 }}
        />
      ) : (
        <Text
          size="sm"
          lineClamp={1}
          style={{ flex: 1, cursor: 'text' }}
          onClick={() => setEditing(true)}
          title="Click to rename"
        >
          {item.title}
        </Text>
      )}

      <Group gap={4} wrap="nowrap">
        {item.is_preview ? (
          <Badge size="xs" variant="light" color="success">
            Preview
          </Badge>
        ) : null}
        {!item.is_published ? (
          <Badge size="xs" variant="light" color="gray">
            Hidden
          </Badge>
        ) : null}
        {item.duration_seconds > 0 ? (
          <Text size="xs" c="dimmed">
            {formatDuration(item.duration_seconds)}
          </Text>
        ) : null}

        <Tooltip label="Edit content">
          <ActionIcon
            variant="subtle"
            size="sm"
            onClick={() => onOpen(item)}
            aria-label={`Edit ${item.title}`}
          >
            <IconFileText size={15} />
          </ActionIcon>
        </Tooltip>

        <Menu position="bottom-end" withinPortal>
          <Menu.Target>
            <ActionIcon
              variant="subtle"
              color="gray"
              size="sm"
              aria-label={`Actions for ${item.title}`}
            >
              <IconDotsVertical size={15} />
            </ActionIcon>
          </Menu.Target>
          <Menu.Dropdown>
            <Menu.Item
              leftSection={item.is_preview ? <IconEyeOff size={14} /> : <IconEye size={14} />}
              onClick={() => onTogglePreview(item)}
            >
              {item.is_preview ? 'Remove free preview' : 'Make a free preview'}
            </Menu.Item>
            <Menu.Item
              leftSection={item.is_published ? <IconEyeOff size={14} /> : <IconEye size={14} />}
              onClick={() => onTogglePublished(item)}
            >
              {item.is_published ? 'Hide from learners' : 'Show to learners'}
            </Menu.Item>
            <Menu.Item leftSection={<IconCopy size={14} />} onClick={() => onDuplicate(item)}>
              Duplicate
            </Menu.Item>
            <Menu.Divider />
            <Menu.Item
              color="danger"
              leftSection={<IconTrash size={14} />}
              onClick={() => onDelete(item)}
            >
              Delete
            </Menu.Item>
          </Menu.Dropdown>
        </Menu>
      </Group>
    </Group>
  );
}
