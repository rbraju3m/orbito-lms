import { useMutation } from '@tanstack/react-query';

import { http } from '@/shared/api/client';

export interface UploadedMedia {
  /** UUID, for addressing the file itself. */
  id: string;
  /** Numeric id — every endpoint that *references* a file speaks in these. */
  ref: number;
  collection: string;
  mime: string;
  size_bytes: number;
  width: number | null;
  height: number | null;
  original_name: string;
  is_private: boolean;
  url: string;
  url_expires_at: string | null;
}

export type MediaCollection =
  | 'avatar'
  | 'course_thumbnail'
  | 'course_intro_video'
  | 'category_image'
  | 'lesson_video'
  | 'lesson_attachment'
  | 'submission'
  /** A file an academy sells. Needs `download.manage` to write into. */
  | 'download';

/**
 * Uploads through the API. The server derives the real MIME type from the
 * bytes, so the type reported here is a hint only.
 *
 * `onProgress` exists because a video upload without a progress bar looks
 * broken; the S3 presigned path in a later phase reports progress the same way.
 */
export function useUploadMedia(onProgress?: (percent: number) => void) {
  return useMutation({
    mutationFn: async ({
      file,
      collection,
    }: {
      file: File;
      collection: MediaCollection;
    }): Promise<UploadedMedia> => {
      const form = new FormData();
      form.append('collection', collection);
      form.append('file', file);

      const { data } = await http.post<{ data: UploadedMedia }>('/media', form, {
        onUploadProgress: (event) => {
          if (onProgress && event.total) {
            onProgress(Math.round((event.loaded / event.total) * 100));
          }
        },
      });

      return data.data;
    },
  });
}

/**
 * Deletes a file its owner uploaded, by UUID.
 *
 * A file taken out of a form before it is handed in must be deleted here, not
 * only dropped from the list: an unused upload counts against its owner's
 * upload quota until it is gone, and nothing else shows it to them again.
 */
export function useDeleteMedia() {
  return useMutation({
    mutationFn: async (id: string): Promise<void> => {
      await http.delete(`/media/${id}`);
    },
  });
}
