# MAKO-WP: WordPress Plugin Architecture

> WordPress plugin for generating and serving MAKO-optimized content via content negotiation.

## 1. Vision & Product Strategy

### Free Tier (v1.0 - Open Source)
- Auto-generate `.mako.md` for posts, pages, and WooCommerce products
- Content negotiation: serve MAKO when `Accept: text/mako+markdown`
- Admin dashboard with token savings stats
- MAKO preview per post/page
- Bulk generation for existing content
- REST API for external access
- `/.well-known/mako.json` sitemap generation
- `<link rel="alternate">` injection in HTML head

### Premium Tier (Future - License Key)
- AI-enhanced MAKO generation (BYOK: OpenAI, Anthropic, etc.)
- CEF embeddings generation and serving (Level 3 compliance)
- Advanced analytics (which agents request MAKO, frequency, top pages)
- MAKO-Score per page with optimization recommendations
- Priority support & automatic updates
- Multisite support
- Custom post type mapping UI
- Webhook notifications on MAKO generation
- CDN integration (Cloudflare, Fastly headers)

---

## 2. Technical Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.0+ |
| WordPress | 6.0+ |
| Optional | WooCommerce 7.0+ |
| HTML Parser | PHP DOMDocument + DOMXPath |
| Markdown | Custom HTML-to-Markdown converter (no external deps) |
| YAML | Symfony YAML component (via Composer) or custom lightweight serializer |
| Storage | WordPress post_meta + options table |
| Caching | WordPress Transients API + object cache |
| Admin UI | React (wp-scripts) or vanilla JS for settings page |
| i18n | WordPress i18n (.pot/.po/.mo) |
| Testing | PHPUnit + WP_UnitTestCase |

---

## 3. Plugin File Structure

```
mako-wp/
├── mako-wp.php                     # Plugin bootstrap (header, activation, deactivation)
├── uninstall.php                   # Clean uninstall (remove all data)
├── composer.json                   # Dependencies (if any)
├── readme.txt                      # WordPress.org readme
├── LICENSE                         # Apache-2.0
│
├── includes/
│   ├── class-mako-plugin.php       # Main plugin orchestrator (singleton)
│   ├── class-mako-activator.php    # Activation hooks (DB setup, defaults)
│   ├── class-mako-deactivator.php  # Deactivation hooks (cleanup)
│   │
│   ├── core/
│   │   ├── class-mako-generator.php      # MAKO file generation orchestrator
│   │   ├── class-mako-content-converter.php  # HTML → clean Markdown
│   │   ├── class-mako-type-detector.php   # Content type detection (product/article/docs...)
│   │   ├── class-mako-entity-extractor.php   # Entity name extraction
│   │   ├── class-mako-link-extractor.php     # Semantic link extraction
│   │   ├── class-mako-action-extractor.php   # CTA/action detection
│   │   ├── class-mako-frontmatter.php     # YAML frontmatter builder
│   │   ├── class-mako-headers.php         # HTTP header generation
│   │   ├── class-mako-token-counter.php   # Token counting
│   │   └── class-mako-validator.php       # Validate generated MAKO files
│   │
│   ├── content-negotiation/
│   │   ├── class-mako-negotiator.php      # Content negotiation handler
│   │   ├── class-mako-rewrite.php         # URL rewrite rules (.mako.md endpoints)
│   │   └── class-mako-response.php        # HTTP response builder
│   │
│   ├── storage/
│   │   ├── class-mako-storage.php         # CRUD for MAKO content (post_meta)
│   │   ├── class-mako-cache.php           # Transient-based caching
│   │   └── class-mako-sitemap.php         # .well-known/mako.json generator
│   │
│   ├── integrations/
│   │   ├── class-mako-woocommerce.php     # WooCommerce product support
│   │   ├── class-mako-yoast.php           # Yoast SEO data extraction
│   │   ├── class-mako-rankmath.php        # Rank Math data extraction
│   │   └── class-mako-acf.php             # ACF custom fields support
│   │
│   ├── api/
│   │   ├── class-mako-rest-controller.php  # REST API endpoints
│   │   └── class-mako-rest-routes.php      # Route registration
│   │
│   └── admin/
│       ├── class-mako-admin.php           # Admin page controller
│       ├── class-mako-admin-settings.php  # Settings page
│       ├── class-mako-admin-dashboard.php # Dashboard/stats page
│       └── class-mako-meta-box.php        # Post editor meta box
│
├── admin/
│   ├── css/
│   │   └── mako-admin.css                 # Admin styles
│   ├── js/
│   │   └── mako-admin.js                  # Admin scripts
│   └── views/
│       ├── settings.php                   # Settings page template
│       ├── dashboard.php                  # Dashboard template
│       └── meta-box.php                   # Meta box template
│
├── public/
│   └── css/
│       └── mako-public.css                # (minimal, for link tag if needed)
│
├── languages/
│   └── mako-wp.pot                        # Translation template
│
└── tests/
    ├── bootstrap.php
    ├── test-generator.php
    ├── test-content-converter.php
    ├── test-type-detector.php
    ├── test-negotiator.php
    ├── test-headers.php
    └── test-validator.php
```

