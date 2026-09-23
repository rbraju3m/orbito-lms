import { Badge, Button, Card, Container, Group, Pagination, Stack, Text } from '@mantine/core';
import { IconLayoutGrid, IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';
import { formatDateTime } from '@/shared/lib/datetime';

import { adminPagesQuery, useCreatePage } from '../api/pages';

/** Every page built for the academy's public site, and the way to start one. */
export function PagesRoute() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(
    adminPagesQuery({ status: 'all', page }),
  );
  const create = useCreatePage();

  const startOne = () => {
    create.mutate(
      { title: 'Untitled page' },
      { onSuccess: (created) => void navigate(`/admin/pages/${created.id}`) },
    );
  };

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Pages"
        description="Pages built from blocks on your academy's public site — including, if you choose, its front page."
        actions={
          <Button
            leftSection={<IconPlus size={16} />}
            loading={create.isPending}
            onClick={startOne}
          >
            New page
          </Button>
        }
      />

      {isPending ? <LoadingState rows={3} height={72} label="Loading pages" /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          icon={IconLayoutGrid}
          title="No pages yet"
          description="Build an About page, a landing page for a campaign, or a new front page for your site."
          action={{ label: 'Build the first one', onClick: startOne }}
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <Stack gap="xs">
          {data.data.map((item) => (
            <Card
              key={item.id}
              withBorder
              padding="sm"
              component={Link}
              to={`/admin/pages/${item.id}`}
            >
              <Group justify="space-between" gap="sm" wrap="nowrap">
                <Stack gap={2} style={{ minWidth: 0 }}>
                  <Text fw={600} truncate>
                    {item.title}
                  </Text>
                  <Text size="sm" c="dimmed">
                    {item.block_count === 1 ? '1 block' : `${item.block_count} blocks`} · edited{' '}
                    {formatDateTime(item.updated_at)}
                  </Text>
                </Stack>
                <Group gap={6} wrap="nowrap">
                  {item.is_home ? (
                    <Badge color="blue" variant="light">
                      Front page
                    </Badge>
                  ) : null}
                  {item.show_in_nav ? (
                    <Badge color="gray" variant="outline">
                      In header
                    </Badge>
                  ) : null}
                  <Badge color={item.status === 'published' ? 'green' : 'gray'} variant="light">
                    {item.status_label}
                  </Badge>
                </Group>
              </Group>
            </Card>
          ))}

          {data.meta.last_page > 1 ? (
            <Group justify="center">
              <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
            </Group>
          ) : null}
        </Stack>
      ) : null}
    </Container>
  );
}
