<?php

/**
 * Class URLFixer
 * @package mattv8\URLFixer
 * @author mattv8
 * @link https://github.com/mattv8/multisite-url-fixer
 */
namespace URLFixer;

use Roots\WPConfig\Config;
use function Env\env;

class URLRewrite
{
    protected $minio_url;
    protected $minio_bucket;
    protected $uploads_baseurl;
    protected $uploads_path;
    protected $rewrite_cache = [];
    protected $subdomain_suffix;
    protected $wp_base_domain;
    protected $scheme;
    protected $rewrite_overrides = []; // Add a property for overrides
    protected $port;

    public function __construct()
    {
        // Fetch uploads directory base URL once to avoid recursion issues
        $uploads_dir = wp_get_upload_dir();
        $this->uploads_baseurl = $uploads_dir['baseurl']; // e.g. http://localhost:81/app/uploads/sites/3
        $this->uploads_path = str_replace("/app/uploads/", "", parse_url($uploads_dir['url'])['path']); // e.g. sites/3/2024/11

        // Load environment variables
        $this->minio_url = Config::get('MINIO_URL', '');
        $this->minio_bucket = Config::get('MINIO_BUCKET', '');
        $this->subdomain_suffix = Config::get('SUBDOMAIN_SUFFIX') ?: '';
        $this->port = Config::get('NGINX_PORT') ?: '';

        $wp_home = Config::get('WP_HOME', 'http://localhost');
        $this->wp_base_domain = get_base_domain($wp_home);
        $parsed_wp_home = parse_url($wp_home);
        $this->scheme = $parsed_wp_home['scheme'];

        $this->rewrite_overrides = $this->loadOverrides();

    }

    private function loadOverrides(): array
    {
        $overrides_file = dirname(__DIR__, 1) . '/overrides.php';
        if (file_exists($overrides_file)) {
            return include $overrides_file;
        }
        return []; // Return an empty array if the file does not exist
    }

    /**
     * Add filters to rewrite URLs, including multisite domain filters.
     */
    public function addFilters()
    {
        if (is_multisite()) {
            add_filter('option_home', [$this, 'rewriteSiteURL']);
            add_filter('option_siteurl', [$this, 'rewriteSiteURL']);
            add_filter('network_site_url', [$this, 'rewriteSiteURL']);
        }

        // Media-specific filters
        add_filter('wp_get_attachment_url', [$this, 'rewriteSiteURL']);
        add_filter('wp_calculate_image_srcset', [$this, 'rewriteSiteURL']);
        add_filter('login_redirect', [$this, 'rewriteSiteURL']);

        // Uncomment below lines if filters for scripts, styles, and other items are needed
        add_filter('script_loader_src', [$this, 'rewriteSiteURL']);
        add_filter('style_loader_src', [$this, 'rewriteSiteURL']);

        add_filter('plugins_url',[$this, 'rewriteSiteURL']);

    }

    /**
     * Core function to rewrite URLs for media and site content.
     */
    protected function rewriteURL($url)
    {
        global $current_blog;

        // Check if $url is an array (for srcset), and apply rewriting to each entry.
        if (is_array($url)) {
            foreach ($url as $key => $single_url) {
                $url[$key] = $this->rewriteURL($single_url);  // Recursively rewrite each URL in the srcset array.
            }
            return $url;
        }

        // Check cache to prevent repeated rewrites
        if (isset($this->rewrite_cache[$url])) {
            return $this->rewrite_cache[$url];
        }

        // Check if valid URL
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['host']) || !isset($parsed_url['scheme'])) {
            return $url;
        }

        // Check overrides
        foreach ($this->rewrite_overrides as $override) {
            if ($this->matchOverride($override, $url)) {
                error_log("URL $url was excluded from rewrite (rule: $override)");
                $this->rewrite_cache[$url] = $url; // Cache the original URL
                return $url; // Return the original URL
            }
        }

        // Check for localhost and missing port
        if ($parsed_url['host'] === 'localhost' && !isset($parsed_url['port'])) {
            $rewrittenURL = "{$parsed_url['scheme']}://{$parsed_url['host']}:{$this->port}{$parsed_url['path']}";

            // Append the query string if it exists
            if (!empty($parsed_url['query'])) {
                $rewrittenURL .= '?' . $parsed_url['query'];
            }

            error_log("Rewritten localhost URL from $url to $rewrittenURL");
            $this->rewrite_cache[$url] = $rewrittenURL;
            return $rewrittenURL;
        }

        $base_url = $parsed_url['host'] . (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
        $path = $parsed_url['path'] ?? '';
        $query_string = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';

        // Skip if already rewritten to MinIO
        if (strpos($base_url, $this->minio_url) !== false) {
            return $url;
        }

        // Rewrite if in the uploads directory
        if ($path && strpos($path, '/app/uploads/') !== false) {
            $uploads_path = strpos($path, '/app/uploads/sites/') !== false
                ? "/sites/{$current_blog->blog_id}"
                : '';

            $rewrittenURL = str_replace(
                $this->uploads_baseurl,
                "{$this->minio_url}/{$this->minio_bucket}$uploads_path",
                $url
            );

            // Cache the rewritten URL
            $this->rewrite_cache[$url] = $rewrittenURL . $query_string;
            error_log("Rewrite media URL from $url to $rewrittenURL");

            return $this->rewrite_cache[$url];
        } elseif (!strpos($url, 'localhost') && !strpos($url, $this->subdomain_suffix . "." . $this->wp_base_domain['with_port'])) {

            // Replace only non-localhost URLs
            $pattern = "/(?:https:\/\/|http:\/\/)?(?:([a-zA-Z0-9_-]+))?(?:[:\.a-zA-Z0-9_-]+)(\/.*)?/";
            $replacement =  $this->scheme . '://${1}' . $this->subdomain_suffix . '.' . $this->wp_base_domain['with_port'] . '${2}';
            $rewrittenURL = preg_replace($pattern, $replacement, $url);
            $this->rewrite_cache[$url] = $rewrittenURL . $query_string;
            error_log("Rewrite generic URL from $url to $rewrittenURL");
            return $this->rewrite_cache[$url];
        }

        // If already pointing to localhost without MinIO
        $this->rewrite_cache[$url] = $url;
        return $url;
    }

    /**
     * Check if the given URL matches an override pattern.
     *
     * @param string $override
     * @param string $url
     * @return bool
     */
    private function matchOverride($override, $url): bool
    {
        // Ensure the URL has a scheme
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['scheme'])) {
            $url = 'http://' . ltrim($url, '/');
        }

        // Handle wildcard patterns
        if (strpos($override, '*') !== false) {
            // If override does not include a scheme, add a scheme to match dynamically
            if (!preg_match('/^https?:\/\//', $override)) {
                $override = 'https?://' . ltrim($override, '/');
            }
            $escaped_override = preg_quote($override, '/');

            // Restore the regex characters like `?` that were escaped
            $escaped_override = str_replace(['\?', '\*'], ['?', '.*'], $escaped_override);
            $pattern = '/^' . $escaped_override . '$/';
            return (bool) preg_match($pattern, $url);
        }

        // Handle regex patterns explicitly (starting with `/`)
        if (strpos($override, '/') === 0) {
            return (bool) preg_match($override, $url);
        }

        // Handle exact matches
        if (!preg_match('/^https?:\/\//', $override)) {
            // Add default scheme for exact match if missing
            $override = 'http://' . ltrim($override, '/');
        }
        return $override === $url;
    }

    /**
     * Rewrites the site URL, applying only necessary transformations.
     */
    public function rewriteSiteURL($url)
    {
        return $this->rewriteURL($url);
    }

}
