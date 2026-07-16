# Backend API: Filter Tabs (Saved Views)

Module: `app/modules/FilterTabs/`
Auth: all endpoints require `auth:sanctum`.

---

## Resource shape

`FilterTabResource` serializes each saved view as:

```json
{
  "id": "uuid",
  "name": "string (max 60)",
  "icon": "string | null",
  "filters": { "...": "..." },
  "sort_order": 0
}
```

- `icon` — an `IconEnum` value (e.g. `"calendar"`, `"flag"`) or `null`.
- `filters` — opaque JSON object. Shape is owned by the client; the backend validates
  only size (`<= 16 384 bytes` when JSON-encoded).
- `sort_order` — integer, 0-based, set by the backend on create and by the reorder
  endpoint. Clients must not write it directly.

All responses are wrapped in Laravel's standard `{ "data": ... }` envelope.

---

## Endpoints

### GET /api/filter-tabs

List saved views for a context.

**Query parameters**

| Param     | Required | Description                          |
|-----------|----------|--------------------------------------|
| `context` | yes      | String identifier, e.g. `"tasks"`.  |

**Response** `200 OK`

```json
{ "data": [ { "id": "...", "name": "...", "icon": null, "filters": {}, "sort_order": 0 } ] }
```

**Errors**

| Code | Field     | Message key                       |
|------|-----------|-----------------------------------|
| 422  | `context` | required validation message       |

---

### POST /api/filter-tabs

Create a saved view.

**Body** (JSON)

```json
{
  "context": "tasks",
  "name": "High priority this week",
  "icon": "flag",
  "filters": { "priority": "high", "date_preset": "this_week" }
}
```

| Field     | Required | Constraints                                                                 |
|-----------|----------|-----------------------------------------------------------------------------|
| `context` | yes      | string, max 64                                                              |
| `name`    | yes      | string, max 60; unique per user + workspace + context                       |
| `icon`    | no       | `IconEnum` value or `null`                                                  |
| `filters` | yes      | array/object; JSON-encoded size <= 16 384 bytes                             |

**Response** `201 Created`

```json
{ "data": { "id": "uuid", "name": "High priority this week", "icon": "flag", "filters": { "priority": "high", "date_preset": "this_week" }, "sort_order": 3 } }
```

**Errors**

| Code | Field     | Message key                                  | Meaning                                     |
|------|-----------|----------------------------------------------|---------------------------------------------|
| 422  | `name`    | `filter_tabs.errors.name_taken`              | Name already exists in this context         |
| 422  | `context` | `filter_tabs.errors.limit_reached`           | 30-tab limit per context reached            |
| 422  | `filters` | `filter_tabs.errors.filters_too_large`       | Serialized filters exceed 16 384 bytes      |
| 403  | —         | —                                            | Not authenticated                           |

---

### PUT /api/filter-tabs/{id}

Update a saved view (patch semantics — all fields optional).

**Body** (JSON)

```json
{
  "name": "Critical this week",
  "icon": "alert-triangle",
  "filters": { "priority": "critical", "date_preset": "this_week" }
}
```

| Field     | Required | Constraints                                                    |
|-----------|----------|----------------------------------------------------------------|
| `name`    | no       | string, max 60; unique (ignores the current tab's own id)      |
| `icon`    | no       | `IconEnum` value or `null` (send `null` to clear the icon)     |
| `filters` | no       | array/object; JSON-encoded size <= 16 384 bytes                |

When a field is absent from the body it is NOT updated. To explicitly clear `icon`,
send `"icon": null`.

**Response** `200 OK` — the updated `FilterTabResource`.

**Errors** — same 422 codes as POST. Additionally:

| Code | —    | Meaning                                      |
|------|------|----------------------------------------------|
| 403  | —    | Authenticated user does not own this tab     |
| 404  | —    | Tab not found                                |

---

### DELETE /api/filter-tabs/{id}

Delete a saved view.

**Response** `204 No Content`

**Errors**

| Code | Meaning                                      |
|------|----------------------------------------------|
| 403  | Authenticated user does not own this tab     |
| 404  | Tab not found                                |

---

### PUT /api/filter-tabs/reorder

Reorder saved views within a context. Accepts the full ordered list of IDs for
that context. The backend assigns `sort_order` 0..N-1 in the submitted order.

**Body** (JSON)

```json
{
  "context": "tasks",
  "ids": ["uuid-b", "uuid-a", "uuid-c"]
}
```

| Field     | Required | Constraints                                          |
|-----------|----------|------------------------------------------------------|
| `context` | yes      | string                                               |
| `ids`     | yes      | array of UUIDs; must match the exact set of the user's tabs for this context |

**Response** `200 OK` — full `FilterTabResource[]` in the new order.

**Errors**

| Code | Field | Message key                                    | Meaning                                |
|------|-------|------------------------------------------------|----------------------------------------|
| 422  | `ids` | `filter_tabs.errors.invalid_reorder_set`       | IDs do not match the stored tab set    |

---

## Authorization

All write operations delegate to `FilterTabPolicy`:

- `create` — any authenticated user.
- `update` / `delete` — the tab's `user_id` must match the authenticated user.

The `reorder` endpoint uses the `create` gate (workspace membership check) and
additionally validates that the submitted IDs all belong to the authenticated user
in the given context.

---

## Limits

| Constraint                   | Value    |
|------------------------------|----------|
| Max tabs per user per context | 30       |
| Max `filters` payload        | 16 384 bytes (JSON-encoded) |
| Max `name` length            | 60 characters |
| Max `context` length         | 64 characters |

---

## 422 error key reference

The backend returns i18n keys as validation messages. Callers must map them
through `t()` — never display the raw key.

| Key                                       | Trigger                                  |
|-------------------------------------------|------------------------------------------|
| `filter_tabs.errors.name_taken`           | Duplicate name in the same context       |
| `filter_tabs.errors.limit_reached`        | 30-tab limit exceeded                    |
| `filter_tabs.errors.filters_too_large`    | Filters JSON > 16 384 bytes              |
| `filter_tabs.errors.invalid_reorder_set`  | IDs mismatch on reorder                  |

---

## Migration / persistence notes

- Primary key: UUID (`HasUuids`).
- Tenant scope: `TenantAware` trait + `WorkspaceScope` — all queries are
  automatically scoped to the current workspace.
- Table: `filter_tabs` with columns `id`, `user_id`, `context`, `name`, `icon`,
  `filters` (JSON cast), `sort_order`, `created_at`, `updated_at`.
