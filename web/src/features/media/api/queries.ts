import { useMutation } from '@tanstack/react-query';

import { http } from '@/shared/api/client';

export interface UploadedMedia {
  id: string;
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
  | 'submission';

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
