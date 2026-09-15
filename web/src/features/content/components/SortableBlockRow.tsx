import { ActionIcon, Badge, Group, Paper, Text } from '@mantine/core';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  IconArrowDown,
  IconArrowUp,
  IconGripVertical,
  IconPencil,
  IconTrash,
} from '@tabler/icons-react';
import type { ReactNode } from 'react';

import type { EditableBlock } from '../api/pages';
import { blockLabel, blockSummary } from '../lib/blocks';

export interface SortableBlockRowProps {
  block: EditableBlock;
  index: number;
  count: number;
  editing: boolean;
  onEdit: () => void;
  onMove: (to: number) => void;
  onRemove: () => void;
  /** The block's form, shown under the row while it is being edited. */
  children?: ReactNode;
}

/**
 * One block in the builder's list. Dragged by its handle — and moved by the
 * up and down buttons, which is how a keyboard user reorders a page without a
 * drag gesture (CLAUDE.md §3: keyboard reachable).
 */
export function SortableBlockRow({
  block,
  index,
  count,
  editing,
  onEdit,
  onMove,
  onRemove,
  children,
}: SortableBlockRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: block.id,
  });

  const label = blockLabel(block.type);
  const summary = blockSummary(block);

  return (
    <Paper
      ref={setNodeRef}
      withBorder
      p="sm"
      style={{
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
      }}
    >
      <Group gap="xs" wrap="nowrap">
        <ActionIcon
          variant="subtle"
          color="gray"
          aria-label={`Drag ${summary}`}
          {...attributes}
          {...listeners}
        >
          <IconGripVertical size={16} />
        </ActionIcon>

        <Badge size="sm" variant="light">
          {label}
        </Badge>

        <Text size="sm" flex={1} lineClamp={1}>
          {summary}
        </Text>

        <ActionIcon
          variant="subtle"
          color="gray"
          aria-label={`Move ${summary} up`}
          disabled={index === 0}
          onClick={() => onMove(index - 1)}
        >
          <IconArrowUp size={16} />
        </ActionIcon>
        <ActionIcon
          variant="subtle"
          color="gray"
          aria-label={`Move ${summary} down`}
          disabled={index === count - 1}
          onClick={() => onMove(index + 1)}
        >
          <IconArrowDown size={16} />
        </ActionIcon>
        <ActionIcon
          variant={editing ? 'light' : 'subtle'}
          aria-label={`Edit ${summary}`}
          aria-expanded={editing}
          onClick={onEdit}
        >
          <IconPencil size={16} />
        </ActionIcon>
        <ActionIcon
          variant="subtle"
          color="red"
          aria-label={`Remove ${summary}`}
          onClick={onRemove}
        >
          <IconTrash size={16} />
        </ActionIcon>
      </Group>

      {editing ? children : null}
    </Paper>
  );
}
