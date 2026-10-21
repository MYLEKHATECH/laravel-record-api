## mylekha/record-api

This app serves its REST API through `mylekha/record-api`: each table is a `RecordTableType` in `config/record.php` (or `config/records/tables/*.php`), and the package registers CRUD, bulk, upsert, restore and RPC routes for it. Docs: https://record-api-docs.vercel.app

- Expose a table, add validation or business logic through `RecordTableType` options and hooks, not a new controller, route or form request.
- Hooks (`beforeCreate`, `afterUpdate`, …) are static class methods registered with `new RecordTableTriggerType(class: ..., functionName: ...)`, never closures. They receive `(Request $request, string $table, array $context)`. A before-hook rejects a write by returning a `JsonResponse` (e.g. `RecordApiResponseService::errorWrapped('...', 422)`); throwing or `abort()` gives a 500.
- Validators (`createValidator`, `updateValidator`, `deleteValidator`) may be closures returning a `Validator`.
- A new non-CRUD action is a `RecordFunctionType` (RPC) in config; return responses through `RecordApiResponseService` so they keep the `{success, error_code, data, meta}` envelope.
- Writes run through the query builder, not Eloquent: model events, observers and Eloquent casts do not fire for API writes.
- The live spec at `/{api_prefix}/docs/openapi.json` (and `/docs/llms.txt`) lists every table, field and operation; read it instead of guessing names.
- Use the `record-api` skill for backend configuration and the `record-api-frontend` skill when calling the API.
