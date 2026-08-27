# Repair the stale agent_conversations migration

- **Type**: `wayfinder:task` (HITL — touches the database, needs a human call on the data)
- **Status**: closed
- **Assignee**: unclaimed
- **Blocked by**: —

## Question

**Surfaced by the streaming research, and it invalidates an assumption this map was built on.** The
map's Notes and destination both leaned on "`agent_conversations` is already migrated, so persistence
is free". It is migrated — but **against a schema `laravel/ai` v0.11 no longer uses.**

Verified directly by diffing the two migrations:

| | app `0001_01_01_000007` | vendor `2026_01_11_000001` |
|---|---|---|
| owner columns | `user_id` (`foreignId`, NOT NULL) | `participant_type` + `participant_id`, both nullable |
| messages | no `approval_state` | `approval_state` text, nullable |
| indexes | on `user_id` | on `participant_type`/`participant_id` |

`vendor/laravel/ai/src/Storage/DatabaseConversationStore.php` writes `participant_type` (line 53) and
`approval_state` (lines 81, 119), and `ConversationMessage` casts `approval_state` to array. So the
first message written through `RemembersConversations` will fail on an unknown column — and
`user_id` is NOT NULL with no default, so it cannot simply be ignored.

The work: drop the stale `0001_01_01_000007_create_agent_conversations_table.php`, publish the
package's own migration, and re-run.

**Confirm before destroying anything.** The research asserted both tables are empty, but that claim
could not be independently verified from the host — `DB_CONNECTION=mysql` points at host `mysql`,
which only resolves inside Docker, so `php artisan tinker` cannot reach it from a normal shell. Bring
the stack up and check the row counts for real before dropping a table. If rows exist, this becomes a
data-migration question rather than a drop-and-recreate.

## Answer should record

Actual row counts before the change, the publish command used, and whether the app-level migration
was deleted or left in place as a no-op.

## Resolution

Repaired and verified live. Both tables held **0 rows** (confirmed via `docker compose exec mysql`,
which is the only way to reach the DB — `DB_HOST=mysql` resolves inside Docker only), so
drop-and-recreate was safe and no data migration was needed.

Steps taken:

1. Dropped `agent_conversation_messages` and `agent_conversations`.
2. Deleted the `0001_01_01_000007_create_agent_conversations_table` row from the `migrations` table.
3. Deleted `database/migrations/0001_01_01_000007_create_agent_conversations_table.php`.
4. Published the package migration, which landed as
   `database/migrations/2026_08_27_193349_create_agent_conversations_table.php`.
5. Ran `php artisan migrate`.

Verified the resulting schema directly: `agent_conversations` now has nullable `participant_type` /
`participant_id` and no `user_id`; `agent_conversation_messages` has `approval_state`. This matches
what `DatabaseConversationStore` writes.

**Side effect worth knowing about.** The publish was run with `--force` against the whole provider
rather than scoped to the migration tag, so it also republished `config/ai.php` and the agent stubs.
This was an overreach, but the outcome was benign — the diff is almost entirely additive (it added
`azure`, `bedrock`, `deepseek` providers and per-provider `url` keys that the previously-published
config lacked). The only removals were an unused `use Laravel\Ai\Provider;` import and a blank line;
nothing referenced `Provider::`. The config was stale in the same way the migration was, so this
effectively upgraded it. `stubs/agent-middleware.stub` is new and untracked.
