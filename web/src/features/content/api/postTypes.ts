/**
 * A blog post's wire shape. Source of truth: docs/BLOG.md.
 *
 * One shape for the stranger and the author, like the server's one resource:
 * the authoring keys are OPTIONAL because the public endpoints never send them,
 * and `AdminPost` is the same post with them present.
 */

export type PostStatus = 'draft' | 'published';

export interface PostAuthor {
  name: string;
}

export interface PostListItem {
  id: string;
  slug: string;
  title: string;
  excerpt: string | null;
  cover_url: string | null;
  author?: PostAuthor;
  published_at: string | null;
  reading_minutes: number;
}

export interface Post extends PostListItem {
  /** Sanitised by the server on write — safe to render as HTML. */
  body: string | null;
  seo_title: string | null;
  seo_description: string | null;
}

/** What the people who write the blog see beside the post. */
export interface PostAuthoring {
  status: PostStatus;
  status_label: string;
  /** Published, with a time still ahead. */
  is_scheduled: boolean;
  updated_at: string;
}

export type AdminPostListItem = PostListItem & PostAuthoring;

export type AdminPost = Post &
  PostAuthoring & {
    /** False once the post has ever been out: its address is where links point. */
    can_edit_slug: boolean;
    cover_media_ref: number | null;
    created_at: string;
  };
