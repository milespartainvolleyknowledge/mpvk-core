=== MPVK Core ===
Version: 0.2.0 (MVP + iMessage-style messaging + simple workout check-off)

WHAT'S NEW IN 0.2.0
- Messaging is now near-real-time (auto-updates every ~4s, no tab-switching) with emoji
  reactions, quoted replies, and image/video attachments. Tap any message for react/reply.
  Attachments are served through an authenticated, tenancy-checked route — never public URLs.
- Workout tracking simplified to MVP: each exercise is one line ("4x8 Squats @ 35% 1RM, rest
  2-3 min") the athlete checks off; the workout auto-marks planned/partial/completed. The richer
  per-set logging schema is retained but hidden, for when Brent defines the real programming model.
- Tests: 62 automated (34 permission + 7 security + 21 v2 features), all passing. Second
  adversarial review done; findings fixed (notably: private attachment serving).

--- prior ---
Version: 0.1.2 (MVP skeleton; hardened after adversarial security review)
Requires WordPress: 6.4+   Requires PHP: 8.1+

WHAT THIS IS
The load-bearing MVP for the MPVK platform: Admin → Org(coach) → Client(athlete)
tiers with org_id tenancy, a training calendar with full exercise-level schema,
in-portal coach↔client messaging, and append-only corpus capture — all built for
the full vision at the schema level, minimal at the UI level.

INSTALL
1. Upload the mpvk-core folder to wp-content/plugins/ (or install the zip via
   Plugins → Add New → Upload).
2. Activate. On activation it creates all wp_mpvk_* tables and the two roles
   (MPVK Org Coach, MPVK Client) and flushes rewrites so /portal works.
3. Optional demo data (1 org + 4 clients + a sample workout), as an admin:
   wp eval 'do_action("mpvk_seed_demo");'   (WP-CLI)
   — or visit any admin page once with ?mpvk_seed=1 handling added later.

USING IT
- Front-end portal lives at /portal (mobile-first). Org/client users are sent
  there on login and kept out of wp-admin; admins keep wp-admin.
- Login screen is brand-styled (parchment/ink/gold, uses the site logo).

TIERS / CAPS
- Admin: your existing WP admin login — full access.
- MPVK Org Coach: manage own org's clients, assign/edit workouts, message clients.
- MPVK Client: view + log own training, message their coach. Nothing else.
Assign a coach/client to an org by setting user meta mpvk_org_id (the seeder and
future checkout do this automatically).

DATA MODEL (wp_mpvk_*)
orgs, exercise_library, templates/template_days/template_day_exercises,
template_subscriptions (copy vs subscribe), workouts/workout_exercises/exercise_logs,
messages, corpus_events (append-only), audit_log (append-only).

SECURITY IN THIS BUILD
- Every REST route has an explicit permission_callback AND an object-level tenancy
  re-check (no IDOR): a client can only ever act as themselves; a coach only within
  their org; cross-org access is denied and audited.
- Login lockout (5 fails / 15 min per IP+username), REST user-enumeration closed,
  ?author= enumeration closed, shorter client auth-cookie horizon.
- 2FA is the next security pass (hooks land in class-mpvk-security.php) — see the
  security research report before implementing.

NOT YET BUILT (deferred modules, schema already supports several)
Commerce (Woo+Stripe), check-in forms, exercise-library UI, Discord, Loom pipeline,
Zoom office hours, AI draft layer, org cloning. Attach one at a time after this MVP
is proven on staging.