---

## 4. Core Architecture

### 4.1 Plugin Lifecycle

```
Activation:
  → Register default options (enabled post types, auto-generate on/off)
  → Flush rewrite rules
  → Schedule bulk generation cron (optional)

Post Save/Update (save_post hook):
  → Check if post type is enabled
  → Check if post status is 'publish'
  → Generate MAKO content (async via wp_schedule_single_event or sync)
  → Store in post_meta (_mako_content, _mako_headers, _mako_tokens, _mako_updated)
  → Invalidate cache

HTTP Request (template_redirect / parse_request):
  → Check Accept header for 'text/mako+markdown'
  → If match → serve MAKO content with proper headers, die()
  → If no match → continue normal WordPress flow

Deactivation:
  → Flush rewrite rules
  → Optionally clean transients

Uninstall:
  → Delete all _mako_* post meta
  → Delete all mako_* options
  → Delete transients
```

### 4.2 Content Negotiation Flow

```
┌─────────────────────────────────────────────────────┐
│                   HTTP Request                       │
│  GET /my-blog-post/                                  │
│  Accept: text/mako+markdown                          │
└──────────────────────┬──────────────────────────────┘
                       │
                       ▼
            ┌──────────────────┐
            │  WordPress Init  │
            │  (parse_request) │
            └────────┬─────────┘
                     │
                     ▼
         ┌───────────────────────┐
         │ Accept header check   │
         │ text/mako+markdown?   │
         └─────┬───────────┬─────┘
               │           │
              YES          NO
               │           │
               ▼           ▼
     ┌─────────────┐  Normal WP
     │ Find post   │  response
     │ by URL      │  (HTML)
     └──────┬──────┘
            │
            ▼
     ┌──────────────┐
     │ Has cached   │
     │ MAKO content?│
     └──┬───────┬───┘
        │       │
       YES      NO
        │       │
        │       ▼
        │  ┌──────────────┐
        │  │ Generate MAKO│
        │  │ on-the-fly   │
        │  │ + cache      │
        │  └──────┬───────┘
        │         │
        ▼         ▼
     ┌──────────────────┐
     │ Set HTTP headers  │
     │ X-Mako-Version    │
     │ X-Mako-Tokens     │
     │ X-Mako-Type       │
     │ X-Mako-Lang       │
     │ X-Mako-Actions    │
     │ Content-Type       │
     │ Vary: Accept       │
     │ Cache-Control      │
     │ Last-Modified      │
     │ Content-Location   │
     └────────┬──────────┘
              │
              ▼
     ┌────────────────────┐
     │ HEAD? → end()      │
     │ GET?  → send body  │
     └────────────────────┘
```

### 4.3 MAKO Generation Pipeline (mirrors mako-site analyzer)

