import {
  Alert,
  Badge,
  Button,
  Card,
  Code,
  Container,
  CopyButton,
  Group,
  Modal,
  PasswordInput,
  Stack,
  Switch,
  Text,
  TextInput,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconAlertTriangle, IconCheck, IconCopy, IconPlugConnected } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { Fragment, useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { paymentGatewaysQuery, useConnectGateway, useDisconnectGateway } from '../api/queries';
import type { PaymentGatewayAccount } from '../api/types';

/**
 * The academy connects its OWN payment provider (ADR-13). The platform is
 * never the merchant of record and never holds these credentials.
 *
 * The form is write-only by construction: the API will not read a secret back,
 * so the fields start empty even for a connected gateway, and an empty field
 * means "keep what is stored" rather than "blank it". That is stated on the
 * screen, because a blank box normally means the opposite.
 */
export function PaymentGatewaysRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(paymentGatewaysQuery());
  const [editing, setEditing] = useState<PaymentGatewayAccount | null>(null);

  if (isPending) return <LoadingState rows={2} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Payment gateways"
        description="Your academy takes payments through its own provider account. Money never passes through the platform."
      />

      <Stack gap="sm">
        {data.map((account) => (
          <GatewayCard key={account.gateway} account={account} onEdit={() => setEditing(account)} />
        ))}
      </Stack>

      <ConnectModal account={editing} onClose={() => setEditing(null)} />
    </Container>
  );
}

function GatewayCard({ account, onEdit }: { account: PaymentGatewayAccount; onEdit: () => void }) {
  const disconnect = useDisconnectGateway();
  const [confirming, { open, close }] = useDisclosure(false);

  return (
    <Card withBorder>
      <Group justify="space-between" wrap="nowrap" gap="md">
        <Stack gap={4} style={{ minWidth: 0 }}>
          <Group gap="xs">
            <Text fw={600}>{account.label}</Text>
            {account.is_active ? (
              <Badge color="green" variant="light">
                Live
              </Badge>
            ) : account.is_connected ? (
              <Badge color="gray" variant="light">
                Connected, not live
              </Badge>
            ) : (
              <Badge color="gray" variant="outline">
                Not connected
              </Badge>
            )}
            {account.is_connected && account.is_test_mode && (
              <Badge color="yellow" variant="light">
                Test mode
              </Badge>
            )}
          </Group>

          {/*
           * A gateway with credentials but no webhook secret cannot verify a
           * delivery, so no payment through it will ever grant access. Worth
           * saying loudly rather than leaving to be discovered at a sale.
           */}
          {account.is_connected && !account.has_webhook_secret && (
            <Text size="xs" c="yellow.7">
              No webhook secret — payments cannot be confirmed until you add one.
            </Text>
          )}
        </Stack>

        <Group gap="xs" wrap="nowrap">
          <Button variant="light" size="compact-sm" onClick={onEdit}>
            {account.is_connected ? 'Update' : 'Connect'}
          </Button>
          {account.is_connected && (
            <Button variant="subtle" color="red" size="compact-sm" onClick={open}>
              Disconnect
            </Button>
          )}
        </Group>
      </Group>

      <WebhookSetup account={account} />

      <Modal opened={confirming} onClose={close} title={`Disconnect ${account.label}?`} centered>
        <Stack gap="sm">
          <Text size="sm">
            The stored credentials are deleted, not just switched off. Orders already taken keep
            their records; new payments through {account.label} stop immediately.
          </Text>
          <Group justify="flex-end">
            <Button variant="subtle" onClick={close}>
              Keep it
            </Button>
            <Button
              color="red"
              loading={disconnect.isPending}
              onClick={() => disconnect.mutate(account.gateway, { onSuccess: close })}
            >
              Disconnect
            </Button>
          </Group>
        </Stack>
      </Modal>
    </Card>
  );
}

