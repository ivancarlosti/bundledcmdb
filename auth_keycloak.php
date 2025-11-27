<?php
// auth_keycloak.php

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

    public function getLoginUrl() {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile'
        ];
        return $this->baseUrl . '/realms/' . $this->realm . '/protocol/openid-connect/auth?' . http_build_query($params);
    }

    public function getLogoutUrl() {
        $params = [
            'client_id' => $this->clientId,
            'post_logout_redirect_uri' => $this->redirectUri
        ];
        return $this->baseUrl . '/realms/' . $this->realm . '/protocol/openid-connect/logout?' . http_build_query($params);
    }

    public function getToken($code) {
        $url = $this->baseUrl . '/realms/' . $this->realm . '/protocol/openid-connect/token';
        $fields = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        if ($response === false) {
            error_log('Keycloak Token Error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        return json_decode($response, true);
    }

    public function getUserInfo($accessToken) {
        $url = $this->baseUrl . '/realms/' . $this->realm . '/protocol/openid-connect/userinfo';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        if ($response === false) {
            error_log('Keycloak UserInfo Error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        return json_decode($response, true);
    }

    public function verifyUser($email, $pdo) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
