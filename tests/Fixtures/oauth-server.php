<?php

declare(strict_types=1);

// An MCP server behind OAuth, for the tests, run with `php -S`: it refuses
// without a token and says where its metadata is (RFC 9728), has an
// authorization server with metadata (RFC 8414) and registration (RFC 7591),
// accepts only registered redirects and S256 PKCE, checks the resource
// (RFC 8707), and gives tokens that run out in seconds, to be refreshed.

$dir = (string) getenv('OAUTH_STATE');
$file = "{$dir}/state.json";
$state = is_file($file) ? json_decode((string) file_get_contents($file), true) : ['clients' => [], 'codes' => [], 'tokens' => [], 'refresh' => [], 'refreshed' => 0];
$save = static function () use (&$state, $file): void {
    file_put_contents($file, json_encode($state));
};
$base = 'http://' . $_SERVER['HTTP_HOST'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$json = static function (mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
};
$b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

switch ($path) {
    case '/.well-known/oauth-protected-resource/mcp':
        $json(['resource' => "{$base}/mcp", 'authorization_servers' => ["{$base}/auth"], 'scopes_supported' => ['mcp:tools']]);
        break;

    case '/.well-known/oauth-authorization-server/auth':
        $json([
            'issuer' => "{$base}/auth",
            'authorization_endpoint' => "{$base}/auth/authorize",
            'token_endpoint' => "{$base}/auth/token",
            'registration_endpoint' => "{$base}/auth/register",
            'code_challenge_methods_supported' => ['S256'],
        ]);
        break;

    case '/auth/register':
        $request = json_decode((string) file_get_contents('php://input'), true);
        $id = 'client-' . bin2hex(random_bytes(4));
        $state['clients'][$id] = $request['redirect_uris'] ?? [];
        $save();
        $json(['client_id' => $id, 'redirect_uris' => $request['redirect_uris'] ?? [], 'token_endpoint_auth_method' => 'none'], 201);
        break;

    case '/auth/authorize':
        $q = $_GET;
        if (!in_array($q['redirect_uri'] ?? '', $state['clients'][$q['client_id'] ?? ''] ?? [], true)) {
            $json(['error' => 'invalid_request', 'error_description' => 'unknown client or redirect'], 400);
            break;
        }
        if (($q['code_challenge_method'] ?? '') !== 'S256' || ($q['resource'] ?? '') !== "{$base}/mcp" || ($q['scope'] ?? '') !== 'mcp:tools') {
            $json(['error' => 'invalid_request', 'error_description' => 'needs S256, this resource and its scope'], 400);
            break;
        }
        $code = bin2hex(random_bytes(8));
        $state['codes'][$code] = ['client' => $q['client_id'], 'challenge' => $q['code_challenge'], 'redirect' => $q['redirect_uri']];
        $save();
        // The user said yes: back to the client.
        header('Location: ' . $q['redirect_uri'] . '?' . http_build_query(['code' => $code, 'state' => $q['state'] ?? '']));
        http_response_code(302);
        break;

    case '/auth/token':
        $form = $_POST;
        if (($form['resource'] ?? '') !== "{$base}/mcp") {
            $json(['error' => 'invalid_target'], 400);
            break;
        }
        if (($form['grant_type'] ?? '') === 'authorization_code') {
            $code = $state['codes'][$form['code'] ?? ''] ?? null;
            unset($state['codes'][$form['code'] ?? '']);
            if ($code === null || $code['client'] !== ($form['client_id'] ?? '') || $code['redirect'] !== ($form['redirect_uri'] ?? '')
                || $b64(hash('sha256', (string) ($form['code_verifier'] ?? ''), true)) !== $code['challenge']) {
                $save();
                $json(['error' => 'invalid_grant', 'error_description' => 'bad code or verifier'], 400);
                break;
            }
        } elseif (($form['grant_type'] ?? '') === 'refresh_token' && isset($state['refresh'][$form['refresh_token'] ?? ''])) {
            $state['refreshed']++;
        } else {
            $json(['error' => 'invalid_grant'], 400);
            break;
        }
        $access = 'at-' . bin2hex(random_bytes(8));
        $refresh = 'rt-' . bin2hex(random_bytes(8));
        $state['tokens'][$access] = time() + 30;
        $state['refresh'][$refresh] = true;
        $save();
        // Runs out at once by Bia's measure, so every use refreshes it first.
        $json(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 2, 'refresh_token' => $refresh]);
        break;

    case '/mcp':
        $token = preg_match('/^Bearer (\S+)$/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m) === 1 ? $m[1] : '';
        if (($state['tokens'][$token] ?? 0) < time()) {
            header('WWW-Authenticate: Bearer resource_metadata="' . $base . '/.well-known/oauth-protected-resource/mcp"');
            $json(['error' => 'unauthorized'], 401);
            break;
        }
        $message = json_decode((string) file_get_contents('php://input'), true);
        if (!isset($message['id'])) {
            http_response_code(202);
            break;
        }
        $result = match ($message['method'] ?? '') {
            'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'oauth-fixture', 'version' => '1']],
            'tools/list' => ['tools' => [['name' => 'whoami', 'description' => 'Says who you are.', 'inputSchema' => ['type' => 'object']]]],
            'tools/call' => ['content' => [['type' => 'text', 'text' => 'logged in, ' . $state['refreshed'] . ' refreshes so far']]],
            default => null,
        };
        $json(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result ?? new stdClass()]);
        break;

    default:
        $json(['error' => 'not found'], 404);
}
