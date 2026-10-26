# Schedule Post and Page Updates

Schedule changes to published posts and pages. Edit a copy that replaces the live version on the date you pick, or schedule a find and replace (2026 to 2027) that runs on its own, every year if you like.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

WordPress can schedule a new post, but not a change to one that's already live. This plugin adds two ways to do that, both under Content Powered > Schedule Post and Page Updates.

- **Scheduled updates.** "Schedule Update" on a published post or page makes a private copy you edit in the normal editor (block or classic). Pick a time; at that moment, the parts you changed replace the live version. The URL, author, comments and publish date stay the same, and the old version is kept in revisions and in the plugin's history.
- **Scheduled find and replace.** Replace a word, phrase or year in chosen posts (or every post that contains it) at a set time, once or every year. A yearly rule counts the years up by one after each run: 2026 → 2027, then 2027 → 2028.
- **History and undo.** Every change is logged with what it replaced. Undo works part by part and leaves anything edited since alone.

## How it works

### Scheduled updates

A copy is a post of the same type with its own status, `sppu_update` ("Scheduled Update"). The status is not public, so copies 404 for visitors and never appear in feeds, sitemaps or search, even if the plugin is removed. They are listed under the "Scheduled Updates" view on the Posts and Pages screens, not under "All".

When a copy is made, the plugin stores a fingerprint (hashes) of its title, content, excerpt, parent, order, comment settings, password, terms and post meta. When it goes live, only the parts that no longer match that fingerprint are written to the live post. So a live edit made in the meantime (a typo fix, a view counter) survives, unless the copy changed that same part.

Applying an update:

1. Takes an atomic lock (an `INSERT IGNORE` row in the options table), so two overlapping cron runs can't apply it twice.
2. Checks that everyone who scheduled or last edited the copy can still edit the live post, then switches to the user who last edited it, so WordPress filters the HTML for that user (`unfiltered_html` or not).
3. Saves a revision of the live post, writes meta and terms, then calls `wp_update_post()`, so SEO and caching plugins see the finished post.
4. Records the old values in the history, deletes the copy and sends the optional email.

If anything fails (the live post was trashed, the person lost access), nothing changes, the copy is kept and unscheduled, and the error shows in the list, the editor and an email.

Publishing a copy is impossible: `wp_insert_post_data` keeps copies in their own status whatever the editor sends, and only copies made by the plugin may hold it (anyone else asking for `sppu_update` gets a draft). `map_meta_cap` ties every permission on a copy (`edit_post`, `read_post`, `delete_post`, also for its revisions) to `edit_post` on the live post.

### Find and replace

Rules live in the `{prefix}sppu_rules` table and run on WP-Cron in batches of 20 posts, up to 20 seconds per request, carrying on in the next cron run. Content is split into the text people read and everything else: tags and attributes, comments, shortcode tags, bare URLs, `<script>` and `<style>` are never changed. Strings inside block settings (`<!-- wp:name {...} -->`) are changed too, except for keys like `url`, `id`, `className` and anything that looks like a link or path, so blocks stay valid. The replacement is plain text and is HTML-escaped when it goes into content.

A rule runs as the administrator who last saved it and only touches posts that person can edit. If they're no longer an administrator, the rule pauses.

### Timing

Each update and rule has its own single WP-Cron event. An hourly sweep (`sppu_sweep`) applies anything more than two minutes overdue, restores missing events and prunes old history. Deactivating the plugin removes every event; reactivating puts back the future ones and unschedules (with a note) anything that fell due while it was off, so nothing is applied months late by surprise.

## Settings and data

| Setting | Default |
|---|---|
| Post types | Posts and Pages (any public type with an editor can be added) |
| When a change goes live | Keep the publish date ("last modified" still moves) |
| Email alerts | When a change can't be made, to the admin email |
| Keep history for | 365 days (0 keeps everything) |

