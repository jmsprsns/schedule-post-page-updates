=== Schedule Post and Page Updates ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: schedule updates, scheduled revisions, find and replace, update year, content freshness
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schedule changes to published posts and pages. Edit a copy that replaces the live version on the date you pick, or schedule a find and replace (2026 to 2027) that runs on its own, every year if you like.

== Description ==

WordPress can schedule a new post, but not a change to one that's already live. This plugin adds that, two ways.

**Scheduled updates.** Click Schedule Update on any published post or page. You get a private copy in the normal editor (block editor or classic). Change anything: text, title, images, categories, tags, the featured image, SEO fields, custom fields. Pick a date and time. At that moment your changes replace the live version. Until then, visitors keep seeing the current one.

* Only what you changed is applied. If someone fixes a typo on the live post in the meantime, it stays fixed, unless you changed that same part.
* The address, author, comments and publish date stay the same. The "last modified" date moves, which search engines and many themes show as "Updated on".
* The old version is saved in the post's revisions and in the plugin's history, where one click undoes it.
* Copies never appear on the site, in feeds, sitemaps or search, and pressing Publish on one can't create a second live post.

**Scheduled find and replace.** Change a word, phrase or year in the posts you choose, at a set time. Made for posts like "Best SEO Tools for 2026": on January 1st, 2026 becomes 2027 in the title, content, excerpt and SEO title. Choose "Every year" and it moves on to 2027 to 2028 the year after, and so on.

* Only the text people read changes. Links, image addresses (like /uploads/2026/01/), HTML, shortcodes, scripts and styles are left alone, and text inside blocks stays valid in the editor.
* Whole-word and case matching, so "2026" never matches "20261".
* Preview every match, in context, before you save.
* Pick exact posts, or let it find every post that contains the text.
* Works with Yoast SEO, Rank Math, SEOPress and The SEO Framework titles and descriptions.

**Built for client sites.**

* Whoever can edit the live post can schedule, edit and apply its updates; nobody else can. Changes go live with the permissions of the person who made them, and if they can no longer edit the post by then, nothing is changed and you get an email.
* Find and replace is limited to administrators.
* Every change is in the history with what it replaced. Undo is part by part, and leaves anything edited since alone.
* An hourly check catches changes that a missed WP-Cron run left behind. If the plugin is switched off when something was due, it isn't applied late by surprise; it waits for a new date.
* Optional email alerts when a change goes live, or when one can't be made.

Find it under Content Powered > Schedule Post and Page Updates in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `schedule-post-page-updates` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Hover over a published post in Posts or Pages and click Schedule Update, or go to Content Powered > Schedule Post and Page Updates.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Does it work with the classic editor? =

Yes. In the block editor, the schedule is in a Scheduled Update panel in the sidebar. In the classic editor, it's in the Publish box, where Publish becomes Schedule Update.

= What happens to the copy after it goes live? =

It's deleted. The live post now has its content, the version it replaced is in the post's revisions and the plugin's history, and you can undo it from the History tab.

= Does it change the URL or the publish date? =

No. The slug and publish date stay the same unless you choose "Also move the publish date" in the settings.

= What if WP-Cron doesn't run on time? =

WordPress runs scheduled tasks when someone visits the site, so on a quiet site a change can go live a little after its time. An hourly check applies anything overdue. If your host switches WP-Cron off (DISABLE_WP_CRON), make sure a server cron job runs wp-cron.php; the plugin's status box warns you when it hasn't run.

= Will find and replace break my images or links? =

No. It only changes the text people read. Image addresses, links, HTML attributes, shortcodes, scripts and styles are skipped.

== Changelog ==

= 1.0.0 =
* First release: scheduled updates (block and classic editor), scheduled find and replace with yearly repeats, history with undo, email alerts.

See https://github.com/jmsprsns/schedule-post-page-updates/releases for the full release history.
