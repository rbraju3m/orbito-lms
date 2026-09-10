import {
  Alert,
  Button,
  Card,
  FileInput,
  Group,
  NumberInput,
  SegmentedControl,
  Stack,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { IconAlertTriangle, IconUpload } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useParams } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { BundleChecklist } from '@/features/bundle/components/BundleChecklist';
import { useUploadMedia } from '@/features/media/api/queries';
import { ApiError } from '@/shared/api/errors';
import { formatBytes } from '@/shared/lib/bytes';
import { formatMinor } from '@/shared/lib/money';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  studioDownloadQuery,
  useChangeDownloadStatus,
  useSetDownloadPrice,
  useUpdateDownload,
} from '../api/queries';
import type { Download, DownloadPricing } from '../api/types';

/** Authoring one download: the file, the words, the price, and whether it may ship. */
export function DownloadEditorRoute() {
  const { id = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(studioDownloadQuery(id));

  if (isPending) return <LoadingState rows={4} height={96} label="Loading download" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <DownloadEditor download={data} />;
}

function DownloadEditor({ download }: { download: Download }) {
  const { session } = useSession();
  const currency = session?.academy?.currency ?? 'USD';

  const update = useUpdateDownload(download.id);
  const setPrice = useSetDownloadPrice(download.id);
  const changeStatus = useChangeDownloadStatus(download.id);
  const upload = useUploadMedia();

  const [title, setTitle] = useState(download.title);
  const [subtitle, setSubtitle] = useState(download.subtitle ?? '');
  const [description, setDescription] = useState(download.description ?? '');
  // Mantine's NumberInput reports a string for anything not yet canonical, so
  // this is the union, never assumed to be a number (§ Phase 7).
  const [amount, setAmount] = useState<string | number>(download.price?.amount_minor ?? '');

  const failed = [update.error, setPrice.error, changeStatus.error, upload.error].find(
    (candidate): candidate is ApiError => candidate instanceof ApiError,
  );

  const replaceFile = (file: File | null) => {
    if (!file) return;
    // Upload into the `download` collection, then point the download at it.
    // A LIVE file: buyers get the new one on their next fetch.
    upload.mutate(
      { file, collection: 'download' },
      { onSuccess: (media) => update.mutate({ media_id: media.ref }) },
    );
  };

  return (
    <>
      <PageHeader
        title={download.title}
        description={`${download.status_label} · ${download.is_free ? 'Free' : 'Paid'}`}
        actions={
          download.status === 'published' ? (
            <Button variant="light" loading={changeStatus.isPending} onClick={() => changeStatus.mutate('unpublish')}>
              Unpublish
            </Button>
          ) : (
            <Button loading={changeStatus.isPending} onClick={() => changeStatus.mutate('publish')}>
              Publish
            </Button>
          )
        }
      />

      <Stack gap="md" maw={760}>
        {failed ? (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {failed.message}
          </Alert>
        ) : null}

        {download.checklist ? <BundleChecklist checks={download.checklist} /> : null}

        <Card withBorder>
          <Stack gap="sm">
            <Text fw={600}>File</Text>
            {download.file ? (
              <Text size="sm">
                {download.file.name} · {formatBytes(download.file.size_bytes)}
              </Text>
            ) : (
              <Text size="sm" c="dimmed">
                No file yet.
              </Text>
            )}
            <FileInput
              label={download.file ? 'Replace the file' : 'Upload the file'}
              description="PDF, EPUB, ZIP, audio, images or Office files, up to 500 MB. Buyers always get the current file."
              placeholder="Choose a file"
              leftSection={<IconUpload size={16} />}
              onChange={replaceFile}
              disabled={upload.isPending}
            />
          </Stack>
        </Card>

        <Card withBorder>
          <Stack gap="sm">
            <Text fw={600}>Details</Text>
            <TextInput label="Title" value={title} onChange={(event) => setTitle(event.currentTarget.value)} />
            <TextInput
              label="Subtitle"
              value={subtitle}
              onChange={(event) => setSubtitle(event.currentTarget.value)}
            />
            <Textarea
              label="Description"
              autosize
              minRows={3}
              value={description}
              onChange={(event) => setDescription(event.currentTarget.value)}
            />
            <Group justify="flex-end">
              <Button
                loading={update.isPending}
                onClick={() =>
                  update.mutate({ title, subtitle: subtitle || null, description: description || null })
                }
              >
                Save
              </Button>
            </Group>
          </Stack>
        </Card>

        <Card withBorder>
          <Stack gap="sm">
            <Text fw={600}>Price</Text>
            <SegmentedControl
              aria-label="Pricing"
              value={download.pricing_model}
              onChange={(value) => update.mutate({ pricing_model: value as DownloadPricing })}
              data={[
                { value: 'free', label: 'Free to members' },
                { value: 'one_time', label: 'Paid' },
              ]}
            />

            {download.pricing_model === 'one_time' ? (
              <>
                <Text size="sm" c="dimmed">
                  In {currency}, in minor units — {formatMinor(100, currency)} is entered as 100.
                </Text>
                <Group align="flex-end" gap="sm">
                  <NumberInput
                    label={`Amount (${currency})`}
                    min={1}
                    value={amount}
                    onChange={setAmount}
                    flex={1}
                  />
                  <Button
                    variant="light"
                    loading={setPrice.isPending}
                    disabled={typeof amount !== 'number'}
                    onClick={() => typeof amount === 'number' && setPrice.mutate({ currency, amount_minor: amount })}
                  >
                    Set price
                  </Button>
                </Group>
              </>
            ) : null}
          </Stack>
        </Card>
      </Stack>
    </>
  );
}
