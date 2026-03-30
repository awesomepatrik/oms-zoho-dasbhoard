<?php
/**
 * ApiCache — flat JSON file cache for Zoho API responses.
 *
 * Each cache entry is a JSON file in the configured cache directory:
 *   { "fetched_at": <unix timestamp>, "data": <api response array> }
 *
 * Writes are atomic (temp file + rename) so concurrent readers never see
 * a partially written file.
 */

require_once __DIR__ . '/helpers.php';

class ApiCache
{
    private string $filePath;

    /**
     * @param string $key  A safe identifier, e.g. 'books_invoices'. Used as the filename.
     */
    public function __construct(string $key)
    {
        $config = get_config();
        // Sanitise key to prevent directory traversal.
        $safeKey        = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
        $this->filePath = rtrim($config['cache_dir'], '/\\') . '/' . $safeKey . '.json';
    }

    /**
     * Return true if a valid, non-expired cache entry exists.
     *
     * @param int $ttl  Maximum age in seconds (default from config).
     */
    public function isValid(int $ttl = 0): bool
    {
        if ($ttl === 0) {
            $ttl = get_config()['cache_ttl'];
        }

        if (!file_exists($this->filePath)) {
            return false;
        }

        $payload = $this->loadPayload();
        if ($payload === null || !isset($payload['fetched_at'])) {
            return false;
        }

        return (time() - $payload['fetched_at']) < $ttl;
    }

    /**
     * Read and return the cached data array.
     * Call isValid() first; this method does not check TTL.
     */
    public function read(): array
    {
        $payload = $this->loadPayload();
        return $payload['data'] ?? [];
    }

    /**
     * Write data to the cache file atomically.
     */
    public function write(array $data): void
    {
        $cacheDir = dirname($this->filePath);
        if (!is_dir($cacheDir)) {
            // Attempt to create the cache dir if missing.
            @mkdir($cacheDir, 0750, true);
        }

        if (!is_writable($cacheDir)) {
            // Degrade gracefully: log and skip caching rather than dying.
            error_log("ApiCache: cache directory not writable: {$cacheDir}");
            return;
        }

        $payload = json_encode(
            ['fetched_at' => time(), 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $tmp = $this->filePath . '.tmp';
        file_put_contents($tmp, $payload, LOCK_EX);
        rename($tmp, $this->filePath);
    }

    /**
     * Delete the cache file to force a fresh API fetch on the next request.
     */
    public function invalidate(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    /**
     * Return the age of the cache entry in seconds, or null if none exists.
     */
    public function age(): ?int
    {
        $payload = $this->loadPayload();
        if ($payload === null || !isset($payload['fetched_at'])) {
            return null;
        }
        return time() - (int) $payload['fetched_at'];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function loadPayload(): ?array
    {
        if (!file_exists($this->filePath)) {
            return null;
        }
        $contents = file_get_contents($this->filePath);
        if ($contents === false) {
            return null;
        }
        return json_decode($contents, true);
    }
}
