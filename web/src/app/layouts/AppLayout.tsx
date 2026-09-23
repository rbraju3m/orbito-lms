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
import {
  IconAddressBook,
  IconArticle,
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
  IconFileDownload,
  IconFiles,
  IconLayoutDashboard,
  IconLayoutGrid,
  IconLogout,
  IconMailPlus,
  IconCertificate,
  IconCertificate2,
  IconCreditCard,
  IconReceipt,
  IconReceiptRefund,
  IconSearch,
  IconSettings,
  IconShieldCheck,
  IconShieldLock,
  IconTicket,
  IconTrophy,
  IconUser,
  IconUsers,
  IconVideo,
  IconWebhook,
} from '@tabler/icons-react';
import { NavLink, Outlet, useNavigate } from 'react-router';

import { LazyNotificationBell } from '@/features/notification/components/LazyNotificationBell';
import { NoAcademyBanner } from '@/features/platform/NoAcademyBanner';
import { SubscriptionBanner } from '@/features/platform/SubscriptionBanner';

import { useLogout } from '@/features/auth/api/queries';
import { useSession } from '@/features/auth/hooks/useSession';
import { t } from '@/shared/i18n';
import { mainContentProps, SkipLink, ThemeToggle } from '@/shared/ui';

import { useNavMenu } from './useNavMenu';

