import { Anchor, Box, Card, Center, Container, Group, Stack, Text } from '@mantine/core';
import { Link, Outlet } from 'react-router';

import { mainContentProps, SkipLink, ThemeToggle } from '@/shared/ui';

/**
 * Focused shell for sign-in, registration and password flows.
 *
 * Every part sits in a landmark — header, main, footer — and each page's own
 * title is the h1 (drawn at h2 size, as before). The brand is text, not a
 * heading: a heading reading "Orbito" above every page's real one was the
 * page's only heading structure axe could find, and it was not level one.
 */
export function AuthLayout() {
  return (
    <Box mih="100dvh">
      <SkipLink />

      <Group component="header" justify="space-between" px="md" py="sm">
        <Anchor component={Link} to="/" underline="never" fw={700} fz="lg">
          Orbito
        </Anchor>
        <ThemeToggle />
      </Group>

      <Center component="main" {...mainContentProps} px="md">
        <Container size={440} w="100%">
          <Card padding="xl">
            <Outlet />
          </Card>
        </Container>
      </Center>

      <Stack component="footer" align="center" mt="md" pb="xl">
        <Text size="xs" c="dimmed">
          Orbito LMS
        </Text>
      </Stack>
    </Box>
  );
}
