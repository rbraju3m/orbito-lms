import { Badge, Button, Card, Group, Stack, Text } from '@mantine/core';
import { IconBroadcast } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { useWebinarRegistration, webinarsQuery } from '../api/queries';
import type { Webinar } from '../api/types';

/**
 * Standalone live events.
 *
 * Members-only, which follows from the tenancy design rather than a product
 * choice — there is no anonymous surface to register from. It is still the one
 * live format not gated on buying a course.
 */
export function WebinarsRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(webinarsQuery());

  if (isPending) return <LoadingState rows={3} height={90} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <>
        <PageHeader title="Webinars" />
        <EmptyState
          icon={IconBroadcast}
          title="No webinars scheduled"
          description="Open sessions run by the academy appear here, whether or not you are on a course."
        />
      </>
    );
  }

  return (
    <>
      <PageHeader title="Webinars" description="Open to everybody in the academy." />

      <Stack gap="sm">
        {data.data.map((webinar) => (
          <WebinarCard key={webinar.id} webinar={webinar} />
        ))}
      </Stack>
    </>
  );
}

function WebinarCard({ webinar }: { webinar: Webinar }) {
  const registration = useWebinarRegistration();
  const full = webinar.places_remaining === 0 && !webinar.is_registered;

  return (
    <Card withBorder>
      <Group justify="space-between" wrap="nowrap" align="flex-start" gap="md">
        <Stack gap={4} style={{ minWidth: 0 }}>
          <Group gap="xs" wrap="wrap">
            <Text fw={600}>{webinar.title}</Text>
            {webinar.status !== 'published' ? (
              <Badge size="xs" variant="light" color="warning">
                {webinar.status_label}
              </Badge>
            ) : null}
            {webinar.is_registered ? (
              <Badge size="xs" variant="light" color="success">
                Registered
              </Badge>
            ) : null}
          </Group>

          {webinar.session ? (
            <Text size="sm" c="dimmed">
              {formatDateTime(webinar.session.starts_at)} · {webinar.session.timezone}
            </Text>
          ) : (
            <Text size="sm" c="dimmed">
              Date to be announced
            </Text>
          )}

          {webinar.description ? (
            <Text size="sm" lineClamp={2}>
              {webinar.description}
            </Text>
          ) : null}

          {/*
           * Null places means uncapped, which is not the same as zero left —
           * so only a real number is ever shown.
           */}
          {webinar.places_remaining !== null ? (
            <Text size="xs" c={webinar.places_remaining === 0 ? 'danger' : 'dimmed'}>
              {webinar.places_remaining === 0 ? 'Full' : `${webinar.places_remaining} places left`}
            </Text>
          ) : null}
        </Stack>

        <Button
          size="compact-sm"
          variant={webinar.is_registered ? 'default' : 'filled'}
          disabled={full}
          loading={registration.isPending && registration.variables?.id === webinar.id}
          onClick={() => registration.mutate({ id: webinar.id, register: !webinar.is_registered })}
        >
          {webinar.is_registered ? 'Cancel' : full ? 'Full' : 'Register'}
        </Button>
      </Group>
    </Card>
  );
}
