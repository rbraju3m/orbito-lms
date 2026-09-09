import {
  Anchor,
  Badge,
  Button,
  Group,
  Pagination,
  Select,
  Stack,
  Table,
  Text,
  TextInput,
} from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { IconBuildingCommunity, IconPlus, IconSearch } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { tenantsQuery } from '../api/queries';
import type { Tenant } from '../api/types';
import { ProvisionAcademyModal } from '../components/ProvisionAcademyModal';
import { TenantStatusBadge } from '../components/TenantStatusBadge';

const STATUSES = [
  { value: 'pending', label: 'Awaiting approval' },
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'rejected', label: 'Rejected' },
];

/**
 * The academy registry — the platform operator's home.
 *
 * This is the one screen in the app that is not about a course. It reads the
 * CENTRAL database and works whether or not the operator is inside an academy,
 * which is what makes it the right place to land after leaving one.
 */
export function AcademiesRoute() {
  const navigate = useNavigate();
  const { session } = useSession();

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  // The list is a server query per keystroke otherwise. 300ms is the point
  // where typing stops feeling watched.
  const [debouncedSearch] = useDebouncedValue(search, 300);

  const [provisioning, setProvisioning] = useState(false);

  const filters = {
    page,
    status: status ?? undefined,
    search: debouncedSearch.trim() || undefined,
  };

  const { data, isPending, isError, error, refetch } = useQuery(tenantsQuery(filters));

  const isFiltered = status !== null || debouncedSearch.trim() !== '';

  const resetTo = (change: () => void) => {
    // Any filter change returns to page one. Staying on page 4 of a list that
    // now has one page shows an empty screen that looks like a broken query.
    setPage(1);
    change();
  };

  return (
    <>
      <PageHeader
        title="Academies"
        description="Every academy on this installation, and its subscription."
        actions={
          <Button leftSection={<IconPlus size={16} />} onClick={() => setProvisioning(true)}>
            New academy
          </Button>
        }
      />

      <Stack gap="md">
        <Group gap="sm" wrap="wrap">
          <TextInput
            value={search}
            onChange={(event) => {
              const next = event.currentTarget.value;
              resetTo(() => setSearch(next));
            }}
            leftSection={<IconSearch size={16} />}
            placeholder="Search by name or slug"
            aria-label="Search academies"
            flex="1 1 240px"
          />

          <Select
            data={STATUSES}
            value={status}
            onChange={(next) => resetTo(() => setStatus(next))}
            placeholder="Any status"
            aria-label="Filter by status"
            clearable
            w={200}
          />
        </Group>

        {isPending ? <LoadingState rows={5} height={52} label="Loading academies" /> : null}

        {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

        {!isPending && !isError && data.data.length === 0 ? (
          <EmptyState
            icon={IconBuildingCommunity}
            title={isFiltered ? 'No academies match' : 'No academies yet'}
            description={
              isFiltered
                ? 'Try a different status, or clear the search.'
                : 'Provision the first one. It is created shut, and you approve it separately.'
            }
            {...(isFiltered ? {} : { action: { label: 'New academy', onClick: () => setProvisioning(true) } })}
          />
        ) : null}

        {!isPending && !isError && data.data.length > 0 ? (
          <>
            {/* A registry is genuinely tabular. It scrolls inside its own
                container rather than making the page scroll sideways. */}
            <Table.ScrollContainer minWidth={760}>
              <Table striped highlightOnHover verticalSpacing="sm">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Academy</Table.Th>
                    <Table.Th>Status</Table.Th>
                    <Table.Th>Plan</Table.Th>
                    <Table.Th>Created</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {data.data.map((tenant) => (
                    <AcademyRow
                      key={tenant.id}
                      tenant={tenant}
                      isCurrent={session?.academy?.id === tenant.id}
                    />
                  ))}
                </Table.Tbody>
              </Table>
            </Table.ScrollContainer>

            {data.meta.last_page > 1 ? (
              <Group justify="center">
                <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
              </Group>
            ) : null}
          </>
        ) : null}
      </Stack>

      <ProvisionAcademyModal
        opened={provisioning}
        onClose={() => setProvisioning(false)}
        onProvisioned={(tenant) => {
          setProvisioning(false);
          void navigate(`/platform/academies/${tenant.slug}`);
        }}
      />
    </>
  );
}

function AcademyRow({ tenant, isCurrent }: { tenant: Tenant; isCurrent: boolean }) {
  return (
    <Table.Tr>
      <Table.Td>
        <Stack gap={2}>
          <Group gap="xs" wrap="nowrap">
            <Anchor component={Link} to={`/platform/academies/${tenant.slug}`} fw={600}>
              {tenant.name}
            </Anchor>
            {isCurrent ? (
              <Badge size="xs" variant="light" color="orbito">
                You are here
              </Badge>
            ) : null}
          </Group>
          <Text size="xs" c="dimmed">
            {tenant.slug}
          </Text>
        </Stack>
      </Table.Td>

      <Table.Td>
        <TenantStatusBadge status={tenant.status} label={tenant.status_label} />
      </Table.Td>

      <Table.Td>
        <Stack gap={2}>
          <Text size="sm">{tenant.subscription?.plan?.name ?? '—'}</Text>
          {tenant.subscription ? (
            <Text size="xs" c={tenant.subscription.permits_writes ? 'dimmed' : 'danger'}>
              {tenant.subscription.permits_writes
                ? tenant.subscription.status_label
                : `${tenant.subscription.status_label} — cannot save`}
            </Text>
          ) : null}
        </Stack>
      </Table.Td>

      <Table.Td>
        <Text size="sm" c="dimmed">
          {formatDateTime(tenant.created_at)}
        </Text>
      </Table.Td>
    </Table.Tr>
  );
}