```
WordPress Post/Page/Product
           │
           ▼
Step 1: Extract raw content
        → post_content (HTML from Gutenberg/Classic)
        → apply_filters('the_content', ...) for shortcodes/blocks
        → WooCommerce: product description + short description
           │
           ▼
Step 2: Count HTML tokens
        → max(words * 1.3, chars / 4)
           │
           ▼
Step 3: Convert HTML → Clean Markdown
        → Strip nav, footer, sidebar, scripts, styles
        → Convert headings, lists, tables, code blocks
        → Preserve semantic structure
        → Clean boilerplate text
           │
           ▼
Step 4: Detect content type
        → post → 'article'
        → page → 'landing' or 'docs' (heuristic)
        → product → 'product'
        → Custom mapping via filter
           │
           ▼
Step 5: Extract entity name
        → post_title (primary)
        → Yoast/RankMath title (if available)
        → Max 100 chars
           │
           ▼
Step 6: Extract semantic links
        → Internal: links to other posts/pages on same site
        → External: links to other domains
        → Context from link text, surrounding content
        → Skip: admin, login, privacy, legal URLs
        → Max 10 internal, 5 external
           │
           ▼
Step 7: Extract actions/CTAs
        → WooCommerce: add_to_cart, purchase, check_availability
        → Contact forms: contact, subscribe
        → Custom: learn_more, download, sign_up
        → Max 5 actions
           │
           ▼
Step 8: Detect language
        → get_locale() → first 2 chars (e.g., 'en_US' → 'en')
        → WPML/Polylang integration if available
           │
           ▼
Step 9: Build MAKO file
        → YAML frontmatter (all required + optional fields)
        → Structured markdown body (section templates per type)
        → Validate against spec
           │
           ▼
Step 10: Count MAKO tokens + calculate savings
         → Store in post_meta
         → Generate HTTP headers JSON
```

---

## 5. Detailed Class Designs

### 5.1 Mako_Generator (Orchestrator)

```php
class Mako_Generator {
    private Mako_Content_Converter $converter;
    private Mako_Type_Detector $type_detector;
    private Mako_Entity_Extractor $entity_extractor;
    private Mako_Link_Extractor $link_extractor;
    private Mako_Action_Extractor $action_extractor;
    private Mako_Frontmatter $frontmatter_builder;
    private Mako_Token_Counter $token_counter;
    private Mako_Validator $validator;

    /**
     * Generate MAKO content for a WordPress post.
     *
     * @param int $post_id WordPress post ID
     * @return array{content: string, headers: array, tokens: int, savings: float}
     */
    public function generate( int $post_id ): array;

    /**
     * Bulk generate MAKO for multiple posts.
     *
     * @param array $post_ids
     * @param callable|null $progress_callback
     * @return array Results per post ID
     */
    public function bulk_generate( array $post_ids, ?callable $progress_callback = null ): array;
}
```

### 5.2 Mako_Content_Converter (HTML → Markdown)

```php
class Mako_Content_Converter {
    // Selectors to remove (same as mako-site analyzer)
    private const REMOVE_SELECTORS = [
        'script', 'style', 'noscript', 'iframe', 'svg', 'form',
        'nav', 'footer', 'header', 'aside',
        // ... class-based patterns for ads, cookies, etc.
    ];

    // Content priority selectors
    private const CONTENT_SELECTORS = [
        'main', 'article', '[role="main"]',
        '.entry-content', '.post-content', '.page-content',
        '.content', '.post', '.entry',
    ];

    /**
     * Convert rendered HTML to clean Markdown.
     *
     * @param string $html Rendered post HTML
     * @param string $base_url Site URL for resolving relative links
     * @return string Clean markdown
     */
    public function convert( string $html, string $base_url ): string;

    /**
     * Convert a single DOM node to Markdown recursively.
     */
    private function convert_node( DOMNode $node, string $base_url ): string;

    /**
     * Clean up generated markdown (normalize whitespace, remove boilerplate).
     */
    private function clean_markdown( string $markdown ): string;
}
```

### 5.3 Mako_Type_Detector

