import { Alert, Card, Group, Stack, Switch, Table, Text, Title, Tooltip } from '@mantine/core';
import { IconInfoCircle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { notificationPreferencesQuery, useUpdatePreferences } from '../api/queries';
import type { NotificationTypeSetting } from '../api/types';

/**
 * Which notifications arrive, and how.
 *
 * The matrix is computed by the server from the type catalogue and whatever
 * overrides exist, so a notification type shipped next phase appears here
 * without a frontend release. The groups and their labels come from the API
 * for the same reason.
 */
export function NotificationPreferencesRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(notificationPreferencesQuery());

  if (isPending) return <LoadingState rows={4} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return (
    <>
      <PageHeader
        title="Notifications"
        description="What reaches you, and where. These settings are for this academy."
      />

      <Stack gap="lg">
        <Alert color="gray" variant="light" icon={<IconInfoCircle size={16} />}>
          {/*
           * The asymmetry, said out loud rather than left as a mysterious
           * disabled switch: silencing the inbox would destroy the record,
           * not the interruption.
           */}
          In-app notifications cannot be switched off — the inbox is the record of what happened.
          Email is what these switches govern.
        </Alert>

        {data.groups
          .filter((group) => group.types.length > 0)
          .map((group) => (
            <Card withBorder key={group.key}>
              <Stack gap="sm">
                <Title order={4}>{group.label}</Title>

                <Table.ScrollContainer minWidth={420}>
                  <Table verticalSpacing="sm">
                    <Table.Thead>
                      <Table.Tr>
                        <Table.Th>Notification</Table.Th>
                        {group.types[0]?.channels.map((channel) => (
                          <Table.Th key={channel.channel} style={{ width: 110 }}>
                            {channel.label}
                          </Table.Th>
                        ))}
                      </Table.Tr>
                    </Table.Thead>
                    <Table.Tbody>
                      {group.types.map((type) => (
                        <PreferenceRow key={type.key} type={type} />
                      ))}
                    </Table.Tbody>
                  </Table>
                </Table.ScrollContainer>
              </Stack>
            </Card>
          ))}
      </Stack>
    </>
  );
}

function PreferenceRow({ type }: { type: NotificationTypeSetting }) {
  const update = useUpdatePreferences();

  return (
    <Table.Tr>
      <Table.Td>
        <Stack gap={2}>
          <Text size="sm" fw={600}>
            {type.label}
          </Text>
          <Text size="xs" c="dimmed">
            {type.description}
          </Text>
        </Stack>
      </Table.Td>

      {type.channels.map((channel) => (
        <Table.Td key={channel.channel}>
          {channel.locked ? (
            <Tooltip label="The in-app record cannot be switched off" withArrow>
              <Group>
                <Switch checked readOnly disabled aria-label={`${type.label} — ${channel.label}`} />
              </Group>
            </Tooltip>
          ) : (
            <Switch
              checked={channel.enabled}
              aria-label={`${type.label} — ${channel.label}`}
              onChange={(event) =>
                // Only the switch that moved. Posting the whole matrix would
                // make two open tabs race, and the older one would win.
                update.mutate([
                  {
                    type: type.key,
                    channel: 'mail',
                    enabled: event.currentTarget.checked,
                  },
                ])
              }
            />
          )}
        </Table.Td>
      ))}
    </Table.Tr>
  );
}
