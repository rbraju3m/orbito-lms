import { Badge, Button, Card, Group, Stack, Text } from '@mantine/core';
import { IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { studioBundlesQuery, useCreateBundle } from '../api/queries';
import type { BundleListItem, BundleStatus } from '../api/types';

const TONE: Record<BundleStatus, string> = {
  draft: 'gray',
  published: 'success',
  archived: 'warning',
};

/** Every bundle in the academy, and the way to start another. */
export function StudioBundlesRoute() {
  const navigate = useNavigate();
  const [page] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(studioBundlesQuery({ page }));
  const create = useCreateBundle();

  const startOne = () => {
    create.mutate(
      { title: 'Untitled bundle' },
      { onSuccess: (bundle) => void navigate(`/studio/bundles/${bundle.id}`) },
    );
  };

  return (
    <>
      <PageHeader
        title="Bundles"
        description="Several courses, sold as one thing."
        actions={
          <Button leftSection={<IconPlus size={16} />} loading={create.isPending} onClick={startOne}>
            New bundle
          </Button>
        }
      />

      {isPending ? <LoadingState rows={3} height={72} label="Loading bundles" /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title="No bundles yet"
          description="A bundle groups two or more published courses and sells them for one price."
          action={{ label: 'Create the first one', onClick: startOne }}
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <Stack gap="xs">
          {data.data.map((bundle) => (
            <BundleRow key={bundle.id} bundle={bundle} />
          ))}
        </Stack>
      ) : null}
    </>
  );
}

function BundleRow({ bundle }: { bundle: BundleListItem }) {
  return (
    <Card withBorder padding="sm" component={Link} to={`/studio/bundles/${bundle.id}`}>
      <Group justify="space-between" gap="sm">
        <Stack gap={2}>
          <Text fw={600}>{bundle.title}</Text>
          <Text size="sm" c="dimmed">
            {bundle.course_count ?? 0} {bundle.course_count === 1 ? 'course' : 'courses'}
          </Text>
        </Stack>

        <Badge color={TONE[bundle.status]} variant="light">
          {bundle.status_label}
        </Badge>
      </Group>
    </Card>
  );
}
