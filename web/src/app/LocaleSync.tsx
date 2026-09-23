import { DirectionProvider } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { Fragment, useEffect, type ReactNode } from 'react';

import { sessionQuery } from '@/features/auth/api/queries';
import { applyLocale, useLocaleStore } from '@/shared/i18n';

/**
 * Draws the app in the language the SERVER resolved (docs/I18N.md).
 *
 * Reads the session from the cache and never fetches it — an anonymous page
 * must not ask `/auth/me` just to learn it has no user. The public site
 * applies its academy's answer itself (`AcademySiteLayout`); a signed-in
 * reader's own session wins there too.
 *
 * Keyed on the locale, so a switch redraws everything: dates, numbers and
 * `t()` are plain functions read during render, and a memoised row would
 * otherwise keep the old language. Switching is rare; the query cache sits
 * above this and survives it.
 */
export function LocaleSync({ children }: { children: ReactNode }) {
  const { data: session } = useQuery({ ...sessionQuery(), enabled: false });
  const active = useLocaleStore((state) => state.active);

  useEffect(() => {
    if (session?.locale) void applyLocale(session.locale);
  }, [session?.locale]);

  return (
    <DirectionProvider
      key={active.direction}
      initialDirection={active.direction}
      detectDirection={false}
    >
      <Fragment key={active.code}>{children}</Fragment>
    </DirectionProvider>
  );
}
