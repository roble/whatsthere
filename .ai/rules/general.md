---
paths:
  - '**'
---

# General

## Develop against the root hot-reload app
Run project commands from the repository root, never through Docker. The development app is already served at https://localhost with `npm run dev`, so do not build for browser verification. Use Chrome DevTools for manual checks. Do not create or run E2E tests until the whole project is finished.

## Write user-facing copy in English only
Ship English. Do not write or update pt_BR translations for new copy, and do not add new locales.

Keep using `$t()` / `__()` as the surrounding code does -- the key is the English string and falls through untranslated, so wrapping costs nothing and leaves the door open. Just do not go and fill in `lang/pt_BR.json` afterwards.

The existing pt_BR files stay where they are. Locales are discovered from `lang/` directories (see the note in `config/app.php`), so deleting them removes Portuguese from the app entirely -- measured: that fails 9 tests in `tests/Feature/LocalizationSettingsTest.php`, which uses pt_BR as its second locale to prove discovery, de-duplication across modules, enabling, and switching. Removing the language means reworking those tests with a fixture locale first. Not worth it to delete files nobody reads.
