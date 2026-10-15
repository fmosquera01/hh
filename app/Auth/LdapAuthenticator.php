<?php
declare(strict_types=1);

final class LdapAuthenticator
{
    /**
     * Busca la cuenta por el atributo configurado y valida la contraseña
     * mediante un segundo bind. Nunca guarda la contraseña en la sesión.
     */
    public function authenticate(string $username, string $password): ?array
    {
        $username = trim($username);
        if ($username === '' || $password === '' || str_contains($username, "\0")) {
            return null;
        }
        if (!function_exists('ldap_connect')) {
            throw new RuntimeException('La extensión PHP LDAP no está instalada.');
        }

        $uri = Config::required('HH_LDAP_URI');
        $baseDn = Config::required('HH_LDAP_BASE_DN');
        $bindDn = Config::required('HH_LDAP_BIND_DN');
        $bindPassword = Config::required('HH_LDAP_BIND_PASSWORD');
        $usernameAttribute = $this->attributeName(Config::get('HH_LDAP_USERNAME_ATTRIBUTE', 'sAMAccountName') ?? 'sAMAccountName');
        $displayAttribute = $this->attributeName(Config::get('HH_LDAP_DISPLAY_ATTRIBUTE', 'displayName') ?? 'displayName');
        $emailAttribute = $this->attributeName(Config::get('HH_LDAP_EMAIL_ATTRIBUTE', 'mail') ?? 'mail');
        $filterTemplate = Config::get('HH_LDAP_USER_FILTER', '(sAMAccountName={username})') ?? '(sAMAccountName={username})';
        if (!str_contains($filterTemplate, '{username}')) {
            throw new RuntimeException('HH_LDAP_USER_FILTER debe incluir {username}.');
        }

        $ldap = @ldap_connect($uri);
        if ($ldap === false) {
            throw new RuntimeException('No se pudo inicializar la conexión LDAP.');
        }

        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, max(1, Config::int('HH_LDAP_TIMEOUT', 5)));

        try {
            if (Config::bool('HH_LDAP_STARTTLS', false)) {
                if (stripos($uri, 'ldap://') !== 0 || !@ldap_start_tls($ldap)) {
                    throw new RuntimeException('No se pudo establecer StartTLS con LDAP.');
                }
            }

            if (!@ldap_bind($ldap, $bindDn, $bindPassword)) {
                throw new RuntimeException('No fue posible autenticar la cuenta de servicio LDAP.');
            }

            $escapedUsername = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
            $filter = str_replace('{username}', $escapedUsername, $filterTemplate);
            $attributes = array_values(array_unique([$usernameAttribute, $displayAttribute, $emailAttribute, 'dn']));
            $search = @ldap_search($ldap, $baseDn, $filter, $attributes, 0, 2);
            if ($search === false) {
                return null;
            }

            $entries = ldap_get_entries($ldap, $search);
            if (!is_array($entries) || (int) ($entries['count'] ?? 0) !== 1) {
                return null;
            }

            $entry = $entries[0];
            $userDn = (string) ($entry['dn'] ?? '');
            if ($userDn === '' || !@ldap_bind($ldap, $userDn, $password)) {
                return null;
            }

            return [
                'username' => $this->entryValue($entry, $usernameAttribute) ?: $username,
                'display_name' => $this->entryValue($entry, $displayAttribute) ?: $username,
                'email' => $this->entryValue($entry, $emailAttribute),
            ];
        } finally {
            ldap_unbind($ldap);
        }
    }

    private function attributeName(string $attribute): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $attribute)) {
            throw new RuntimeException('Nombre de atributo LDAP no válido en configuración.');
        }
        return $attribute;
    }

    private function entryValue(array $entry, string $attribute): string
    {
        $key = strtolower($attribute);
        foreach ($entry as $name => $value) {
            if (is_string($name) && strtolower($name) === $key && is_array($value)) {
                return isset($value[0]) && is_string($value[0]) ? $value[0] : '';
            }
        }
        return '';
    }
}