Data: the option `schedule_post_page_updates_settings`, the tables `{prefix}sppu_rules` and `{prefix}sppu_log`, copies (posts with status `sppu_update`) and their `_sppu_*` meta. Deleting the plugin from the Plugins screen removes all of it on every site of a multisite, including unapplied copies. Live posts and changes already made are not touched.

### Permissions

- Scheduling, editing, previewing, applying and deleting an update: anyone who can edit the live post.
- The Content Powered page (find and replace, history, settings): `manage_options`.

## Developer reference

### Filters

| Filter | Arguments | Use |
|---|---|---|
| `schedule_post_page_updates_post_types` | `$types` | Post types the plugin works on. |
| `schedule_post_page_updates_skip_meta` | `$skip, $key` | Leave a meta key out of copies and updates. |
| `schedule_post_page_updates_seo_meta_keys` | `$keys` | Meta keys changed by find and replace's "SEO title and description". |
| `schedule_post_page_updates_changes` | `$changes, $original, $copy` | Change the change set an update is about to apply. |

### Actions

| Action | Arguments | When |
|---|---|---|
| `schedule_post_page_updates_copy_created` | `$copy_id, $original_id` | A scheduled update copy was made. |
| `schedule_post_page_updates_applied` | `$post_id, $changes, $log_id` | An update replaced its live post. |

## Automatic updates

Updates come from https://github.com/jmsprsns/schedule-post-page-updates through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `schedule-post-page-updates.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `schedule-post-page-updates.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Push a tag that matches that version (or publish a GitHub release with it):

   ```bash
   git tag -a v1.0.1 -m "Version 1.0.1" && git push origin v1.0.1
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The description, requirements and "Tested up to" in the "View details" popup come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag. PUC uses the latest GitHub release, then the highest version tag, then the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'SCHEDULE_POST_PAGE_UPDATES_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'SCHEDULE_POST_PAGE_UPDATES_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy.

## Structure

```
schedule-post-page-updates.php     Plugin header, constants, boot, activation and deactivation
uninstall.php                      Removes tables, settings, copies and events when deleted
readme.txt                         WordPress readme; feeds the "View details" popup
includes/
  class-…-plugin.php               Main singleton, settings, enabled post types
  class-…-install.php              Tables (dbDelta), created on activation and on upgrade
  class-…-copies.php               The copy status, creating copies, permissions, Posts screen
  class-…-changes.php              Fingerprints, diffs, applying a change set with a backup, undo
  class-…-scheduler.php            Cron events, applying updates, the hourly sweep, locks, emails
  class-…-replace.php              Find and replace: rules, HTML-aware matching, preview, runs
  class-…-log.php                  History table
  class-…-editor.php               Block editor panel data, classic editor box, admin-post actions
  class-…-admin.php                Content Powered > Schedule Post and Page Updates page
  class-…-menu.php                 Shared top-level "Content Powered" menu
  class-…-feedback.php             Shared Submit Feedback and Report a Bug pages
  class-…-updater.php              GitHub update checks (PUC wiring)
  class-…-icons.php                Inline Lucide icon helper
assets/
  admin.css, admin.js              The plugin's page only
  editor.css, editor.js            Post editor panel (block editor), classic editor and Posts screen
  icon.svg                         Icon on the update screens
  content-powered.svg              Sumo logo for the Content Powered menu
icons/                             The Lucide icons the plugin uses (plus LICENSE)
plugin-update-checker/             Plugin Update Checker 5.7 library
```

`editor.js` has no build step: it uses `wp.element.createElement` and the editor's own components, so it works from WordPress 5.8 to 7.x.

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-schedule-post-page-updates-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-schedule-post-page-updates-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

`icons/` holds only the [Lucide](https://lucide.dev/icons) icons the plugin uses. To use another, copy its SVG from the full set into `icons/`, then print it inline with:

```php
echo Schedule_Post_Page_Updates_Icons::get( 'calendar-clock', 20 );        // name, size in px
echo Schedule_Post_Page_Updates_Icons::get( 'history', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`; a missing one prints nothing.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
