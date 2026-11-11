# Renown: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Every change goes to the Command Center session for review before release. New extension: dev + showcase only.

## Design rules that exist for a reason

- **Every XP change goes through `Ledger`** under the member's `users` row lock (SELECT … FOR UPDATE): the daily cap, the like limits and the cooldown are read inside it, and the totals are written as absolute values computed in PHP. Never `column - n` (MySQL counters are unsigned).
- **One log row per award**, unique on (user, source, subject, actor): awards are idempotent, take-backs exact (delete the rows a post produced). Each row records the `prestige` it was earned in: taking it back after a Prestige lowers lifetime XP only.
- **The history replay (`Replay`) is uncapped** (Ernest, 2026-11-11): no caps, cooldown, like limits or minimum length for history; only eligibility (hidden, unapproved, private, excluded tags, own likes). It never prestiges anyone: past Level 100 stays at Immortal V with XP banked. It runs in slices from the scheduler (`renown:replay`), the admin page (polls `POST /api/renown/admin {action: run}`) and `php flarum renown:rebuild`; never in the enable request.
- What posts and cards show is on the user row (`renown_level`, `renown_prestige`, `renown_xp`, `renown_lifetime_xp`): **0 added queries** on any list. The rank-up toast compares `renownSeen` (prestige × 1000 + level) client-side.
- Visits: core saves `last_seen_at` at most every 3 minutes; `User::saved` with a new UTC day awards the visit (`renown_day` column keeps it to once a day). Cadence fires no event: its row is read once a day after a visit/post (`renown_streak_day`).
- Integrations only while the other extension is enabled (`Extend\Conditional`): Likes, Approval, FoF Best Answer, Answers (upvotes are read back from its reputation log), Kudos (rides on its "received" alert; `Kudos::deleted` takes back), Buzz (rank-ups off by default), FoF Badges (metrics registered by `container->resolving`; 🚨 a metric must implement EVERY `MetricInterface` method or building the MetricManager fatals every request that uses badges, silently on the CLI).
- Group rewards only ever remove a group Renown gave (`renown_grants`); built-in groups 1–4 are never used.
- 🚨 Migrations never name indexes or foreign keys. extend.php uses string literals for settings keys, never `Settings::` constants.
- Frontend: forum.js draws the insignia (SVG, ~2 KB) and wires things up; the leaderboard, profile tab and modals are ONE lazy chunk (`js/src/forum/components`). Link rules start with `html:not(#x-hi)` to beat themes; colours come from Flarum's CSS properties.

## Testing

- `composer test:setup && composer test` (`DB_DRIVER=mysql` and a `DB_PREFIX` to test prefixes), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum ~/Herd/renowndev (https://renowndev.test, prefix `rn_`). Seed, sessions, screenshots: ~/github/scopes/renown (`seed.php`, `session.php`, `shots.mjs`).
