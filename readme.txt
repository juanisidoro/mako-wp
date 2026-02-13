=== MAKO - AI-Optimized Content ===
Contributors: makoprotocol
Tags: ai, llm, markdown, content-negotiation, seo, mako
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Serve LLM-optimized markdown content via HTTP content negotiation. Reduces AI token consumption by ~94%.

== Description ==

**MAKO** implements the [MAKO Protocol](https://makospec.vercel.app) for WordPress, enabling your site to serve optimized markdown content to AI agents via HTTP content negotiation.

When an AI agent sends `Accept: text/mako+markdown`, your WordPress site responds with a clean, structured markdown file instead of raw HTML. This reduces token consumption by approximately **94%** while preserving all semantic meaning, structured metadata, and actionable links.

= How It Works =

1. An AI agent requests your page with the header `Accept: text/mako+markdown`
2. MAKO-WP intercepts the request and serves optimized markdown with MAKO headers
3. The agent receives clean, structured content at a fraction of the token cost
4. Regular browsers continue to see your normal HTML pages

= Key Features =

* **Automatic MAKO generation** for posts, pages, and WooCommerce products
* **Content negotiation** - serves MAKO when AI agents request it, HTML otherwise
* **Full MAKO spec compliance** - frontmatter, semantic links, actions, proper HTTP headers
* **WooCommerce integration** - product data, pricing, stock status, add-to-cart actions
* **SEO plugin integration** - uses Yoast SEO and Rank Math data for richer metadata
* **ACF support** - includes Advanced Custom Fields data in MAKO output
* **MAKO Sitemap** at `/.well-known/mako.json` for agent discovery
* **REST API** for programmatic access and integration
* **Admin dashboard** with token savings statistics
* **Post editor meta box** with MAKO preview and regeneration
* **Bulk generation** for existing content
* **Caching with ETags** for optimal performance
* **`<link rel="alternate">` injection** for MAKO discoverability

= Supported Content Types =

MAKO detects and maps your content to the appropriate type:

* **Posts** -> `article`
* **Pages** -> `landing`, `docs`, `faq`, or `profile` (auto-detected)
* **Products** -> `product` (with WooCommerce data)
* **Custom post types** -> configurable mapping

= Requirements =

* WordPress 6.0+
* PHP 8.0+
* Optional: WooCommerce 7.0+ for product support
* Optional: Yoast SEO or Rank Math for enhanced metadata
* Optional: ACF for custom fields support

== Installation ==

1. Upload the `mako-wp` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to Settings > MAKO to configure
4. Click "Generate All Missing" in the MAKO Dashboard to generate content for existing posts

= Verify It Works =

Test with curl:

`curl -H "Accept: text/mako+markdown" https://your-site.com/your-post/`

You should see a MAKO markdown file with YAML frontmatter and optimized content.

== Frequently Asked Questions ==

= What is the MAKO Protocol? =

MAKO (Markdown Agent Knowledge Optimization) is an open standard for serving LLM-optimized web content. It defines how web servers respond with clean markdown when AI agents request it, reducing token consumption while preserving semantic meaning.

= Does this affect my site for regular visitors? =

No. MAKO-WP only responds with markdown when the request includes `Accept: text/mako+markdown`. Regular browsers receive your normal HTML pages as usual.

= Does it work with page builders? =

Yes. MAKO-WP processes the rendered HTML output, so it works with Gutenberg, Classic Editor, Elementor, Divi, and other page builders.

= Is there a performance impact? =

Minimal. MAKO content is generated on post save and cached. Serving cached MAKO content is faster than rendering HTML. The plugin uses WordPress transients and ETags for efficient caching.

= What data is exposed in MAKO files? =

Only public content from published posts. MAKO-WP automatically strips admin URLs, user data, credentials, and private content. The output is essentially a clean markdown version of what's already publicly visible on your site.

= Can I customize the generated MAKO content? =

Yes. The plugin provides extensive WordPress filters:
* `mako_content_type` - override detected content type
* `mako_frontmatter` - modify frontmatter data
* `mako_body` - modify markdown body
* `mako_content` - modify complete MAKO output
* `mako_response_headers` - modify HTTP headers
* And many more (see documentation)

== Screenshots ==

1. MAKO Dashboard with token savings statistics
2. Post editor meta box with MAKO status and preview
3. Settings page with content negotiation options
4. MAKO preview modal showing generated markdown

== Changelog ==

= 1.0.0 =
* Initial release
* MAKO spec v1.0 compliance
* Content negotiation handler
* HTML to Markdown converter
* Content type auto-detection
* Semantic link extraction
* Action/CTA detection
* WooCommerce product integration
* Yoast SEO and Rank Math integration
* ACF custom fields support
* REST API endpoints
* MAKO Sitemap (/.well-known/mako.json)
* Admin dashboard with statistics
* Post editor meta box with preview
* Bulk generation support
* ETag-based caching
* WPML/Polylang language support

== Upgrade Notice ==

= 1.0.0 =
Initial release. Install to start serving AI-optimized content from your WordPress site.
