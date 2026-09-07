# DESIGN_SYSTEM.md — Orbito Design System (on Mantine)

Mantine is the foundation. Orbito's design system is a **thin, opinionated layer** on top:
tokens, wrappers, and patterns. We do not fork Mantine and we do not add a second UI library.

---

## 1. Rules

1. **Tokens over values.** No hard-coded hex, px spacing, or font size in a feature component.
2. **Wrap where the wrapper makes a decision**, not by reflex. `shared/ui`
   holds the components that encode a choice (what an empty screen says, that
   every error carries a retry and a request id). A wrapper that only
   re-exports a Mantine component is an indirection with no payload — import
   `@mantine/core` directly for those. Feature code imports from `shared/ui`
   wherever a wrapper exists.
3. **One component per job.** If two components render a course card, one of them is wrong.
4. **Every state is designed**: loading, empty, error, success, disabled, locked.
5. **Dark mode is not an afterthought** — every token has a light and dark value, and every
   component is reviewed in both.
6. **Accessible by default.** A component that cannot be used with a keyboard is not done.

---

## 2. Tokens

### Colour
Mantine's 10-shade scale. Semantic names, never raw colours in code.

| Token | Role |
|---|---|
| `primary` | brand; indigo/violet family, index 6 as the base |
| `success` | completion, paid, passed |
| `warning` | pending review, expiring, unsaved |
| `danger` | destructive, failed, rejected |
| `info` | neutral informational |
| `neutral` | gray scale for text, borders, surfaces |

Surface tokens (resolved per scheme): `bg.canvas`, `bg.surface`, `bg.raised`, `bg.sunken`,
`border.default`, `border.strong`, `text.primary`, `text.secondary`, `text.muted`,
`text.inverse`.

Domain accents (used for item-type icons and status dots, and nowhere else):
`lesson` blue · `quiz` violet · `assignment` orange · `resource` teal · `live` red.

**Contrast:** body text ≥ 4.5:1, large text and UI borders ≥ 3:1, in both schemes.
Colour never carries meaning alone — always pair with an icon or a label.

### Typography
System stack (`-apple-system, Segoe UI, Roboto, …`) with a Bengali-capable fallback
(`Noto Sans Bengali`) — the platform ships bilingual, so the type stack must too.

| Token | Size / line-height | Use |
|---|---|---|
| `display` | 40 / 1.15 | marketing hero only |
| `h1` | 30 / 1.2 | page title |
| `h2` | 24 / 1.25 | section |
| `h3` | 20 / 1.3 | card title |
| `body-lg` | 16 / 1.6 | lesson content |
| `body` | 14 / 1.55 | default UI |
| `body-sm` | 13 / 1.5 | secondary |
| `caption` | 12 / 1.4 | meta, timestamps |

Weights: 400 / 500 / 600 / 700. Lesson body copy is `body-lg` with a `68ch` max width.

### Spacing, radius, shadow, motion
- Spacing scale (4 px base): `xs 4 · sm 8 · md 16 · lg 24 · xl 32 · 2xl 48 · 3xl 64`.
- Radius: `xs 4 · sm 6 · md 8 · lg 12 · xl 16 · full`. Cards `md`, buttons `sm`, modals `lg`.
- Shadow: `xs` hover, `sm` cards, `md` dropdowns, `lg` modals/drawers. Dark mode uses
  a raised surface colour plus a border rather than heavy shadow.
- Motion: `fast 120ms · base 200ms · slow 320ms`, `ease-out` in, `ease-in` out.
  All of it disabled under `prefers-reduced-motion`.

### Breakpoints (Mantine defaults, declared in `postcss.config.cjs`)
`xs 36em · sm 48em · md 62em · lg 75em · xl 88em`.
Design mobile-first: the 360 px layout is the primary target, not a fallback.

---

## 3. Component inventory (`shared/ui`)

> **What actually exists at Phase 8 is five components**: `EmptyState`,
> `ErrorState`, `LoadingState`, `PageHeader`, `ThemeToggle` — the four that
> encode a *decision* (what an empty screen says, that an error carries a retry
> and a request id, that a loading state matches the layout it replaces) plus
> the theme switch. Everything else on this list is either used straight from
> `@mantine/core` or lives in the feature that needed it.
>
> That was not the original plan, and it is worth being clear about why it held.
> A wrapper that only re-exports a Mantine component adds an indirection and a
> file to keep in step, and buys nothing: Mantine's own props are already the
> design system. Rule 2 below — *wrap, don't rebuild* — therefore reads in
> practice as **wrap where the wrapper makes a decision**. Domain components
> (`CourseCard`, `CurriculumTree`, `QuestionInput`) belong to their feature,
> not to `shared/ui`, because they are not shared.
>
> The list below stays as the intended shape for anything that does get built.

**Primitives** — `Button`, `IconButton`, `Link`, `Badge`, `Chip`, `Avatar`, `AvatarGroup`,
`Tag`, `StatusDot`, `Divider`, `Kbd`, `Tooltip`.

**Forms** — `TextField`, `TextArea`, `NumberField`, `MoneyField` (currency-aware, minor
units), `Select`, `MultiSelect`, `Combobox`, `DatePicker`, `DateTimePicker`,
`DurationField`, `Switch`, `Checkbox`, `RadioGroup`, `Slider`, `ColorField`,
`RichTextEditor` (Tiptap, lazy), `FileUpload` (dropzone + progress + retry),
`MediaPicker` (library + upload in one modal), `FormSection`, `FormActions`.

