import { Anchor, Card, Group, SimpleGrid, Stack, Text, Title } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';

import { CourseCard } from '@/features/catalog/components/CourseCard';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { publicHomePageQuery } from '../api/pages';
import { publicAcademyQuery, publicCoursesQuery, publicWebinarsQuery } from '../api/queries';
import { PageBlocks } from '../components/PageBlocks';
import { LeadCaptureForm } from '../components/LeadCaptureForm';

/**
 * An academy's front page, to a stranger.
 *
 * Three queries rather than one composed endpoint, because each is separately
 * cacheable and separately useful: the header is read on every page of the
 * site, the courses change when one publishes, the webinars when one is
 * announced. A single `GET /public/{academy}/home` would be invalidated by
 * all three.
 *
 * Each block renders its own loading, empty and error state. A failed webinar
 * query must not blank the courses — a marketing page that shows nothing
 * because one panel failed is worse than one that shows most of itself.
 */
export function AcademyHomeRoute() {
  const { academy = '' } = useParams();

  /*
   * The front page the academy BUILT, when it chose and published one
   * (docs/PAGES.md §4). A failure to load it is not a reason to show nothing:
   * the standard front page below is always there to fall back to, and its
   * own queries run only when it is the page being shown.
   */
  const home = useQuery(publicHomePageQuery(academy));
  const standard = home.isError || (home.isSuccess && home.data === null);

  const academyQuery = useQuery(publicAcademyQuery(academy));
  const courses = useQuery({ ...publicCoursesQuery(academy), enabled: standard });
  const webinars = useQuery({ ...publicWebinarsQuery(academy), enabled: standard });

  if (home.isPending) {
    return <LoadingState label="Loading" />;
  }

  if (home.data) {
    const headline = home.data.seo_title ?? home.data.title;

    return (
      <Stack gap="lg" p="md" maw={960} mx="auto">
        <title>{academyQuery.data ? `${headline} — ${academyQuery.data.name}` : headline}</title>
        {home.data.seo_description ? (
          <meta name="description" content={home.data.seo_description} />
        ) : null}
        <Title order={1} className="sr-only">
          {academyQuery.data?.name ?? home.data.title}
        </Title>
        <PageBlocks academy={academy} blocks={home.data.blocks} />
      </Stack>
    );
  }

  if (academyQuery.isPending) {
    return <LoadingState label="Loading" />;
  }

  if (academyQuery.isError) {
    return (
      <ErrorState
        error={academyQuery.error}
        title="This page is not available — the academy may have moved or closed"
      />
    );
  }

  const upcoming = (webinars.data?.data ?? []).filter((webinar) => webinar.session != null);

  return (
    <Stack gap="xl" p="md">
      <Stack gap="xs">
        <Title order={1}>{academyQuery.data.name}</Title>
        <Text c="dimmed">
          {academyQuery.data.registration_open
            ? 'Browse what is on offer, then create an account to enrol.'
            : 'Browse what is on offer. Registration is currently closed.'}
        </Text>
      </Stack>

      <Stack gap="md">
        <Group justify="space-between" align="baseline">
          <Title order={2} size="h3">
            Courses
          </Title>
          {/* Only when there is more to see than this page shows. */}
          {courses.isSuccess && courses.data.meta.total > courses.data.data.length ? (
            <Anchor component={Link} to={`/a/${academy}/courses`}>
              All {courses.data.meta.total} courses
            </Anchor>
          ) : null}
        </Group>

        {courses.isPending ? <LoadingState label="Loading courses" /> : null}

        {courses.isError ? (
          <ErrorState
            error={courses.error}
            title="The course list could not be loaded"
            onRetry={() => void courses.refetch()}
          />
        ) : null}

        {courses.isSuccess && courses.data.data.length === 0 ? (
          <EmptyState
            title="No courses yet"
            description="Nothing has been published here so far."
          />
        ) : null}

        {courses.isSuccess && courses.data.data.length > 0 ? (
          <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
            {courses.data.data.map((course) => (
              <CourseCard
                key={course.id}
                course={course}
                // Inside the academy's site, never the members-only page a
                // stranger would be bounced off.
                to={`/a/${academy}/courses/${course.slug}`}
                headingOrder={3}
              />
            ))}
          </SimpleGrid>
        ) : null}
      </Stack>

      {upcoming.length > 0 ? (
        <Stack gap="md">
          <Title order={2} size="h3">
            Events
          </Title>

          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            {upcoming.map((webinar) => (
              <Card key={webinar.id} withBorder padding="md">
                <Stack gap="xs">
                  <Anchor component={Link} to={`/a/${academy}/webinars/${webinar.slug}`} fw={600}>
                    {webinar.title}
                  </Anchor>
                  {webinar.session ? (
                    <Group gap="xs">
                      <Text size="sm" c="dimmed">
                        {new Date(webinar.session.starts_at).toLocaleString()}
                      </Text>
                      <Text size="sm" c="dimmed">
                        ({webinar.session.timezone})
                      </Text>
                    </Group>
                  ) : null}
                </Stack>
              </Card>
            ))}
          </SimpleGrid>
        </Stack>
      ) : null}

      <LeadCaptureForm
        academy={academy}
        source="site"
        description="Leave your email and we will tell you when new courses and events are announced."
      />
    </Stack>
  );
}