```php
class Mako_Type_Detector {
    /**
     * Detect MAKO content type from WordPress post.
     *
     * Uses post type + content analysis for heuristic detection.
     *
     * @param WP_Post $post
     * @param string  $markdown Converted markdown content
     * @return string One of: product, article, docs, landing, listing, profile, event, recipe, faq, custom
     */
    public function detect( WP_Post $post, string $markdown ): string;
}
```

**Type mapping logic:**
| WordPress Type | Default MAKO Type | Override Condition |
|---------------|-------------------|-------------------|
| `post` | `article` | - |
| `page` | `landing` | `docs` if has 3+ code blocks |
| `product` | `product` | - |
| `event` (custom) | `event` | - |
| `recipe` (custom) | `recipe` | - |
| `faq` (custom) | `faq` | - |
| Other CPT | `custom` | Filterable via `mako_content_type` |

### 5.4 Mako_Negotiator (Content Negotiation)

```php
class Mako_Negotiator {
    /**
     * Hook into WordPress request lifecycle.
     * Registered at 'template_redirect' with priority 1 (before theme).
     */
    public function handle_request(): void;

    /**
     * Check if current request accepts MAKO format.
     */
    private function accepts_mako(): bool;

    /**
     * Send MAKO response with proper headers.
     */
    private function send_mako_response( int $post_id ): void;
}
```

### 5.5 Mako_Headers

```php
class Mako_Headers {
    /**
     * Build HTTP response headers from MAKO data.
     *
     * @param array $frontmatter Parsed frontmatter data
     * @param string $canonical  Canonical URL
     * @return array<string, string> HTTP headers
     */
    public static function build( array $frontmatter, string $canonical ): array;
}
```

**Headers generated:**
```
Content-Type: text/mako+markdown; charset=utf-8
X-Mako-Version: 1.0
X-Mako-Tokens: {tokens}
X-Mako-Type: {type}
X-Mako-Lang: {language}
X-Mako-Actions: {action1, action2}      # if actions exist
Vary: Accept
Cache-Control: public, max-age=3600
Last-Modified: {updated as HTTP-date}
Content-Location: {permalink}
```

### 5.6 Mako_Storage

```php
class Mako_Storage {
    // Post meta keys
    const META_CONTENT    = '_mako_content';      // Full .mako.md string
    const META_HEADERS    = '_mako_headers';       // JSON-encoded headers
    const META_TOKENS     = '_mako_tokens';        // MAKO token count
    const META_HTML_TOKENS = '_mako_html_tokens';  // Original HTML token count
    const META_SAVINGS    = '_mako_savings_pct';   // Savings percentage
    const META_TYPE       = '_mako_type';          // Detected content type
    const META_UPDATED    = '_mako_updated_at';    // Last generation timestamp
    const META_HASH       = '_mako_content_hash';  // MD5 of post_content (change detection)

    public function save( int $post_id, array $data ): void;
    public function get( int $post_id ): ?array;
    public function delete( int $post_id ): void;
    public function needs_regeneration( int $post_id ): bool;
    public function get_stats(): array;  // Global stats for dashboard
}
```

### 5.7 Mako_Sitemap

```php
class Mako_Sitemap {
    /**
     * Generate /.well-known/mako.json with all MAKO-enabled pages.
     *
     * @return array MAKO sitemap structure
     */
    public function generate(): array;

    /**
     * Handle request for /.well-known/mako.json
     */
    public function serve(): void;
}
```

**Output format:**
```json
{
  "mako": "1.0",
  "generator": "mako-wp/1.0.0",
  "site": "https://example.com",
  "pages": [
    {
      "url": "/my-post/",
      "type": "article",
      "tokens": 280,
      "updated": "2026-02-13",
      "entity": "My Blog Post"
    }
  ]
}
```

---

## 6. WordPress Hooks & Filters

