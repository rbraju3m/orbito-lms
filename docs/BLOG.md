# BLOG.md — The academy's blog (O1)

An academy writes posts in its admin area and publishes them on its public
site, at `/a/{academy}/blog`. A stranger reads them with no account; a post can
be scheduled to appear on its own; a CRM or newsletter tool can hear about each
one through the `post.published` webhook.

It is the second thing in the `Content` context, after leads (`LEADS.md`), and
the first thing an academy *authors* for the public site. The page builder (O2)
is the other half of that context and comes next.

Code: `app/Domain/Content/{Models/Post, Actions/{CreatePost, UpdatePost,
ChangePostStatus, DeletePost}, Events/PostPublished}`, `Content\PostController`,
`Content\PostStatusController`, `PublicSite\PostController`,
`web/src/features/content/routes/{PostsRoute, PostEditorRoute}`,
`web/src/features/publicsite/routes/{BlogIndexRoute, BlogPostRoute}`.

---

## 1. Who writes, who reads

| | |
|---|---|
| **Writes** | Academy staff holding `post.manage` — Admin and Super Admin |
| **Reads a draft** | The same people, at `/admin/posts` |
| **Reads a published post** | Anybody on the internet, at `/a/{academy}/blog/{slug}` |

The blog speaks for the ACADEMY on its public site, so it is academy-wide:
holding a course does not let an instructor publish in the academy's name.

---

## 2. A post's life

```
draft ──publish──────────────▶ published, live      (published_at ≤ now)
      ──publish {published_at}─▶ published, scheduled (published_at > now) ──time passes──▶ live
published ──unpublish──▶ draft   (keeps its address and its first date)
```

- **Scheduled is not a status.** It is a published post whose time is still
  ahead. `Post::published()` compares against the clock, so it appears on the
  site on its own, with nothing to sweep and nothing that can be late
  (§ Patterns established in Phase 15).
- **A published address is a promise.** The slug is editable while a post has
  never been out and locked from its first publication on — including after it
  is unpublished, because the link is out there whether or not the page
  currently is (`UpdatePostRequest`).
- **The first date sticks.** A post taken down and put back keeps the
  `published_at` it first went out with, so it does not jump to the top of the
  list. An explicit `published_at` overrides it — which is also how a post moved
  from an old blog keeps its original date.
- **A post with nothing in it cannot be published** (422 `post_not_publishable`).
- **Publishing and saving are separate acts.** The edit form never carries a
  status; publishing is its own endpoint and puts out the SAVED version.
- **Deleting is outright.** Nothing refers to a post, so there is no history to
  keep; unpublishing is how an author only hides one.

`PostPublished` fires on the transition from draft, and only if the post is
live at that moment. A scheduled post fires nothing when it is scheduled and
nothing when its time comes — an integration told "published" early would
announce a page that does not exist yet, and telling it on time needs a sweep
at the scheduled moment (§6).

---

## 3. Endpoints

### The academy's side — `/api/v1/admin` (`auth:sanctum`, `tenant`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `posts?status=&q=&page=` | Every post, drafts included, most recently edited first |
| `GET` | `posts/{uuid}` | The whole post, with the authoring keys |
| `POST` | `posts` | `{title, slug?, excerpt?, body?, cover_media_id?, seo_title?, seo_description?}` — always a draft |
| `PATCH` | `posts/{uuid}` | The same fields, partially. `slug` 422s once the post has been out |
| `POST` | `posts/{uuid}/publish` | `{published_at?}` — none means now; a future instant schedules |
| `POST` | `posts/{uuid}/unpublish` | Back to draft |
| `DELETE` | `posts/{uuid}` | 204 |

Every write sits behind `subscription`; the reads do not (§ Multi-tenancy). All
of them need `post.manage`.

### The stranger's side — `/api/v1/public/{academy}` (`tenant.public`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `posts?page=` | Live posts only, newest first, without bodies |
| `GET` | `posts/{slug}` | One live post. A draft, a scheduled post and an unknown slug are the same 404 |

