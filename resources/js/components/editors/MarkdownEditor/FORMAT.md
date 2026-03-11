# MarkdownEditor format & structure

## Custom Markdown dialect

We extend CommonMark with three inline directives and one fenced container. All payloads carry `data-version: 1` and are encoded as JSON inside parentheses for inline directives or inside fenced code blocks for blocks. JSON is stringified without line breaks to simplify parsing.

### Inline directives

| Feature    | Syntax                                                                 | Notes |
|------------|------------------------------------------------------------------------|-------|
| Mention    | `@[mention]("{\"id\":\"u_1\",\"name\":\"Alice\",\"avatar\":\"/img/alice.png\",\"v\":1}")` | JSON must include at least `id`, `name`, `avatar`. Renderer rehydrates chips via config using `id`. |
| Variable   | `@[variable]("{\"id\":\"total\",\"name\":\"Order total\",\"type\":\"number\",\"locked\":false,\"pipeline\":[...],\"resultType\":\"text\",\"v\":1}")` | `pipeline` is an ordered array of `{ id, type, args }`. `resultType` reflects the last operation output. |
| AI text    | `@[ai-text]("{\"id\":\"ai_1\",\"persona\":null,\"prompt\":\"...md...\",\"labels\":[...],\"v\":1}")` | `prompt` stores Markdown with inline directives + IF blocks; it is recursively serialized. |

Directive payload escaping follows JSON-in-attribute rules: the entire JSON string is wrapped in double quotes and the string itself uses standard `\"` escaping. During parsing we detect the directive keyword, decode JSON, then feed it into TipTap nodes.

### IF block container

````markdown
```if-block {"id":"if_1","v":1}
[[IF {"variableId":"var_bool","pipeline":[...] }]]
...markdown body...
[[ELSE_IF {"variableId":"var_num","pipeline":[],"v":1}]]
...markdown body...
[[ELSE]]
...markdown body...
[[/IF]]
```
````

- The outer fenced block wraps each logical IF block and carries structural metadata (id, nesting depth, etc.).
- Section markers are double-bracket directives with uppercase keywords. Each marker optionally holds JSON metadata (same escaping rules).
- Section bodies can contain full Markdown, including further IF blocks and inline directives.
- During parsing we tokenize fence → sections → bodies, recursively building child TipTap documents.

### Nesting rules

- IF blocks can be nested arbitrarily because bodies are re-parsed after each section.
- Inline directives are valid everywhere (paragraphs, headings, list items, AI prompts, IF bodies, etc.).

## Folder structure

```
resources/js/components/editors/MarkdownEditor/
├── MarkdownEditor.vue          # entry component (v-model, toolbar, panels)
├── FORMAT.md                   # this spec
├── types/
│   └── editor.ts               # EditorConfig, node state, pipeline types, schema versions
├── extensions/
│   ├── index.ts                # aggregates all extensions
│   └── nodes/
│       ├── MentionNode.ts      # TipTap node + Vue NodeView chip
│       ├── VariableNode.ts
│       ├── AiTextNode.ts
│       └── IfBlockNode.ts
├── panels/
│   ├── VariablePanel.vue       # drawer controlling pipeline + name lock
│   ├── AiTextPanel.vue         # persona, prompt editor, labels
│   └── IfPanel.vue             # JEŚLI / JEŚLI INACZEJ / INACZEJ sections
├── utils/
│   ├── serialize.ts            # doc → markdown
│   ├── parse.ts                # markdown → doc
│   └── schema.ts               # reusable helpers for TipTap schema/commands
├── __tests__/
│   └── serializer.spec.ts      # round-trip + nesting tests (Vitest)
└── index.ts                    # named exports for consumer modules
```

## Type system

`types/editor.ts` will export:

- `EditorConfig` – mirrors user props; contains `features` object with per-feature settings and datasets.
- `MentionConfig`, `VariableConfig`, `AiTextConfig`, `IfBlockConfig` – strongly typed feature subtrees.
- `MentionNodeAttrs`, `VariableState`, `VariablePipelineStep`, `VariableOperationArg`, `AiTextState`, `IfBlockState`, `IfBranchState` – runtime payloads stored on TipTap nodes & serialized to markdown.
- `SerializedDirective<T>` helper and guards for version upgrades (e.g., `DATA_VERSION = 1`).
- Utility enums / constants for variable types (`VariablePrimitive = 'text' | 'number' | 'boolean'`) and ai prompt allowances.

Versioning approach in serialization utils:

```ts
const DATA_VERSION = 1;

export interface VersionedPayload<T> {
  v: number;
  data: T;
}
```

Each directive payload becomes `{ v: DATA_VERSION, data: NodeState }`, allowing future migrations inside `parse.ts`.

## Next steps

1. Implement the TypeScript models & validators.
2. Build TipTap nodes + Vue NodeViews following this specification.
3. Wire up serialization + parsing utilities that honor DATA_VERSION.
4. Cover round-trips with Vitest tests.