### Actions (Plugin fires)
```php
// Fired after MAKO is generated for a post
do_action( 'mako_generated', $post_id, $mako_content, $headers );

// Fired before MAKO response is sent
do_action( 'mako_before_response', $post_id );

// Fired after MAKO response is sent
do_action( 'mako_after_response', $post_id );

// Fired during bulk generation
do_action( 'mako_bulk_progress', $post_id, $current, $total );
```

### Filters (Developers can customize)
```php
// Override detected content type
$type = apply_filters( 'mako_content_type', $type, $post );

// Modify frontmatter before generation
$frontmatter = apply_filters( 'mako_frontmatter', $frontmatter, $post );

// Modify markdown body before assembly
$body = apply_filters( 'mako_body', $body, $post );

// Modify complete MAKO content before storage
$content = apply_filters( 'mako_content', $content, $post );

// Modify HTTP headers before response
$headers = apply_filters( 'mako_response_headers', $headers, $post );

// Filter enabled post types
$types = apply_filters( 'mako_enabled_post_types', $types );

// Filter links before inclusion
$links = apply_filters( 'mako_links', $links, $post );

// Filter actions before inclusion
$actions = apply_filters( 'mako_actions', $actions, $post );

// Filter max token limit
$max_tokens = apply_filters( 'mako_max_tokens', 1000 );

// Modify cache TTL
$ttl = apply_filters( 'mako_cache_ttl', 3600 );

// Custom section template for a content type
$sections = apply_filters( 'mako_section_template', $sections, $type );
```

---

## 7. REST API Endpoints

### Public Endpoints
```
GET  /wp-json/mako/v1/post/{id}          → Get MAKO content for a post
GET  /wp-json/mako/v1/posts              → List posts with MAKO data
GET  /wp-json/mako/v1/sitemap            → Get MAKO sitemap
GET  /wp-json/mako/v1/stats              → Get global stats
```

### Admin Endpoints (requires auth)
```
POST /wp-json/mako/v1/generate/{id}      → Generate MAKO for a post
POST /wp-json/mako/v1/generate/bulk      → Bulk generate (batch of IDs)
POST /wp-json/mako/v1/regenerate/{id}    → Force regenerate
DELETE /wp-json/mako/v1/post/{id}        → Delete MAKO for a post
GET  /wp-json/mako/v1/settings           → Get plugin settings
POST /wp-json/mako/v1/settings           → Update plugin settings
```

---

## 8. Admin Interface

### 8.1 Settings Page (Settings → MAKO)

**General Settings:**
- Enable/disable plugin globally
- Select enabled post types (checkboxes: Posts, Pages, Products, Custom...)
- Auto-generate on publish/update (toggle)
- Default freshness value (dropdown)
- Cache TTL (number input, seconds)

**Content Settings:**
- Max tokens per page (default: 1000)
- Include featured image in body (toggle)
- Include categories/tags in MAKO tags (toggle)
- Include excerpt as summary (toggle)
- Custom content type mappings (CPT → MAKO type)

**Headers & Discovery:**
- Enable content negotiation (toggle)
- Enable `<link rel="alternate">` in HTML head (toggle)
- Enable `/.well-known/mako.json` (toggle)
- Custom Cache-Control value

**Integrations:**
- WooCommerce detected (auto-configure)
- Yoast SEO detected (use SEO title/description)
- Rank Math detected (use SEO data)
- WPML/Polylang detected (per-language MAKO)

### 8.2 Dashboard Page (MAKO → Dashboard)

**Stats cards:**
- Total MAKO pages generated
- Average token savings (%)
- Total tokens saved
- Last generation timestamp

**Table: Recent MAKO pages**
| Post | Type | Tokens | Savings | Updated | Status |
|------|------|--------|---------|---------|--------|
| My Post | article | 280 | 94% | 2026-02-13 | Valid |

**Actions:**
- "Generate All" button (bulk generate)
- "Regenerate All" button (force regenerate)
- Export MAKO sitemap

### 8.3 Post Editor Meta Box

