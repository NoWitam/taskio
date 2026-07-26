// Map a backend FileType (app/modules/Disk/Enums/FileType.php) to a `next` Icon
// glyph for the browser tiles + drawer. Unknown / 'another' falls back to a
// generic document glyph.
import type { IconName } from '../../ui/primitives/icons';

const FILE_TYPE_ICON: Record<string, IconName> = {
  image: 'image',
  video: 'film',
  audio: 'music',
  text: 'file-text',
  document: 'file-text',
  spreadsheet: 'table',
  archive: 'archive',
  another: 'file-text',
};

export function fileTypeIcon(type: string | null | undefined): IconName {
  return (type && FILE_TYPE_ICON[type]) || 'file-text';
}

/** Whether a file renders as an inline image thumbnail (by FileType or mime). */
export function isImageFile(type: string | null | undefined, mime?: string | null): boolean {
  return type === 'image' || (mime?.startsWith('image/') ?? false);
}

/** Whether a file's content is plain text we can preview as a snippet thumbnail. */
export function isTextFile(type: string | null | undefined, mime?: string | null): boolean {
  return type === 'text' || (mime?.startsWith('text/') ?? false);
}

/** Whether a file is a PDF we can show a server-rendered page thumbnail for (by FileType or mime). */
export function isPdfFile(type: string | null | undefined, mime?: string | null): boolean {
  return type === 'pdf' || mime === 'application/pdf';
}
