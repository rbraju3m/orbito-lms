import {
  Alert,
  Anchor,
  Button,
  Card,
  Container,
  Group,
  Modal,
  Stack,
  Switch,
  Text,
  Title,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconAlertTriangle, IconArrowLeft, IconSend } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  useDeleteWebhookEndpoint,
  useRotateWebhookSecret,
  useSendTestWebhook,
  useUpdateWebhookEndpoint,
  webhookEndpointQuery,
  webhookEndpointsQuery,
} from '../api/queries';
import type { WebhookEndpoint } from '../api/types';
import { DeliveryLog } from '../components/DeliveryLog';
import { EndpointForm } from '../components/EndpointForm';
import { SecretRevealModal } from '../components/SecretRevealModal';

export function WebhookEndpointRoute() {
  const { endpointId = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(webhookEndpointQuery(endpointId));
  // The topic list rides on the list's `meta`; usually already cached by the
  // page this was opened from. Above the early returns — hooks are counted.
  const topics = useQuery(webhookEndpointsQuery(1)).data?.meta.topics;

  if (isPending) return <LoadingState rows={3} label="Loading endpoint" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <EndpointDetail endpoint={data} topics={topics} />;
}

function EndpointDetail({
  endpoint,
  topics,
}: {
  endpoint: WebhookEndpoint;
  topics: Parameters<typeof EndpointForm>[0]['topics'] | undefined;
}) {
  const update = useUpdateWebhookEndpoint(endpoint.id);
  const test = useSendTestWebhook(endpoint.id);
  const rotate = useRotateWebhookSecret(endpoint.id);
  const remove = useDeleteWebhookEndpoint();
  const navigate = useNavigate();
  const [confirmingRotate, rotateModal] = useDisclosure(false);
  const [confirmingDelete, deleteModal] = useDisclosure(false);

  const actionError = [update.error, test.error, rotate.error, remove.error].find(
    (err): err is ApiError => err instanceof ApiError,
  );

  return (
    <Container size="md" py="lg">
      <Anchor component={Link} to="/admin/webhooks" size="sm">
        <Group gap={4}>
          <IconArrowLeft size={14} />
          All endpoints
        </Group>
      </Anchor>

      <PageHeader title={endpoint.description ?? 'Webhook endpoint'} description={endpoint.url} />

      <Stack gap="md">
        {!endpoint.is_active ? (
          <Alert color="warning" icon={<IconAlertTriangle size={16} />} title="Switched off" role="note">
            {endpoint.disabled_reason ?? 'Nothing is sent to this endpoint.'} Switch it back on when
            your receiver is answering.
          </Alert>
        ) : null}

        {actionError ? (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {actionError.message}
          </Alert>
        ) : null}

        <Card withBorder>
          <Group justify="space-between" gap="sm">
            <Switch
              label="Sending events"
              checked={endpoint.is_active}
              disabled={update.isPending}
              onChange={(event) => {
                const next = event.currentTarget.checked;
                update.mutate({ is_active: next });
              }}
            />
            <Group gap="xs">
              <Button
                variant="light"
                leftSection={<IconSend size={16} />}
                disabled={!endpoint.is_active}
                loading={test.isPending}
                onClick={() => test.mutate()}
              >
                Send test event
              </Button>
              <Button variant="default" onClick={rotateModal.open}>
                Rotate secret
              </Button>
              <Button variant="subtle" color="red" onClick={deleteModal.open}>
                Delete
              </Button>
            </Group>
          </Group>
          {test.isSuccess ? (
            <Text size="sm" c="dimmed" mt="xs" role="status">
              Test event queued — it appears in the deliveries below.
            </Text>
          ) : null}
        </Card>

        <Card withBorder>
          <Title order={3} size="h5" mb="sm">
            Settings
          </Title>
          {topics ? (
            <EndpointForm
              key={endpoint.updated_at}
              topics={topics}
              defaultValues={{
                url: endpoint.url,
                description: endpoint.description ?? '',
                events: endpoint.events,
              }}
              submitLabel="Save"
              pending={update.isPending}
              onSubmit={(values) =>
                update.mutateAsync({
                  url: values.url,
                  description: values.description === '' ? null : values.description,
                  events: values.events,
                })
              }
            />
          ) : (
            <LoadingState rows={2} label="Loading events" />
          )}
        </Card>

        <Card withBorder>
          <Title order={3} size="h5" mb="sm">
            Deliveries
          </Title>
          <DeliveryLog endpointId={endpoint.id} isActive={endpoint.is_active} />
        </Card>
      </Stack>

      <Modal opened={confirmingRotate} onClose={rotateModal.close} title="Rotate the signing secret?" centered>
        <Stack gap="sm">
          <Text size="sm">
            The current secret stops working immediately. Deliveries will fail verification until
            your receiver has the new one.
          </Text>
          <Group justify="flex-end">
            <Button variant="subtle" onClick={rotateModal.close}>
              Keep the current one
            </Button>
            <Button loading={rotate.isPending} onClick={() => rotate.mutate(undefined, { onSuccess: rotateModal.close })}>
              Rotate
            </Button>
          </Group>
        </Stack>
      </Modal>

      <Modal opened={confirmingDelete} onClose={deleteModal.close} title="Delete this endpoint?" centered>
        <Stack gap="sm">
          <Text size="sm">
            Nothing more is sent to it, including deliveries still waiting to retry. Its delivery
            log goes with it.
          </Text>
          <Group justify="flex-end">
            <Button variant="subtle" onClick={deleteModal.close}>
              Keep it
            </Button>
            <Button
              color="red"
              loading={remove.isPending}
              onClick={() =>
                remove.mutate(endpoint.id, { onSuccess: () => void navigate('/admin/webhooks') })
              }
            >
              Delete
            </Button>
          </Group>
        </Stack>
      </Modal>

      {/* The new secret lives in the mutation result until the reveal closes. */}
      <SecretRevealModal secret={rotate.data?.secret ?? null} onClose={() => rotate.reset()} />
    </Container>
  );
}
