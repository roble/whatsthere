# Whatsthere

Ask about a place or a house in plain language and watch the map keep up.

Whatsthere is a streaming AI chat beside a live map. Ask where something is and
the assistant moves the map to it. Ask what is around, and pins appear. Ask
about homes for sale, and listings from the local database light up so you can
filter, compare, and open photos without leaving the conversation.

Drag the map yourself and the assistant knows what you are looking at when you
ask "what's here?".

<div align="center">

[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://php.net)
[![Vue](https://img.shields.io/badge/Vue-3.5-4FC08D?logo=vue.js&logoColor=white)](https://vuejs.org)
[![Inertia.js](https://img.shields.io/badge/Inertia.js-3.x-9553E9)](https://inertiajs.com)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind%20CSS-4.x-06B6D4?logo=tailwind-css&logoColor=white)](https://tailwindcss.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)](https://postgresql.org)
[![MapLibre](https://img.shields.io/badge/MapLibre%20GL-6.x-295DAA)](https://maplibre.org)

</div>

## How it works

**Two conversations, one map.** A new chat can explore a place anywhere in the
world, or search homes and plots for sale. Place chats interview briefly, then
open the map with a plan and optional itinerary. Property chats skip the
interview and search the listings already in the database.

**The assistant never invents coordinates.** When an answer is about somewhere,
it calls a map tool. `show_on_map` geocodes one name through
[Nominatim](https://nominatim.openstreetmap.org/). `find_places` ranks up to 40
OpenStreetMap results for "what is around here". Property tools search the
listings table and move the camera to the matches.

**The map answers back.** Every message carries where the map is pointing. Pan
away from where the conversation left it and the new centre is named afresh, so
"here" means what you can see rather than what was last discussed.

**Listings stay on the facts.** Homes and land come from imported MyHome Cork
fixtures — real addresses, asking prices, BER ratings, and photos. The
assistant quotes those fields and compares nearby schools, hospitals, or stops
from mapped amenities. It does not invent planning permission or availability.

**Your own agent can drive it.** The chat page publishes
[WebMCP](https://github.com/webmachinelearning/webmcp) tools — list and read
conversations, move the map, read its position, ask the assistant a question.
They run in the page inside your existing session, so there are no tokens and no
second auth surface.

Tiles come from [OpenFreeMap](https://openfreemap.org/), which serves
OpenStreetMap vector data with no key and no quota.

## Getting started

```bash
cp .env.example .env
docker compose up -d
docker compose exec app composer setup
```

The app is served at https://localhost. Set an AI provider key in `.env` before
the chat will answer.

Property chats need listings in the database. `composer setup` runs migrations
but does not seed them. The seeder loads `myhome-cork-lite.json` plus the Daft
buy and sold fixtures — not the full 52 MB MyHome dump, which is gitignored:

```bash
docker compose exec app php artisan db:seed --class=Modules\\Properties\\Database\\Seeders\\PropertiesDatabaseSeeder
```

Or import a file by hand:

```bash
docker compose exec app php artisan data:import --provider=myhome
docker compose exec app php artisan data:import --provider=daft
```

Frontend changes need Vite running:

```bash
npm run dev
```

`CHAT_TEST_MODE=true` answers with canned replies instead of calling a model, so
the front end can be worked on without spending tokens. It is refused in
production and pinned off in PHPUnit.

## AI providers

Chat follows `AI_PROVIDER`. The two supported values:

| `AI_PROVIDER` | What runs |
| ------------- | --------- |
| `openai`      | Chat and tools on OpenAI |
| `manus`       | Chat on [Manus](https://open.manus.ai/docs), tools on OpenAI (hybrid) |

Manus cannot call Laravel tools. In hybrid mode, prose can stay on Manus while
map and listing tools run on `CHAT_AI_TOOLS_PROVIDER` (OpenAI by default).

```dotenv
AI_PROVIDER=openai
OPENAI_API_KEY=
# AI_PROVIDER=manus
# MANUS_API_KEY=
# MANUS_AGENT_PROFILE=lite
# CHAT_AI_PROVIDER=openai
```

Override chat alone with `CHAT_AI_PROVIDER` when the rest of the app should stay
on a different default.

## The database

PostgreSQL 18 with PostGIS 3.6, via the `imresamu/postgis` image — the official
`postgis/postgis` publishes no arm64 manifest and will not start on Apple
Silicon. This one is multi-arch, so the same tag serves a laptop and an amd64
VPS.

The major version matches Laravel Cloud, whose Serverless Postgres is Neon.
Neon pins extension versions per major: PostGIS 3.5.0 and pgvector 0.8.0 on 17,
3.6.0 and 0.8.6 on 18. Postgres majors cannot be swapped on an existing data
directory, so local and production are pinned to the same one deliberately.

The image enables PostGIS on the database it creates, so geometry columns,
spatial indexes and the `ST_*` functions are available now even though nothing
uses them yet. That is the reason for the image choice: an app about drawing
areas, placing points and computing routes will want them, and picking the
plain `postgres` image would have made adding them later a deploy step rather
than a migration.

`pgvector` is **not** installed. When embeddings arrive, add it to the image:

```dockerfile
FROM imresamu/postgis:18-3.6
RUN apt-get update && apt-get install -y postgresql-18-pgvector
```

Then `CREATE EXTENSION vector;`. Rebuilding the image does not touch the data
volume.

## Testing

```bash
php artisan test --compact          # PHP
npm run test:e2e                    # Playwright
vendor/bin/pint --dirty             # PHP formatting
npm run lint && npm run format      # frontend
```

`tests/TestCase::setUp()` pins the test connection to in-memory SQLite before
the application boots. Those three lines are load-bearing: `docker-compose.yml`
sets a real `DB_CONNECTION` in the container, and PHPUnit's `<env>` entries lose
to real environment variables — without the pin, `RefreshDatabase` drops every
table in the development database.

The suite therefore does not exercise PostgreSQL. That is fine while the schema
is portable; it stops being fine the day a migration adds a geometry or vector
column, at which point tests need a real Postgres service.

## Modules

Modules are copy-and-own packages under `modules/`. An installed module is
active; there is no enable/disable toggle.

| Module       | Description                                                                      |
| ------------ | -------------------------------------------------------------------------------- |
| `chat`       | The streaming assistant, the map beside it, property tools, and the WebMCP tools |
| `properties` | Listings, asking prices, search filters, and the MyHome Cork import              |
| `auth`       | Authentication, social login, email verification, impersonation                  |
| `settings`   | Profile management, avatar uploads, password changes                             |

`chat` is the application's home: `/` and `/dashboard` both redirect there once
you are signed in.

## Built on Saucebase

Whatsthere is built on [Saucebase](https://saucebase-dev.github.io/docs/), a
modular Laravel starter kit, which supplies the module system and the `auth` and
`settings` modules. This is a starting point rather than a vendored dependency —
inherited code that no longer suits the application is fair game to change.

`CONTRIBUTING.md` is still the upstream Saucebase contributor guide and describes
a workflow that does not apply here.

## Links

- [License (MIT)](LICENSE)
- [Third-party PHP / Composer licenses](THIRD_PARTY_LICENSES.md)
- [Third-party JavaScript / npm licenses](THIRD_PARTY_PACKAGE_LICENSES.md)