**Data display** — `DataTable` (sort, filter, select, bulk actions, sticky header,
virtualised over 200 rows), `DescriptionList`, `StatCard`, `ProgressRing`, `ProgressBar`,
`RatingStars`, `Timeline`, `Chart` wrappers over `@mantine/charts`.

**Feedback** — `Skeleton` variants per layout (card / row / player), `EmptyState`
(illustration + one-line explanation + primary action), `ErrorState` (message + Retry +
request id), `LockedState` (why + next action), `Toast` (via `@mantine/notifications`),
`ConfirmDialog` (via `@mantine/modals`, **destructive actions only**), `InlineAlert`.

**Navigation** — `AppShellSidebar`, `TopBar`, `Breadcrumbs`, `Tabs`, `Pagination`,
`CommandPalette` (`@mantine/spotlight`, `Cmd/Ctrl+K`), `PageHeader` (title + breadcrumbs +
actions), `StepIndicator`.

**Overlays** — `Drawer` (the default for secondary flows), `Modal` (confirmations and
short forms only), `Popover`, `Menu`, `Sheet` (mobile bottom sheet).

**Domain** — `CourseCard`, `CourseListItem`, `InstructorCard`, `CurriculumTree`,
`ItemTypeIcon`, `VideoPlayer` shell, `QuizQuestionRenderer`, `AnswerInput` (one per
question type), `SubmissionCard`, `CertificatePreview`, `PriceTag`, `EnrollButton`,
`ProgressSummary`, `ReviewCard`, `DiscussionThread`.

---

## 4. Interaction patterns

| Situation | Pattern |
|---|---|
| Secondary create/edit flow | **Drawer** from the right (desktop) / bottom sheet (mobile) |
| Destructive action | Modal confirm naming the object: "Delete section *Introduction*?" |
| Non-destructive action | Just do it. Undo toast where feasible. **No confirmation.** |
| Rename | Inline edit — click the text, Enter commits, Escape cancels |
| Reorder | Drag with an explicit handle + keyboard alternative |
| Long form | Tabs or a wizard, never one long scroll |
| Search | `Cmd/Ctrl+K` palette (courses, students, orders, settings, actions) |
| Bulk operations | Row selection → sticky action bar at the bottom of the table |
| Filters | Chips above the table, mirrored into the URL query string (shareable) |
| Saving | One status indicator per surface, not a toast per field |
| Errors | Inline at the field for 422; `ErrorState` in-place for a failed section; toast only for background failures |
| Loading | Skeletons shaped like the content. Spinners only for < 500 ms in-button waits |

**Never:** a modal that opens a modal · a confirmation for a reversible action ·
a full page reload · a table where a card list reads better on mobile · a toast for
something the user can already see happened.

---

## 5. Accessibility baseline

- WCAG 2.1 AA is the target.
- Every interactive element is reachable and operable by keyboard; `:focus-visible` is
  never removed.
- Modals and drawers trap focus and restore it on close; Escape closes.
- Every icon-only button has an `aria-label`.
- Forms: real `<label>`s, `aria-describedby` for help and error text, `aria-invalid`.
- Live regions announce async results (save state, drag moves, quiz timer at 5 min / 1 min).
- Video: captions supported, keyboard controls, no autoplay with sound.
- Colour is never the only signal; motion respects `prefers-reduced-motion`.
- Skip-to-content link on every shell.
- Target size ≥ 44 × 44 px on touch.

---

## 6. Theming

```ts
// app/theme.ts
export const theme = createTheme({
  primaryColor: 'orbito',
  primaryShade: { light: 6, dark: 8 },
  colors: { orbito: [...], success: [...], warning: [...], danger: [...] },
  defaultRadius: 'md',
  fontFamily: '…',
  headings: { fontFamily: '…', sizes: { h1: {...}, h2: {...} } },
  components: {
    Button: Button.extend({ defaultProps: { radius: 'sm' } }),
    Card:   Card.extend({ defaultProps: { withBorder: true, radius: 'md', padding: 'lg' } }),
    Modal:  Modal.extend({ defaultProps: { centered: true, radius: 'lg' } }),
  },
})
```

Colour scheme: `light | dark | auto`, defaulting to `auto`. Stored per user on the server
(`users.preferences`) so it follows them across devices, with `localStorage` as the
pre-hydration hint. `<ColorSchemeScript>` prevents the flash.

RTL (P16) via `dir="rtl"` and logical CSS properties — use `margin-inline-start`, not
`margin-left`, from the start.

---

## 7. Empty states — write them like a person

Every empty state has: an illustration or icon, one sentence of what belongs here, and
one primary action. Examples:

| Where | Copy | Action |
|---|---|---|
| Studio, no courses | "You haven't created a course yet." | **Create your first course** |
| Curriculum, no sections | "A course needs at least one section." | **Add a section** |
| Dashboard, no enrolments | "You're not enrolled in anything yet." | **Browse courses** |
| Grading queue, empty | "Nothing to grade — you're all caught up." | — |
| Catalogue, no results | "No courses match these filters." | **Clear filters** |

No "No data available." No empty table with a header and nothing under it.
