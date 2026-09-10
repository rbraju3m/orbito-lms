# FRONTEND_ARCHITECTURE.md — Route & Module Architecture

Stack: **React + TypeScript + Vite + Mantine (v9) + TanStack Query (v5) + React Router +
Zod + React Hook Form + Zustand (small client state) + dnd-kit**.

> Versions verified 2026-09-07: Mantine 9.x, `@tanstack/react-query` 5.x.
> Mantine requires `postcss`, `postcss-preset-mantine`, `postcss-simple-vars`,
> `@mantine/core/styles.css`, a `<MantineProvider>`, and `<ColorSchemeScript>`.
>
> **State: Phase 15, plus the platform operator's surface.** The route map and
> directory layout below are the target. What exists today is marked ✅;
> unmarked entries are not built.
>
> **The bundle is watched, and the numbers are in `ROADMAP.md`.** Mantine is a
> shared chunk, so a lazy route does NOT keep its component imports off the
> first-paint path — that lesson cost 5.5 KB for one admin colour picker in
> P11, and is why P13 hand-rolled an SVG chart rather than adding a charting
> library for the one screen that draws a line.
>
> **Tenancy changed the contract, and the route map with it.** The catalogue,
> course pages and the player are **members-only** — tenancy resolves from the
> authenticated user, so an anonymous request belongs to no academy and the API
> answers 401. `RequireAuth` turns that into a login redirect. There is no
> anonymous preview route any more: `is_preview` means "try before you
> *enrol*". Any write can also return **402** when the academy's subscription
> has lapsed; that is caught once in the `MutationCache` and rendered as an
> app-wide banner, not per button. `@mantine/dates`
> is deliberately *not* installed — the one date field so far uses a native
> `datetime-local` input converted at the edge by `shared/lib/datetime.ts`,
> which costs nothing against the first-paint budget (255 KB since Phase 16;
> `npm run size` is the only measurement).

---

## 1. Four surfaces, one app

| Surface | Path prefix | Audience | Shell |
|---|---|---|---|
| **Public** | `/` | anonymous + logged in | marketing header/footer, catalogue |
| **Learn** | `/learn/*` | enrolled students | distraction-free player shell |
| **Platform** | `/platform/*` | platform operators | sidebar app shell — the academy REGISTRY, above every academy |
| **Dashboard** | `/dashboard/*` | students | sidebar app shell |
| **Studio** | `/studio/*` | instructors, course managers, TAs, reviewers | sidebar app shell + course context |
| **Admin** | `/admin/*` | admins, staff | sidebar app shell |

Each surface has its own layout, its own navigation, and its own lazy-loaded route
bundle. The player is deliberately *not* the dashboard shell — it is full-bleed.

---

## 2. Route map

`✅` marks a route that exists today. The rest is the target.

