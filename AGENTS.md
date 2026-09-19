# Repository Guidelines

## Project Overview

BookStack is an opinionated, free, open-source documentation/wiki platform: content organized as **Shelves → Books → Chapters → Pages**, with roles/permissions, comments, tags, activity/audit logs, webhooks, search, and exports (PDF/HTML/Markdown/ZIP). PHP 8.2+ / Laravel 12 / MySQL monolith with a Blade + TypeScript/ESM frontend. REST API plus signed-URL public file access. Docs: [bookstackapp.com](https://www.bookstackapp.com), dev docs in `dev/docs/` (start at `dev/docs/development.md`).

## Architecture & Data Flow

Domain-organized monolith. Each `app/<Domain>/` owns its `Controllers/`, `Models/`, `Repos/`, `Queries/` — there is no conventional `app/Providers` or root `config/` split.

- **Controllers are thin**: constructor-injected `*Repo`/`*Service`/`*Queries` classes hold business logic. Base `app/Http/Controller.php` provides the shared helper bag: `checkPermission()`, `checkOwnablePermission()`, `setPageTitle()`, success/warning/error notification flashes, `redirectToRequest()` (same-origin guarded), `jsonError()`, `logActivity()`.
- **Permissions**: `app/Permissions/Permission.php` is a string-backed enum of every permission. Controllers call `$this->checkPermission(Permission::BookshelfCreateAll)`; routes/middleware use `Permission::X->middleware()` which renders `'can:bookshelf-create-all'`. Ownable (per-entity) checks go through `checkOwnablePermission()` → global `userCan()`. Complex joint-permission resolution lives in `Permissions/` (`JointPermissionBuilder`, `PermissionApplicator`).
- **Routing**: `app/App/Providers/RouteServiceProvider.php` (note: inside `app/App/Providers/`, not `app/Providers/`) mounts `routes/web.php` under the `web` group and `routes/api.php` under `api` + `api/` prefix. Routes are hand-written explicit `[BookShelfController::class, 'index']` arrays — no `Route::resource`. API throttling uses `Http/Middleware/ThrottleApiRequests` reading `config('api.requests_per_minute')` from **`app/Config/`** (which overrides stock Laravel config). Themes can inject additional routes via `ThemeEvents::ROUTES_REGISTER_WEB`.
- **Errors are thrown, not returned**: domain exceptions in `app/Exceptions/` (`NotifyException`, `ImageUploadException`, `SocialDriverNotConfigured`, zip import/export errors, …) are rendered by the central `BookStack\Exceptions\Handler` bound in `bootstrap/app.php`. Permission failures throw (base methods are `never`-typed). API controllers build consistent error/list JSON via `ApiController::$rules` + `apiListingResponse()` (wrapped by `Api/ListingResponseBuilder`).
- **Settings & state**: DB-backed settings via the `setting()` helper (`app/Settings/SettingService`) — e.g. `setting()->getForCurrentUser('lists-page-count-shelves', 18)`. The guest visitor is a real `User` (`User::getGuest()`); unauthenticated code paths assume a User object exists. Activity/audit, comments, tags, watches and webhooks all flow through `Activity/` services.
- **Cross-cutting**: `References/` parses cross-links between entities and updates them on moves/renames; `Search/` holds the search engine + indexing; `Exports/` covers PDF/HTML/MD rendering and the ZIP export/import pipeline; `Uploads/` holds image/attachment storage services and the signed-URL signer (`FileUrlSigner`, `signed-file` middleware); `Theming/` exposes the ThemeEvents hook system.
- **Frontend**: Blade views in `resources/views`; TypeScript/ESM (wysiwyg editor on a vendored Lexical fork, CodeMirror 6) bundled by esbuild into `public/dist`; dart-sass for CSS.

## Key Directories

| Directory | Purpose |
|---|---|
| `app/Access/` | Authentication: LDAP, OIDC, SAML2, Social(ite), MFA, login/registration + their controllers |
| `app/Activity/` | Audit log, comments, tags, watches, webhook dispatch, notifications |
| `app/Api/` | API infrastructure: token guard, docs generator, listing response builder |
| `app/App/` | App core: `Application`, `HomeController`, `MetaController`, service providers, `helpers.php`, `AppVersion` (namespace `BookStack\App` — don't confuse with PSR-4 root) |
| `app/Config/` | Overridden Laravel config files (`app.php`, `session.php`, `saml2.php`, …) — there is no root `config/` |
| `app/Console/` | Console kernel + 16 artisan commands, auto-discovered from `Commands/` via `$this->load(__DIR__.'/Commands')` |
| `app/Entities/` | Core content: Book, Chapter, Page, Bookshelf models, repos, queries, controllers, sluggable trait |
| `app/Exceptions/` | Domain exceptions + the central `Handler` |
| `app/Exports/` | Export formatting, PDF generation, ZIP export/import pipeline |
| `app/Http/` | Base `Controller`/`ApiController`, `Kernel` (middleware groups/aliases), all middleware |
| `app/Permissions/` | `Permission` enum, joint-permission builder, `PermissionApplicator`, `PermissionsRepo` |
| `app/References/` | Cross-link parsing + reference maintenance between entities |
| `app/Search/` | Search engine (`SearchRunner`, `SearchIndex`, `SearchOptions`) + search controllers |
| `app/Settings/` | DB-backed `SettingService`, maintenance controllers |
| `app/Sorting/` | Book/chapter sort logic, `SortRule` |
| `app/Theming/` | Theme service, `ThemeEvents` hooks, theme module manager |
| `app/Translation/` | Custom translation FileLoader, `LocaleManager`, locale definitions |
| `app/Uploads/` | Image/attachment/file services, storage disks, `FileUrlSigner`, upload controllers |
| `app/Users/` | User/Role models, `UserRepo`, preferences |
| `app/Util/` | Utilities: HtmlPurifier setup, `CspService`, SvgIcon, mime sniffing, URL validation |
| `app/View/` | ViewBlock/layout composition system (`LayoutController`, `ViewBlockManager`) |
| `routes/` | `web.php` (~400 lines; public routes + one big `auth` group), `api.php` (hand-expanded resource verbs; controllers must end in `ApiController`) |
| `resources/` | Blade `views/`, TS/JS in `js/`, SCSS in `sass/` |
| `lang/` | ~52 Crowdin-managed locale dirs (`en/` is source); each has the same flat PHP files (`entities.php`, `common.php`, …) |
| `database/` | `migrations/` (Laravel timestamped snake_case, e.g. `2024_11_02_160700_create_imports_table.php`), `factories/` (PSR-4 by domain: `Entities/`, `Users/`, `Access/`, …), `seeders/` (`DatabaseSeeder`, `DummyContentSeeder`, `LargeContentSeeder`) |
| `tests/` | PHPUnit suite; mirrors `app/` module structure (see Testing) |
| `dev/` | Build scripts (`build/esbuild.mjs`), Docker (dev + `Dockerfile.prod`), licensing, and **project documentation** (`docs/development.md`, `php-testing.md`, `javascript-code.md`, theme-system docs, `wysiwyg-js-api.md`, …) |
| `themes/` | Opt-in theme overrides; empty by default (`.gitignore` stub), activated via `APP_THEME` env |
| `bookstack-system-cli/` | Standalone compiled PHP PHAR CLI |
| `public/dist/` | Build output — never edit by hand |

## Development Commands

Setup: PHP 8.2+, Node 22+, then `composer install` and `npm install`. Copy `.env.example` (minimal) or `.env.example.complete` (all options) to `.env`, set DB credentials, then `php artisan key:generate` and `php artisan migrate`.

```bash
# PHP (via composer scripts)
composer test                     # phpunit (full suite)
phpunit --filter SomeTest         # or vendor/bin/phpunit tests/Entity/PageTest.php (single class)
composer t-reset                  # artisan test --recreate-databases (rebuild testing DB)
composer refresh-test-database    # migrate:refresh + DummyContentSeeder on mysql_testing
composer lint                     # phpcs (PSR12)
composer format                   # phpcbf (auto-fix)
composer check-static             # phpstan --memory-limit=2g (Larastan, level 5)
composer build-licenses           # regenerate dependency license lists

# JS (via npm scripts)
npm run build                     # dev sass + esbuild in parallel (alias: watch/dev variants)
npm run production                # minified production bundles -> public/dist
npm run lint                      # eslint (resources/**)
npm run fix                       # eslint --fix
npm run ts:lint                   # tsc --noEmit (type check)
npm run test                      # jest (jsdom, ts-jest)
npm run test:ci                   # jest --maxWorkers=1

# Artisan
php artisan list                # 16 bookstack:* commands, e.g. bookstack:create-admin, bookstack:update-url,
                                # bookstack:reset-mfa, bookstack:regenerate-search, bookstack:regenerate-references,
                                # bookstack:clear-revisions, bookstack:copy-shelf-permissions, bookstack:install-module
php artisan migrate               # after pulling (check database/migrations/)
```

Docker alternative: `docker-compose.yml` is a **dev-only** stack — `db` (MySQL 8.4), `app` (port 8080, xdebug, repo mounted), `node` (22-alpine), `mailhog` (8025). See `dev/docs/development.md` for the full workflow.

## Code Conventions & Common Patterns

- **Namespace**: PSR-4 root is `BookStack\` → `app/` (composer.json autoload), **not** `App\`. There *is* an `app/App/` directory whose namespace is `BookStack\App` — read the namespace, not the path, when resolving FQCNs.
- **Naming**: domain services end `Repo` (data: `BookshelfRepo`, `UserRepo`, `CommentRepo`) or `Service` (cross-cutting: `SettingService`, `LdapService`, `ThemeService`); read/query layers are `*Queries` / `*Queries` classes; API controllers end `ApiController` (enforced by routes/api.php conventions); commands end `Command` (`ClearRevisionsCommand`).
- **Dependency injection**: constructor property promotion throughout — `public function __construct(protected BookshelfRepo $shelfRepo, protected BookshelfQueries $queries)`. Complement, not replace: global helpers in `app/App/helpers.php` (`user()`, `userCan()`, `setting()`, `theme_path()`, `versioned_asset()`). No facades-heavy or service-locator styles in controllers (only 2 facades exist: `Activity`, `Theme`).
- **Permission checks**: always through the `Permission` enum — `$this->checkPermission(Permission::BookCreateAll)` for module-level, `$this->checkOwnablePermission(Permission::BookUpdate, $book)` for per-entity. For routes: `'middleware' => (Permission::BookCreateAll)->middleware()`. Never hand-write `'can:xxx'` strings.
- **Validation**: `$this->validate($request, $rules)` (ValidatesRequests) in web controllers; API controllers declare a `$rules` property consumed by `ApiController` helpers.
- **Errors/notifications**: throw domain exceptions (`NotifyException` for user-facing failures); the Handler renders them. In controllers, flash via `$this->showSuccessNotification('...')` / `showWarningNotification` / `showErrorNotification` (session-based, trans-able). API errors via `$this->jsonError()`. Docblocks declare `@throws` — keep them honest.
- **User-facing strings**: always `trans('entities.books')` — never literal English. Locale files live parallel under `lang/<locale>/` matching `lang/en/`.
- **Settings**: never read/write settings tables directly; use `setting()->get(...)` / `setting()->getForCurrentUser(...)` / `setting()->getInteger('key', $default, $min, $max)` / `setting()->put(...)`.
- **List pages**: sorting/pagination via `SimpleListOptions::fromRequest($request, 'shelves')` + `*Queries`Builder pattern — copy an existing entity controller (`app/Entities/Controllers/BookshelfController.php`) rather than inventing structure.
- **Middleware**: route-level in `routes/web.php`/`Kernel` groups, but per-controller middleware in constructors is also common (`$this->middleware(Permission::ContentExport->middleware())` in `BookExportController`). Both styles coexist; prefer whichever the surrounding code uses.
- **Public/unauthenticated**: `user()` always returns a `User` (guest included); rate limiters special-case guests; public file access uses the `signed-file` middleware (`VerifySignedFileRequest`) outside the auth group.
- **Theming**: theme overrides activated via `APP_THEME` env; views/styles/i18n overridden through `ThemeServiceProvider` + `ThemeEvents` (see `dev/docs/logical-theme-system.md`). Themes may even register routes.
- **Database**: factories are PSR-4, namespaced by domain under `Database\Factories\` (e.g. `Entities/PageFactory`); migrations follow Laravel timestamped snake_case; keep migrations reversible (CI rolls them back against MariaDB).
- **Lint consistency**: PHP = PSR12 via phpcs; PHPStan level 5 (Larastan) over `app/` only; TS strict; ESLint 4-space indent, max-len 110.

## Important Files

| File | Why |
|---|---|
| `bootstrap/app.php` | Classic (pre-11) skeleton: instantiates `BookStack\App\Application`, binds `Http\Kernel`, `Console\Kernel`, `Exceptions\Handler`. No Laravel-11 `withRouting()` — don't "modernize" it |
| `app/Http/Kernel.php` | Middleware groups (`web`, `api` — includes `ThrottleApiRequests`, `StartSessionIfCookieExists`) and aliases (`auth`, `can`, `signed-file`) |
| `app/Console/Kernel.php` | Commands auto-discovered via `$this->load(__DIR__.'/Commands')`; add new commands by placing a `*Command` class there |
| `app/Http/Controller.php` / `app/Http/ApiController.php` | Base helper bags every controller inherits — check here before adding a helper |
| `app/Permissions/Permission.php` | Single enum of all permissions; `->middleware()` builds the `can:` strings |
| `app/App/Providers/RouteServiceProvider.php` | Mounts routes, defines rate limiters (`api`/`public`/`exports`) |
| `app/App/helpers.php` | Global helpers: `user()`, `userCan()`, `setting()`, `theme_path()`, `versioned_asset()` |
| `routes/web.php`, `routes/api.php` | All endpoints, hand-written `[Controller::class, 'method']` |
| `app/Config/` | Where `config('api.requests_per_minute')` etc. actually live (overrides stock Laravel config; no root `config/`) |
| `.env.example`, `.env.example.complete` | Minimal vs. full configuration reference |
| `phpunit.xml` | Test env: `mysql_testing` connection, array cache, sync queue, STORAGE_TYPE=local, disabled external services |
| `jest.config.ts`, `tsconfig.json` | JS tests: ts-jest + jsdom, roots `resources/js`, only `__tests__/**/*.test.[jt]s`; path aliases incl. vendored Lexical |
| `phpstan.neon.dist`, `phpcs.xml` | Static analysis + style: Larastan L5 (app only), PSR12 with pragmatic test/database exceptions |
| `eslint.config.mjs` | JS style: 4-space, max-len 110, no-console (warn) |
| `dev/build/esbuild.mjs` | JS bundler config — 5 ESM entries → `public/dist`, target es2021 |
| `composer.json`, `package.json` | Version constraints and the canonical task runners (see commands above) |

## Runtime/Tooling Preferences

- **PHP ^8.2.0** (CI matrix tests 8.2–8.5; phpstan targets 8.2–8.5), **Laravel ^12.26.4**. Required extensions: curl, dom, fileinfo, gd, mbstring, xml, zip.
- **Node 22+** (dev docs; docker uses node:22). No `engines` pin in package.json — match the docker/dev docs.
- **npm + package-lock.json**. Do not introduce yarn/pnpm, and keep lockfiles updated when adding deps.
- **Bundler is esbuild + dart-sass** (`dev/build/esbuild.mjs`) → `public/dist`. There is no vite/webpack/next — don't add one. Target: es2021 ESM.
- **Lexical (wysiwyg) is a vendored fork** wired through tsconfig path aliases (`lexical`, `@lexical`, `@icons`) — import via aliases; don't `npm install` separate Lexical packages.
- **PHP: no PHPStan beyond L5** assumptions; code must pass both `phpcs` and `phpstan` (Larastan). Tests may relax PSR1 (multi-class/camelcase); `database/` may omit namespaces; `app/Config/` skips header-order.
- **Translations are Crowdin-managed** (`crowdin.yml`, project 377219): edit `lang/en/` freely; other locales sync via Crowdin PRs (label "Translations") — don't hand-edit them, including on translation-PR reviews.
- **CI runs on Forgejo** (`.forgejo/workflows/`, i.e. Codeberg), not GitHub Actions. GitHub Actions only publishes the custom-variant Docker image (`docker-publish.yml` → ghcr.io, amd64+arm64, from `Dockerfile.prod`).

## Testing & QA

- **PHP: PHPUnit ^11.5**. Single testsuite covering `tests/`; runs against a real `mysql_testing` database with `DatabaseTransactions` (nothing persists) and `DummyContentSeeder`-seeded content. Recreate/refresh with `composer refresh-test-database` or `composer t-reset`.
- **Structure mirrors `app/`**: `tests/Entity/PageTest.php` ↔ `app/Entities/...`, `tests/Api/BooksApiTest.php`, `tests/Auth/OidcTest.php`, `tests/Uploads/ImageTest.php`, `tests/Commands/...`, `tests/Unit/...`, etc. When adding a test, mirror the corresponding `app/` path and namespace (`Tests\Entity\...`).
- **Base class**: `tests/TestCase.php` wires fixture providers in `setUp()`: `$this->entities` (`EntityProvider` — `page()`, `chapter()`, `book()`, `shelf()`), `$this->users` (`UserRoleProvider` — editor/viewer/admin), `$this->permissions` (`PermissionsProvider`), `$this->files` (`FileProvider` + `tests/test-data/` fixtures). Role helpers: `$this->asAdmin()`, `asEditor()`, `asViewer()`. Other useful members: `setSettings()`, `partialMockService()`, `mockHttpClient()`, `runWithEnv()` (temp env override + full app refresh), `usingThemeFolder()`, `assertSessionError()`, `assertPermissionError()`, `withTestLogger()`, `assertDatabaseHasEntityData()` (entity data is split across `entities`/`entity_page_data`/`entity_container_data` — use this, not plain `assertDatabaseHas`, for entity tables).
- **HTML assertions**: via `ssddanbrown/asserthtml` — `$this->withHtml($response)->assertElementContains('header', 'Some text')`.
- **API tests**: use the `TestsApi` trait (`tests/Api/TestsApi.php`): `actingAsApiAdmin()` / `actingAsApiEditor()` / `actingAsForApi($user)`; expected error shapes via `errorResponse()` / `validationResponse()`. Test credentials are fixed: token id `apitoken`, secret `password`.
- **Permission matrices**: for role/permission combination coverage, extend `tests/Permissions/Scenarios/PermissionScenarioTestCase.php` (see `dev/docs/permission-scenario-testing.md`) instead of hand-rolling loops.
- **JS/TS: Jest 30 + ts-jest + jsdom**, only `resources/js/**/__tests__/*.test.ts` (wysiwyg/Lexical + utility code). Run `npm run test`; CI uses `npm run test:ci`.
- **Every PR is gated** by Forgejo CI (path-filtered): `test-php`, `analyse-php` (phpstan), `lint-php` (phpcs), `test-js` (jest), `lint-js` (eslint + tsc), `test-migrations` (migrate/rollback/rerun on MariaDB, PHP 8.2–8.5). Run the relevant composer/npm commands locally before marking work done.
- **Expectation**: new/changed behavior ships with a test exercising it through the public surface (HTTP/Artisan/API), mirroring the affected `app/` path, reusing the `TestCase` providers above rather than bespoke mocks. Migrations must migrate, rollback, and re-migrate cleanly.
