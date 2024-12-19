<?php

/**
 * PHP Proxy Script
 *
 * This script acts as a proxy to fetch content from a specified URL, bypassing
 * restrictive headers (e.g., X-Frame-Options, Content-Security-Policy) and ensuring
 * that all linked and dynamically loaded resources are routed through the proxy.
 * It rewrites URLs within the fetched content to ensure proper loading and prevents
 * issues caused by cross-origin or restrictive security policies.
 *
 * Author: Christian Prior-Mamulyan
 * Email: cprior@gmail.com
 */

// Restricting the use of this proxy to prevent misuse
header('X-Frame-Options: SAMEORIGIN');

// Sanitize and validate URL
$url = filter_var($_GET['url'], FILTER_VALIDATE_URL);
if (!$url) {
    echo "Invalid URL.";
    exit;
}

/**
 * Fetches content from a specified URL using cURL.
 *
 * This function sets up a cURL session to retrieve the content from the specified URL.
 * It also ensures that restrictive headers from the original content (e.g., X-Frame-Options,
 * Content-Security-Policy) are removed to allow the content to be embedded.
 *
 * @param string $url The URL to fetch content from.
 * @return string The fetched content with headers removed or modified.
 */
function fetch_content($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Verify SSL certificates
    curl_setopt($ch, CURLOPT_HEADER, true);

    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    curl_close($ch);

    // Strip or modify security-related headers
    $header_lines = explode("\r\n", $headers);
    foreach ($header_lines as $header) {
        // Remove or skip over headers that might prevent embedding
        if (stripos($header, 'X-Frame-Options') === false && stripos($header, 'Content-Security-Policy') === false) {
            header($header);
        }
    }

    return $body;
}

/**
 * Rewrites URLs within the content to be proxied through this script.
 *
 * This function modifies relative and absolute URLs found in the HTML attributes
 * (e.g., href, src, action) to ensure they are routed through the proxy. This ensures
 * that all content is fetched and displayed correctly.
 *
 * @param string $content The HTML content in which URLs are to be rewritten.
 * @param string $base_url The base URL used to convert relative URLs to absolute.
 * @return string The modified content with proxied URLs.
 */
function rewrite_urls($content, $base_url) {
    $pattern = '/(href|src|action|url)=[\'"]([^\'"]+)[\'"]/i';
    return preg_replace_callback($pattern, function($matches) use ($base_url) {
        $attr = $matches[1];
        $url = $matches[2];

        // Convert relative URL to absolute if needed
        if (parse_url($url, PHP_URL_SCHEME) === null) {
            $url = rtrim($base_url, '/') . '/' . ltrim($url, '/');
        }

        // Proxy the URL through this script (even if already absolute)
        $proxied_url = 'iframe.php?url=' . urlencode($url);
        return $attr . '="' . $proxied_url . '"';
    }, $content);
}

/**
 * Rewrites JavaScript content to handle dynamic URL creation.
 *
 * This function modifies JavaScript code that sets URLs using constructs like
 * window.location or document.URL, ensuring that dynamically created resources
 * are also routed through the proxy.
 *
 * @param string $content The JavaScript content to modify.
 * @param string $base_url The base URL used for proxying dynamic URLs.
 * @return string The modified JavaScript content.
 */
function rewrite_js_content($content, $base_url) {
    // Replace occurrences of window.location or dynamically created URLs
    $content = preg_replace('/window\.location\.href\s*=\s*[\'"]([^\'"]+)[\'"];?/i', 
                            'window.location.href="iframe.php?url=' . urlencode($base_url) . '/$1";', 
                            $content);
    $content = preg_replace('/document\.URL\s*=\s*[\'"]([^\'"]+)[\'"];?/i', 
                            'document.URL="iframe.php?url=' . urlencode($base_url) . '/$1";', 
                            $content);
    return $content;
}

// Determine content type and apply necessary transformations
$parsed_url = parse_url($url);
$base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
$response = fetch_content($url);

$content_type = ''; // Initialize
if (isset($_SERVER["CONTENT_TYPE"])) {
    $content_type = strtolower($_SERVER["CONTENT_TYPE"]);
}

// If it's JavaScript, rewrite dynamic URLs
if (stripos($content_type, 'javascript') !== false) {
    $response = rewrite_js_content($response, $base_url);
}

// Rewrite URLs in HTML and JavaScript
$rewritten_response = rewrite_urls($response, $base_url);

// Output the modified content
echo $rewritten_response;

?>