```
/                                  home / marketing                       ✅
/courses                           catalogue  (filters in the URL, shareable)✅ members-only
/courses/:slug                     course page: prerequisites, seats, enrol ✅ members-only
/courses/:slug/preview/:itemId     ~~free preview item~~  removed — no anonymous surface
/instructors/:slug
/categories/:slug
/blog · /blog/:slug                                       (P16)
/verify/:token                     public certificate verification
/cart · /checkout · /checkout/:orderUuid/status
/login · /register?academy=<slug> · /forgot-password · /reset-password
/verify-email                                                             ✅

/learn/:courseId                   → redirect to last/first item          ✅
/learn/:courseId/announcements     every announcement in the course       ✅
/learn/:courseId/discussions/:id   one thread                             ✅
/learn/:courseId/:itemId           the player                             ✅
    ├─ content pane (video | text | pdf | quiz | assignment | live)
    ├─ curriculum drawer (mobile) / sidebar (desktop)
    └─ tabs: Notes · Q&A · Announcements · Live · Leaderboard             ✅

The two STATIC segments are declared before `:itemId`, which would otherwise
swallow them. They are also the `action_path` values the server freezes into a
notification payload, so they are part of that contract rather than a
convenience: a link in a year-old email has to still land somewhere.
/learn/:courseId/:itemId/quiz              quiz intro: attempts used, past results   ✅
/learn/:courseId/:itemId/quiz/:attemptUuid quiz runner (own focused layout)        ✅
/learn/:courseId/:itemId/quiz/:attemptUuid/result                                  ✅

/dashboard                         continue learning + stats              ✅
/dashboard/courses                 enrolled (in progress | completed | all)✅
/certificates                      shipped at the root, not under /dashboard ✅
/wishlist                          saved courses; empties itself on enrol  ✅
/calendar                          the next month, grouped by local day     ✅
/webinars                          open sessions, members-only              ✅
/achievements                      points, badges, streak, opt-out         ✅
/leaderboard                       the academy board                       ✅
/notifications                     the inbox                               ✅
/account/notifications             the preference matrix                   ✅
/orders · /orders/:uuid                                                    ✅
/dashboard/quiz-attempts · /dashboard/submissions
/account/profile · /account/security                                       ✅

/studio                            instructor home (reorderable cards)    ✅
/studio/courses                    my/managed courses                     ✅
/studio/courses/new                creation wizard                        ✅
/studio/courses/:id                the course editor                              ✅
    ├─ basics           title, category, level, language, thumbnail, intro video   ✅
    ├─ curriculum       THE BUILDER                                                ✅
    ├─ settings         completion mode, certificate, Q&A, seats, expiry           ✅
    ├─ access           drip mode, seat limit, expiry, retake/reset  (sidebar)     ✅
    ├─ prerequisites    whole-set picker, cycles refused server-side (sidebar)     ✅
    ├─ pricing          price, sale, currency, coupons scope                       P10
    ├─ students         roster, bulk enrol, suspend/extend/revoke                  ✅
    ├─ announcements    draft, publish (its own button), delete                     ✅
    └─ analytics        trend, KPIs, the stall heatmap, CSV                         ✅

Reviews and Q&A did NOT become studio tabs. Both are read where they are
written — reviews under the course page, Q&A inside the player — and a second
copy in the studio would be a second thing to keep in step. Moderation that
crosses courses has its own screen instead: `/admin/reviews`.

The sub-pages shipped as **tabs within one route**, not as nested routes. A
course editor is one task with several panels, and a URL per panel would mean a
refetch and a scroll reset every time the author moved between them. Grading is
the exception and does have its own route: it is a queue somebody works
through, not a panel of the editor.
/studio/courses/:id/grading                one queue: quizzes and assignments      ✅
/studio/grading/quiz/:attemptId            mark the open questions                 ✅
/studio/grading/assignment/:submissionId   mark, or hand back for another go       ✅
(quiz and assignment builders — reached through the curriculum item's editor
 drawer, not their own routes: both are edited in the context of the course
 they belong to)
/studio/courses/:id/assignments/:id
/studio/question-banks
/studio/earnings · /studio/payouts
/studio/analytics

/admin                             KPIs + charts
/admin/users · /admin/users/:id                                           ✅
/admin/instructors                 approval queue                         ✅
/admin/courses                     all courses, review queue
/admin/orders · /admin/orders/:uuid
/admin/coupons · /admin/products · /admin/tax
/admin/payouts
/admin/certificates · /admin/certificate-templates
/admin/reviews                     moderation                            ✅
/admin/media
/admin/analytics                   KPIs, trend, top courses, CSV           ✅
/admin/academy                     who may sign up, and the link          ✅
/admin/roles · /admin/permissions
/admin/settings/*

/platform/academies                the registry: search, filter, provision  ✅
/platform/academies/:slug          approve/reject/suspend, plan, enter/leave ✅
```

`/platform/*` is **not** `/admin/*`, and the distinction is the one this
codebase keeps making: `/admin/*` administers ONE academy from inside it, and
everything there is gated by a permission — which is a role, which lives in that
academy's schema. `/platform/*` is about academies rather than inside one, so it
is gated by `is_platform_operator` on the central user row instead. An academy
Super Admin holds every permission there is and still sees nothing here.