**In post/page/product edit screen:**
- MAKO generation status (Generated / Not generated / Needs update)
- Token count (HTML vs MAKO)
- Savings percentage
- Detected content type (with override dropdown)
- Preview button (shows .mako.md in modal)
- Regenerate button
- Enable/disable MAKO for this specific post

---

## 9. WooCommerce Integration

### Product → MAKO Mapping

```yaml
# Generated frontmatter for WooCommerce product
mako: "1.0"
type: product
entity: "Product Name"
updated: "2026-02-13"
tokens: 245
language: en
summary: "Short description, max 160 chars"
tags:
  - category1
  - category2
  - tag1
actions:
  - name: add_to_cart
    description: "Add this product to the shopping cart"
    endpoint: /wp-json/wc/v3/cart/add
    method: POST
    params:
      - name: product_id
        type: integer
        required: true
        description: "Product ID"
      - name: quantity
        type: integer
        required: false
        description: "Quantity (default: 1)"
  - name: check_availability
    description: "Check product stock status"
    endpoint: /wp-json/wc/v3/products/{id}
    method: GET
links:
  internal:
    - url: /product-category/shoes/
      context: "Browse all products in this category"
      type: parent
```

### Product Body Template
```markdown
# {product_name}
{short_description}

## Key Facts
- Price: {price} {currency} {sale_info}
- Availability: {stock_status}
- SKU: {sku}
- Categories: {categories}
- Rating: {average_rating}/5 ({review_count} reviews)

## Description
{full_description_as_markdown}

## Attributes
{product_attributes_as_list}

## Reviews Summary
{aggregated_review_sentiment}
```

---

## 10. Security Considerations

### Input Validation
- Sanitize all post content before markdown conversion
- Escape YAML values properly (prevent YAML injection)
- Validate URLs in links (no javascript:, data:, etc.)
- Cap content length (max 1MB processed)

### Access Control
- Admin endpoints require `manage_options` capability
- Generate endpoints require `edit_posts` capability
- Public endpoints are read-only
- Nonce verification on all admin AJAX/REST calls

### Output Security
- MAKO content must not contain:
  - WordPress admin URLs
  - User emails or personal data
  - Database credentials or secrets
  - Internal file paths
  - Draft/private content
- Strip all shortcodes that could leak data
- Filter wp-admin, wp-login, wp-content paths from links

### Rate Limiting
- Bulk generation: max 50 posts per batch
- REST API: WordPress default rate limiting
- Content negotiation: no special limiting (same as HTML)

---

## 11. Performance Optimization

### Caching Strategy
```
Layer 1: Post Meta (_mako_content)
  → Persistent, survives cache clears
  → Only regenerated when post content changes (hash comparison)

Layer 2: WordPress Transients
  → TTL-based (default 1 hour)
  → For computed data (sitemap, stats)
  → Uses object cache if available (Redis/Memcached)

Layer 3: HTTP Cache Headers
  → Cache-Control: public, max-age=3600
  → ETag based on content hash
  → Vary: Accept (critical for CDNs)
```

### Change Detection
```php
// On save_post:
$current_hash = md5( $post->post_content . $post->post_title . $post->post_modified );
$stored_hash  = get_post_meta( $post_id, '_mako_content_hash', true );

if ( $current_hash !== $stored_hash ) {
    // Content changed → regenerate MAKO
    $this->generator->generate( $post_id );
}
```

### Async Generation
- Use `wp_schedule_single_event()` for non-blocking generation on save
- Bulk generation via WP-Cron with batching (10 posts per cron run)
- Admin can trigger immediate generation via REST API

---

## 12. Database Schema

### Post Meta (no custom tables needed)
```
_mako_content       TEXT     Full .mako.md file content
_mako_headers       TEXT     JSON-encoded HTTP headers
_mako_tokens        INT      MAKO token count
_mako_html_tokens   INT      Original HTML token count
_mako_savings_pct   FLOAT    Savings percentage
_mako_type          VARCHAR  Detected content type
_mako_updated_at    VARCHAR  ISO 8601 timestamp
_mako_content_hash  VARCHAR  MD5 hash for change detection
_mako_enabled       BOOL     Per-post enable/disable (default: true)
```

