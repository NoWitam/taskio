/**
 * Types dla MarkdownWysiwygEditor
 */

export type FeatureToggle = boolean;

export interface MentionUser {
  id: string;
  name: string;
  avatarUrl?: string;
}

export type VariableType = 'text' | 'number' | 'date' | 'boolean';

export interface VariableDef {
  id: string;
  name: string;
  type: VariableType;
  value?: any;
  meta?: any;
}

export interface AiBot {
  id: string;
  name: string;
  description?: string;
}

export interface KnowledgeTag {
  id: string;
  name: string;
}

export interface DiskAsset {
  id: string;
  name: string;
  url: string;
  type: 'image' | 'file';
}

export interface ImageSources {
  allowUpload?: boolean;
  allowDisk?: boolean;
}

export interface EditorConfig {
  // Bazowe markdown
  headings?: FeatureToggle;
  underline?: FeatureToggle;
  lists?: FeatureToggle;
  blockquote?: FeatureToggle;
  code?: FeatureToggle;
  links?: FeatureToggle;
  images?: FeatureToggle;

  // Custom features
  mentions?: FeatureToggle;
  variables?: FeatureToggle;
  aiText?: FeatureToggle;
  conditionBlocks?: FeatureToggle;

  // Configurations
  mentionUsers?: MentionUser[];
  variablesList?: VariableDef[];
  aiBots?: AiBot[];
  knowledgeTags?: KnowledgeTag[];
  diskAssets?: DiskAsset[];
  imageSources?: ImageSources;
  maxAiNesting?: number; // default 3

  // Placeholder i disabled state
  placeholder?: string;
  disabled?: boolean;
}

// Mention node attrs
export interface MentionNodeAttrs {
  id: string;
  label: string;
}

// Variable node attrs
export interface VariableOpDef {
  op: string;
  args?: any[];
}

export interface VariableNodeAttrs {
  varId: string;
  ops?: VariableOpDef[];
}

// AI block node attrs
export interface AiBlockNodeAttrs {
  aiId: string;
  botId: string;
  tags?: string[];
  prompt: string;
  nestingLevel: number;
}

// Conditional block node attrs
export type ConditionalType = 'if' | 'for' | 'switch';

export interface ConditionExpr {
  expr?: string; // Dla IF/SWITCH
  varId?: string; // Dla FOR
}

export interface ConditionalBlockNodeAttrs {
  type: ConditionalType;
  condition?: ConditionExpr;
  branches?: Record<string, any>; // Dla SWITCH
}

// Variable operations registry
export type VariableOperationHandler = (value: any, args?: any[]) => any;

export interface VariableOperation {
  name: string;
  handler: VariableOperationHandler;
  description?: string;
}

// Preview modes
export type EditorMode = 'edit' | 'preview-raw' | 'preview-rendered';

// Editor emits
export interface EditorEmits {
  'update:modelValue': [value: string];
  'change': [value: string];
}
