import { Alert, Card, Group, Stack, Text } from '@mantine/core';
import { IconAlertTriangle, IconFile } from '@tabler/icons-react';
import { useIsMutating, useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';

import { formatBytes } from '@/shared/lib/bytes';
import { formatMinor } from '@/shared/lib/money';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { DownloadAction } from '../components/DownloadAction';
import { downloadQuery } from '../api/queries';
import type { Download } from '../api/types';

/** A download as a member sees it: what it is, what it costs, and the one button. */
export function DownloadDetailRoute() {
  const { slug = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(downloadQuery(slug));

  if (isPending) return <LoadingState rows={3} height={96} label="Loading download" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <DownloadDetail download={data} />;
}

function DownloadDetail({ download }: { download: Download }) {
  // A mutation failing here (a 402 in a lapsed academy, say) is shown by the
  // global handler's banner; this only says a request is in flight.
  const busy = useIsMutating() > 0;

  return (
    <>
      <PageHeader title={download.title} description={download.subtitle ?? undefined} />

      <Stack gap="md" maw={760}>
        {download.description ? <Text>{download.description}</Text> : null}

        <Card withBorder>
          <Group justify="space-between" gap="sm">
            <Stack gap={4}>
              {download.file ? (
                <Group gap={6}>
                  <IconFile size={16} aria-hidden />
                  <Text size="sm">
                    {download.file.extension.toUpperCase()} · {formatBytes(download.file.size_bytes)}
                  </Text>
                </Group>
              ) : null}

              <Text fw={700} fz="lg">
                {download.is_free
                  ? 'Free'
                  : download.price
                    ? formatMinor(download.price.amount_minor, download.price.currency)
                    : 'Not available right now'}
              </Text>
            </Stack>

            <DownloadAction download={download} />
          </Group>
        </Card>

        {download.status === 'archived' && download.can_fetch ? (
          <Alert color="gray" variant="light" icon={<IconAlertTriangle size={16} />} role="note">
            This is no longer on sale. You still own it.
          </Alert>
        ) : null}

        {busy ? (
          <Text size="sm" c="dimmed" role="status">
            Working…
          </Text>
        ) : null}
      </Stack>
    </>
  );
}