A post (the authoring keys are ABSENT on the public endpoints, not false —
`PublicBlogTest` asserts it):

```json
{
  "id": "0192…", "slug": "why-watercolour", "title": "Why watercolour",
  "excerpt": "Where every beginner should start.",
  "body": "<h2>Start wet</h2><p>…</p>",
  "cover_url": "https://…/course_thumbnail/….webp",
  "author": { "name": "Nusrat Jahan" },
  "published_at": "2026-09-10T08:00:00+00:00",
  "reading_minutes": 4,
  "seo_title": null, "seo_description": null,

  "status": "published", "status_label": "Published", "is_scheduled": false,
  "can_edit_slug": false, "cover_media_ref": 42,
  "created_at": "…", "updated_at": "…"
}
```

The list shape is the same without `body`, the search fields, `can_edit_slug`,
`cover_media_ref` and `created_at`.

---

## 4. What a post is made of

- **The body is HTML, sanitised on WRITE** by `RichTextSanitizer` — the same
  allowlist as lessons: headings, paragraphs, lists, links, images, tables,
  quotes and code. Script, styles, iframes, event handlers and `javascript:`
  URLs are dropped when the post is saved, so the stored value is already safe
  for the public page, and the page renders it with no second pass. It is
  written in a plain HTML textarea, like a lesson; the editor's Preview shows
  the saved, sanitised version, exactly as a visitor will see it.
- **The cover** shares the course-cover collection with bundles and downloads —
  a public disk, images only, 5 MB — and `post.manage` may upload into it. The
  id must be the uploader's own file (`ValidatesOwnedMedia`).
- **Reading time** is words ÷ 200, at least one minute, counted by splitting on
  Unicode whitespace. `str_word_count` knows only ASCII letters and would read a
  Bengali post as nearly empty.
- **The author** is whoever created the post. The name is read from the central
  users table (§ Multi-tenancy); a deleted account reads "Former member".

---

## 5. Search engines — client-side rendering, ACCEPTED

The public site is rendered in the browser. A crawler that does not run
JavaScript fetches `/a/{academy}/blog/{slug}` and sees an empty document.

This was a decision, taken in Phase 16 before the blog was built, between three
options:

| Option | Cost | Why not now |
|---|---|---|
| Prerender public pages at request time for bots | A rendering service in front of the SPA, and a cache to invalidate on every publish | A new moving part in production before the site has any traffic to rank |
| Server-side rendering | A second rendering path for every public page — the SPA stops being the only client of the API | The largest change to the frontend's architecture since Phase 2 |
| **Accept it** | Pages may rank poorly with crawlers that run no JavaScript | Chosen. Google renders JavaScript; the site's first readers arrive by links an academy shares |

What the pages still do, because it costs nothing and serves the reader:

- Each sets its own `<title>` and `<meta name="description">`, which React 19
  hoists into the document head — the search title and description first,
  then the post's title and excerpt.
- A post sets `og:title`, `og:description` and `og:image` for crawlers and link
  previewers that do run JavaScript.

What would reopen the decision: an academy whose readers arrive by search, or a
link-preview service that matters and runs no JavaScript. The cheapest step
then is prerendering the public routes only — the members-only SPA is
unaffected either way.

---

## 6. Known limits, deliberately left

- **No categories or tags.** An academy with a handful of posts needs a list,
  not a taxonomy; `DATABASE.md` §12 still sketches both.
- **A scheduled post never fires `post.published`.** Telling integrations on
  time means a sweep at the scheduled moment — the one place a clock-derived
  state needs a job.
- **No RSS feed and no sitemap.**
- **No rich-text editor.** HTML in a textarea, like lessons; the sanitiser is
  what keeps it safe, not the editor.
- **No revisions.** Saving replaces the post.
- **No comments.** A comment box on a public page is an anonymous write, and
  would need an abuse story of its own (`LEADS.md`).
- **The blog is not linked from the front page's content**, only from the
  academy site's header.
