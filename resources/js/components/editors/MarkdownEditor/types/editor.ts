import type { JSONContent } from '@tiptap/core';

export const DATA_VERSION = 1 as const;

export type VariablePrimitive = 'text' | 'number' | 'boolean';

export interface MentionNodeAttrs {
  id: string;
  name: string;
  avatar?: string;
}

export interface MentionUser {
  id: string;
  name: string;
  avatar?: string;
}

export interface MentionFeatureConfig {
  enabled: boolean;
  users: MentionUser[];
  trigger?: string;
}

export interface VariableDefinition {
  id: string;
  name: string;
  type: VariablePrimitive;
}

export type VariableOperationArgumentType = VariablePrimitive | 'select';

export interface VariableOperationArgumentDefinition {
  id: string;
  label: string;
  type: VariableOperationArgumentType;
  placeholder?: string;
  options?: Array<{ label: string; value: string }>;
  defaultValue?: string | number | boolean;
}

export interface VariableOperationDefinition {
  id: string;
  label: string;
  description?: string;
  inputTypes: VariablePrimitive[];
  outputType: VariablePrimitive;
  args?: VariableOperationArgumentDefinition[];
}

export interface VariablePipelineStep {
  stepId: string;
  operationId: string;
  args: Record<string, string | number | boolean>;
  outputType: VariablePrimitive;
}

export interface VariableState {
  id: string;
  name: string;
  type: VariablePrimitive;
  locked: boolean;
  pipeline: VariablePipelineStep[];
  resultType: VariablePrimitive;
}

export interface VariableFeatureConfig {
  enabled: boolean;
  variables: VariableDefinition[];
  operationsCatalog: VariableOperationDefinition[];
}

export interface AiLabelOption {
  id: string;
  name: string;
  color?: string;
}

export interface AiTextState {
  id: string;
  personaId: string | null;
  prompt: string; // serialized markdown obeying same dialect
  labels: string[];
}

export interface AiTextFeatureConfig {
  enabled: boolean;
  personas?: Array<{ id: string; label: string }>;
  labelsEnabled: boolean;
  labelsCatalog?: AiLabelOption[];
}

export type IfBranchKind = 'if' | 'else-if' | 'else';

export interface IfConditionState {
  variableId: string;
  pipeline: VariablePipelineStep[];
  resultType: 'boolean';
}

export interface IfBranchState {
  id: string;
  kind: IfBranchKind;
  condition?: IfConditionState;
  content: JSONContent;
}

export interface IfBlockState {
  id: string;
  branches: IfBranchState[];
}

export interface IfBlockFeatureConfig {
  enabled: boolean;
  maxElseIf?: number;
}

export interface AiTextNodeAttrs extends AiTextState {}

export interface VariableNodeAttrs extends VariableState {}

export interface IfBlockNodeAttrs extends IfBlockState {}

export interface MarkdownFeatureConfig {
  headings: Array<1 | 2 | 3>;
  links: boolean;
  lists: boolean;
  bold: boolean;
  italic: boolean;
  underline: boolean;
}

export interface EditorFeaturesConfig {
  markdown: MarkdownFeatureConfig;
  mentions?: MentionFeatureConfig;
  variables?: VariableFeatureConfig;
  ifBlock?: IfBlockFeatureConfig;
  aiText?: AiTextFeatureConfig;
}

export interface EditorConfig {
  features: EditorFeaturesConfig;
}

export interface VersionedPayload<T> {
  v: number;
  data: T;
}

export interface SerializedDirective<T> {
  type: 'mention' | 'variable' | 'ai-text';
  payload: VersionedPayload<T>;
}

export interface SerializedIfBlock {
  type: 'if-block';
  payload: VersionedPayload<IfBlockState>;
}

export interface MarkdownEditorChangeMeta {
  doc: JSONContent;
  dirty: boolean;
}
