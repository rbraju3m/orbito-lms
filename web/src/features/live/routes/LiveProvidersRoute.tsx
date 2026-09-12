import {
  Alert,
  Anchor,
  Badge,
  Button,
  Card,
  Container,
  Group,
  Modal,
  PasswordInput,
  Stack,
  Switch,
  Text,
  TextInput,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import {
  IconAlertTriangle,
  IconExternalLink,
  IconPlugConnected,
  IconVideo,
} from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  liveProvidersQuery,
  useConnectLiveProvider,
  useDisconnectLiveProvider,
} from '../api/queries';
import type { LiveProviderAccount } from '../api/types';

/**
 * The academy connects its OWN meeting provider. The platform holds no Zoom
 * or Google account on anybody's behalf — the ADR-13 decision payment
 * gateways made, which is why this screen reads like that one.
 *
 * Write-only by construction: the API returns no credential under any key, so
 * every box starts empty even for a connected provider and an empty box means
 * "keep what is stored". The form's boxes are the SERVER's declaration
 * (`fields`), not a copy kept here — adding a provider is a case in the enum
 * and a class beside it, with no screen to remember.
 */
export function LiveProvidersRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(liveProvidersQuery());
  const [editing, setEditing] = useState<LiveProviderAccount | null>(null);

  if (isPending) return <LoadingState rows={3} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Live providers"
        description="Where your live classes actually happen. Orbito keeps the schedule, the roster and the attendance whichever you use."
      />

      <Stack gap="sm">
        {data.map((provider) => (
          <ProviderCard
            key={provider.provider}
            provider={provider}
            onEdit={() => setEditing(provider)}
          />
        ))}
      </Stack>

      <ConnectModal provider={editing} onClose={() => setEditing(null)} />
    </Container>
  );
}

function ProviderCard({
  provider,
  onEdit,
}: {
  provider: LiveProviderAccount;
  onEdit: () => void;
}) {
  const disconnect = useDisconnectLiveProvider();
  const [confirming, { open, close }] = useDisclosure(false);

  return (
    <Card withBorder>
      <Group justify="space-between" wrap="nowrap" gap="md" align="flex-start">
        <Stack gap={4} style={{ minWidth: 0 }}>
          <Group gap="xs">
            <Text fw={600}>{provider.label}</Text>
            <StatusBadge provider={provider} />
          </Group>

          {!provider.needs_account ? (
            <Text size="sm" c="dimmed">
              Nothing to connect. Whoever schedules a session pastes the link from whichever tool
              they already use, and Orbito does the rest.
            </Text>
          ) : provider.is_connected ? (
            <Text size="sm" c="dimmed">
              Sessions scheduled with {provider.label} create the meeting for you.
            </Text>
          ) : (
            <Text size="sm" c="dimmed">
              Not connected, so it cannot be chosen when scheduling.{' '}
              {provider.setup_url && (
                <Anchor href={provider.setup_url} target="_blank" rel="noreferrer" size="sm">
                  Create the credentials
                  <IconExternalLink size={12} style={{ verticalAlign: 'middle', marginLeft: 2 }} />
                </Anchor>
              )}
            </Text>
          )}

          {/*
           * Sessions already scheduled through a provider nothing is
           * connected to. Their links still work and they can still be
           * cancelled — what they cannot be is rescheduled.
           */}
          {provider.needs_account && !provider.is_connected && provider.upcoming_sessions > 0 && (
            <Text size="xs" c="yellow.7">
              {provider.upcoming_sessions} scheduled session
              {provider.upcoming_sessions === 1 ? '' : 's'} still point{' '}
              {provider.upcoming_sessions === 1 ? 's' : ''} at {provider.label}. They can be joined
              and cancelled, but not moved, until it is connected again.
            </Text>
          )}
        </Stack>

        {provider.needs_account && (
          <Group gap="xs" wrap="nowrap">
            <Button variant="light" size="compact-sm" onClick={onEdit}>
              {provider.is_connected ? 'Update' : 'Connect'}
            </Button>
            {provider.is_connected && (
              <Button variant="subtle" color="red" size="compact-sm" onClick={open}>
                Disconnect
              </Button>
            )}
          </Group>
        )}
      </Group>

      <Modal opened={confirming} onClose={close} title={`Disconnect ${provider.label}?`} centered>
        <Stack gap="sm">
          <Text size="sm">
            The stored credentials are deleted, not switched off.
            {provider.upcoming_sessions > 0
              ? ` The ${provider.upcoming_sessions} session${
                  provider.upcoming_sessions === 1 ? '' : 's'
                } already scheduled keep their links and can still be cancelled, but nobody will be able to move them.`
              : ' Sessions already scheduled keep their links.'}
          </Text>
          <Group justify="flex-end">
            <Button variant="subtle" onClick={close}>
              Keep it
            </Button>
            <Button
              color="red"
              loading={disconnect.isPending}
              onClick={() => disconnect.mutate(provider.provider, { onSuccess: close })}
            >
              Disconnect
            </Button>
          </Group>
        </Stack>
      </Modal>
    </Card>
  );
}

