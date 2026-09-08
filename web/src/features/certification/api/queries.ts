import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';

import { apiDelete, apiGet, apiGetRaw, apiPatch, apiPost } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';

import type { Certificate, CertificateTemplate, CertificateVerification } from './types';

export const certificationKeys = {
  all: ['certification'] as const,
  certificates: () => [...certificationKeys.all, 'certificates'] as const,
  list: (page: number) => [...certificationKeys.certificates(), 'list', page] as const,
  /** Prefix, so invalidating a list does not evict every detail entry. */
  lists: () => [...certificationKeys.certificates(), 'list'] as const,
  detail: (id: string) => [...certificationKeys.certificates(), 'detail', id] as const,
  verification: (tenant: string, token: string) =>
    [...certificationKeys.all, 'verify', tenant, token] as const,
  templates: () => [...certificationKeys.all, 'templates'] as const,
};

export const certificatesQuery = (page: number) =>
  queryOptions({
    queryKey: certificationKeys.list(page),
    queryFn: ({ signal }) =>
      apiGetRaw<Paginated<Certificate>>('/certificates', { signal, params: { page } }),
    staleTime: 30_000,
  });

export const certificateQuery = (id: string) =>
  queryOptions({
    queryKey: certificationKeys.detail(id),
    queryFn: ({ signal }) => apiGet<Certificate>(`/certificates/${id}`, { signal }),
    /*
     * Short, because `has_pdf` flips without the browser doing anything — the
     * render is queued and lands out of band, exactly like a payment webhook.
     */
    staleTime: 5_000,
  });

/**
 * The public verification lookup.
 *
 * `retry: false` on purpose: a 404 here is the ANSWER — "no such certificate"
 * — not a transient failure, and retrying it three times only makes a
 * stranger wait to be told the same thing.
 */
export const verificationQuery = (tenant: string, token: string) =>
  queryOptions({
    queryKey: certificationKeys.verification(tenant, token),
    queryFn: ({ signal }) =>
      apiGet<CertificateVerification>(`/verify/${tenant}/${token}`, { signal }),
    retry: false,
    staleTime: 5 * 60_000,
  });

export const certificateTemplatesQuery = () =>
  queryOptions({
    queryKey: certificationKeys.templates(),
    queryFn: ({ signal }) =>
      apiGet<CertificateTemplate[]>('/admin/certificate-templates', { signal }),
    staleTime: 60_000,
  });

/**
 * Fetches a short-lived signed URL for the PDF.
 *
 * A mutation rather than a query, even though it is a GET: the URL expires,
 * so caching it would hand somebody a dead link minutes later. It is fetched
 * at the moment of clicking and used immediately.
 */
export function useCertificateDownload() {
  return useMutation({
    mutationFn: (id: string) =>
      apiGet<{ url: string; expires_at: string }>(`/certificates/${id}/download`),
  });
}

export function useRevokeCertificate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      apiPost<Certificate>(`/certificates/${id}/revoke`, reason ? { reason } : {}),
    onSuccess: (certificate) => {
      queryClient.setQueryData(certificationKeys.detail(certificate.id), certificate);
      void queryClient.invalidateQueries({ queryKey: certificationKeys.lists() });
    },
  });
}

export interface TemplateInput {
  name?: string;
  orientation?: 'landscape' | 'portrait';
  background_media_id?: number | null;
  is_default?: boolean;
  is_active?: boolean;
  layout?: Partial<CertificateTemplate['layout']>;
}

export function useSaveTemplate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...input }: TemplateInput & { id?: string }) =>
      id === undefined
        ? apiPost<CertificateTemplate>('/admin/certificate-templates', input)
        : apiPatch<CertificateTemplate>(`/admin/certificate-templates/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: certificationKeys.templates() });
    },
  });
}

export function useDeleteTemplate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => apiDelete(`/admin/certificate-templates/${id}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: certificationKeys.templates() });
    },
  });
}
