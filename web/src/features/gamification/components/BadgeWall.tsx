import { Badge, Card, SimpleGrid, Stack, Text, ThemeIcon, Tooltip } from '@mantine/core';
import { IconAward, IconLock } from '@tabler/icons-react';

import { formatDate } from '@/shared/lib/datetime';

import type { BadgeSummary, BadgeTier } from '../api/types';

const TIER_COLOUR: Record<BadgeTier, string> = {
  bronze: 'orange',
  silver: 'gray',
  gold: 'yellow',
};

/**
 * The shelf, held and unheld together.
 *
 * AN UNEARNED BADGE SHOWS ITS REQUIREMENT. A wall of only what you already
 * hold is a trophy cabinet; the next one visible, with the number on it, is a
 * reason to come back — and hiding the requirement turns the whole thing into
 * a lottery.
 *
 * Unheld badges are dimmed AND carry a lock icon, because dimming alone is a
 * colour difference and colour alone is not information.
 */
export function BadgeWall({ badges }: { badges: BadgeSummary[] }) {
  return (
    <SimpleGrid cols={{ base: 2, sm: 3, md: 4 }} spacing="md">
      {badges.map((badge) => (
        <BadgeTile key={badge.id} badge={badge} />
      ))}
    </SimpleGrid>
  );
}

function BadgeTile({ badge }: { badge: BadgeSummary }) {
  const colour = TIER_COLOUR[badge.tier];

  return (
    <Tooltip label={requirement(badge)} withArrow multiline w={220}>
      <Card withBorder padding="md" style={{ opacity: badge.is_held ? 1 : 0.55 }}>
        <Stack gap={6} align="center" ta="center">
          <ThemeIcon
            size={48}
            radius="xl"
            variant={badge.is_held ? 'filled' : 'light'}
            color={badge.is_held ? colour : 'gray'}
          >
            {badge.is_held ? <IconAward size={26} /> : <IconLock size={22} />}
          </ThemeIcon>

          <Text size="sm" fw={600} lineClamp={2}>
            {badge.name}
          </Text>

          <Badge size="xs" variant="light" color={badge.is_held ? colour : 'gray'}>
            {badge.tier_label}
          </Badge>

          {badge.is_held && badge.awarded_at ? (
            <Text size="xs" c="dimmed">
              {formatDate(badge.awarded_at)}
            </Text>
          ) : (
            <Text size="xs" c="dimmed" lineClamp={2}>
              {requirement(badge)}
            </Text>
          )}
        </Stack>
      </Card>
    </Tooltip>
  );
}

/** Plain language, from the closed set of criteria the server sends. */
function requirement(badge: BadgeSummary): string {
  const n = badge.criteria.threshold;

  if (n === null) return badge.description ?? badge.name;

  switch (badge.criteria.type) {
    case 'points_total':
      return `Earn ${n.toLocaleString()} points.`;
    case 'streak_days':
      return `Learn on ${n} consecutive days.`;
    case 'lessons_completed':
      return `Complete ${n} ${n === 1 ? 'lesson' : 'lessons'}.`;
    case 'courses_completed':
      return `Finish ${n} ${n === 1 ? 'course' : 'courses'}.`;
    case 'answers_accepted':
      return `Have ${n} of your answers accepted.`;
    default:
      return badge.description ?? badge.name;
  }
}
