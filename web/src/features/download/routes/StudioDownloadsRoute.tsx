import { Badge, Button, Card, Group, Stack, Text } from '@mantine/core';
import { IconFile, IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { studioDownloadsQuery, useCreateDownload } from '../api/queries';
import type { DownloadStatus } from '../api/types';

const TONE: Record<DownloadStatus, string> = {
  draft: 'gray',
  published: 'success',
  archived: 'warning',
};

/** Every download in the academy, and the way to add another. */
export function StudioDownloadsRoute() {
  const navigate = useNavigate();
  const { data, isPending, isError, error, refetch } = useQuery(studioDownloadsQuery({ page: 1 }));
  const create = useCreateDownload();

  const startOne = () => {
    create.mutate(
      { title: 'Untitled download' },
      { onSuccess: (download) => void navigate(`/studio/downloads/${download.id}`) },
    );
  };

  return (
    <>
      <PageHeader
        title="Downloads"
        description="Files this academy sells or gives away."
        actions={
          <Button leftSection={<IconPlus size={16} />} loading={create.isPending} onClick={startOne}>
            New download
          </Button>
        }
      />

      {isPending ? <LoadingState rows={3} height={72} label="Loading downloads" /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          icon={IconFile}
          title="No downloads yet"
          description="Sell a workbook, a template pack or an audio series alongside your courses."
          action={{ label: 'Create the first one', onClick: startOne }}
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <Stack gap="xs">
          {data.data.map((download) => (
            <Card key={download.id} withBorder padding="sm" component={Link} to={`/studio/downloads/${download.id}`}>
              <Group justify="space-between" gap="sm">
                <Stack gap={2}>
                  <Text fw={600}>{download.title}</Text>
                  <Text size="sm" c="dimmed">
                    {download.is_free ? 'Free' : 'Paid'}
                    {download.file ? ` · ${download.file.name}` : ' · no file yet'}
                  </Text>
                </Stack>
                <Badge color={TONE[download.status]} variant="light">
                  {download.status_label}
                </Badge>
              </Group>
            </Card>
          ))}
        </Stack>
      ) : null}
    </>
  );
}
