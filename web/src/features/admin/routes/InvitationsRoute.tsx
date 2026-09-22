import {
  Alert,
  Badge,
  Button,
  Card,
  Container,
  Group,
  Modal,
  Pagination,
  SegmentedControl,
  Stack,
  Text,
  TextInput,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { useDebouncedValue } from '@mantine/hooks';
import { IconMailPlus, IconSearch } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useId, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';

import { ApiError } from '@/shared/api/errors';
import { formatDate } from '@/shared/lib/datetime';
import { applyServerErrors } from '@/shared/lib/form';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  invitationsQuery,
  useResendInvitation,
  useRevokeInvitation,
  useSendInvitation,
  type Invitation,
  type InvitationStatus,
} from '../api/invitations';
import { invitationSchema, type InvitationValues } from '../invitationSchema';

const STATUS_COLOR: Record<InvitationStatus, string> = {
  pending: 'blue',
  expired: 'orange',
  accepted: 'green',
  revoked: 'gray',
};

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'expired', label: 'Expired' },
  { value: 'accepted', label: 'Accepted' },
  { value: 'revoked', label: 'Revoked' },
];

const ROLES = [
  { value: 'student', label: 'Student' },
  { value: 'instructor', label: 'Instructor' },
];

function isStatus(value: string): value is InvitationStatus {
  return value === 'pending' || value === 'expired' || value === 'accepted' || value === 'revoked';
}

/** The one line under each address — what happened to it, and when. */
function history(invitation: Invitation): string {
  const sent =
    invitation.sent_count === 1 ? 'Sent once' : `Sent ${invitation.sent_count} times`;

  switch (invitation.status) {
    case 'accepted':
      return `${sent} · accepted ${formatDate(invitation.accepted_at)}`;
    case 'revoked':
      return `${sent} · revoked ${formatDate(invitation.revoked_at)}`;
    case 'expired':
      return `${sent} · expired ${formatDate(invitation.expires_at)}`;
    case 'pending':
      return `${sent} · link works until ${formatDate(invitation.expires_at)}`;
  }
}

/**
 * Invite people by email as a student or an instructor (`invitation.manage`,
 * docs/INVITATIONS.md). Works whatever the academy's sign-up setting: an
 * invitation is the academy's own act.
 */
