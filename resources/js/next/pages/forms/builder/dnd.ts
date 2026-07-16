// Shared drag-and-drop + tree context for the form builder (next).
//
// Provided once by FormBuilderView and injected by the palette + the (recursive)
// canvas, so a NEW element dragged from the palette and an EXISTING element
// dragged within the canvas share one drag state, and any canvas level can reach
// the root tree / create localized elements / drive selection.
import type { InjectionKey } from 'vue';
import type { FormElement, FormElementType } from '../types';

/** What is currently being dragged (a new palette type OR an existing element). */
export interface BuilderDragState {
  type: FormElementType | null;
  id: string | null;
}

export interface BuilderContext {
  dnd: BuilderDragState;
  /** Begin dragging a NEW element of `type` from the palette. */
  startNew(type: FormElementType): void;
  /** Begin dragging an EXISTING element by id (reorder). */
  startMove(id: string): void;
  /** Clear the drag state (drop / dragend / cancel). */
  endDnd(): void;
  /** Create a new element with a localized default label. */
  create(type: FormElementType): FormElement;
  /** The root element list (for cross-cutting lookups). */
  root(): FormElement[];
  selectedId(): string | null;
  select(id: string): void;
}

export const BUILDER_CTX: InjectionKey<BuilderContext> = Symbol('builder-ctx');
