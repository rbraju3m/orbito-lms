import { AppShell, Burger, Group, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { NavLink, Outlet } from 'react-router';

import { ThemeToggle } from '@/shared/ui';

const NAV = [
  { to: '/', label: 'Home', end: true },
  { to: '/courses', label: 'Courses', end: false },
  { to: '/system', label: 'System', end: false },
];

/**
 * The public shell. Phase 2 ships this one only; the Learn, Dashboard, Studio
 * and Admin shells arrive with their phases (docs/FRONTEND_ARCHITECTURE.md §1).
 */
export function PublicLayout() {
  const [opened, { toggle, close }] = useDisclosure(false);

  return (
    <AppShell
      header={{ height: 56 }}
      navbar={{ width: 220, breakpoint: 'sm', collapsed: { desktop: true, mobile: !opened } }}
      padding="md"
    >
      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between">
          <Group gap="sm">
            <Burger opened={opened} onClick={toggle} hiddenFrom="sm" size="sm" aria-label="Menu" />
            <Text fw={700} size="lg">
              Orbito
            </Text>
          </Group>

          <Group gap="lg" visibleFrom="sm">
            {NAV.map((item) => (
              <NavLink key={item.to} to={item.to} end={item.end}>
                {({ isActive }) => (
                  <Text size="sm" fw={isActive ? 600 : 400} c={isActive ? undefined : 'dimmed'}>
                    {item.label}
                  </Text>
                )}
              </NavLink>
            ))}
            <ThemeToggle />
          </Group>

          <Group hiddenFrom="sm">
            <ThemeToggle />
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="md">
        {NAV.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} onClick={close}>
            {({ isActive }) => (
              <Text py="xs" fw={isActive ? 600 : 400}>
                {item.label}
              </Text>
            )}
          </NavLink>
        ))}
      </AppShell.Navbar>

      <AppShell.Main>
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
