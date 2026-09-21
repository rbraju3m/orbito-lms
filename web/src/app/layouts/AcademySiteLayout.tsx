import { AppShell, Burger, Button, Group, Image, Stack, Text } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { Link, Outlet, useParams } from 'react-router';

import { publicNavigationQuery } from '@/features/publicsite/api/pages';
import { publicAcademyQuery } from '@/features/publicsite/api/queries';
import { ThemeToggle } from '@/shared/ui';

import { useNavMenu } from './useNavMenu';

/**
 * The shell for ONE academy's public site — the only anonymous surface.
 *
 * Not `PublicLayout`, which is Orbito's own shell and whose nav points at
 * members-only pages: this one wears the ACADEMY's name, because a visitor
 * reading about a course has no idea what Orbito is and should not have to.
 *
 * Sign in and Sign up both carry `?academy=<slug>`, and that is load-bearing
 * rather than a convenience: registration is TOLD which academy to create the
 * account in (§ Multi-tenancy), and a visitor who arrived from a course page
 * would otherwise sign up into nowhere.
 *
 * Below `sm` the links move into a burger menu. The header is a fixed 56px,
 * and a row that wraps there does not grow it — it spills over the page, which
 * is what an academy's own page links did to Sign in and Sign up at 360px.
 */
export function AcademySiteLayout() {
  const { academy = '' } = useParams();
  const { data } = useQuery(publicAcademyQuery(academy));
  // The pages the academy linked from its header (docs/PAGES.md).
  const navigation = useQuery(publicNavigationQuery(academy));
  const { opened, close, navbarInert, burgerProps } = useNavMenu({ onDesktop: 'header' });

  const links = [
    ...(navigation.data ?? []).map((link) => ({
      key: `page-${link.slug}`,
      to: `/a/${academy}/p/${link.slug}`,
      label: link.title,
    })),
    { key: 'blog', to: `/a/${academy}/blog`, label: 'Blog' },
    {
      key: 'sign-in',
      to: `/login?academy=${encodeURIComponent(academy)}`,
      label: 'Sign in',
    },
  ];

  /*
    Absent, not disabled, when the academy is not taking signups: a greyed-out
    button is an invitation to keep clicking. Sign in stays, because an
    existing member still has an account.
  */
  const signUp =
    data?.registration_open === true ? `/register?academy=${encodeURIComponent(academy)}` : null;

  return (
    <AppShell
      header={{ height: 56 }}
      navbar={{ width: 260, breakpoint: 'sm', collapsed: { desktop: true, mobile: !opened } }}
      padding="md"
    >
      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between" wrap="nowrap">
          <Group gap="sm" wrap="nowrap" miw={0}>
            <Burger {...burgerProps} hiddenFrom="sm" size="sm" />
            {/* The anchor is the outer element and carries nothing clickable
                inside it — a button within a link is not a button (§ Patterns
                established in Phase 12). */}
            {/* Until the name arrives — or when there is no such academy — the
                link holds only a space, so it is named for what it does. */}
            <Link
              to={`/a/${academy}`}
              onClick={close}
              aria-label={data?.name ? undefined : 'Home'}
              style={{ textDecoration: 'none', minWidth: 0 }}
            >
              <Group gap="sm" wrap="nowrap">
                {data?.logo_url ? <Image src={data.logo_url} alt="" h={28} w="auto" /> : null}
                <Text fw={700} size="lg" c="var(--mantine-color-text)" truncate>
                  {/* The name arrives a moment later; a non-breaking space holds
                      the header's height so it does not jump. */}
                  {data?.name ?? ' '}
                </Text>
              </Group>
            </Link>
          </Group>

          <Group gap="sm" wrap="nowrap" visibleFrom="sm">
            {links.map((link) => (
              <Button key={link.key} component={Link} to={link.to} variant="subtle" size="sm">
                {link.label}
              </Button>
            ))}
            <ThemeToggle />
            {signUp ? (
              <Button component={Link} to={signUp} size="sm">
                Sign up
              </Button>
            ) : null}
          </Group>

          <Group hiddenFrom="sm">
            <ThemeToggle />
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="md" inert={navbarInert}>
        <Stack gap="xs">
          {links.map((link) => (
            <Button
              key={link.key}
              component={Link}
              to={link.to}
              onClick={close}
              variant="subtle"
              justify="flex-start"
            >
              {link.label}
            </Button>
          ))}
          {signUp ? (
            <Button component={Link} to={signUp} onClick={close}>
              Sign up
            </Button>
          ) : null}
        </Stack>
      </AppShell.Navbar>

      <AppShell.Main>
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
