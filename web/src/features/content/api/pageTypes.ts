import type { CourseListItem } from '@/features/catalog/api/types';
import type { Webinar } from '@/features/live/api/types';

import type { PostListItem } from './postTypes';

/**
 * A page's wire shape. Source of truth: docs/PAGES.md.
 *
 * A block is `{id, type, props}` plus `data` where it points at something —
 * resolved by the server with the public scopes, so a course that went back
 * to draft is simply not in it. The builder edits `props`; the renderer reads
 * `props` and `data`. One shape for both, so the preview cannot drift from the
 * public page.
 */

export type BlockType =
  'heading' | 'text' | 'image' | 'button' | 'courses' | 'webinars' | 'posts' | 'lead_form';

export interface HeadingProps {
  text: string;
  level: 2 | 3;
}

export interface TextProps {
  /** Sanitised by the server on save — safe to render as HTML. */
  html: string;
}

export interface ImageProps {
  /** Numeric media ref, from an upload into the course-cover collection. */
  media_ref: number;
  alt: string | null;
  caption: string | null;
}

export interface ButtonProps {
  label: string;
  /** `https://…`, `http://…`, or a path on this site starting with `/`. */
  url: string;
}

export interface CoursesProps {
  title: string | null;
  course_ids: string[];
}

export interface ListProps {
  title: string | null;
  limit: number;
}

export interface LeadFormProps {
  title: string | null;
  description: string | null;
}

export type PageBlock =
  | { id: string; type: 'heading'; props: HeadingProps }
  | { id: string; type: 'text'; props: TextProps }
  | { id: string; type: 'image'; props: ImageProps; data?: { url: string | null } }
  | { id: string; type: 'button'; props: ButtonProps }
  | { id: string; type: 'courses'; props: CoursesProps; data?: CourseListItem[] }
  | { id: string; type: 'webinars'; props: ListProps; data?: Webinar[] }
  | { id: string; type: 'posts'; props: ListProps; data?: PostListItem[] }
  | { id: string; type: 'lead_form'; props: LeadFormProps };

export interface PublicPage {
  id: string;
  slug: string;
  title: string;
  blocks: PageBlock[];
  seo_title: string | null;
  seo_description: string | null;
}

export type PageStatus = 'draft' | 'published';

export interface AdminPage extends PublicPage {
  status: PageStatus;
  status_label: string;
  published_at: string | null;
  is_home: boolean;
  show_in_nav: boolean;
  /** False once the page has ever been published. */
  can_edit_slug: boolean;
  created_at: string;
  updated_at: string;
}

export interface AdminPageListItem {
  id: string;
  slug: string;
  title: string;
  status: PageStatus;
  status_label: string;
  is_home: boolean;
  show_in_nav: boolean;
  block_count: number;
  published_at: string | null;
  updated_at: string;
}

export interface NavLink {
  slug: string;
  title: string;
}