function StatusBadge({ provider }: { provider: LiveProviderAccount }) {
  if (!provider.needs_account) {
    return (
      <Badge color="green" variant="light" leftSection={<IconVideo size={12} />}>
        Always available
      </Badge>
    );
  }

  if (provider.is_connected && provider.is_active) {
    return (
      <Badge color="green" variant="light">
        Connected
      </Badge>
    );
  }

  if (provider.is_connected) {
    return (
      <Badge color="gray" variant="light">
        Connected, switched off
      </Badge>
    );
  }

  return (
    <Badge color="gray" variant="outline">
      Not connected
    </Badge>
  );
}

function ConnectModal({
  provider,
  onClose,
}: {
  provider: LiveProviderAccount | null;
  onClose: () => void;
}) {
  const connect = useConnectLiveProvider();
  const [values, setValues] = useState<Record<string, string>>({});
  const [active, setActive] = useState(true);

  const error = connect.error instanceof ApiError ? connect.error : null;
  const missing = Array.isArray(error?.meta.missing) ? (error.meta.missing as string[]) : [];

  // Re-seed when a different provider is opened. The boxes stay empty on
  // purpose — see the route docblock.
  const [seededFor, setSeededFor] = useState<string | null>(null);
  if (provider && seededFor !== provider.provider) {
    setSeededFor(provider.provider);
    setValues({});
    setActive(provider.is_connected ? provider.is_active : true);
  }

  if (!provider) return null;

  const submit = () => {
    // Blank boxes are omitted, so a partial update keeps what is stored
    // rather than wiping it.
    const credentials = Object.fromEntries(
      Object.entries(values).filter(([, value]) => value.trim() !== ''),
    );

    connect.mutate(
      {
        provider: provider.provider,
        ...(Object.keys(credentials).length > 0 ? { credentials } : {}),
        is_active: active,
      },
      { onSuccess: onClose },
    );
  };

  return (
    <Modal opened onClose={onClose} title={`Connect ${provider.label}`} centered>
      <Stack gap="md">
        <Alert color="gray" icon={<IconPlugConnected size={16} />} role="note">
          These are stored encrypted and never shown again. Leave a box empty to keep what is
          already saved.
        </Alert>

        {provider.fields.map((field) => {
          const Input = field.secret ? PasswordInput : TextInput;

          return (
            <Input
              key={field.key}
              label={field.label}
              description={field.help}
              placeholder={
                provider.is_connected && field.required ? 'Leave empty to keep the current value' : ''
              }
              required={field.required && !provider.is_connected}
              error={missing.includes(field.key) ? 'Needed before this can be used' : undefined}
              value={values[field.key] ?? ''}
              onChange={(event) => {
                const value = event.currentTarget.value;
                setValues((current) => ({ ...current, [field.key]: value }));
              }}
              autoComplete="off"
            />
          );
        })}

        <Switch
          label={`Offer ${provider.label} when scheduling`}
          checked={active}
          onChange={(event) => setActive(event.currentTarget.checked)}
        />

        {error && (
          <Alert color="red" icon={<IconAlertTriangle size={16} />}>
            {error.message}
          </Alert>
        )}

        <Group justify="flex-end">
          <Button variant="subtle" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={connect.isPending} onClick={submit}>
            Save
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