### Options Table
```
mako_enabled              BOOL     Global enable/disable
mako_post_types           ARRAY    Enabled post types ['post', 'page', 'product']
mako_auto_generate        BOOL     Auto-generate on publish
mako_freshness_default    STRING   Default freshness value
mako_cache_ttl            INT      Cache TTL in seconds
mako_max_tokens           INT      Max tokens per page
mako_include_image        BOOL     Include featured image
mako_include_tags         BOOL     Include categories/tags
mako_use_excerpt          BOOL     Use excerpt as summary
mako_content_negotiation  BOOL     Enable content negotiation
mako_alternate_link       BOOL     Enable <link rel="alternate">
mako_sitemap_enabled      BOOL     Enable /.well-known/mako.json
mako_cache_control        STRING   Custom Cache-Control value
mako_version              STRING   Plugin version (for migrations)
mako_stats_total          INT      Total generated (cached stat)
mako_stats_avg_savings    FLOAT    Average savings (cached stat)
```

---

## 13. Compatibility & Requirements

### Minimum Requirements
- PHP 8.0+
- WordPress 6.0+
- MySQL 5.7+ or MariaDB 10.3+

### Tested With
- WordPress 6.0 - 6.7
- PHP 8.0, 8.1, 8.2, 8.3
- WooCommerce 7.0+
- Yoast SEO 20+
- Rank Math 1.0+
- WPML 4.6+
- Polylang 3.0+

### Conflicts / Edge Cases
- Page builders (Elementor, Divi, Beaver): use rendered HTML, not raw post_content
- Classic Editor: full support
- Gutenberg: full support (blocks rendered to HTML first)
- Multisite: works per-site (network activation in premium)

---

## 14. Development & Release Plan

### Phase 1: Core (v1.0.0) - MVP
1. HTML → Markdown converter (PHP DOMDocument)
2. MAKO generator (frontmatter + body)
3. Content negotiation handler
4. Post meta storage
5. Basic admin settings page
6. Post editor meta box
7. `<link rel="alternate">` injection
8. Token counting + savings calculation
9. Unit tests for core classes
10. WordPress.org readme + screenshots

### Phase 2: Integrations (v1.1.0)
1. WooCommerce product support
2. Yoast SEO / Rank Math data extraction
3. `/.well-known/mako.json` sitemap
4. REST API endpoints
5. Bulk generation with progress
6. Admin dashboard with stats
7. i18n support (5 languages)

### Phase 3: Polish (v1.2.0)
1. MAKO preview modal in editor
2. Change detection (skip unchanged posts)
3. WP-Cron async generation
4. Cache strategy with ETags
5. WPML/Polylang integration
6. Custom post type mapping UI
7. Export/import settings
8. Performance profiling

### Phase 4: Premium (v2.0.0)
1. AI-enhanced generation (BYOK)
2. CEF embeddings (Level 3)
3. Analytics dashboard
4. MAKO-Score per page
5. License key system
6. Multisite support
7. Webhook notifications
8. CDN integration

---

## 15. Testing Strategy

### Unit Tests (PHPUnit)
- Content converter: HTML → Markdown accuracy
- Type detector: correct mapping for all WP types
- Entity extractor: title extraction edge cases
- Link extractor: internal/external classification
- Action extractor: WooCommerce + form detection
- Frontmatter builder: YAML output validation
- Header builder: correct header format
- Token counter: accuracy within 10%
- Validator: all error/warning cases

### Integration Tests
- Full generation pipeline: post → MAKO
- Content negotiation: Accept header → correct response
- WooCommerce: product → MAKO with actions
- REST API: all endpoints
- Cache: invalidation on post update
- Sitemap: correct JSON structure

### Manual Testing
- Gutenberg blocks → clean markdown
- Classic Editor → clean markdown
- Various themes (minimal, full-featured)
- Page builders (Elementor, Divi)
- Mobile/desktop admin
- Multisite
