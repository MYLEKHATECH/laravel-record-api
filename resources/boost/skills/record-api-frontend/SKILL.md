---
name: record-api-frontend
description: Use when building a frontend, script or agent that calls a backend powered by mylekha/record-api — finding endpoints and fields in the OpenAPI spec, building CRUD, upsert, bulk and RPC requests, filters, relationship embedding, and handling responses and errors.
---

# Agent Context: record-api Frontend (API Consumer)

## What This Is

The backend exposes every configured table and function as REST endpoints, and describes all of
them in a live OpenAPI spec. The spec is the source of truth: never hardcode table names, columns
or function paths from memory.

```
GET {baseUrl}/{apiPrefix}/docs/openapi.json   the schema (apiPrefix is usually api/v1)
GET {baseUrl}/{apiPrefix}/docs/llms.txt       short agent guide for this API
```

From the spec:
- `paths`: every endpoint and method that exists. If a path or method is missing, it is not available for that table.
- `operationId`: a stable name for each operation (`listInvoices`, `createInvoices`, `getInvoices`, `updateInvoices`, `deleteInvoices`, `restoreInvoices`, `upsertInvoices`, …). Use it as the function or tool name.
- `components.schemas.<Table>Write`: the fields you may send; `<Table>Read`: the fields you get back.
- Each operation's `description`: its relationships and payload examples. `info.description`: operators and conventions.
- `parameters`: required headers, such as the tenant header on tenant-scoped tables.

---

## Making a Call

1. Find the path and method in the spec (`operationId` helps).
2. Headers:
   ```
   Authorization: Bearer {token}     on endpoints the app protects
   X-Tenant-ID: {tenantId}           only where the spec lists the tenant header (the name can differ)
   Content-Type: application/json    on POST / PUT / PATCH
   ```
3. Body: only fields from `<Table>Write`. Never send `id`, `created_at`, `updated_at`, `deleted_at`
   or the tenant column: the server sets them.
4. Read the response envelope (below) and branch on `error_code`.

Login is not part of this package: the app provides it, usually as a function such as
`POST /{apiPrefix}/auth/login`. Find it in the spec.

---

## Endpoints

```
GET    /{apiPrefix}/{table}                          list (filters, sort, pages)
GET    /{apiPrefix}/{table}/{id}                     one record
POST   /{apiPrefix}/{table}                          create
PUT    /{apiPrefix}/{table}/{id}                     update (PATCH also works; send only changed fields)
DELETE /{apiPrefix}/{table}/{id}                     delete (soft delete when the table supports it)
POST   /{apiPrefix}/{table}/{id}/restore             restore a soft-deleted record
DELETE /{apiPrefix}/{table}/{id}/force               delete permanently
POST   /{apiPrefix}/{table}/upsert?match_on=col      create or update one record
POST   /{apiPrefix}/{table}/bulk/create              create many
POST   /{apiPrefix}/{table}/bulk/update              update many (each item needs its id)
POST   /{apiPrefix}/{table}/bulk/delete              delete many
POST   /{apiPrefix}/{table}/bulk/upsert?match_on=col create or update many
POST   /{apiPrefix}/{table}/bulk                     mixed: no id = create, id + fields = update, id only = delete
```

Functions (custom actions) have their own paths, methods and parameters, listed in the spec:
`/{apiPrefix}/{table}/{functionName}` or `/{apiPrefix}/{functionName}` (the app may add an `rpc/` segment).

---

## Query Parameters (list endpoints)

### Filters

Format: `?{column}={operator}.{value}`. Several filters are combined with AND.

| Operator | Example |
|----------|---------|
| `eq`, `neq` | `?status=eq.paid` |
| `gt`, `gte`, `lt`, `lte` | `?total=gte.100` |
| `like`, `ilike`, `not_like` (`%` wildcard) | `?name=ilike.%john%` |
| `contains`, `starts_with`, `ends_with` | `?ref=starts_with.INV` |
| `regex`, `match`, `imatch` | `?ref=imatch.^inv-[0-9]+` |
| `in`, `not_in` | `?id=in.(5,6,9)` or `?status=in.draft,sent` |
| `between`, `not_between` | `?total=between.100,1000` |
| `is`, `is_not` (null checks) | `?approved_at=is.null` |
| `empty`, `not_empty` | `?notes=empty` |
| `date_eq`, `date_gt`, `date_gte`, `date_lt`, `date_lte` (date part only) | `?date=date_gte.2024-01-01` |
| negation | `?status=not.eq.void` |
| any / all | `?name=like(any).{ACME,SHOP}` |
| compare two columns | `?updated_at=compare.gt.created_at` |
| PostgreSQL only: `fts`, `plfts`, `phfts`, `wfts`, `cs`, `cd`, `ov`, `sl`, `sr`, `nxl`, `nxr`, `adj` | `?body=fts.invoice` |

