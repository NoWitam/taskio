// placeholder.ts — a tiny local Placeholder extension for the "next" editor.
//
// The official `@tiptap/extension-placeholder` is a NEW dependency the project
// rules forbid, so we re-implement the minimal behavior we need with the
// ProseMirror plugin API from `@tiptap/pm` (already a dependency): when the doc
// is empty (a single empty paragraph), draw the placeholder text on that node via
// a `data-placeholder` attribute + a `.is-editor-empty` class. The actual text is
// painted by CSS (`.next-md-content .is-editor-empty::before`) so it never enters
// the document or the serialized markdown.
import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

export interface PlaceholderOptions {
  placeholder: string;
}

export const Placeholder = Extension.create<PlaceholderOptions>({
  name: 'nextPlaceholder',

  addOptions() {
    return { placeholder: '' };
  },

  addProseMirrorPlugins() {
    const options = this.options;
    const editor = this.editor;

    return [
      new Plugin({
        key: new PluginKey('nextPlaceholder'),
        props: {
          decorations: (state) => {
            if (!editor.isEditable || !options.placeholder) return null;

            const { doc } = state;
            const isEmpty =
              doc.childCount === 1 &&
              doc.firstChild?.type.name === 'paragraph' &&
              doc.firstChild.content.size === 0;

            if (!isEmpty) return null;

            const decorations: Decoration[] = [];
            doc.descendants((node, pos) => {
              if (node.type.name !== 'paragraph') return;
              decorations.push(
                Decoration.node(pos, pos + node.nodeSize, {
                  class: 'is-editor-empty',
                  'data-placeholder': options.placeholder,
                }),
              );
            });
            return DecorationSet.create(doc, decorations);
          },
        },
      }),
    ];
  },
});
