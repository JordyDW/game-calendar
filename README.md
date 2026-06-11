# Game Calendar

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
- **Auto-updates** — updates delivered via GitHub Releases, installable from the WP admin

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- [IGDB API credentials](https://dev.twitch.tv/docs/api/) (free — requires a Twitch account)

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
2. Bump `GC_VERSION` in `game-calendar.php` (e.g. `1.0.0` → `1.1.0`)
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