Grouped logic: `?or=(balance_due.gt.0,id.eq.5)` and nested `?and=(or(balance_due.gt.0,id.eq.5),id.neq.2)`.
An operator the database does not support returns `422`.

### Search, columns, relations, sorting

```
?s=john                                      search the table's searchable columns
?select=id,name,email                        only these columns
?select=*,customer:customers(id,name)        embed a related record
?select=id,items(id,name,product:products(*))  nested embedding
?sortby=created_at&order=asc                 sort (default: primary key, desc)
```

Relationship names and their tables are in each operation's `description` in the spec.

### Pages

```
?page=2&per_page=25     meta.page, meta.per_page, meta.total (+ X-Page, X-Per-Page, X-Total-Pages, X-Total-Count headers)
?limit=50               the first 50 rows, no paging (meta.total = rows returned)
(neither)               up to the server's limit_max rows, meta.total
```

`per_page` and `limit` are capped by the server (`per_page_max`, `limit_max`; 10,000 by default).
There is no cursor pagination.

### Aggregation

```
?aggregate=count
?aggregate=count,sum:total,avg:total
?aggregate=sum:total&group_by=status
```

Functions: `count`, `count:{col}`, `sum:{col}`, `avg:{col}`, `min:{col}`, `max:{col}`.

---

## Writing Related Records

Send related records under the relationship's name, in the same body as the parent.

| Relationship | Payload | Remove |
|--------------|---------|--------|
| belongs-to | set the foreign key: `"customer_id": 42` | set it to `null` |
| has-many (child rows) | `"items": [{...new}, {"id": 3, ...changes}]` | `{"id": 3, "_delete": true}` |
| many-to-many | `"tags": [1, {"id": 2}, {"id": 3, "is_primary": true}]` | `{"id": 4, "_delete": true}` |
| has-many-through | `"modules": [1, {"id": 2}]` | `{"id": 2, "_delete": true}` |

- Has-many: an item without `id` is created; an item with `id` updates that child, but only a child this parent already owns.
- Many-to-many and has-many-through: a bare id or `{"id": …}` links an existing row; an object without `id` creates the row and links it.
  Extra pivot columns go next to the `id` (`"is_primary": true`), not in a nested object.
- Rows of another tenant are never linked or changed.

```json
{
  "customer_id": 42,
  "items": [
    { "product_id": 10, "quantity": 2 },
    { "id": 3, "quantity": 5 },
    { "id": 7, "_delete": true }
  ],
  "tags": [1, { "id": 2 }]
}
```

---

## Upsert and Bulk

`match_on` is a **query parameter** and is required. Several columns: `match_on=sku,warehouse_id`.

```
POST /{apiPrefix}/products/upsert?match_on=sku
{ "sku": "PROD-001", "name": "Widget", "price": 9.99 }
```

A match updates that row; no match creates one. Every item must contain the `match_on` columns.

Bulk endpoints take a **JSON array** of items (at most the server's `bulk_max`, 1,000 by default):

```
POST /{apiPrefix}/products/bulk/upsert?match_on=sku
[
  { "sku": "PROD-001", "price": 9.99 },
  { "sku": "PROD-002", "price": 12.50 }
]
```

`POST /{table}/bulk` also accepts `{"items": [...]}`. Bulk writes run in one transaction: one rejected
item rolls back the batch.

---

## Response Envelope

```json
{
  "success": true,
  "error_code": 0,
  "data": [ { "id": 1 } ],
  "meta": { "request_id": "uuid", "page": 1, "per_page": 25, "total": 142 }
}
```

```json
{
  "success": false,
  "error_code": 10004,
  "message": "Validation failed",
  "errors": { "email": ["The email field is required."] },
  "meta": { "request_id": "uuid" }
}
```

The key is `error_code` (snake_case), and pages use `page`, not `current_page`.
Branch on `error_code`, not on `message`, and quote `meta.request_id` when reporting a failure.

| error_code | Meaning | Usual HTTP |
|------------|---------|------------|
| 0 | success | 200 |
| 10000 | general error | 400 |
| 10001 | invalid tenant id | 400 |
| 10002 | not authenticated / invalid access | 401 |
| 10003 | invalid token | 401 |
| 10004 | validation failed | 422 |
| 10005 | invalid resource | 404 |
| 10006 | invalid permission | 403 |
| 10007 | invalid credentials | 401 |
| 10008 | permission denied | 403 |
| 10009 | not found | 404 |
| 10010 | internal server error | 500 |
| 10011 | unknown error | 500 |
| 10012 | tenant not found | 404 |
| 10013 | tenant disabled | 403 |
| 10014 | tenant has no access to this operation | 403 |
| 10015 | token expired | 401 |

---

## Rules for Agents

- Read the spec first; do not invent tables, fields or endpoints.
- Prefer one request with `select` relations over many requests.
- Confirm with the user before delete, force delete, bulk or upsert writes.
- On `401`, re-authenticate; on `403`, do not retry; on `422`, fix the fields named in `errors`.