**Entering an academy clears the whole query cache.** `users.tenant_id` decides
which schema every other request resolves against, so after a switch every
cached answer in the client belongs to the academy the operator just left.
Nothing short of `queryClient.clear()` is correct — anything less leaves one
academy's course list on screen while the server answers for another.

**Route guards.** A `<RequirePermission permission="…" />` wrapper reads the permission
set from `GET /auth/me`. It hides UI; it is not security. Every route's data still comes
from an endpoint that authorizes independently. `<RequirePlatformOperator />` is
the same idea for the registry, reading the operator flag rather than a
permission.

---

## 3. Directory layout

```
src/
├── app/
│   ├── router.tsx            route tree, lazy boundaries, error elements
│   ├── providers.tsx         QueryClient, MantineProvider, Notifications, ModalsProvider
│   ├── theme.ts              design tokens → Mantine theme
│   └── layouts/{Public,Learn,Dashboard,Studio,Admin}Layout.tsx
├── shared/
│   ├── api/
│   │   ├── client.ts         axios instance, base URL, credentials, interceptors
│   │   ├── errors.ts         ApiError class, code→message mapping
│   │   └── types.ts          Envelope<T>, Paginated<T>, CursorPaginated<T>, ApiErrorBody
│   ├── ui/                   the design system (see DESIGN_SYSTEM.md)
│   ├── hooks/                useDebounce, useMediaQuery, usePermission, useConfirm
│   ├── lib/                  money.ts, date.ts, duration.ts, slug.ts, cn.ts
│   └── i18n/
└── features/
    ├── account/       ✅ profile, password, instructor application
    ├── admin/         ✅ users, instructor approval queue, academy settings
    ├── assignment/    ✅ authoring, the learner's pane, submission form
    ├── auth/          ✅
    ├── catalog/       ✅ public catalogue + course detail
    ├── curriculum/    ✅ the drag-and-drop builder, item editor drawer
    ├── dashboard/     ✅ continue learning, my courses
    ├── grading/       ✅ the shared queue + both grading screens
    ├── home/          ✅
    ├── learning/      ✅ the player
    ├── media/         ✅ the upload hook
    ├── quiz/          ✅ builder, runner, results
    ├── studio/        ✅ course list, course editor
    ├── system/        ✅ the health page
    ├── enrollment/    ✅ the roster panel
    ├── commerce/      ✅ cart, orders, gateway admin
    ├── certification/ ✅ certificates, public verification, templates
    ├── engagement/    ✅ reviews, Q&A, announcements, wishlist
    ├── notification/  ✅ the bell, the inbox, the preference matrix
    ├── analytics/     ✅ the academy dashboard, the course panel, the heatmap
    ├── gamification/  ✅ the badge wall, the boards, the opt-out
    ├── live/          ✅ the calendar, session cards, webinars
    └── platform/      ✅ the academy registry, the lapse banner, the
                          "you are inside no academy" banner
```

`platform/` is the only feature whose screens are not about a course, and the
only one gated by a FLAG rather than a permission — it is above the academies
rather than inside one. It also holds the two app-wide banners, which is where
the feature started: for a long time it was a Zustand store and an alert.

`notification/` is its own feature rather than a corner of `engagement/`,
mirroring the backend: engagement is a thing people DO to a course, and a
notification is a message about anything at all — a grade, a certificate, an
announcement. Folding one into the other would have put a certificate's inbox
entry inside the reviews feature.

The plan called this folder `assessment/`; it shipped as `quiz/` and
`assignment/` because the two have almost no shared UI — one is a timed runner,
the other is a form and a history. They share the `grading/` feature instead,
which is where the overlap actually was.

Every feature folder is the same shape:

```
features/<name>/
├── api/
│   ├── keys.ts        query key factory
│   ├── requests.ts    thin fns returning typed data
│   └── queries.ts     queryOptions() + useMutation hooks
├── components/
├── hooks/
├── routes/            route-level components (the only default exports)
├── schemas/           Zod schemas (forms + response parsing where it earns its keep)
└── types.ts
```

