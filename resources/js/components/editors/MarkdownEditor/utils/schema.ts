import type { Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Table from '@tiptap/extension-table';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import TableRow from '@tiptap/extension-table-row';
import type { EditorConfig } from '../types/editor';
import { MentionNode, VariableNode, AiTextNode, IfBlockNode, ConfigExtension } from '../extensions';

export function createBaseExtensions(config: EditorConfig): Extension[] {
  const markdown = config.features.markdown;

  const starter = StarterKit.configure({
    heading: markdown.headings.length ? { levels: markdown.headings } : false,
    bold: markdown.bold,
    italic: markdown.italic,
    underline: false,
    bulletList: markdown.lists ? {} : false,
    orderedList: markdown.lists ? {} : false,
    link: false,
  });

  const extensions: Extension[] = [
    starter, 
    ConfigExtension, 
    MentionNode, 
    VariableNode, 
    AiTextNode, 
    IfBlockNode,
    Table.configure({
      resizable: true,
    }),
    TableRow,
    TableHeader,
    TableCell,
  ];

  if (markdown.underline) {
    extensions.push(Underline);
  }

  if (markdown.links) {
    extensions.push(Link.configure({ autolink: true, openOnClick: false }));
  }

  return extensions;
}
