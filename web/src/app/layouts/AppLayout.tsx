import {
  AppShell,
  Avatar,
  Burger,
  Group,
  Menu,
  NavLink as MantineNavLink,
  ScrollArea,
  Stack,
  Text,
  UnstyledButton,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import {
  IconAward,
  IconBell,
  IconBroadcast,
  IconBook,
  IconBookmark,
  IconBuildingCommunity,
  IconCalendar,
  IconChartBar,
  IconChalkboard,
  IconPackages,
  IconLayoutDashboard,
  IconLogout,
  IconCertificate,
  IconCertificate2,
  IconCreditCard,
  IconReceipt,
  IconSearch,
  IconSettings,
  IconShieldCheck,
  IconShieldLock,
  IconTrophy,
  IconUser,
  IconUsers,
} from '@tabler/icons-react';
import { NavLink, Outlet, useNavigate } from 'react-router';

import { NotificationBell } from '@/features/notification/components/NotificationBell';
import { NoAcademyBanner } from '@/features/platform/NoAcademyBanner';
import { SubscriptionBanner } from '@/features/platform/SubscriptionBanner';

import { useLogout } from '@/features/auth/api/queries';
import { useSession } from '@/features/auth/hooks/useSession';
import { ThemeToggle } from '@/shared/ui';

interface NavItem {
  to: string;
  label: string;
  icon: typeof IconBook;
  /** Shown only when the caller holds one of these permissions. */
  anyOf?: string[];
  /**
   * Shown only to a platform operator. A separate flag rather than another
   * permission key, because the registry is not inside an academy and
   * permissions are — see RequirePlatformOperator.
   */
  operatorOnly?: boolean;
  end?: boolean;
}

const NAV: NavItem[] = [
  // First: an operator with no academy has nothing else that works, and the
  // registry is where they pick one.
  {
    to: '/platform/academies',
    label: 'Academies',
    icon: IconBuildingCommunity,
    operatorOnly: true,
  },
  { to: '/dashboard', label: 'Dashboard', icon: IconLayoutDashboard, end: true },
  { to: '/dashboard/courses', label: 'My learning', icon: IconBook },
  { to: '/courses', label: 'Browse courses', icon: IconSearch },
  { to: '/wishlist', label: 'Saved courses', icon: IconBookmark },
  { to: '/calendar', label: 'Calendar', icon: IconCalendar },
  { to: '/webinars', label: 'Webinars', icon: IconBroadcast },
  { to: '/achievements', label: 'Achievements', icon: IconAward },
  { to: '/leaderboard', label: 'Leaderboard', icon: IconTrophy },
  { to: '/certificates', label: 'Certificates', icon: IconCertificate },
  { to: '/orders', label: 'Orders', icon: IconReceipt },
  {
    to: '/studio/courses',
    label: 'Studio',
    icon: IconChalkboard,
    anyOf: ['course.create', 'course.update.own'],
  },
  // Its own entry rather than a tab inside the studio: the audiences differ.
  // An instructor holds the studio permissions and not this one.
  { to: '/studio/bundles', label: 'Bundles', icon: IconPackages, anyOf: ['bundle.manage'] },
  {
    to: '/admin',
    label: 'Administration',
    icon: IconShieldLock,
    anyOf: ['user.view', 'settings.view'],
  },
  { to: '/admin/instructors', label: 'Instructors', icon: IconUsers, anyOf: ['instructor.view'] },
  {
    to: '/admin/analytics',
    label: 'Analytics',
    icon: IconChartBar,
    anyOf: ['analytics.view.platform'],
  },
  {
    to: '/admin/reviews',
    label: 'Review moderation',
    icon: IconShieldCheck,
    anyOf: ['review.moderate'],
  },
  {
    to: '/admin/payment-gateways',
    label: 'Payments',
    icon: IconCreditCard,
    anyOf: ['gateway.manage'],
  },
  {
    to: '/admin/certificate-templates',
    label: 'Certificates',
    icon: IconCertificate2,
    anyOf: ['certificate.template.manage'],
  },
];

/**
 * The signed-in shell. Navigation is filtered by permission so a user only
 * sees areas they can actually use — a UI convenience on top of server-side
 * authorization, never a substitute for it.
 */
export function AppLayout() {
  const [opened, { toggle, close }] = useDisclosure(false);
  const { session, canAny } = useSession();
  const { mutateAsync: signOut } = useLogout();
  const navigate = useNavigate();

  const isOperator = session?.is_platform_operator === true;

  /*
   * An operator inside NO academy. Every item below except the registry reads
   * tenant data, and the API answers those with a 409 `no_academy_selected` —
   * so offering them is offering fifteen links that all fail. The banner in
   * the main area says why and where to go.
   */
  const outsideAnyAcademy = isOperator && session?.academy == null;

  const items = NAV.filter((item) => {
    if (item.operatorOnly === true) return isOperator;

    if (outsideAnyAcademy) return false;

    return !item.anyOf || canAny(item.anyOf);
  });

  const handleSignOut = async () => {
    await signOut();
    void navigate('/login', { replace: true });
  };

  return (
    <AppShell
      header={{ height: 56 }}
      navbar={{ width: 240, breakpoint: 'sm', collapsed: { mobile: !opened } }}
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

          <Group gap="sm">
            <NotificationBell />
            <ThemeToggle />

            <Menu position="bottom-end" width={220} withinPortal>
              <Menu.Target>
                <UnstyledButton aria-label="Account menu">
                  <Group gap="xs">
                    <Avatar radius="xl" size="sm" name={session?.user.name} color="orbito" />
                    <Text size="sm" visibleFrom="sm">
                      {session?.user.name}
                    </Text>
                  </Group>
                </UnstyledButton>
              </Menu.Target>

              <Menu.Dropdown>
                <Menu.Label>{session?.user.email}</Menu.Label>
                <Menu.Item
                  component={NavLink}
                  to="/account/profile"
                  leftSection={<IconUser size={16} />}
                >
                  Profile
                </Menu.Item>
                <Menu.Item
                  component={NavLink}
                  to="/account/notifications"
                  leftSection={<IconBell size={16} />}
                >
                  Notifications
                </Menu.Item>
                <Menu.Item
                  component={NavLink}
                  to="/account/security"
                  leftSection={<IconSettings size={16} />}
                >
                  Security
                </Menu.Item>
                <Menu.Divider />
                <Menu.Item
                  color="danger"
                  leftSection={<IconLogout size={16} />}
                  onClick={() => void handleSignOut()}
                >
                  Sign out
                </Menu.Item>
              </Menu.Dropdown>
            </Menu>
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="sm">
        <ScrollArea>
          <Stack gap={2}>
            {items.map(({ to, label, icon: Icon, end }) => (
              <MantineNavLink
                key={to}
                component={NavLink}
                to={to}
                end={end ?? false}
                label={label}
                leftSection={<Icon size={18} stroke={1.5} />}
                onClick={close}
              />
            ))}
          </Stack>
        </ScrollArea>
      </AppShell.Navbar>

      <AppShell.Main>
        <NoAcademyBanner />
        <SubscriptionBanner />
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
