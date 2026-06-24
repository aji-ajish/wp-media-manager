=== Media Route & Replace ===
Contributors: ajiajish
Tags: media, custom urls, url rewriting, webp, 301 redirect, replace media, uploads, seo, clean urls
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take full control of your WordPress media URLs. Create clean custom paths, seamlessly replace files without breaking links, and manage powerful 301/302/404 redirect rules.

== Description ==

Are you tired of ugly, date-based WordPress upload URLs like `/wp-content/uploads/2024/05/image.png`? 

**Media Route & Replace** is a powerful URL management suite that gives you complete control over how your media files are served. It is built around 3 core workflows designed for performance, SEO, and ease of use.

### 🎯 1. Clean & Custom Media Paths
Transform messy upload URLs into beautiful, human-readable paths.
*   Change `/wp-content/uploads/2024/05/report.pdf` → `/downloads/annual-report.pdf`
*   Optionally hide or show file extensions (e.g., `/images/logo` instead of `/images/logo.png`).
*   **Smart WebP Mapping:** Automatically maps and serves `.webp` versions for modern browsers without altering your database.
*   **Deep Integration:** Works flawlessly with standard WordPress galleries, ACF (Advanced Custom Fields), and page builders via our dual-layer filtering system.

### 🔄 2. Smart Media Replacement
Need to update an image or PDF but don't want to change the URL or lose SEO rankings?
*   Replace the actual file on the server directly from the admin panel.
*   **Keep or Update Dates:** Choose to keep the original upload date (for SEO) or update it to the current date (to move it to a new month folder).
*   **Rename on the Fly:** Optionally use the newly uploaded file's name while automatically updating all custom path mappings.
*   Bypasses CDN and cache issues seamlessly with built-in cache-busting.

### 🔀 3. Advanced Redirection Rules (301, 302, 404)
A lightweight, high-performance redirect manager built right into the plugin.
*   **Multi-Domain Support:** Easily redirect traffic from an old domain (e.g., `https://olddomain.com/blog-post`) to your new site paths.
*   **Flexible Rules:** Set up 301 (Permanent), 302 (Temporary), or 404 (Not Found) responses.
*   **Hit Tracking:** See how many times a redirect rule has been triggered directly in the admin UI.
*   **Zero Conflict:** Uses smart memory caching to handle thousands of rules without slowing down your website.

### 🔧 How It Works (Under the Hood)
Unlike basic redirect plugins, Media Route & Replace uses a safe "Dual-Layer" architecture:
*   **Layer 1:** Intercepts URLs at the PHP data level (before HTML is generated) for zero performance overhead.
*   **Layer 2:** Uses a highly optimized output buffer with regex extraction to catch hardcoded theme URLs (like WebP `<picture>` tags) without causing memory exhaustion.

== Installation ==

1. Upload the `media-route-and-replace` folder to the `/wp-content/plugins/` directory, or install it directly through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Media Manager** in your admin sidebar to start creating custom paths.
4. Go to **Redirect Rules** to manage your 301/302 redirects.

== Frequently Asked Questions ==

= Will this break my existing images if I deactivate the plugin? =
No. The plugin does not physically move your files or change WordPress core data. If you deactivate it, your site will simply revert to the default `/wp-content/uploads/...` URLs. 

= Does it work with page builders like Elementor or Divi? =
Yes. The plugin hooks deep into WordPress core filters (`wp_get_attachment_url`, `the_content`, etc.) and has specific compatibility built-in for ACF image and file fields.

= What happens if I replace a file using the Media Replacer? =
The plugin safely overwrites the physical file on the server. Your custom URL remains exactly the same, so you don't lose any SEO value or social media shares.

= Can I redirect an entire old domain to my new WordPress paths? =
Yes! In the Redirect Rules tab, simply paste the full old URL (e.g., `https://olddomain.com/about-us`) as the source, and your new path (e.g., `/about`) as the target.

= Does this work with caching plugins like WP Rocket? =
Yes. The plugin automatically clears its own internal URL mapping cache whenever a file is replaced or a path is updated. For hard caches, a simple "Purge All" in your caching plugin will reflect the new URLs.

== Changelog ==

= 1.0.0 =
* Initial release.
* Core Feature: Custom media path mapping with extension control.
* Core Feature: Smart WebP companion auto-mapping.
* Core Feature: Advanced media file replacement (keep date, rename, change date).
* Core Feature: Redirect Rules manager (301, 302, 404) with multi-domain support.
* Performance: Dual-layer URL replacement to prevent memory leaks.
* Security: Full XSS, SQL Injection, and CSRF protection.