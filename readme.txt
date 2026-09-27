=== QuickCall Connect ===
Contributors: thuexemayhanoi
Tags: contact, call button, zalo, floating button, chatbot link
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight contact launcher: call, Zalo and map buttons, plus optional social, messaging and chatbot links.

== Description ==

QuickCall Connect adds a small contact launcher to your website:

* Call, Zalo and Map/Directions buttons (shown only when you fill them in)
* Optional links for Facebook, Messenger, WhatsApp, TripAdvisor, YouTube, TikTok, Instagram, X, Pinterest, Reddit, Email, SMS and Telegram — any channel you leave empty stays completely hidden
* One Custom Link / Chatbot slot pointing to any http(s) URL: an AI chatbot, booking page, menu or contact form

Placement modes:

* Floating right
* Floating left
* Bottom dock (safe on 320px screens, scrolls horizontally instead of breaking the layout)

Built to stay light:

* Vanilla JavaScript, plain CSS, bundled SVG icons
* No jQuery, no frameworks, no CDN, no trackers, no cookies, no remote requests
* Assets are only loaded when the widget is enabled and at least one channel is active

Appearance controls cover colors, size, gap, corner radius, offsets, labels, subtle animation and new-tab behaviour. All settings are sanitized and all output is escaped.

== Installation ==

1. Upload the `quickcall-connect` folder to the `/wp-content/plugins/` directory, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to Settings → QuickCall Connect and fill in at least Call, Zalo or Map.
4. Leave any channel you do not use empty — it will not appear on your site.

== Frequently Asked Questions ==

= Where do my settings go when I deactivate the plugin? =

They stay in the database, so reactivating restores your configuration. Deleting the plugin from the Plugins screen removes its settings.

= The widget does not appear =

The widget renders only when the plugin is enabled and at least one channel has a valid value (a phone number, or a full http(s) URL).

= Can I change the button text? =

Yes. Every channel has an optional custom label field; it is plain text only, so HTML is not allowed.

== Screenshots ==

1. Settings page
2. Floating launcher on a website

== Changelog ==

= 0.1.0 =

* Initial release: Call, Zalo, Map, 14 optional channels, Custom Link/Chatbot slot.
* Placement modes: floating right, floating left, bottom dock.
* Appearance presets: colors, size, gap, radius, offsets, labels, animation, new tab.
* Vanilla JS + plain CSS frontend with keyboard and Escape support, reduced-motion friendly, no-JS fallback.
