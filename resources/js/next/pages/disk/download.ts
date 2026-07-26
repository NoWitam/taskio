// Shared "download this file" helper for the disk surfaces (tile kebab + preview top bar).
//
// The binary can only be served WITH the workspace/auth headers the api client sends — a bare
// <a href> navigation cannot — so the bytes are blob-fetched and handed to a temporary
// same-origin object-URL anchor (the pattern proven in the old file drawer / TaskAttachments).
import { api } from '../../app/lib/api';
import type { DiskFile } from './types';

export async function downloadFile(file: Pick<DiskFile, 'path' | 'name'>): Promise<void> {
  let url: string | null = null;
  try {
    const blob = await api.get<Blob>(file.path, { responseType: 'blob' });
    url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    document.body.appendChild(link);
    link.click();
    link.remove();
  } finally {
    if (url) setTimeout((u: string) => URL.revokeObjectURL(u), 10_000, url);
  }
}
