---
layout: home

hero:
  name: Record API
  text: Config-driven REST API for Laravel
  tagline: Declare your tables once in PHP. Get CRUD, bulk, upsert, RPC, audit logging and an OpenAPI spec without writing controllers or routes.
  image:
    src: /logo.svg
    alt: Record API
  actions:
    - theme: brand
      text: Get Started
      link: /guide/getting-started
    - theme: alt
      text: API Reference
      link: /reference/api
    - theme: alt
      text: GitHub
      link: https://github.com/MYLEKHATECH/laravel-record-api

features:
  - title: Endpoints from configuration
    details: Each RecordTableType in config/record.php becomes a full REST resource — list, show, create, update, delete and restore — with no per-table controller.
  - title: Filtering, search and relations
    details: PostgREST-style operators (eq, like, gt, between, …), sorting, full-text search and nested relationship loading from the query string.
  - title: Bulk and upsert
    details: Bulk create, update, delete and upsert in one request, with match_on upserts that run the same triggers and validators as normal writes.
  - title: Hooks and RPC functions
    details: Before and after triggers, custom validators, computed attributes, casts, and table or global RPC functions with their own middleware.
  - title: Audit logging
    details: A queue-based audit trail of every change, with per-table custom loggers, retention and a cleanup command.
  - title: Tenants and permissions
    details: Built-in tenant isolation, nested writes scoped to the parent and tenant, and optional Spatie Permission integration.
  - title: Any auth guard
    details: Works with whichever Laravel guard you use — Sanctum, Passport, JWT or your own.
  - title: OpenAPI out of the box
    details: A live OpenAPI spec and docs UI generated from your table configuration, plus an AI-agent friendly summary.
---

## Install in minutes

```bash
composer require mylekha/record-api
php artisan record-api:setup
php artisan vendor:publish --tag=record-api-migrations
php artisan migrate
```

Requires PHP 8.2+ and Laravel 12. Continue with the [Getting Started guide](/guide/getting-started).
