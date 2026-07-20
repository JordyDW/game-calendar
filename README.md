# Game Calendar

[![Latest release](https://img.shields.io/github/v/release/JordyDW/game-calendar?style=flat-square)](https://github.com/JordyDW/game-calendar/releases/latest)
[![License](https://img.shields.io/github/license/JordyDW/game-calendar?style=flat-square)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?style=flat-square&logo=wordpress&logoColor=white)
![Last commit](https://img.shields.io/github/last-commit/JordyDW/game-calendar?style=flat-square)
![Downloads](https://img.shields.io/github/downloads/JordyDW/game-calendar/total?style=flat-square)

A WordPress plugin for gaming sites. Track game releases and gaming events on an interactive calendar, with IGDB integration to auto-fill game metadata.

Built for [Gamekast](https://gamekast.be).

---

## Features

- **Game Releases** — title, release date, developer, publisher, genre, platforms, cover art, URL
- **Gaming Events** — title, start/end datetime, URL (conferences, showcases, streams)
- **IGDB integration** — search and auto-fill game data including cover art directly from the add modal
- **Admin calendar** — click a date to add an entry, click an entry to edit or delete it, all without leaving the calendar
- **Public calendar** — embed anywhere with a shortcode; month, week, day and agenda views
- **Dark gaming theme** — styled to match a gaming site aesthetic out of the box
- **Cover art in calendar cards** — game covers render directly on the calendar tile
- **Hover tooltips** — rich tooltip on hover showing cover, date, developer, platforms
- **Click-to-detail modal** — click any event to see full details; visit page button if a URL is set
- **Type filters** — toggle game releases and gaming events independently
- **Discord notifications** — push releases, events and DLC to a Discord channel via webhook, with instant, daily, countdown and weekly-digest triggers, rich embeds, optional `@everyone`/role pings, and per-entry overrides
- **Import logs** — a **Logs** page recording everything that entered the calendar, automatically (scheduled IGDB import) or manually (quick-add), plus auto-import runs, cancellations and deletions; filter by source and jump to any entry
- **Auto-updates** — updates delivered via GitHub Releases, installable from the WP admin

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- [IGDB API credentials](https://dev.twitch.tv/docs/api/) (free — requires a Twitch account)
- *(optional)* A Discord channel webhook, for community notifications

---

## Installation

1. Download the latest `game-calendar.zip` from [Releases](https://github.com/JordyDW/game-calendar/releases)
2. WP Admin → Plugins → Add New → Upload Plugin → install and activate
3. Go to Settings → Permalinks → Save Changes (flushes rewrite rules)
4. Go to Game Calendar → Settings → enter your IGDB Client ID and Client Secret

---

## IGDB Setup

IGDB uses Twitch OAuth2. To get credentials:

1. Sign in at [dev.twitch.tv](https://dev.twitch.tv/console/apps)
2. Click **Register Your Application**
3. Name: anything (e.g. `My Game Calendar`)
4. OAuth Redirect URL: `http://localhost`
5. Category: **Application Integration**
6. Copy the **Client ID** and generate a **Client Secret**
7. Paste both into Game Calendar → Settings → Test Connection to verify

---

## Discord Notifications

Push calendar updates to a Discord community channel via an [incoming webhook](https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks). No bot or OAuth required.

### 1. Create a webhook in Discord

1. Open the target channel → **Edit Channel** (gear icon) → **Integrations** → **Webhooks** → **New Webhook**
2. Give it a name and avatar — these appear on every message; the plugin doesn't override them
3. Click **Copy Webhook URL**

### 2. Connect it in WordPress

Go to **Game Calendar → Settings → Discord Notifications**:

1. Paste the **Webhook URL**
2. Click **Send Test Message** — a sample embed should land in your channel within a second (you can test before saving)
3. Pick your **triggers**, **entry types**, **mention** and **schedule**, then **Save Settings**

### Triggers

| Trigger | When it fires |
| --- | --- |
| **Instant** | Immediately, the first time a release/event/DLC is published |
| **Daily** | Every day at the configured **daily time** (default **09:00** site time), lists everything releasing that day |
| **Countdown** | Same daily run as above (default **09:00** site time), a reminder a set number of days before a release |
| **Weekly digest** | Once a week on the configured **weekday** (default **Monday**) at the configured **weekly time** (default **09:00** site time), recaps the coming 7 days |

Each toggles independently. **Entry types** (game releases, gaming events, DLC) control what's eligible across all triggers.

When the **Instant** trigger is on, the calendar quick-add modal shows an **Announce on Discord** checkbox (ticked by default). Untick it to add an entry silently — the instant alert is skipped, but the entry still appears in the scheduled daily, countdown and weekly digests. To suppress an entry across *every* trigger, use the per-entry **Don't announce this entry on Discord** option instead (see below).

### Mentions (pings)

Leave **Mention** blank for no ping, or enter:

- `@everyone` or `@here`
- a role mention `<@&ROLE_ID>` — turn on **User Settings → Advanced → Developer Mode** in Discord, then **Server Settings → Roles → ⋯ → Copy Role ID**

The plugin sends the matching `allowed_mentions`, so the ping actually notifies members (Discord suppresses pings otherwise).

### Schedule & timezone

Daily, countdown and weekly times use your site's timezone (**Settings → General**). Changing them re-arms the schedule automatically when you save.

> **Note:** Scheduled triggers run on WP-Cron, which fires on site traffic — so on a quiet site the 09:00 digest goes out on the first visit after 09:00. For exact timing, [disable WP-Cron](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/) and point a real server cron at `wp-cron.php`. Sends that hit Discord's rate limit are retried once; the daily/countdown reminders that fail are re-tried on the next run rather than dropped.

### Per-entry overrides

Each release/event/DLC edit screen has a **Discord** box that overrides the global settings for that entry:

- **Don't announce this entry** — skip it on every trigger
- **Mention override** — ping a different role (e.g. `@everyone` for a big launch)
- **Countdown days** — a custom lead time for that one entry

### Link back to your calendar

Set **Calendar page URL** to the page where you placed the `[game_calendar]` shortcode, and every embed gains a **📅 View the full calendar** link — a one-click path from Discord back to the site. Leave it blank to omit the link entirely.

### Branding

The message name and avatar come from the webhook (step 1). The optional **Footer text** adds a small line at the bottom of each embed (defaults to the site name).

---

## Usage

### Shortcode

Embed the calendar on any page or post:

```
[game_calendar]
```

Optional height parameter (default `650px`):

```
[game_calendar height="800px"]
```

### Adding entries

In the WP admin, go to **Game Calendar**. Either:
- Click any date on the calendar to open the quick-add modal
- Click the **Add Release or Event** button in the toolbar

For game releases, type in the IGDB search box to auto-fill all fields from the IGDB database.

### Editing entries

Click any event on the admin calendar → click **Edit** in the popover. The modal opens pre-filled — no WP post edit screen required.

---

## Development

Requires [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

```bash
npm install -g @wordpress/env
cd game-calendar
wp-env start
```

WordPress runs at `http://localhost:8888`
WP Admin at `http://localhost:8888/wp-admin` (admin / password)

### Seed test data

```bash
wp-env run cli wp eval-file wp-content/plugins/game-calendar/seed.php
```

Inserts 10 game releases and 4 gaming events spread across the current period.

### Restart environment after changes

```bash
wp-env stop && wp-env start
```

---

## Releasing an update

1. Make your changes
2. Bump the version in `game-calendar.php` (e.g. `1.0.0` → `1.1.0`) in **both** places: the `Version:` plugin header and the `GC_VERSION` constant — they must match
3. Rebuild the zip:
   ```bash
   cd .. && zip -r game-calendar.zip game-calendar \
     --exclude "game-calendar/.wp-env.json" \
     --exclude "game-calendar/.wp-env.php.ini" \
     --exclude "game-calendar/seed.php" \
     --exclude "game-calendar/.git/*"
   ```
4. Push to GitHub
5. Create a new release: tag `v1.1.0`, attach the zip
6. Any site running the plugin will see "Update available" in WP Admin → Plugins

---

## Plugin structure

```
game-calendar/
├── game-calendar.php              # Bootstrap, autoloader, updater
├── includes/
│   ├── class-post-types.php       # CPT + taxonomy registration
│   ├── class-meta-boxes.php       # Admin meta boxes
│   ├── class-igdb-api.php         # IGDB OAuth + search
│   ├── class-calendar-query.php   # REST endpoint + shortcode
│   ├── class-admin-calendar.php   # Admin calendar page + AJAX
│   ├── class-settings.php         # Settings page
│   ├── class-discord-notifier.php # Discord webhook notifications + cron
│   ├── class-igdb-importer.php    # Scheduled IGDB auto-import + date sync
│   ├── class-import-log.php       # Import/activity log store + backfill
│   └── lib/
│       └── plugin-update-checker/ # Auto-updater library
├── admin/
│   ├── js/admin-calendar.js       # Admin calendar + modal JS
│   ├── js/igdb-search.js          # IGDB search on post edit screens
│   ├── css/admin-calendar.css     # Admin calendar styles
│   └── css/admin.css              # Post edit screen styles
├── public/
│   ├── js/calendar.js             # Public calendar JS
│   └── css/calendar.css           # Public calendar styles
└── templates/
    └── calendar.php               # Shortcode template
```

---

## License

[GPL v2 or later](LICENSE)

---

## Credits

- [FullCalendar](https://fullcalendar.io/) — calendar UI
- [IGDB API](https://api-docs.igdb.com/) — game metadata
- [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) — auto-updates
