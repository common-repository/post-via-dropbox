=== Post via Dropbox ===
Contributors: PaoloBe
Tags: dropbox, post via dropbox, remote update, post, posting, remote
Requires at least: 3.0.0
Tested up to: 4.9.5
Stable tag: 2.20
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Post via Dropbox allows you to post or edit your blog with text files uploaded via Dropbox. It just works seamlessly without any effort.

== Description ==

**Post via Dropbox** is a easy way to update your blog using **Dropbox**, the famous cloud sharing service. It permits to add or edit your posts with text files uploaded via Dropbox.

Once you linked your Dropbox Account, you can upload text files into your Dropbox folder for updating your blog. It supports also **Markdown** syntax.
Everything happens automatically and without further actions on your part.

Please read the readme file or Help & Faq section for further informations and instructions.

Post via Dropbox requires **Wordpress 3.0 (and above), PHP 5.3.0 (and above) and a Dropbox account**.


== Installation ==

1. Upload the contents of post-via-dropbox.zip to the /wp-content/plugins/ directory or use WordPress's built-in plugin install tool.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to 'Settings' -> 'Post via Dropbox' menu, link your Dropbox account and configure the plugin.
4. Read Help & Faq section for usage instructions.

== Frequently Asked Questions ==

= How it works? =

**Post via Dropbox** checks automatically the existance of new files in your Dropbox folder and then it proceeds to update your blog. Once posted, the text files is moved into subidirectory "posted", if you have not selected "delete" option. It supports also **Markdown syntax**!

= Some examples of what you can do =

You can post using only your **favorite text editor** without opening browser.
You can post **a bunch of posts at a time** or it can make more easy **the import process** from another platform.
You can post from your **mobile device** using a text editor app with Dropbox support.
There're many ways of using it: text files are very flexible and they can adapt without much efforts.

= Where do I put my text files? =

Text files must be uploaded inside **Dropbox/Apps/Post_via_Dropbox/** . Once posted, the text files is moved into subidirectory "posted", if you have not selected "delete" option. The text file may have whatever extensions you want (default .txt) and it should have UTF-8 encoding.

= How should be the text files? =

Why WordPress is able to read informations in proper manner, you must use some tags like **[title] [/title]** and **[content] [/content]**.
If you have selected **"Simplified posting"**, you can avoid using these tags: the title of the post will be the filename while the content of the post will be the content of the text file. It is very fast and clean.
Moreover, you can formatted your post with **Markdown syntax** (selecting the "Markdown option").

= How can I edit an existing post? =

You need to specify the ID of the post, there're two ways: 1) using [id] tag or 2) prepend to filename the ID (example: 500-filename.txt).
The quickest way to edit an existing post, already posted via Dropbox, is to move the file from the subfolder 'posted' to the principal folder.

= This is the list of the tags that you can use (if you have not selected "Simplified posting"): =

* **[title]** post title **[/title]** (mandatory)
* **[content]** the content of the post (you can use Markdown syntax) **[/content]** (mandatory)
* **[category]** category, divided by comma **[/category]**
* **[tag]** tags, divided by comma **[/tag]**
* **[status]** post status (publish, draft, private, pending, future) **[/status]**
* **[excerpt]** post excerpt **[/excerpt]**
* **[id]** if you want to modify an existing post, you should put here the ID of the post **[/id]**
* **[date]** the date of the post (it supports english date format, like 1/1/1970 00:00 or 1 jan 1970 and so on, or UNIX timestamp) **[/date]**
* **[sticky]** stick or unstick the post (use word 'yes' / 'y' or 'no' / 'n') **[/sticky]**
* **[customfield]** custom fields (you must use this format: field_name1=value & field_name2=value ) **[/customfield]**
* **[taxonomy]** taxonomies (you must use this format: taxonomy_slug1=term1,term2,term3 & taxonomy_slug2=term1,term2,term3) **[/taxonomy]**
* **[slug]** the name (slug) for you post **[/slug]**
* **[comment]** comments status (use word 'open' or 'closed') **[/comment]**
* **[ping]** ping status (use word 'open' or 'closed') **[/ping]**
* **[post_type]** Post Type (e.g. 'post', 'page'..) **[/post_type]**

The only necessary tags are [title] and [content]

== Screenshots ==

1. Options page

== Changelog ==

= 2.20 =
*   Plugin working again!
*   Change method for linking Dropbox account (Manual mode)

= 2.10 =
*   Added Post Type support
*   Fixed minor bug in manual mode for WP 3.9+

= 2.00 =
*	Added Markdown support
*	Added new feature (Simplified posting)
*	Fixed minor bug

= 1.10 =
*	Fixed minor bugs
*	Added new features (Date, Custom fields, Taxonomies, Sticky, Comment/Ping status, Slug name support)

= 1.00 =
*	Initial release
