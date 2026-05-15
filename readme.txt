=== Imaginasite Per Page CSS ===
Contributors: Imaginasite
Tags: css, custom css, editor, gutenberg, per page css
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.5.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add page-specific CSS directly from the editor with live preview support in Gutenberg.

== Description ==

Imaginasite Per Page CSS is a lightweight utility that allows administrators to add custom CSS directly to individual posts, pages, and custom post types.

The CSS is automatically injected into the `<head>` of the content being viewed on the frontend, allowing you to apply styles only where they are needed instead of bloating your global stylesheet.

The plugin integrates directly into the editor and provides live preview support in Gutenberg for a faster and more intuitive workflow.

=== Why this plugin? ===

Many WordPress websites become harder to maintain over time. As new pages are added — especially custom landing pages or highly specific layouts — the main stylesheet often grows larger and more difficult to manage.

This plugin helps you keep your CSS local, organized, and maintainable by applying styles only to the content where they belong.

Gutenberg already covers many styling needs, but it is unlikely that it will ever support the full flexibility of CSS. This plugin is designed for the situations where custom CSS is still necessary.

=== Features ===

* Add specific CSS to posts, pages, and custom post types
* Automatic CSS injection into the frontend `<head>`
* Real-time live preview in the Gutenberg editor
* Compatible with both Gutenberg and Classic Editor
* Integrated CodeMirror editor with syntax highlighting and code folding
* Minimalist approach: no settings page, no ads, no unnecessary options
* Restricted to administrators (`manage_options` and `unfiltered_html`) for better control
* CSS-only input with administrator-only access and validation safeguards

=== When to use it ===

This plugin is useful when:
* Gutenberg does not allow the styling you need
* CSS should only apply to a specific page or post
* You want to avoid growing a large global stylesheet
* You are maintaining older or client websites with complex CSS structures

=== When NOT to use it ===

This plugin is not intended for global styling.

For site-wide CSS, consider using:
* `theme.json`
* Appearance → Editor → Additional CSS
* Your theme customizer
* Your theme stylesheet (`style.css`)

=== Why this plugin instead of another one? ===

Several plugins already provide per-page custom CSS functionality, some with many more options.

Imaginasite Per Page CSS focuses on simplicity and editing comfort:

* CSS is edited directly in the sidebar instead of at the bottom of the page
* Live preview makes editing much more comfortable in Gutenberg
* No additional admin menus or configuration screens
* Lightweight and focused on a single task

=== Limitations ===

CSS added with this plugin is only injected on the individual post or page currently being viewed.

It will not automatically apply to:
* Archive pages
* Category pages
* Blog indexes
* Search results
* Other global views

If you need global styles, use your theme CSS instead.

=== Documentation ===

For more details and use cases:
https://www.imaginasite.com/per-page-css-wordpress-plugin/

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/imaginasite-per-page-css` directory, or install the plugin directly through the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Edit any post, page, or custom post type.
4. A new "Per Page CSS" panel will appear in the Gutenberg sidebar or as a meta box in the Classic Editor.

== Frequently Asked Questions ==

= Is the CSS applied on archive or listing pages? =

No. To prevent unintended CSS conflicts, the custom CSS is only injected when viewing the individual post or page.

= Who can edit the CSS? =

Only administrators or users with the `manage_options` and `unfiltered_html` capabilities can edit the CSS field.

= Where is the CSS output? =

The CSS is injected into the `<head>` tag of the frontend page output.

= What happens if I uninstall the plugin? =

CSS added through the plugin will no longer be injected into your pages or posts.

= Is the plugin secure? =

The plugin restricts CSS editing to administrators with proper privileges and sanitizes output before rendering on the frontend.

== Screenshots ==

1. Gutenberg sidebar editor with live preview support
2. Editing page-specific CSS directly inside Gutenberg
3. Classic Editor metabox integration
4. CodeMirror editor with syntax highlighting and code folding

== Upgrade Notice ==

= 1.5.2 =
Major update: Extended custom CSS functionality to Full Site Editing (FSE) Templates (`wp_template`), allowing per-template CSS injection natively in the Site Editor with full WordPress 6.6+ compatibility and improved template resolution.

= 1.5.1 =
Major update: Extended custom CSS functionality to Full Site Editing (FSE) Templates (`wp_template`), allowing per-template CSS injection natively in the Site Editor with full WordPress 6.6+ compatibility.

= 1.5.0 =
Major update: Extended custom CSS functionality to Full Site Editing (FSE) Templates (`wp_template`), allowing per-template CSS injection natively in the Site Editor.

= 1.2.7 =
This version significantly improves security and user experience with real-time CSS validation, editor locking, and enhanced data protection.

= 1.2.1 =
Prevented script from loading on Site Editor and FSE template screens for better compatibility.

== Changelog ==

= 1.5.2 =

* Fix: Improved FSE template resolution fallback logic using native `get_block_template` for reliable styling.

= 1.5.1 =

* Fix: Resolved REST API constraints and unified Site Editor compatibility for WordPress 6.6+.
* Feature: Extended support to FSE Templates (`wp_template`).
* Improvement: Added contextual notices and improved UI integration within the Site Editor.

= 1.5.0 =

* Feature: Extended support to FSE Templates (`wp_template`).
* Improvement: Added contextual notices and improved UI integration within the Site Editor.

= 1.2.7 =

* Enhanced security and UX: Implemented real-time CSS validation with editor locking in Gutenberg, added error notices, and refined server-side data protection to prevent accidental loss of valid CSS.

= 1.2.6 =

* Improved UX: Added server-side protection to prevent overwriting valid CSS with invalid input, and implemented real-time validation notices in Gutenberg.

= 1.2.4 =

* Enhanced security: Refined CSS sanitization, added server-side validation for output, and implemented revision checks.

= 1.2.3 =

* Enhanced security: Added server-side CSS validation and refined sanitization.

= 1.2.2 =

* Enhanced security: Added `unfiltered_html` capability check for CSS editing.

= 1.2.1 =

* Fix: Prevented CSS panel and JavaScript from loading on Site Editor and FSE template screens to avoid conflicts.

= 1.2.0 =

* Initial release for the WordPress.org repository.