/**
 * Where the provider must send its webhooks, and which ones.
 *
 * The academy id in the URL is shown nowhere else in the product, and the
 * provider's endpoint setup cannot be finished without it. Shown before a
 * gateway is connected too, because the setup order is: create the endpoint
 * at the provider, THEN paste the signing secret it gives you in here. The
 * event names are the server's list — the one the webhook handler acts on —
 * never a second copy kept in the SPA.
 */
function WebhookSetup({ account }: { account: PaymentGatewayAccount }) {
  const label = `${account.label} webhook URL`;

  return (
    <Stack gap={4} mt="sm">
      <Group gap="xs" wrap="nowrap">
        <TextInput value={account.webhook_url} readOnly flex={1} size="xs" aria-label={label} />
        <CopyButton value={account.webhook_url}>
          {({ copied, copy }) => (
            <Button
              variant="light"
              size="xs"
              color={copied ? 'success' : undefined}
              leftSection={copied ? <IconCheck size={14} /> : <IconCopy size={14} />}
              onClick={copy}
              aria-label={`Copy ${label}`}
            >
              {copied ? 'Copied' : 'Copy'}
            </Button>
          )}
        </CopyButton>
      </Group>

      {account.webhook_events.length > 0 && (
        <Text size="xs" c="dimmed">
          Send these events to it:{' '}
          {account.webhook_events.map((event, index) => (
            <Fragment key={event}>
              {index > 0 && ', '}
              <Code>{event}</Code>
            </Fragment>
          ))}
        </Text>
      )}
    </Stack>
  );
}

function ConnectModal({
  account,
  onClose,
}: {
  account: PaymentGatewayAccount | null;
  onClose: () => void;
}) {
  const connect = useConnectGateway();
  const [secretKey, setSecretKey] = useState('');
  const [webhookSecret, setWebhookSecret] = useState('');
  const [testMode, setTestMode] = useState(true);
  const [active, setActive] = useState(false);

  const error = connect.error instanceof ApiError ? connect.error : null;

  // Re-seed the toggles when a different gateway is opened. The secrets stay
  // empty on purpose — see the component docblock.
  const [seededFor, setSeededFor] = useState<string | null>(null);
  if (account && seededFor !== account.gateway) {
    setSeededFor(account.gateway);
    setTestMode(account.is_test_mode);
    setActive(account.is_active);
    setSecretKey('');
    setWebhookSecret('');
  }

  if (!account) return null;

  const submit = () => {
    connect.mutate(
      {
        gateway: account.gateway,
        // Omitted when blank, so a partial update KEEPS the stored value
        // rather than wiping it.
        ...(secretKey ? { credentials: { key: secretKey } } : {}),
        ...(webhookSecret ? { webhook_secret: webhookSecret } : {}),
        is_test_mode: testMode,
        is_active: active,
      },
      { onSuccess: onClose },
    );
  };

  return (
    <Modal opened onClose={onClose} title={`Connect ${account.label}`} centered>
      <Stack gap="md">
        <Alert color="gray" icon={<IconPlugConnected size={16} />}>
          These credentials are stored encrypted and are never shown again. Leave a box empty to
          keep what is already saved.
        </Alert>

        <PasswordInput
          label="Secret key"
          placeholder={account.is_connected ? 'Leave empty to keep the current key' : 'sk_live_…'}
          value={secretKey}
          onChange={(event) => setSecretKey(event.currentTarget.value)}
          autoComplete="off"
        />

        <PasswordInput
          label="Webhook signing secret"
          description="Used to verify that a payment notification really came from the provider."
          placeholder={account.is_connected ? 'Leave empty to keep the current secret' : 'whsec_…'}
          value={webhookSecret}
          onChange={(event) => setWebhookSecret(event.currentTarget.value)}
          autoComplete="off"
        />

        <Switch
          label="Test mode"
          description="Use the provider's sandbox. No real money moves."
          checked={testMode}
          onChange={(event) => setTestMode(event.currentTarget.checked)}
        />

        <Switch
          label="Take payments with this gateway"
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
