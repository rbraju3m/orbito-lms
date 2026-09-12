import { AppShell, Button, Group, Image, Text } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { Link, Outlet, useParams } from 'react-router';

import { publicAcademyQuery } from '@/features/publicsite/api/queries';
import { ThemeToggle } from '@/shared/ui';

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
 */
export function AcademySiteLayout() {
  const { academy = '' } = useParams();
  const { data } = useQuery(publicAcademyQuery(academy));

  return (
    <AppShell header={{ height: 56 }} padding="md">
      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between">
          {/* The anchor is the outer element and carries nothing clickable
              inside it — a button within a link is not a button (§ Patterns
              established in Phase 12). */}
          <Link to={`/a/${academy}`} style={{ textDecoration: 'none' }}>
            <Group gap="sm">
              {data?.logo_url ? <Image src={data.logo_url} alt="" h={28} w="auto" /> : null}
              <Text fw={700} size="lg" c="var(--mantine-color-text)">
                {/* The name arrives a moment later; a non-breaking space holds
                    the header's height so it does not jump. */}
                {data?.name ?? '\u00a0'}
              </Text>
            </Group>
          </Link>

          <Group gap="sm">
            <ThemeToggle />
            <Button
              component={Link}
              to={`/login?academy=${encodeURIComponent(academy)}`}
              variant="subtle"
              size="sm"
            >
              Sign in
            </Button>
            {/*
              Absent, not disabled, when the academy is not taking signups: a
              greyed-out button is an invitation to keep clicking. Sign in
              stays, because an existing member still has an account.
            */}
            {data?.registration_open === true ? (
              <Button
                component={Link}
                to={`/register?academy=${encodeURIComponent(academy)}`}
                size="sm"
              >
                Sign up
              </Button>
            ) : null}
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Main>
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
