<?php
declare(strict_types=1);

final class GlpiOAuthClient
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $scope;

    public function __construct()
    {
        $this->baseUrl = rtrim(Config::required('GLPI_BASE_URL'), '/');
        $this->clientId = Config::required('GLPI_OAUTH_CLIENT_ID');
        $this->clientSecret = Config::required('GLPI_OAUTH_CLIENT_SECRET');
        $this->redirectUri = rtrim(Config::required('HH_BASE_URL'), '/') . '/glpi-callback.php';
        $this->scope = trim(Config::get('GLPI_OAUTH_SCOPE', 'api user email') ?? 'api user email');

        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL) || parse_url($this->baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('GLPI_BASE_URL debe ser una URL HTTPS válida.');
        }
        if (!filter_var($this->redirectUri, FILTER_VALIDATE_URL) || parse_url($this->redirectUri, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('HH_BASE_URL debe ser la URL HTTPS pública de HH.');
        }
    }

    public function authorizationUrl(string $state): string
    {
        return $this->baseUrl . '/api.php/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code): array
    {
        return $this->request('POST', '/api.php/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
            'scope' => $this->scope,
        ]);
    }

    public function currentUser(string $accessToken): array
    {
        $user = $this->request('GET', '/api.php/Me', [], $accessToken);
        if (!is_array($user) || !isset($user['id'])) {
            throw new RuntimeException('GLPI no devolvió una identidad de usuario válida.');
        }

        return [
            'glpi_user_id' => (string) $user['id'],
            'username' => (string) ($user['name'] ?? $user['username'] ?? $user['id']),
            'display_name' => trim((string) ($user['firstname'] ?? '') . ' ' . (string) ($user['realname'] ?? ''))
                ?: (string) ($user['name'] ?? $user['username'] ?? $user['id']),
            'email' => (string) ($user['email'] ?? ''),
        ];
    }

    private function request(string $method, string $path, array $form = [], ?string $accessToken = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión PHP cURL no está instalada.');
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar la conexión con GLPI.');
        }

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($accessToken !== null) {
            $options[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $accessToken;
        }

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($method !== 'GET') {
            curl_close($ch);
            throw new RuntimeException('Método HTTP no soportado.');
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            throw new RuntimeException('No fue posible conectar con GLPI: ' . $curlError);
        }

        $data = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            error_log('[HH GLPI OAuth] HTTP ' . $status . ' al consultar endpoint OAuth/API.');
            throw new RuntimeException('GLPI rechazó la solicitud de autenticación o API. Verifique el cliente OAuth y sus permisos.');
        }

        return $data;
    }
}
