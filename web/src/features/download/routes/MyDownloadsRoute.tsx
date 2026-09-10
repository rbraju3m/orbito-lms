import { Button, Card, Group, Pagination, Stack, Text } from '@mantine/core';
import { IconDownload, IconFile } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';

import { formatBytes } from '@/shared/lib/bytes';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { myDownloadsQuery, useFetchDownload } from '../api/queries';
import type { DownloadListItem } from '../api/types';

/**
 * Everything the reader owns — including downloads the academy has since
 * archived. Archiving takes a file off sale; it never takes it back.
 */
export function MyDownloadsRoute() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(myDownloadsQuery(page));

  if (isPending) return <LoadingState rows={3} label="Loading your downloads" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <>
        <PageHeader title="My downloads" />
        <EmptyState
          icon={IconFile}
          title="No downloads yet"
          description="Files you buy or claim appear here, ready to download again whenever you need them."
          action={{ label: 'Browse downloads', onClick: () => void navigate('/downloads') }}
        />
      </>
    );
  }

  return (
    <>
      <PageHeader title="My downloads" description="Download any of these again, as often as you like." />

      <Stack gap="sm">
        {data.data.map((download) => (
          <OwnedRow key={download.id} download={download} />
        ))}

        {data.meta.last_page > 1 ? (
          <Group justify="center" mt="md">
            <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
          </Group>
        ) : null}
      </Stack>
    </>
  );
}

function OwnedRow({ download }: { download: DownloadListItem }) {
  const fetchFile = useFetchDownload();

  return (
    <Card withBorder>
      <Group justify="space-between" gap="md">
        <Stack gap={2} style={{ minWidth: 0 }}>
          <Text fw={600} component={Link} to={`/downloads/${download.slug}`} truncate>
            {download.title}
          </Text>
          {download.file ? (
            <Text size="sm" c="dimmed">
              {download.file.extension.toUpperCase()} · {formatBytes(download.file.size_bytes)}
            </Text>
          ) : null}
        </Stack>

        <Button
          variant="light"
          leftSection={<IconDownload size={16} />}
          loading={fetchFile.isPending}
          onClick={() => fetchFile.mutate(download.slug)}
        >
          Download
        </Button>
      </Group>
    </Card>
  );
}
