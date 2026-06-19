<?php
/**
 * ZohoOAuth — OAuth2 token management for Zoho AU APIs.
 *
 * Handles:
 *  - Building the authorisation URL (connect step)
 *  - Exchanging an auth code for tokens (callback step)
 *  - Transparently refreshing expired access tokens
 *  - File-locked token storage to prevent race conditions
 */

require_once __DIR__ . '/helpers.php';

class ZohoAuthException extends RuntimeException {}

class ZohoOAuth
{
    private array $config;

    public function __construct()
    {
        $this->config = get_config();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build the Zoho OAuth2 authorisation URL.
     * Redirect the user's browser to this URL to start the auth flow.
     */
    public function getAuthUrl(): string
    {
        return $this->config['auth_base'] . '/auth?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'scope'         => $this->config['scope'],
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);
    }

    /**
     * Exchange a one-time authorisation code for access + refresh tokens.
     * Call this once from auth/callback.php.
     *
     * @throws ZohoAuthException on failure
     */
    public function exchangeCode(string $code): void
    {
        $response = $this->post($this->config['auth_base'] . '/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'code'          => $code,
        ]);

        $this->assertTokenResponse($response, 'exchangeCode');
        $this->writeTokens([
            'access_token'  => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_at'    => time() + (int) $response['expires_in'],
        ]);
    }

    /**
     * Return a valid access token, refreshing it transparently if needed.
     *
     * @throws ZohoAuthException if no tokens exist or refresh fails
     */
    public function getValidAccessToken(): string
    {
        $tokens = $this->readTokens();

        if (empty($tokens)) {
            throw new ZohoAuthException('No tokens found. OAuth2 authorisation required.');
        }

        // 60-second safety buffer before expiry.
        if (!empty($tokens['access_token']) && ($tokens['expires_at'] - time()) > 60) {
            return $tokens['access_token'];
        }

        return $this->refreshAccessToken($tokens);
    }

    /**
     * Return true if tokens.json exists and contains a refresh token.
     * Does not validate whether the refresh token is still accepted by Zoho.
     */
    public function hasValidTokens(): bool
    {
        $tokens = $this->readTokens();
        return !empty($tokens['refresh_token']);
    }

    /**
     * Revoke the stored refresh token and delete the token file.
     */
    public function revoke(): void
    {
        $tokens = $this->readTokens();
        if (empty($tokens['refresh_token'])) {
            return;
        }

        // Best-effort revocation; ignore errors (token may already be invalid).
        $this->post(
            $this->config['auth_base'] . '/revoke',
            ['token' => $tokens['refresh_token']]
        );

        if (file_exists($this->config['token_file'])) {
            unlink($this->config['token_file']);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Refresh the access token using the stored refresh token.
     * Uses double-checked locking: re-reads the file after acquiring the
     * exclusive lock so that concurrent requests only refresh once.
     *
     * @throws ZohoAuthException on failure
     */
    private function refreshAccessToken(array $staleTokens): string
    {
        $tokenFile = $this->config['token_file'];
        $handle    = fopen($tokenFile, 'c+');

        if ($handle === false) {
            throw new ZohoAuthException('Cannot open token file for refresh.');
        }

        try {
            // Acquire exclusive lock — blocks other processes until done.
            flock($handle, LOCK_EX);

            // Double-check: another process may have refreshed while we waited.
            $current = json_decode(stream_get_contents($handle), true) ?? [];
            if (!empty($current['access_token']) && ($current['expires_at'] - time()) > 60) {
                return $current['access_token'];
            }

            // Still expired — perform the refresh HTTP call.
            $response = $this->post($this->config['auth_base'] . '/token', [
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'refresh_token' => $staleTokens['refresh_token'],
            ]);

            $this->assertTokenResponse($response, 'refreshAccessToken');

            $newTokens = [
                'access_token'  => $response['access_token'],
                'refresh_token' => $staleTokens['refresh_token'], // Zoho does not rotate this
                'expires_at'    => time() + (int) $response['expires_in'],
            ];

            // Atomic write into the already-open, locked file.
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($newTokens, JSON_PRETTY_PRINT));

            return $newTokens['access_token'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Read tokens.json using a shared (read) lock.
     * Returns an empty array if the file does not exist.
     */
    private function readTokens(): array
    {
        $tokenFile = $this->config['token_file'];

        if (!file_exists($tokenFile)) {
            return [];
        }

        $handle = fopen($tokenFile, 'r');
        if ($handle === false) {
            return [];
        }

        flock($handle, LOCK_SH);
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return json_decode($contents, true) ?? [];
    }

    /**
     * Write tokens to tokens.json atomically using a temp-file + rename strategy.
     * Used only by exchangeCode() (initial token storage); refresh uses the
     * already-open locked file handle in refreshAccessToken().
     */
    private function writeTokens(array $tokens): void
    {
        $tokenFile = $this->config['token_file'];
        $tmp       = $tokenFile . '.tmp';

        file_put_contents($tmp, json_encode($tokens, JSON_PRETTY_PRINT), LOCK_EX);
        rename($tmp, $tokenFile);
    }

    /**
     * Assert that a token endpoint response contains an access token.
     *
     * @throws ZohoAuthException
     */
    private function assertTokenResponse(array $response, string $context): void
    {
        if (empty($response['access_token'])) {
            $detail = $response['error'] ?? 'unknown_error';
            throw new ZohoAuthException("Token request failed in {$context}: {$detail}");
        }
    }

    /**
     * POST to a URL with form-encoded parameters using cURL.
     * Returns the decoded JSON response body.
     *
     * @throws ZohoAuthException on network or decode errors
     */
    private function post(string $url, array $params): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new ZohoAuthException("cURL error during token request: {$curlErr}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ZohoAuthException("Non-JSON response from token endpoint (HTTP {$httpCode}).");
        }

        return $decoded;
    }
}
