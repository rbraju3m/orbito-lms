import {
  Alert,
  Button,
  Card,
  CopyButton,
  FileInput,
  Group,
  Image,
  Radio,
  Stack,
  Text,
  TextInput,
} from '@mantine/core';
import { IconAlertTriangle, IconCheck, IconCopy, IconUpload } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { useUploadMedia } from '@/features/media/api/queries';
import { ApiError } from '@/shared/api/errors';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  academyQuery,
  useUpdateAcademy,
  type Academy,
  type RegistrationMode,
} from '../api/academy';

/**
 * Who may join this academy, and the link that lets them.
 *
 * The academy's own decision, not the platform operator's — which is why this
 * lives behind `settings.update` rather than the operator flag. The operator
 * can see the answer on the registry screen, so support can explain why nobody
 * is getting in, but not change it.
 */
export function AcademySettingsRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(academyQuery());

  if (isPending) return <LoadingState rows={3} height={96} label="Loading academy settings" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <AcademySettings academy={data} />;
}

function AcademySettings({ academy }: { academy: Academy }) {
  const update = useUpdateAcademy();
  const [mode, setMode] = useState<RegistrationMode>(academy.registration_mode);

  const apiError = update.error instanceof ApiError ? update.error : null;
  const dirty = mode !== academy.registration_mode;

  const signupUrl = `${window.location.origin}${academy.signup_path}`;

  return (
    <>
      <PageHeader
        title="Academy"
        description={`How ${academy.name} looks to visitors, who may join it, and how they do it.`}
      />

      <Stack gap="md" maw={720}>
        <LogoCard academy={academy} />

        <Card withBorder>
          <Stack gap="sm">
            <Text fw={600}>Sign-ups</Text>

            <Radio.Group value={mode} onChange={(next) => setMode(next as RegistrationMode)}>
              <Stack gap="xs" mt="xs">
                {academy.registration_modes.map((option) => (
                  <Radio
                    key={option.value}
                    value={option.value}
                    label={option.label}
                    disabled={!option.available}
                    description={
                      option.available
                        ? undefined
                        : 'Not available yet — invitations are not built.'
                    }
                  />
                ))}
              </Stack>
            </Radio.Group>

            {apiError ? (
              <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
                {apiError.message}
              </Alert>
            ) : null}

            <Group justify="flex-end">
              <Button
                loading={update.isPending}
                disabled={!dirty}
                onClick={() => update.mutate({ registration_mode: mode })}
              >
                Save
              </Button>
            </Group>
          </Stack>
        </Card>

        <Card withBorder>
          <Stack gap="sm">
            <Text fw={600}>Signup link</Text>
            <Text size="sm" c="dimmed">
              An account belongs to one academy, so this link is how somebody joins yours. Without
              it a visitor has no academy to sign up to.
            </Text>

            <Group gap="xs" wrap="nowrap">
              <TextInput value={signupUrl} readOnly flex={1} aria-label="Signup link" />
              <CopyButton value={signupUrl}>
                {({ copied, copy }) => (
                  <Button
                    variant="light"
                    color={copied ? 'success' : undefined}
                    leftSection={copied ? <IconCheck size={16} /> : <IconCopy size={16} />}
                    onClick={copy}
                  >
                    {copied ? 'Copied' : 'Copy'}
                  </Button>
                )}
              </CopyButton>
            </Group>

            {academy.registration_mode !== 'open' ? (
              <Text size="sm" c="danger">
                Sign-ups are {academy.registration_mode_label.toLowerCase()}, so this link will
                currently refuse anyone who follows it.
              </Text>
            ) : null}
          </Stack>
        </Card>
      </Stack>
    </>
  );
}

/**
 * The mark on the public site's header, beside the academy's name.
 *
 * Saved the moment it is uploaded, like a post's cover: a picture uploaded and
 * never saved would be a file nobody could see again. Replacing or removing
 * the logo deletes the old file server-side (`UpdateAcademySettings`).
 */
function LogoCard({ academy }: { academy: Academy }) {
  const upload = useUploadMedia();
  const update = useUpdateAcademy();

  const onFile = (file: File | null) => {
    if (file === null) return;
    upload.mutate(
      { file, collection: 'academy_logo' },
      { onSuccess: (media) => update.mutate({ logo_media_id: media.ref }) },
    );
  };

  const error = [upload.error, update.error].find((candidate) => candidate instanceof ApiError);
  const busy = upload.isPending || update.isPending;

  return (
    <Card withBorder>
      <Stack gap="sm">
        <Text fw={600}>Logo</Text>
        <Text size="sm" c="dimmed">
          Shown beside {academy.name} at the top of your public site. A wide image works best; it is
          drawn 28 pixels high. JPEG, PNG, WebP or AVIF, up to 2 MB.
        </Text>

        {academy.logo_url ? (
          <Image
            src={academy.logo_url}
            alt={`${academy.name} logo`}
            h={56}
            w="auto"
            fit="contain"
            style={{ alignSelf: 'flex-start' }}
          />
        ) : null}

        {error ? (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {error.message}
          </Alert>
        ) : null}

        <Group gap="sm" align="flex-end">
          <FileInput
            label={academy.logo_url ? 'Replace the logo' : 'Upload a logo'}
            // No SVG: it can carry script, and this is shown to every visitor.
            accept="image/jpeg,image/png,image/webp,image/avif"
            leftSection={<IconUpload size={16} />}
            onChange={onFile}
            disabled={busy}
            clearable={false}
          />
          {academy.logo_url ? (
            <Button
              variant="subtle"
              color="danger"
              loading={update.isPending && !upload.isPending}
              disabled={busy}
              onClick={() => update.mutate({ logo_media_id: null })}
            >
              Remove logo
            </Button>
          ) : null}
        </Group>
      </Stack>
    </Card>
  );
}
