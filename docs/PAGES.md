# PAGES.md — The page builder (O2)

An academy builds pages for its public site out of blocks — a heading, some
text, an image, a button, a grid of chosen courses, the next few events, the
latest posts, a stay-in-touch form — and publishes them at
`/a/{academy}/p/{slug}`. One page may be chosen as the site's FRONT page,
replacing the standard one at `/a/{academy}`, and pages can be linked from the
site's header.

It is the rest of the `Content` context, after leads (`LEADS.md`) and the blog
(`BLOG.md`), and the authorable half of O3's "landing page": the fixed front
page is still there for an academy that never builds one.

Code: `app/Domain/Content/{Models/Page, Enums/BlockType, Support/PageBlocks,
Queries/PageRenderer, Actions/{CreatePage, UpdatePage, SavePageBlocks,
ChangePageStatus, SetHomePage, DeletePage}}`, `Content\PageController`,
`Content\PageStatusController`, `PublicSite\PageController`,
`web/src/features/content/routes/{PagesRoute, PageBuilderRoute}`,
`web/src/features/publicsite/{components/PageBlocks, routes/PublicPageRoute}`.

---

## 1. Who builds, who reads

| | |
|---|---|
| **Builds** | Academy staff holding `page.manage` — Admin and Super Admin |
| **Reads a published page** | Anybody on the internet |

The public site is the academy's front door, so building it is academy-wide,
like the blog.

---

## 2. Blocks

A page is `{title, slug, blocks, …}`, and `blocks` is an ordered list:

```json
[
  { "id": "7f3c…", "type": "heading", "props": { "text": "About the school", "level": 2 } },
  { "id": "a21e…", "type": "courses", "props": { "title": "Start here", "course_ids": ["0192…", "0193…"] } }
]
```

- **A closed set of types** (`BlockType`). There is no custom-HTML block and no
  embed: a page is edited through the API and rendered to strangers, so every
  type is one whose props can be validated and whose output the SPA renders
  itself. An UNKNOWN type is refused on save, never stored and skipped
  (§ Patterns established in Phase 14).
- **The WHOLE list is saved every time** (`PUT pages/{uuid}/blocks`). There is
  no "move block 3" endpoint for two editors to interleave into a page neither
  built (§ Patterns established in Phase 5).
- **Stored as JSON on the page, not a table of blocks.** Nothing queries into a
  block, the list is rewritten whole on every save anyway, and a `position`
  column would be a second ordering to keep consistent. `DATABASE.md` §12 notes
  the difference from the original sketch.
- **Normalised on the way in.** Props a type does not declare are dropped,
  numbers become numbers, and text HTML is sanitised (`RichTextSanitizer`), so
  what is stored is exactly what the renderer expects.
- **At most 50 blocks.**

| Type | Props | Rules worth knowing |
|---|---|---|
| `heading` | `text`, `level` (2 or 3) | |
| `text` | `html` | Sanitised on write — the lesson allowlist |
| `image` | `media_ref`, `alt`, `caption` | The file must be the saver's own when ADDED (below); course-cover collection only |
| `button` | `label`, `url` | `https://`, `http://` or a site path starting `/`. Never `javascript:`, `data:`, or a `//host` link that reads like a path and leaves the site |
| `courses` | `title`, `course_ids` (≤12) | Resolved at render with `Course::live()` |
| `webinars` | `title`, `limit` (1–6) | The next published events with a session still ahead |
| `posts` | `title`, `limit` (1–6) | The latest live posts |
| `lead_form` | `title`, `description` | The lead form, with source `site` (`LEADS.md`) |

**An image is checked against its owner only when it is added.** The list is
saved whole, so checking every image on every save would refuse an admin
re-saving a page because a colleague put a picture on it last week — the file
is the colleague's, and it was checked when the colleague added it. A reference
already on the stored page is trusted; a new one must be the saver's own file.

---

## 3. Rendering

`PageRenderer` returns each block with `data` added where it points at
something — course cards (the catalogue's own `CourseListResource`), the
image's public address, posts, events. It resolves with the PUBLIC scopes, so
the query is the boundary (`ROLES_PERMISSIONS.md` §6a): a course that went back
to draft, a post not yet out and a cancelled event are simply absent, and a page
built last month never shows a stranger what is no longer public.

It runs ONE query per block type, never per block.

The builder's preview and the public page render through the same component,
`PageBlocks.tsx`, from the same shape — the preview cannot drift from what a
visitor sees. The preview shows the SAVED page.

A block whose references all resolved to nothing renders nothing, rather than
an empty frame.

---

## 4. The front page, the header, the address

- **One front page, as a constraint.** `pages.home_key` is `'home'` on at most
  one row, enforced by a UNIQUE index — `SetHomePage` clears the old one and
  sets the new one in a transaction, and two people choosing at the same moment
  cannot both win (409 `page_home_conflict`). A DRAFT may be chosen: the site
  keeps its standard front page until the chosen one is published, which is how
  an academy builds a new front page without taking the old one down.
- **The header** links published pages with `show_in_nav`, oldest first, up to
  eight — a header holds a handful of links.
- **The address** is `/a/{academy}/p/{slug}`. The static `p/` means a page slug
  can never collide with `blog`, `courses` or `webinars`. It locks at the first
  publication and stays locked, like a blog post's.
- **Client-rendered**, by the decision in `BLOG.md` §5. Pages set their own
  title, description and `og:title`.

---

## 5. Endpoints

### The academy's side — `/api/v1/admin` (`page.manage`; writes behind `subscription`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `pages?status=&page=` | No blocks in the list |
| `GET` | `pages/{uuid}` | The page with RENDERED blocks and the authoring keys |
| `POST` | `pages` | `{title, slug?}` — a draft with no blocks |
| `PATCH` | `pages/{uuid}` | `{title, slug, show_in_nav, seo_title, seo_description}` |
| `PUT` | `pages/{uuid}/blocks` | `{blocks: […]}` — the whole list |
| `POST` | `pages/{uuid}/publish` | 422 `page_not_publishable` with no blocks |
| `POST` | `pages/{uuid}/unpublish` | |
| `POST` / `DELETE` | `pages/{uuid}/home` | Make, or stop being, the front page |
| `DELETE` | `pages/{uuid}` | |

### The stranger's side — `/api/v1/public/{academy}`

| Method | Path | Notes |
|---|---|---|
| `GET` | `pages/{slug}` | A published page, blocks rendered. A draft is the same 404 as no page |
| `GET` | `home` | The published front page, or 404 — the site then shows its standard one |
| `GET` | `navigation` | `[{slug, title}]` |

The authoring keys (`status`, `is_home`, `show_in_nav`, `can_edit_slug`, the
dates) are ABSENT on the public endpoints; `PublicPagesTest` asserts it.

---

## 6. Known limits, deliberately left

- **No custom HTML, embeds, forms or columns.** A layout block is a decision
  about responsive rendering this slice does not take.
- **No revisions and no scheduled publishing.**
- **The course picker offers the first page of the public catalogue** — public,
  published courses. An unlisted course cannot be placed on a page yet.
- **No reordering of header links** beyond creation order.
- **A page publish fires no event.** Nothing consumes one yet.