**Import rule:** a feature may import from `shared/*` and from its own folder.
Cross-feature imports go through a feature's `index.ts` barrel and are reviewed —
if two features need the same thing, it belongs in `shared/`.

---

## 4. TanStack Query usage

### Query keys — one factory per feature, never inline
```ts
// features/catalog/api/keys.ts
export const courseKeys = {
  all:      ['courses'] as const,
  lists:    () => [...courseKeys.all, 'list'] as const,
  list:     (f: CourseFilters) => [...courseKeys.lists(), f] as const,
  details:  () => [...courseKeys.all, 'detail'] as const,
  detail:   (id: CourseId) => [...courseKeys.details(), id] as const,
  curriculum: (id: CourseId) => [...courseKeys.detail(id), 'curriculum'] as const,
}
```
Invalidation targets a prefix: `queryClient.invalidateQueries({ queryKey: courseKeys.detail(id) })`
drops the detail *and* its curriculum in one call.

### queryOptions — shared between components, prefetch, and loaders
```ts
export const courseDetailQuery = (id: CourseId) =>
  queryOptions({
    queryKey: courseKeys.detail(id),
    queryFn: ({ signal }) => getCourse(id, signal),
    staleTime: 60_000,
  })
```

### Defaults
```ts
new QueryClient({ defaultOptions: { queries: {
  staleTime: 30_000,
  gcTime: 5 * 60_000,
  retry: (count, err) => !(err instanceof ApiError && err.status < 500) && count < 2,
  refetchOnWindowFocus: 'always',
}}})
```
Never retry a 4xx. Auth, permission, and validation failures are answers, not glitches.

### Cache policy by data class
| Data | staleTime | Notes |
|---|---|---|
| Categories, tags, currencies, locales | 1 h | near-static |
| Course catalogue list | 60 s | |
| Course detail / curriculum | 5 min | invalidated by builder mutations |
| Player progress | 0 | always fresh; optimistic on complete |
| Quiz attempt in progress | `Infinity` | **never refetched** — server truth is set at start |
| Cart, orders, payments | 0 | never optimistic |
| Analytics | 5 min | |

### Mutations
```ts
useMutation({
  mutationFn: reorderCurriculum,
  onMutate: async (next) => {            // safe: pure ordering
    await qc.cancelQueries({ queryKey: courseKeys.curriculum(courseId) })
    const prev = qc.getQueryData(courseKeys.curriculum(courseId))
    qc.setQueryData(courseKeys.curriculum(courseId), applyReorder(prev, next))
    return { prev }
  },
  onError: (_e, _v, ctx) => qc.setQueryData(courseKeys.curriculum(courseId), ctx!.prev),
  onSettled: () => qc.invalidateQueries({ queryKey: courseKeys.curriculum(courseId) }),
})
```

**Optimistic allowed:** reordering, rename, toggle preview, mark complete, wishlist, notes.
**Optimistic forbidden:** publish, enroll, checkout, pay, refund, grade, submit attempt.

### Infinite / cursor
`useInfiniteQuery` for discussions, activity, notifications, and the catalogue's mobile
"load more". `getNextPageParam: (last) => last.meta.next_cursor ?? undefined`.

---

## 5. Client state (Zustand — small and few)

| Store | Holds |
|---|---|
| `useUiStore` | color scheme override, sidebar collapsed, command palette open |
| `usePlayerStore` | curriculum drawer open, playback rate, volume, captions, theatre mode |
| `useBuilderStore` | active drag id, expanded section ids, unsaved-indicator flag |
| `useCartUiStore` | cart drawer open |

That is the whole list. If a candidate store holds anything that came from the server,
it is wrong — that belongs in TanStack Query.

---

## 6. The course builder (highest-priority component)

```
CurriculumBuilder
├── BuilderToolbar          add section · expand/collapse all · preview · publish · save state
├── DndContext (dnd-kit)
│   └── SortableSection[]
│       ├── SectionHeader   inline-editable title, item count, duration, menu
│       └── SortableItem[]  type icon · inline title · preview badge · drip badge · duration · menu
├── AddItemMenu             Lesson · Quiz · Assignment · Resource (· Live, P15)
├── ItemEditorDrawer        type-specific editor, opens over the tree
└── PublishChecklist        blocking + advisory validation
```

