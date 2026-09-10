import {
  Anchor,
  Badge,
  Button,
  Card,
  Container,
  Group,
  Modal,
  Pagination,
  Stack,
  Text,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconPlus, IconWebhook } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { useCreateWebhookEndpoint, webhookEndpointsQuery } from '../api/queries';
import type { WebhookEndpoint } from '../api/types';
import { EndpointForm } from '../components/EndpointForm';
import { SecretRevealModal } from '../components/SecretRevealModal';
import { topicCount } from '../lib/topics';

/**
 * An academy's outbound webhook endpoints (ADR-12). Super Admin only — an
 * endpoint receives learners' names and email addresses.
 */
export function WebhooksRoute() {
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(webhookEndpointsQuery(page));
  const [creating, { open, close }] = useDisclosure(false);
  const create = useCreateWebhookEndpoint();
  const navigate = useNavigate();

  if (isPending) return <LoadingState rows={3} label="Loading webhooks" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  // The one-time secret lives in the mutation's result and nowhere else. It
  // is dropped (`reset`) the moment the reveal closes, so it does not linger
  // in the mutation cache for the rest of the session.
  const created = create.data ?? null;

  const finishReveal = () => {
    const id = created?.id;
    create.reset();
    if (id) void navigate(`/admin/webhooks/${id}`);
  };

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Webhooks"
        description="Tell other systems — a CRM, a mailing list, an automation tool — what happens in your academy, as it happens."
      />

      {data.data.length === 0 ? (
        <EmptyState
          icon={IconWebhook}
          title="No endpoints yet"
          description="Add one to start sending events. Each delivery is signed so your receiver can check it came from us."
          action={{ label: 'Add endpoint', onClick: open }}
        />
      ) : (
        <Stack gap="sm">
          <Group justify="flex-end">
            <Button leftSection={<IconPlus size={16} />} onClick={open}>
              Add endpoint
            </Button>
          </Group>

          {data.data.map((endpoint) => (
            <EndpointCard key={endpoint.id} endpoint={endpoint} />
          ))}

          {data.meta.last_page > 1 ? (
            <Group justify="center">
              <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
            </Group>
          ) : null}
        </Stack>
      )}

      <Modal opened={creating} onClose={close} title="Add an endpoint" size="lg">
        <EndpointForm
          topics={data.meta.topics}
          defaultValues={{ url: '', description: '', events: [] }}
          submitLabel="Create endpoint"
          pending={create.isPending}
          onCancel={close}
          onSubmit={async (values) => {
            await create.mutateAsync({
              url: values.url,
              description: values.description === '' ? null : values.description,
              events: values.events,
            });
            close();
          }}
        />
      </Modal>

      <SecretRevealModal secret={creating ? null : (created?.secret ?? null)} onClose={finishReveal} />
    </Container>
  );
}

/** One `<Link>` on the URL, and no buttons inside it (§ Phase 12). */
function EndpointCard({ endpoint }: { endpoint: WebhookEndpoint }) {
  return (
    <Card withBorder>
      <Stack gap={6}>
        <Group gap="xs" wrap="nowrap" justify="space-between">
          <Anchor
            component={Link}
            to={`/admin/webhooks/${endpoint.id}`}
            fw={600}
            style={{ minWidth: 0, overflowWrap: 'anywhere' }}
          >
            {endpoint.url}
          </Anchor>
          {endpoint.is_active ? (
            <Badge color="green" variant="light">
              Sending
            </Badge>
          ) : (
            <Badge color="gray" variant="light">
              Switched off
            </Badge>
          )}
        </Group>

        {endpoint.description ? <Text size="sm">{endpoint.description}</Text> : null}

        <Text size="xs" c="dimmed">
          {topicCount(endpoint.events)}
          {' · '}
          {endpoint.last_delivered_at
            ? `last delivered ${formatDateTime(endpoint.last_delivered_at)}`
            : 'nothing delivered yet'}
        </Text>

        {!endpoint.is_active && endpoint.disabled_reason ? (
          <Text size="xs" c="warning.7">
            {endpoint.disabled_reason}
          </Text>
        ) : null}
      </Stack>
    </Card>
  );
}
