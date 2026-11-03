<?php
// auth_keycloak.php
//
// Thin Keycloak OIDC client. The legacy panel (index.php) uses getLoginUrl() /
// getToken() / getUserInfo(); the SPA login (public/auth.php) passes its own
// redirect URI and a `state` parameter through the optional arguments.

class KeycloakAuth {
    private $baseUrl;
    private $realm;
    private $clientId;
    private $clientSecret;
    private $redirectUri;

    public function __construct() {
        $this->baseUrl = rtrim(KEYCLOAK_BASE_URL, '/');
        $this->realm = KEYCLOAK_REALM;
        $this->clientId = KEYCLOAK_CLIENT_ID;
        $this->clientSecret = KEYCLOAK_CLIENT_SECRET;
        $this->redirectUri = KEYCLOAK_REDIRECT_URI;
    }

    /** Realm-scoped endpoint, e.g. protocol/openid-connect/token. */
    private function endpoint($path) {
        return $this->baseUrl . '/realms/' . $this->realm . '/protocol/openid-connect/' . ltrim($path, '/');
    }

    /**
     * POST a form body and decode the JSON response (null on failure).
     *
     * The handle is deliberately not passed to curl_close(): it is a no-op since
     * PHP 8.0 (handles are objects freed when they go out of scope) and calling it
     * is deprecated as of PHP 8.5, where the notice would be printed into the
     * redirect response and break the OIDC callback.
     */
    private function post($url, array $fields) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        if ($response === false) {
            error_log('Keycloak request error: ' . curl_error($ch));
            return null;
        }
        return json_decode($response, true);
    }

    /**
     * GET an endpoint, optionally with a bearer token; decodes JSON.
     * See post() for why curl_close() is not called.
     */
    private function get($url, $bearer = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if ($bearer !== null) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $bearer]);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            error_log('Keycloak request error: ' . curl_error($ch));
            return null;
        }
        return json_decode($response, true);
    }

    /**
     * Authorization (login) URL.
     *
     * @param string|null $redirectUri callback registered in the Keycloak client
     * @param array       $extraParams e.g. ['state' => '…', 'prompt' => 'login']
     */
    public function getLoginUrl($redirectUri = null, array $extraParams = []) {
        $params = array_merge([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri ?: $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
        ], $extraParams);
        return $this->endpoint('auth') . '?' . http_build_query($params);
    }

    /**
     * RP-initiated logout. Without a registered post_logout_redirect_uri
     * Keycloak shows its own confirmation page, which is the safe default.
     */
    public function getLogoutUrl($postLogoutRedirect = null) {
        $params = ['client_id' => $this->clientId];
        if ($postLogoutRedirect !== null && $postLogoutRedirect !== '') {
            $params['post_logout_redirect_uri'] = $postLogoutRedirect;
        }
        return $this->endpoint('logout') . '?' . http_build_query($params);
    }

    public function getToken($code, $redirectUri = null) {
        return $this->post($this->endpoint('token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri ?: $this->redirectUri,
        ]);
    }

    public function getUserInfo($accessToken) {
        return $this->get($this->endpoint('userinfo'), $accessToken);
    }

    public function verifyUser($email, $pdo) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
