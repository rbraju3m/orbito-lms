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
  Select,
  Stack,
  Text,
  TextInput,
} from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { IconAddressBook, IconDownload, IconSearch } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { useCsvExport } from '@/features/analytics/api/queries';
import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  leadsExportPath,
  leadsQuery,
  useDeleteLead,
  useUpdateLeadStatus,
  type Lead,
  type LeadStatus,
} from '../api/leads';

const STATUS_COLOR: Record<LeadStatus, string> = {
  new: 'blue',
  contacted: 'green',
  archived: 'gray',
};

const STATUS_OPTIONS = [
  { value: 'new', label: 'New' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'archived', label: 'Archived' },
];

const FILTERS = [{ value: 'all', label: 'All' }, ...STATUS_OPTIONS];

function isLeadStatus(value: string | null): value is LeadStatus {
  return value === 'new' || value === 'contacted' || value === 'archived';
}

/**
 * People who asked to hear from the academy on its public site
 * (`lead.view`). What the reader may DO — change a status, erase, export —
 * comes from `meta`, so a button that would 403 is never drawn.
 */
export function LeadsRoute() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<LeadStatus | 'all'>('all');
  const [search, setSearch] = useState('');
  const [q] = useDebouncedValue(search, 300);
  const [deleting, setDeleting] = useState<Lead | null>(null);

  const { data, isPending, isError, error, refetch } = useQuery(leadsQuery({ page, status, q }));
  const update = useUpdateLeadStatus();
  const remove = useDeleteLead();
  const exportCsv = useCsvExport();

  if (isPending) return <LoadingState rows={4} label="Loading leads" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const filtered = status !== 'all' || q.trim() !== '';
  const { can_manage: canManage, can_export: canExport } = data.meta;

  const closeDelete = () => {
    setDeleting(null);
    remove.reset();
  };

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Leads"
        description="People who left their email on your public site. None of them has an account yet."
        actions={
          canExport && data.meta.total > 0 ? (
            <Button
              variant="light"
              leftSection={<IconDownload size={16} />}
              loading={exportCsv.isPending}
              onClick={() =>
                exportCsv.mutate({
                  path: leadsExportPath({ status, q }),
                  filename: `leads-${new Date().toISOString().slice(0, 10)}.csv`,
                })
              }
            >
              Export CSV
            </Button>
          ) : undefined
        }
      />

      <Stack gap="md">
        <Group gap="sm" wrap="wrap" align="flex-end">
          <SegmentedControl
            aria-label="Filter by status"
            data={FILTERS}
            value={status}
            onChange={(value) => {
              setStatus(value === 'all' || isLeadStatus(value) ? value : 'all');
              setPage(1);
            }}
          />
          <TextInput
            label="Search"
            placeholder="Email or name"
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

        {exportCsv.isError ? (
          <Alert color="red" role="alert">
            The export could not be downloaded. Try again in a minute.
          </Alert>
        ) : null}

        {update.error instanceof ApiError ? (
          <Alert color="red" role="alert">
            {update.error.message}
          </Alert>
        ) : null}

        {data.data.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={IconSearch}
              title="No leads match"
              description="Try another status or search."
            />
          ) : (
            <EmptyState
              icon={IconAddressBook}
              title="No leads yet"
              description="When somebody leaves their email on your public site — the front page or a course page — it appears here."
            />
          )
        ) : (
          <Stack gap="sm">
            {data.data.map((lead) => (
              <Card key={lead.id} withBorder>
                <Group justify="space-between" gap="md" wrap="wrap" align="flex-start">
                  <Stack gap={4} style={{ minWidth: 0 }}>
                    <Group gap="xs" wrap="wrap">
                      <Text fw={600} style={{ overflowWrap: 'anywhere' }}>
                        {lead.email}
                      </Text>
                      <Badge color={STATUS_COLOR[lead.status]} variant="light">
                        {lead.status_label}
                      </Badge>
                    </Group>
                    {lead.name !== null ? <Text size="sm">{lead.name}</Text> : null}
                    <Text size="xs" c="dimmed">
                      {lead.source_label}
                      {lead.source_title !== null ? `: ${lead.source_title}` : ''}
                      {' · '}
                      {lead.submissions_count === 1
                        ? 'asked once'
                        : `asked ${lead.submissions_count} times`}
                      {' · last '}
                      {formatDateTime(lead.last_submitted_at)}
                    </Text>
                  </Stack>

                  {canManage ? (
                    <Group gap="xs" wrap="nowrap">
                      <Select
                        aria-label={`Status for ${lead.email}`}
                        data={STATUS_OPTIONS}
                        value={lead.status}
                        allowDeselect={false}
                        size="xs"
                        w={130}
                        disabled={update.isPending}
                        onChange={(value) => {
                          if (isLeadStatus(value) && value !== lead.status) {
                            update.mutate({ id: lead.id, status: value });
                          }
                        }}
                      />
                      <Button
                        variant="subtle"
                        color="red"
                        size="compact-sm"
                        aria-label={`Delete ${lead.email}`}
                        onClick={() => setDeleting(lead)}
                      >
                        Delete
                      </Button>
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

      <Modal opened={deleting !== null} onClose={closeDelete} title="Delete this lead?">
        {deleting !== null ? (
          <Stack gap="sm">
            <Text size="sm">
              <strong>{deleting.email}</strong> is erased outright — this is how you honour a
              request to delete somebody&apos;s details, and it cannot be undone.
            </Text>
            <Text size="sm" c="dimmed">
              If a webhook already sent this lead to another system, delete it there too.
            </Text>
            {remove.error instanceof ApiError ? (
              <Alert color="red" role="alert">
                {remove.error.message}
              </Alert>
            ) : null}
            <Group justify="flex-end" gap="xs">
              <Button variant="default" onClick={closeDelete}>
                Keep it
              </Button>
              <Button
                color="red"
                loading={remove.isPending}
                onClick={() => remove.mutate(deleting.id, { onSuccess: closeDelete })}
              >
                Delete
              </Button>
            </Group>
          </Stack>
        ) : null}
      </Modal>
    </Container>
  );
}