interface NavItem {
  to: string;
  /**
   * A function, not a string: this list is built when the module loads,
   * before the reader's catalogue has arrived, and a string would stay
   * English for ever. Called during render (docs/I18N.md §3a).
   */
  label: () => string;
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
    label: () => t('shell.nav.academies', 'Academies'),
    icon: IconBuildingCommunity,
    operatorOnly: true,
  },
  {
    to: '/dashboard',
    label: () => t('shell.nav.dashboard', 'Dashboard'),
    icon: IconLayoutDashboard,
    end: true,
  },
  {
    to: '/dashboard/courses',
    label: () => t('shell.nav.my_learning', 'My learning'),
    icon: IconBook,
  },
  { to: '/courses', label: () => t('shell.nav.browse', 'Browse courses'), icon: IconSearch },
  { to: '/wishlist', label: () => t('shell.nav.saved', 'Saved courses'), icon: IconBookmark },
  { to: '/calendar', label: () => t('shell.nav.calendar', 'Calendar'), icon: IconCalendar },
  { to: '/webinars', label: () => t('shell.nav.webinars', 'Webinars'), icon: IconBroadcast },
  {
    to: '/achievements',
    label: () => t('shell.nav.achievements', 'Achievements'),
    icon: IconAward,
  },
  { to: '/leaderboard', label: () => t('shell.nav.leaderboard', 'Leaderboard'), icon: IconTrophy },
  {
    to: '/certificates',
    label: () => t('shell.nav.certificates', 'Certificates'),
    icon: IconCertificate,
  },
  { to: '/downloads', label: () => t('shell.nav.downloads', 'Downloads'), icon: IconFileDownload },
  { to: '/orders', label: () => t('shell.nav.orders', 'Orders'), icon: IconReceipt },
  {
    to: '/studio/courses',
    label: () => t('shell.nav.studio', 'Studio'),
    icon: IconChalkboard,
    anyOf: ['course.create', 'course.update.own'],
  },
  // Its own entry rather than a tab inside the studio: the audiences differ.
  // An instructor holds the studio permissions and not this one.
  {
    to: '/studio/bundles',
    label: () => t('shell.nav.bundles', 'Bundles'),
    icon: IconPackages,
    anyOf: ['bundle.manage'],
  },
  {
    to: '/studio/downloads',
    label: () => t('shell.nav.manage_downloads', 'Manage downloads'),
    icon: IconFiles,
    anyOf: ['download.manage'],
  },
  {
    to: '/admin',
    label: () => t('shell.nav.administration', 'Administration'),
    icon: IconShieldLock,
    anyOf: ['user.view', 'settings.view'],
  },
  {
    to: '/admin/instructors',
    label: () => t('shell.nav.instructors', 'Instructors'),
    icon: IconUsers,
    anyOf: ['instructor.view'],
  },
  {
    to: '/admin/invitations',
    label: () => t('shell.nav.invitations', 'Invitations'),
    icon: IconMailPlus,
    anyOf: ['invitation.manage'],
  },
  {
    to: '/admin/analytics',
    label: () => t('shell.nav.analytics', 'Analytics'),
    icon: IconChartBar,
    anyOf: ['analytics.view.platform'],
  },
  {
    to: '/admin/reviews',
    label: () => t('shell.nav.review_moderation', 'Review moderation'),
    icon: IconShieldCheck,
    anyOf: ['review.moderate'],
  },
  {
    to: '/admin/payment-gateways',
    label: () => t('shell.nav.payments', 'Payments'),
    icon: IconCreditCard,
    anyOf: ['gateway.manage'],
  },
  {
    to: '/admin/live-providers',
    label: () => t('shell.nav.live_providers', 'Live providers'),
    icon: IconVideo,
    anyOf: ['live.provider.manage'],
  },
  {
    to: '/admin/coupons',
    label: () => t('shell.nav.coupons', 'Coupons'),
    icon: IconTicket,
    anyOf: ['coupon.manage'],
  },
  // Strangers who asked to hear from the academy on its public site (docs/LEADS.md).
  {
    to: '/admin/pages',
    label: () => t('shell.nav.pages', 'Pages'),
    icon: IconLayoutGrid,
    anyOf: ['page.manage'],
  },
  {
    to: '/admin/posts',
    label: () => t('shell.nav.blog', 'Blog'),
    icon: IconArticle,
    anyOf: ['post.manage'],
  },
  {
    to: '/admin/leads',
    label: () => t('shell.nav.leads', 'Leads'),
    icon: IconAddressBook,
    anyOf: ['lead.view'],
  },
  // Refunds the provider reported that the books could not take in (REFUNDS.md §6).
  {
    to: '/admin/refund-reports',
    label: () => t('shell.nav.refund_reports', 'Refund reports'),
    icon: IconReceiptRefund,
    anyOf: ['order.refund'],
  },
  // Super Admin only: an endpoint receives learners' names and emails.
  {
    to: '/admin/webhooks',
    label: () => t('shell.nav.webhooks', 'Webhooks'),
    icon: IconWebhook,
    anyOf: ['webhook.manage'],
  },
  {
    to: '/admin/certificate-templates',
    label: () => t('shell.nav.certificate_templates', 'Certificates'),
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
  const { opened, close, navbarInert, burgerProps } = useNavMenu({ onDesktop: 'navbar' });
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
      <SkipLink />

      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between">
          <Group gap="sm">
            <Burger {...burgerProps} hiddenFrom="sm" size="sm" />
            <Text fw={700} size="lg">
              Orbito
            </Text>
          </Group>

          <Group gap="sm">
            <LazyNotificationBell />
            <ThemeToggle />

            <Menu position="bottom-end" width={220} withinPortal>
              <Menu.Target>
                <UnstyledButton aria-label={t('shell.account.menu', 'Account menu')}>
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
                  {t('shell.account.profile', 'Profile')}
                </Menu.Item>
                <Menu.Item
                  component={NavLink}
                  to="/account/notifications"
                  leftSection={<IconBell size={16} />}
                >
                  {t('shell.account.notifications', 'Notifications')}
                </Menu.Item>
                <Menu.Item
                  component={NavLink}
                  to="/account/security"
                  leftSection={<IconSettings size={16} />}
                >
                  {t('shell.account.security', 'Security')}
                </Menu.Item>
                <Menu.Divider />
                <Menu.Item
                  color="danger"
                  leftSection={<IconLogout size={16} />}
                  onClick={() => void handleSignOut()}
                >
                  {t('shell.account.sign_out', 'Sign out')}
                </Menu.Item>
              </Menu.Dropdown>
            </Menu>
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="sm" inert={navbarInert}>
        <ScrollArea>
          <Stack gap={2}>
            {items.map(({ to, label, icon: Icon, end }) => (
              <MantineNavLink
                key={to}
                component={NavLink}
                to={to}
                end={end ?? false}
                label={label()}
                leftSection={<Icon size={18} stroke={1.5} />}
                onClick={close}
              />
            ))}
          </Stack>
        </ScrollArea>
      </AppShell.Navbar>

      <AppShell.Main {...mainContentProps}>
        <NoAcademyBanner />
        <SubscriptionBanner />
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
