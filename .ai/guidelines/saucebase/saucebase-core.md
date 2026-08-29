## Whatsthere

Whatsthere is a Laravel application **built on** the Saucebase modular starter
kit. Saucebase supplies the module system and the `auth`, `settings`, and
`breadcrumbs` modules; everything else here is application code. This is a
starting point, not a vendored dependency: inherited code that no longer suits
the application is fair game to change.

Treat the implementation and manifests as authoritative; do not infer dependency
versions or tool settings from prose.

### Sources of Truth

- Backend dependencies and constraints: `composer.json`
- Frontend dependencies: `package.json`
- Static-analysis configuration: `phpstan.neon`
- Module behavior: `app/Providers/ModuleServiceProvider.php` and `module-loader.js`

This project is **Vue only**. The starter kit also ships a React tree and a
stack-selection step; neither exists here, so there is no second implementation
to mirror frontend changes into and no `stubs/saucebase/stack/*` manifests to
consult. The root `package.json` is the real frontend source of truth.

### Module Conventions

Modules are copy-and-own Composer packages installed under lowercase
`modules/<name>/` directories. PHP namespaces remain TitleCase.

An installed Composer module is active; there is no enable/disable toggle.
Never bypass `module-loader.js` for module assets, translations, or Playwright
project discovery.

Every main module provider extends `App\Providers\ModuleServiceProvider`. Do not
add `$name` or `$nameLower`: the base provider resolves the module name through
`ModuleRegistry::moduleForClass()`.

Use lowercase module identifiers in frontend checks such as
`modules().has('auth')`.

### Frontend Conventions

All components must support light and dark themes. Use stable `data-testid`
attributes for E2E selectors; never select translated text, labels, or role
names. Item-specific selectors use `{action}-${item.id}`.

### Verification

Run the smallest relevant checks:

- PHP: `php artisan test --compact`, plus `vendor/bin/pint --dirty` before finishing
- Frontend: `npm run lint` and `npx prettier --check`
- Run module PHPUnit tests with a 2048 MB PHP memory limit

### Feature Workflow

Build the feature first, then verify the behaviour by hand with Chrome DevTools
against the running app. Do not write tests alongside code that is still
changing shape.

Once the feature works and the user agrees it is finished, **ask** whether to
add and run e2e tests covering the main scenarios. Write them only after that
agreement, never speculatively.

Delete tests that no longer describe how a feature works. A stale test is worse
than no test.

E2E setup runs `migrate` and idempotent seeders against the same database the
dev app serves. It must never run `migrate:fresh`, which would destroy the
team's working data.

`CONTRIBUTING.md` is still the upstream Saucebase contributor guide. It describes
a stack-selection workflow and source layout that do not apply to this repository.

Update these source guidelines when a durable project convention changes, then
regenerate agent instructions with `composer boost:update`. Never edit the
generated Laravel Boost blocks in `AGENTS.md` or `CLAUDE.md` directly.
