import {
  Badge,
  Button,
  Code,
  Collapse,
  Group,
  Pagination,
  ScrollArea,
  Stack,
  Table,
  Text,
} from '@mantine/core';
import { IconSend } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { Fragment, useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { useRedeliverWebhook, webhookDeliveriesQuery } from '../api/queries';
import type { DeliveryStatus, WebhookDelivery } from '../api/types';

const STATUS_COLOR: Record<DeliveryStatus, string> = {
  succeeded: 'green',
  pending: 'yellow',
  failed: 'danger',
};

export interface DeliveryLogProps {
  endpointId: string;
  /** A switched-off endpoint cannot be redelivered to; the server says 409. */
  isActive: boolean;
}

/**
 * What was sent and what the receiver said. Polls while anything on the page
 * is still pending (see `webhookDeliveriesQuery`).
 */
export function DeliveryLog({ endpointId, isActive }: DeliveryLogProps) {
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<string | null>(null);
  const { data, isPending, isError, error, refetch } = useQuery(
    webhookDeliveriesQuery(endpointId, page),
  );
  const redeliver = useRedeliverWebhook(endpointId);

  if (isPending) return <LoadingState rows={3} label="Loading deliveries" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <EmptyState
        title="Nothing sent yet"
        description="Deliveries appear here as events happen. Send a test event to see one now."
      />
    );
  }

  const redeliverError = redeliver.error instanceof ApiError ? redeliver.error.message : null;

  return (
    <Stack gap="sm">
      {redeliverError ? (
        <Text size="sm" c="danger" role="alert">
          {redeliverError}
        </Text>
      ) : null}

      <ScrollArea type="auto">
        <Table verticalSpacing="xs" miw={640} highlightOnHover>
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Event</Table.Th>
              <Table.Th>Status</Table.Th>
              <Table.Th>Attempts</Table.Th>
              <Table.Th>Receiver</Table.Th>
              <Table.Th>Created</Table.Th>
              <Table.Th aria-label="Actions" />
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {data.data.map((delivery) => (
              <Fragment key={delivery.id}>
                <Table.Tr>
                  <Table.Td>
                    <Code>{delivery.topic}</Code>
                  </Table.Td>
                  <Table.Td>
                    <Badge color={STATUS_COLOR[delivery.status]} variant="light">
                      {delivery.status_label}
                    </Badge>
                  </Table.Td>
                  <Table.Td>
                    {delivery.attempts} of {delivery.max_attempts}
                  </Table.Td>
                  <Table.Td>
                    <ReceiverAnswer delivery={delivery} />
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{formatDateTime(delivery.created_at)}</Text>
                  </Table.Td>
                  <Table.Td>
                    <Group gap="xs" wrap="nowrap" justify="flex-end">
                      <Button
                        variant="subtle"
                        size="compact-sm"
                        aria-expanded={open === delivery.id}
                        onClick={() => setOpen(open === delivery.id ? null : delivery.id)}
                      >
                        {open === delivery.id ? 'Hide' : 'Details'}
                      </Button>
                      {delivery.status !== 'pending' ? (
                        <Button
                          variant="light"
                          size="compact-sm"
                          leftSection={<IconSend size={14} />}
                          disabled={!isActive}
                          loading={redeliver.isPending && redeliver.variables === delivery.id}
                          aria-label={`Redeliver ${delivery.topic}`}
                          onClick={() => redeliver.mutate(delivery.id)}
                        >
                          Redeliver
                        </Button>
                      ) : null}
                    </Group>
                  </Table.Td>
                </Table.Tr>
                <Table.Tr>
                  <Table.Td colSpan={6} p={0} style={{ borderTop: 0 }}>
                    <Collapse expanded={open === delivery.id}>
                      <DeliveryDetail delivery={delivery} />
                    </Collapse>
                  </Table.Td>
                </Table.Tr>
              </Fragment>
            ))}
          </Table.Tbody>
        </Table>
      </ScrollArea>

      {data.meta.last_page > 1 ? (
        <Group justify="center">
          <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
        </Group>
      ) : null}
    </Stack>
  );
}

function ReceiverAnswer({ delivery }: { delivery: WebhookDelivery }) {
  if (delivery.status === 'pending' && delivery.attempts === 0) {
    return <Text size="sm" c="dimmed">Not sent yet</Text>;
  }

  return (
    <Stack gap={0}>
      <Text size="sm">
        {delivery.response_status !== null ? `HTTP ${delivery.response_status}` : 'No answer'}
        {delivery.duration_ms !== null ? ` · ${delivery.duration_ms} ms` : ''}
      </Text>
      {delivery.status === 'pending' && delivery.next_attempt_at ? (
        <Text size="xs" c="dimmed">
          Retrying {formatDateTime(delivery.next_attempt_at)}
        </Text>
      ) : null}
    </Stack>
  );
}

function DeliveryDetail({ delivery }: { delivery: WebhookDelivery }) {
  return (
    <Stack gap="xs" p="sm">
      {delivery.error ? (
        <Text size="sm" c="danger">
          {delivery.error}
        </Text>
      ) : null}
      <Text size="xs" c="dimmed">
        Event id <Code>{delivery.event_id}</Code>
      </Text>
      <Text size="xs" fw={600}>
        Sent
      </Text>
      <ScrollArea.Autosize mah={240} type="auto">
        <Code block>{JSON.stringify(delivery.payload, null, 2)}</Code>
      </ScrollArea.Autosize>
      {delivery.response_body ? (
        <>
          <Text size="xs" fw={600}>
            Receiver answered
          </Text>
          <ScrollArea.Autosize mah={160} type="auto">
            <Code block>{delivery.response_body}</Code>
          </ScrollArea.Autosize>
        </>
      ) : null}
    </Stack>
  );
}
