# Screenshot harness

Drives `~/Sites/plugin-testing` into a state worth photographing, so the images on the marketing
site and in `promos/shots/` are real captures of a real install rather than mockups.

**There is no live Recombee database here, and there should not be.** Instead a stand-in API runs
inside the container and Bee talks to it through its own client — real signing, real timings, real
retries, real error handling. Everything in the sync table, the connection log and the attribution
ledger is therefore written by Bee itself; only the server on the other end is fake.

## Running it

```sh
cd ~/Sites/plugin-testing

# 1. the stand-in API, inside the web container
docker exec -u root ddev-plugin-testing-web \
  bash -c 'grep -q rapi-us-west /etc/hosts || echo "127.0.0.1 rapi-us-west.recombee.com" >> /etc/hosts'
docker exec -d ddev-plugin-testing-web \
  bash -c 'cd /var/www/craft-bee/tests/shots && php -S 127.0.0.1:8899 stub-recombee.php'

# 2. settings, sources, catalog, properties, traffic
ddev exec php /var/www/craft-bee/tests/shots/seed.php      # settings + two catalog sources
ddev exec php /var/www/craft-bee/tests/shots/polish.php    # credentials from .env, point at the stub
ddev exec php craft bee/sync/catalog --force --interactive=0
ddev exec php craft bee/sync/properties --interactive=0
ddev exec php craft bee/interactions/backfill --interactive=0
ddev exec php craft bee/log/clear --interactive=0
ddev exec php /var/www/craft-bee/tests/shots/finish.php    # recommendation + interaction traffic

# 3. capture
cd ~/Sites/plugin-shots && node capture.mjs out/bee specs/bee.json
```

`urls.php` prints the source UIDs and a few log-row IDs, which is what `specs/bee.json` needs.

## The two states

The diagnostics board and the connection log want opposite things, and one install cannot show both
at once. So they are captured at different moments, each of them honest:

- **Clean** — `finish.php` against the stub with no failure injection. Eleven green checks.
- **Failing** — `burst.php` against the stub started with `FLAKY=22 DUPES=25`, which makes it return
  a mix of 400, 429, 503 and 409. That fills the log with the error rows the log screen exists to
  show. Restart the stub without those variables afterwards.

`FLAKY` is the percentage of *all* calls that fail; `DUPES` is the percentage of interactions
answered with a 409, which is Recombee's way of saying the interaction already exists — the
duplicate protection working, not a failure.

## Traps

**`Plugins::savePluginSettings()` replaces `plugins.bee.settings` in project config with exactly the
array it is handed.** It does not merge. Passing two keys silently wipes the credentials, and the
next console command fails with "Bee is not connected to a Recombee database" for no visible reason.
`_settings.php` wraps it and always sends the whole model; use `bee_set_settings()`, never the Craft
method directly.

**A console script never reaches `EVENT_AFTER_REQUEST`, so project config changes are never
flushed.** The save reports success, the in-memory model is correct, and nothing is written. Every
write here ends with `saveModifiedConfigData()` — also inside `bee_set_settings()`.

**Dry-run logs every request at 200 and 0.0ms.** It is the right switch for a customer verifying a
connection, and the wrong one here: the log screen fills with a column of identical zeroes. Leave
`dryRun` off and let the stub answer.

**The connection check prints the host it reached.** Pointing `baseUri` at `127.0.0.1` puts that in
the screenshot. The hosts entry above is what makes it read `rapi-us-west.recombee.com` instead.

**Credentials belong in `.env`.** With them stored literally the diagnostics screen reports an amber
"stored literally rather than as environment variables", which is correct advice and a bad
screenshot. `polish.php` sets them to `$BEE_DATABASE_ID` / `$BEE_PRIVATE_TOKEN`; the values live in
`plugin-testing/.env`.

**The stub remembers item properties across calls** (in `sys_get_temp_dir()`), because Bee's
"properties not defined in Recombee yet" check compares what the sources declare against what the
database lists back. A stub that returns a fixed list leaves that check permanently amber.
