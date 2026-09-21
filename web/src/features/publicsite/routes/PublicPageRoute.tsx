import { Stack, Title } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';

import { ErrorState, LoadingState } from '@/shared/ui';

import { publicPageQuery } from '../api/pages';
import { publicAcademyQuery } from '../api/queries';
import { PageBlocks } from '../components/PageBlocks';

/**
 * A built page at `/a/:academy/p/:slug`, readable with no account.
 *
 * The `p/` prefix is deliberate: a page's slug can never collide with `blog`,
 * `courses` or `webinars`, today or when the site grows another section.
 * Client-rendered like the rest of the public site (docs/BLOG.md §5); the page
 * still names itself for the tab and for link previews.
 */
export function PublicPageRoute() {
  const { academy = '', slug = '' } = useParams();

  const academyQuery = useQuery(publicAcademyQuery(academy));
  const { data, isPending, isError, error } = useQuery(publicPageQuery(academy, slug));

  if (isPending) {
    return <LoadingState label="Loading page" rows={4} />;
  }

  if (isError) {
    return <ErrorState error={error} title="This page is not available" />;
  }

  const headline = data.seo_title ?? data.title;
  const academyName = academyQuery.data?.name;

  return (
    <Stack gap="lg" p="md" maw={960} mx="auto">
      <title>{academyName ? `${headline} — ${academyName}` : headline}</title>
      {data.seo_description ? <meta name="description" content={data.seo_description} /> : null}
      <meta property="og:title" content={headline} />

      {/* A heading block is level 2 or 3, so the page's own title is its
          h1: announced, not drawn, because the author's first block usually
          says the same words. */}
      <Title order={1} className="sr-only">
        {data.title}
      </Title>
      <PageBlocks academy={academy} blocks={data.blocks} />
    </Stack>
  );
}