export function InvitationsRoute() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<InvitationStatus | 'all'>('all');
  const [search, setSearch] = useState('');
  const [q] = useDebouncedValue(search, 300);
  const [inviting, setInviting] = useState(false);
  const [revoking, setRevoking] = useState<Invitation | null>(null);

  const { data, isPending, isError, error, refetch } = useQuery(
    invitationsQuery({ page, status, q }),
  );
  const resend = useResendInvitation();
  const revoke = useRevokeInvitation();

  if (isPending) return <LoadingState rows={4} label="Loading invitations" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const filtered = status !== 'all' || q.trim() !== '';
  const rowError = resend.error ?? null;

  const closeRevoke = () => {
    setRevoking(null);
    revoke.reset();
  };

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Invitations"
        description="Invite somebody by email to join as a student or an instructor. The link works even when sign-ups are closed."
        actions={
          <Button leftSection={<IconMailPlus size={16} />} onClick={() => setInviting(true)}>
            Invite
          </Button>
        }
      />

      <Stack gap="md">
        <Group gap="sm" wrap="wrap" align="flex-end">
          <SegmentedControl
            aria-label="Filter by status"
            data={FILTERS}
            value={status}
            onChange={(value) => {
              setStatus(isStatus(value) ? value : 'all');
              setPage(1);
            }}
          />
          <TextInput
            label="Search"
            placeholder="Email"
            leftSection={<IconSearch size={16} />}
            value={search}
            onChange={(event) => {
              const { value } = event.currentTarget;
              setSearch(value);
              setPage(1);
            }}
            style={{ flex: 1, minWidth: 180 }}
          />
        </Group>

        {rowError instanceof ApiError ? (
          <Alert color="red" role="alert">
            {rowError.message}
          </Alert>
        ) : null}

        {resend.isSuccess ? (
          <Alert color="green" role="status">
            A new link is on its way to {resend.data.email}. The old one no longer works.
          </Alert>
        ) : null}

        {data.data.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={IconSearch}
              title="No invitations match"
              description="Try another status or search."
            />
          ) : (
            <EmptyState
              icon={IconMailPlus}
              title="No invitations yet"
              description="Invite a student or an instructor by email. They choose a password from the link and arrive signed in."
            />
          )
        ) : (
          <Stack gap="sm">
            {data.data.map((invitation) => (
              <Card key={invitation.id} withBorder>
                <Group justify="space-between" gap="md" wrap="wrap" align="flex-start">
                  <Stack gap={4} style={{ minWidth: 0 }}>
                    <Group gap="xs" wrap="wrap">
                      <Text fw={600} style={{ overflowWrap: 'anywhere' }}>
                        {invitation.email}
                      </Text>
                      <Badge variant="outline" color="gray">
                        {invitation.role_label}
                      </Badge>
                      <Badge color={STATUS_COLOR[invitation.status]} variant="light">
                        {invitation.status_label}
                      </Badge>
                    </Group>
                    <Text size="xs" c="dimmed">
                      {history(invitation)}
                    </Text>
                  </Stack>

                  {invitation.can_resend || invitation.can_revoke ? (
                    <Group gap="xs" wrap="nowrap">
                      {invitation.can_resend ? (
                        <Button
                          variant="light"
                          size="compact-sm"
                          aria-label={`Send a new link to ${invitation.email}`}
                          loading={resend.isPending && resend.variables === invitation.id}
                          disabled={resend.isPending}
                          onClick={() => resend.mutate(invitation.id)}
                        >
                          Resend
                        </Button>
                      ) : null}
                      {invitation.can_revoke ? (
                        <Button
                          variant="subtle"
                          color="red"
                          size="compact-sm"
                          aria-label={`Revoke the invitation to ${invitation.email}`}
                          onClick={() => setRevoking(invitation)}
                        >
                          Revoke
                        </Button>
                      ) : null}
                    </Group>
                  ) : null}
                </Group>
              </Card>
            ))}

            {data.meta.last_page > 1 ? (
              <Group justify="center">
                <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
              </Group>
            ) : null}
          </Stack>
        )}
      </Stack>

      <InviteModal opened={inviting} onClose={() => setInviting(false)} />

      <Modal opened={revoking !== null} onClose={closeRevoke} title="Revoke this invitation?">
        {revoking !== null ? (
          <Stack gap="sm">
            <Text size="sm">
              The link sent to <strong>{revoking.email}</strong> stops working. The invitation
              stays on this list as revoked, and you can invite the address again later.
            </Text>
            {revoke.error instanceof ApiError ? (
              <Alert color="red" role="alert">
                {revoke.error.message}
              </Alert>
            ) : null}
            <Group justify="flex-end" gap="xs">
              <Button variant="default" onClick={closeRevoke}>
                Keep it
              </Button>
              <Button
                color="red"
                loading={revoke.isPending}
                onClick={() => revoke.mutate(revoking.id, { onSuccess: closeRevoke })}
              >
                Revoke
              </Button>
            </Group>
          </Stack>
        ) : null}
      </Modal>
    </Container>
  );
}

function InviteModal({ opened, onClose }: { opened: boolean; onClose: () => void }) {
  const roleLabelId = useId();
  const send = useSendInvitation();
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    control,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<InvitationValues>({
    resolver: zodResolver(invitationSchema),
    defaultValues: { email: '', role: 'student' },
  });

  const close = () => {
    reset();
    send.reset();
    setFormError(null);
    onClose();
  };

  const submit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await send.mutateAsync(values);
      close();
    } catch (error) {
      setFormError(applyServerErrors(error, setError, ['email', 'role'] as const));
    }
  });

  return (
    <Modal opened={opened} onClose={close} title="Invite somebody">
      <form onSubmit={submit} noValidate>
        <Stack gap="sm">
          <TextInput
            label="Email"
            type="email"
            autoComplete="off"
            required
            data-autofocus
            error={errors.email?.message}
            {...register('email')}
          />

          <Controller
            control={control}
            name="role"
            render={({ field }) => (
              <Stack gap={4}>
                <Text size="sm" fw={500} id={roleLabelId}>
                  Joins as
                </Text>
                <SegmentedControl
                  aria-labelledby={roleLabelId}
                  data={ROLES}
                  value={field.value}
                  onChange={(value) => field.onChange(value)}
                />
                <Text size="xs" c="dimmed">
                  {field.value === 'instructor'
                    ? "Approved as an instructor on arrival, and holds one of your plan's instructor seats while the invitation is open."
                    : 'A student, like anybody who signs up.'}
                </Text>
              </Stack>
            )}
          />

          {formError !== null ? (
            <Alert color="red" role="alert">
              {formError}
            </Alert>
          ) : null}

          <Text size="xs" c="dimmed">
            Inviting an address again sends a new link, and the old one stops working.
          </Text>

          <Group justify="flex-end" gap="xs">
            <Button variant="default" onClick={close}>
              Cancel
            </Button>
            <Button type="submit" loading={isSubmitting}>
              Send invitation
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
