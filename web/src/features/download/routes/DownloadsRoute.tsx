import { Badge, Button, Card, Group, Pagination, SimpleGrid, Stack, Text } from '@mantine/core';
import { IconFile } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';

import { formatMinor } from '@/shared/lib/money';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { downloadCatalogueQuery } from '../api/queries';
import type { DownloadListItem } from '../api/types';

/** The academy's shelf of downloads. */
export function DownloadsRoute() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(downloadCatalogueQuery(page));

  return (
    <>
      <PageHeader
        title="Downloads"
        description="Files from this academy — workbooks, templates, audio."
        actions={
          <Button variant="light" component={Link} to="/my-downloads">
            My downloads
          </Button>
        }
      />

      {isPending ? <LoadingState rows={3} height={96} label="Loading downloads" /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          icon={IconFile}
          title="Nothing here yet"
          description="When this academy publishes a download, it appears here."
          action={{ label: 'Browse courses', onClick: () => void navigate('/courses') }}
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <Stack gap="md">
          <SimpleGrid cols={{ base: 1, sm: 2, md: 3 }}>
            {data.data.map((download) => (
              <DownloadCard key={download.id} download={download} />
            ))}
          </SimpleGrid>

          {data.meta.last_page > 1 ? (
            <Group justify="center">
              <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
            </Group>
          ) : null}
        </Stack>
      ) : null}
    </>
  );
}

function DownloadCard({ download }: { download: DownloadListItem }) {
  return (
    <Card withBorder component={Link} to={`/downloads/${download.slug}`}>
      <Stack gap={6}>
        <Text fw={600}>{download.title}</Text>
        {download.subtitle ? (
          <Text size="sm" c="dimmed" lineClamp={2}>
            {download.subtitle}
          </Text>
        ) : null}
        <Group justify="space-between" mt="xs">
          {download.file ? (
            <Badge variant="light" color="gray">
              {download.file.extension.toUpperCase()}
            </Badge>
          ) : (
            <span />
          )}
          <Text size="sm" fw={600}>
            {download.is_free
              ? 'Free'
              : download.price
                ? formatMinor(download.price.amount_minor, download.price.currency)
                : '—'}
          </Text>
        </Group>
      </Stack>
    </Card>
  );
}
