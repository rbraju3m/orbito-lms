import { AppShell, Burger, Group, Text } from '@mantine/core';
import { NavLink, Outlet } from 'react-router';

import { mainContentProps, SkipLink, ThemeToggle } from '@/shared/ui';

import { useNavMenu } from './useNavMenu';

const NAV = [
  { to: '/', label: 'Home', end: true },
  { to: '/courses', label: 'Courses', end: false },
  { to: '/system', label: 'System', end: false },
];

/**
 * The public shell — catalogue and marketing surfaces. The player, dashboard
 * and studio have their own shells (docs/FRONTEND_ARCHITECTURE.md §1); the
 * player's is full-bleed by design and is deliberately not this one.
 */
export function PublicLayout() {
  const { opened, close, navbarInert, burgerProps } = useNavMenu({ onDesktop: 'header' });

  return (
    <AppShell
      header={{ height: 56 }}
      navbar={{ width: 220, breakpoint: 'sm', collapsed: { desktop: true, mobile: !opened } }}
      padding="md"
    >
      <SkipLink />

      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between">
          <Group gap="sm">
            <Burger {...burgerProps} hiddenFrom="sm" size="sm" />
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

      <AppShell.Navbar p="md" inert={navbarInert}>
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

      <AppShell.Main {...mainContentProps}>
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
