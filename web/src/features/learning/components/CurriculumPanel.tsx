import {
  Accordion,
  Badge,
  Group,
  Progress,
  ScrollArea,
  Stack,
  Text,
  ThemeIcon,
  UnstyledButton,
} from '@mantine/core';
import { IconCircle, IconCircleCheckFilled, IconLock, IconPlayerPlay } from '@tabler/icons-react';

import { formatDuration } from '@/features/curriculum/hooks/useCurriculumTree';

import type { CourseProgress, LearnerItem, LearnerSection } from '../api/types';

export interface CurriculumPanelProps {
  sections: LearnerSection[];
  activeItemId: string | null;
  progress: CourseProgress | null;
  hasAccess: boolean;
  onSelect: (item: LearnerItem) => void;
}

export function CurriculumPanel({
  sections,
  activeItemId,
  progress,
  hasAccess,
  onSelect,
}: CurriculumPanelProps) {
  const activeSection = sections.find((section) =>
    section.items.some((item) => item.id === activeItemId),
  );

  return (
    <Stack gap="sm" h="100%">
      {progress ? (
        <Stack gap={4} px="sm" pt="sm">
          <Group justify="space-between">
            <Text size="xs" fw={600}>
              {progress.completed_items} of {progress.total_items} complete
            </Text>
            <Text size="xs" c="dimmed">
              {Math.round(progress.percent)}%
            </Text>
          </Group>
          <Progress
            value={progress.percent}
            size="sm"
            aria-label={`Course progress: ${Math.round(progress.percent)} percent`}
          />
        </Stack>
      ) : null}

      <ScrollArea style={{ flex: 1 }}>
        <Accordion
          multiple
          defaultValue={
            activeSection
              ? [String(activeSection.id)]
              : sections.slice(0, 1).map((s) => String(s.id))
          }
          variant="filled"
        >
          {sections.map((section) => (
            <Accordion.Item key={section.id} value={String(section.id)}>
              <Accordion.Control>
                <Group justify="space-between" pr="sm">
                  <Text size="sm" fw={600} lineClamp={1}>
                    {section.title}
                  </Text>
                  <Text size="xs" c="dimmed">
                    {section.items.length}
                  </Text>
                </Group>
              </Accordion.Control>

              <Accordion.Panel>
                <Stack gap={2}>
                  {section.items.map((item) => (
                    <ItemRow
                      key={item.id}
                      item={item}
                      active={item.id === activeItemId}
                      // A locked item is still listed: seeing what you would
                      // get is the point of the outline.
                      locked={!hasAccess && !item.is_preview}
                      onSelect={onSelect}
                    />
                  ))}
                </Stack>
              </Accordion.Panel>
            </Accordion.Item>
          ))}
        </Accordion>
      </ScrollArea>
    </Stack>
  );
}

function ItemRow({
  item,
  active,
  locked,
  onSelect,
}: {
  item: LearnerItem;
  active: boolean;
  locked: boolean;
  onSelect: (item: LearnerItem) => void;
}) {
  const done = item.status === 'completed';

  return (
    <UnstyledButton
      onClick={() => onSelect(item)}
      px="xs"
      py={6}
      bdrs="sm"
      bg={active ? 'var(--mantine-color-default-hover)' : undefined}
      aria-current={active ? 'true' : undefined}
      data-testid={`curriculum-item-${item.id}`}
    >
      <Group gap="xs" wrap="nowrap">
        <ThemeIcon
          size={18}
          radius="xl"
          variant={done ? 'filled' : 'light'}
          color={done ? 'success' : 'gray'}
        >
          {locked ? (
            <IconLock size={11} />
          ) : done ? (
            <IconCircleCheckFilled size={12} />
          ) : active ? (
            <IconPlayerPlay size={11} />
          ) : (
            <IconCircle size={11} />
          )}
        </ThemeIcon>

        <Text size="sm" lineClamp={1} style={{ flex: 1 }} c={locked ? 'dimmed' : undefined}>
          {item.title}
        </Text>

        {item.is_preview && locked ? (
          <Badge size="xs" variant="light" color="success">
            Free
          </Badge>
        ) : null}

        {item.duration_seconds > 0 ? (
          <Text size="xs" c="dimmed">
            {formatDuration(item.duration_seconds)}
          </Text>
        ) : null}
      </Group>
    </UnstyledButton>
  );
}
