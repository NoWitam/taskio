import { Extension } from '@tiptap/core';
import type { EditorConfig } from '../types/editor';

export const ConfigExtension = Extension.create({
  name: 'markdownEditorConfig',

  addStorage() {
    return {
      config: null as EditorConfig | null,
    };
  },
});
