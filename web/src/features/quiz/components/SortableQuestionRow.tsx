import { ActionIcon, Badge, Group, Paper, Text } from '@mantine/core';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { IconGripVertical, IconPencil, IconTrash } from '@tabler/icons-react';

import type { AuthoredQuestion } from '../api/builderTypes';

export interface SortableQuestionRowProps {
  question: AuthoredQuestion;
  index: number;
  onEdit: () => void;
  onDelete: () => void;
}

export function SortableQuestionRow({
  question,
  index,
  onEdit,
  onDelete,
}: SortableQuestionRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: question.id,
  });

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
      <Group gap="sm" wrap="nowrap">
        <ActionIcon
          variant="subtle"
          color="gray"
          aria-label={`Reorder ${question.title}`}
          {...attributes}
          {...listeners}
        >
          <IconGripVertical size={16} />
        </ActionIcon>

        <Text size="sm" c="dimmed" w={24}>
          {index + 1}
        </Text>

        <Text size="sm" flex={1} lineClamp={2}>
          {question.title}
        </Text>

        <Badge size="sm" variant="light">
          {question.type_label}
        </Badge>

        {question.needs_manual_grading ? (
          <Badge size="sm" variant="light" color="warning">
            Manual
          </Badge>
        ) : null}

        <Text size="xs" c="dimmed">
          {question.points} pt
        </Text>

        <ActionIcon variant="subtle" aria-label={`Edit ${question.title}`} onClick={onEdit}>
          <IconPencil size={16} />
        </ActionIcon>
        <ActionIcon
          variant="subtle"
          color="danger"
          aria-label={`Delete ${question.title}`}
          onClick={onDelete}
        >
          <IconTrash size={16} />
        </ActionIcon>
      </Group>
    </Paper>
  );
}