**Behaviour**
- Reorder is optimistic; the mutation is **debounced 400 ms and coalesced** — dragging
  three times sends one request with the final tree.
- A single "Saving… / Saved / Retry" indicator in the toolbar. No toast per keystroke.
- Text fields autosave on blur or after 800 ms idle, per field, with per-field error.
- Deleting a section warns only when it contains items (destructive-only confirmation).
- Keyboard: `dnd-kit`'s keyboard sensor gives Space-to-lift / arrows-to-move /
  Space-to-drop, with a live region announcing each move.
- Mobile: drag handles are explicit (not long-press on the whole row); sections collapse
  by default.
- Offline/failed save: the indicator turns into an actionable "Retry" and the local tree
  is kept — never silently discard the user's arrangement.

**Publish checklist** (blocking vs advisory) is rendered from the API's 422 `details[]`,
so the rules live on the server and the UI cannot drift from them.

---

## 7. The learning player

```
LearnLayout
├── TopBar          course title · progress ring · exit · theatre toggle
├── ContentPane     VideoPlayer | RichContent | PdfViewer | QuizRunner | AssignmentView
├── CurriculumPanel sidebar ≥ lg, drawer < lg; locked items show a reason, not a 404
├── ContextTabs     Overview · Notes · Resources · Discussion · Announcements
└── FooterNav       Previous · Mark complete · Next   (sticky on mobile)
```

- Video position posts at most once per 15 s and on pause/unmount (`sendBeacon`).
- Completion is optimistic; the progress ring and the curriculum tick update immediately.
- The quiz runner is its own focused layout: one question per page by default, a server
  authoritative countdown (client clock is display only), autosave per answer, and an
  explicit review step before submit.
- An assignment stays *inside* the player: there is no countdown and nothing to lose by
  navigating away, so the brief, the submit form and the history are one pane.
- Locked items render a `LockedItemState` explaining *why* (drip date, prerequisite,
  expired enrollment) with the next useful action.

---

## 8. Forms

- React Hook Form + `zodResolver`. The Zod schema is the source of truth for the TS type
  (`z.infer`), and it mirrors the server's Form Request rules.
- Server 422 `details[]` are mapped back onto fields via `setError`.
- Long forms are **tabs or a wizard**, never one scroll. Studio course settings is tabbed;
  course creation is a 3-step wizard (Basics → Curriculum → Pricing) with "Save & exit".

---

## 9. Performance

- Route-level `React.lazy` per surface *and* per heavy route (builder, player, analytics).
- Recharts (via `@mantine/charts`), the Tiptap editor, and the PDF viewer are lazy —
  none is in the initial bundle.
- Prefetch on intent: `onMouseEnter` of a course card prefetches the detail query; the
  player prefetches the next item's content.
- Virtualise any list that can exceed ~200 rows (students, orders, question banks).
- `<Image>` wrapper: explicit width/height, `loading="lazy"`, `decoding="async"`, blurred
  placeholder from the media record.
- Budget: initial JS ≤ 255 KB gzipped — raised from 250 in Phase 16, see
  ROADMAP; measured only by `npm run size`. LCP < 2.5 s on a mid-tier phone over 4G.

---

## 10. Testing

| Layer | Tool | Scope |
|---|---|---|
| Unit | Vitest | money/date/duration/progress helpers, Zod schemas, reducers |
| Component | Vitest + Testing Library + MSW | states (loading/empty/error), forms, permission gating |
| Critical flows | Playwright | register → login → enrol → complete lesson → take quiz → submit assignment → checkout → certificate |
| A11y | `axe` in Playwright | every layout shell + the builder and the player |
| Types | `tsc --noEmit` | CI gate |

MSW handlers are generated from the OpenAPI spec so mocks cannot drift from the contract.
