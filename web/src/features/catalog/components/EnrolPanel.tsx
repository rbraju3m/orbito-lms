import { Alert, Button, List, Stack, Text, ThemeIcon } from '@mantine/core';
import {
  IconAlertTriangle,
  IconCircleCheckFilled,
  IconCircleDashed,
  IconLock,
} from '@tabler/icons-react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router';

import { BuyPanel } from '@/features/commerce/components/BuyPanel';
import { apiPost } from '@/shared/api/client';
import { ApiError } from '@/shared/api/errors';

import { catalogKeys } from '../api/keys';
import type { Course } from '../api/types';

/**
 * Everything standing between a visitor and the course, said before they
 * click rather than after.
 *
 * The button is disabled with a REASON, never silently. A disabled control
 * with no explanation is the same dead end as a 403.
 */
export function EnrolPanel({ course }: { course: Course }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const unmet = course.prerequisites.filter((prerequisite) => !prerequisite.is_met);
  const full = course.seats_remaining === 0;
  const paid = course.pricing_model !== 'free';

  const enrol = useMutation({
    mutationFn: () => apiPost<{ id: string }>(`/courses/${course.id}/enroll`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: catalogKeys.all });
      void navigate(`/learn/${course.id}`);
    },
  });

  const error = enrol.error instanceof ApiError ? enrol.error : null;

  /*
   * Being PAID is no longer a reason to block. It used to be, because there
   * was no checkout to send anyone to; now paying is the way past a price, and
   * only the genuine gates — an unmet prerequisite, a full course — may
   * disable the button. Leaving `paid` in this list would have shipped a
   * priced course with a dead buy button.
   */
  const gateBlocked = unmet.length > 0 || full;
  const gateReason = full
    ? 'Ask the course team whether more places will open.'
    : unmet.length > 0
      ? `Finish ${unmet.length === 1 ? `“${unmet[0]?.title}”` : `${unmet.length} courses`} first.`
      : undefined;

  return (
    <Stack gap="sm">
      {!paid && (
        <Text fw={700} size="xl">
          Free
        </Text>
      )}

      {course.prerequisites.length > 0 && (
        <Stack gap={4}>
          <Text size="sm" fw={600}>
            Complete first
          </Text>
          <List spacing={4} size="sm" center>
            {course.prerequisites.map((prerequisite) => (
              <List.Item
                key={prerequisite.id}
                icon={
                  <ThemeIcon
                    size={18}
                    radius="xl"
                    variant={prerequisite.is_met ? 'filled' : 'light'}
                    color={prerequisite.is_met ? 'success' : 'gray'}
                  >
                    {prerequisite.is_met ? (
                      <IconCircleCheckFilled size={12} />
                    ) : (
                      <IconCircleDashed size={12} />
                    )}
                  </ThemeIcon>
                }
              >
                <Text component={Link} to={`/courses/${prerequisite.slug}`} size="sm" td="none">
                  {prerequisite.title}
                </Text>
              </List.Item>
            ))}
          </List>
        </Stack>
      )}

      {/* null means uncapped, which is not the same as none left. */}
      {course.seats_remaining !== null && course.seats_remaining <= 10 && (
        <Text size="sm" c={full ? 'red' : 'dimmed'}>
          {full
            ? 'This course is full.'
            : `${course.seats_remaining} place${course.seats_remaining === 1 ? '' : 's'} left`}
        </Text>
      )}

      {error && (
        <Alert
          color={error.isBillingBlocked ? 'yellow' : 'red'}
          icon={<IconAlertTriangle size={16} />}
        >
          {error.message}
        </Alert>
      )}

      {/*
        * A paid course goes through the basket, never through this button:
        * `POST /courses/{id}/enroll` refuses anything but a free course, and
        * access is granted only by a verified webhook (ADR-05).
        */}
      {paid ? (
        <BuyPanel
          price={course.price}
          courseTitle={course.title}
          disabled={gateBlocked}
          disabledReason={gateReason}
        />
      ) : (
        <>
          <Button
            fullWidth
            disabled={gateBlocked}
            loading={enrol.isPending}
            leftSection={gateBlocked ? <IconLock size={16} /> : undefined}
            onClick={() => enrol.mutate()}
          >
            Enrol for free
          </Button>

          {/* The reason lives under the button, not inside a tooltip nobody opens. */}
          {gateBlocked && gateReason && (
            <Text size="xs" c="dimmed" ta="center">
              {gateReason}
            </Text>
          )}
        </>
      )}
    </Stack>
  );
}
