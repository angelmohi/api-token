<?php
header('Content-Type: application/json; charset=utf-8');

// 1) Leer parámetros
$username = $_GET['username'] ?? null;
$password = $_GET['password'] ?? null;
$deviceId = $_GET['deviceId'] ?? null;

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetros "username" y "password" obligatorios.']);
    exit;
}

/**
 * Petición HTTP con cURL enviando formulario (x-www-form-urlencoded)
 */
function callApi(string $url, string $method = 'GET', array $data = null, string $bearerToken = null): array {
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    if ($bearerToken) {
        $headers[] = 'Authorization: Bearer ' . $bearerToken;
    }

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error cURL: {$err}");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);
    return ['code' => $code, 'body' => $body, 'json' => $json];
}

/**
 * Petición HTTP con cURL enviando JSON (application/json)
 */
function callApiJson(string $url, string $method = 'POST', array $data = null, string $bearerToken = null): array {
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    if ($bearerToken) {
        $headers[] = 'Authorization: Bearer ' . $bearerToken;
    }
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error cURL: {$err}");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);
    return ['code' => $code, 'body' => $body, 'json' => $json];
}

try {
    // 2) Login (form data)
    $loginUrl  = 'https://auth.dof6.com/auth/oauth2/token?deviceClass=amazon.tv';
    $loginData = [
        'grant_type'  => 'password',
        'deviceClass' => 'amazon.tv',
        'username'    => $username,
        'password'    => $password,
    ];
    $res = callApi($loginUrl, 'POST', $loginData);
    if ($res['code'] < 200 || $res['code'] >= 300 || empty($res['json']['access_token'])) {
        throw new Exception('Login fallido: ' . ($res['body'] ?: 'sin detalle'));
    }
    $accessToken = $res['json']['access_token'];

    // 3) Obtener accountNumber
    $ts  = round(microtime(true) * 1000);
    $url = "https://auth.dof6.com/movistarplus/api/devices/amazon.tv/users/authenticate?_={$ts}";
    $res = callApi($url, 'GET', null, $accessToken);
    if ($res['code'] < 200 || $res['code'] >= 300) {
        throw new Exception('Error al obtener info de cuenta: ' . $res['body']);
    }
    $ofertas = $res['json']['ofertas'] ?? [];
    if (empty($ofertas[0]['accountNumber'])) {
        throw new Exception('No se encontró accountNumber en la respuesta.');
    }
    $accountNumber = $ofertas[0]['accountNumber'];

    // 4) Funciones auxiliares para initData y creación/registro de device
    function postInitData($token, $devId, $acctNum) {
        $url = "https://clientservices.dof6.com/movistarplus/amazon.tv/sdp/mediaPlayers/{$devId}/initData"
             . "?qspVersion=ssp&version=8&status=default";
        $body = [
            "accountNumber"             => $acctNum,
            "userProfile"               => "0",
            "streamMiscellanea"         => "HTTPS",
            "deviceType"                => "SMARTTV_OTT",
            "deviceManufacturerProduct" => "LG",
            "streamDRM"                 => "Widevine",
            "streamFormat"              => "DASH",
        ];
        return callApiJson($url, 'POST', $body, $token);
    }

    function createAndRegisterDevice($token, $acctNum) {
        // Crear
        $createUrl = "https://auth.dof6.com/movistarplus/amazon.tv/accounts/{$acctNum}/devices/?qspVersion=ssp";
        $res = callApi($createUrl, 'POST', null, $token);
        if ($res['code'] < 200 || $res['code'] >= 300) {
            throw new Exception('Error creando deviceId: ' . $res['body']);
        }
        $newId = trim($res['body'], '"');

        // Registrar
        $regUrl = "https://auth.dof6.com/movistarplus/amazon.tv/accounts/{$acctNum}/devices/{$newId}?qspVersion=ssp";
        $res2 = callApi($regUrl, 'POST', null, $token);
        if ($res2['code'] < 200 || $res2['code'] >= 300) {
            throw new Exception('Error registrando deviceId: ' . $res2['body']);
        }

        return $newId;
    }

    // 5) initData / posible creación de device
    $shouldCreate = !$deviceId;
    if (!$shouldCreate) {
        $initRes = postInitData($accessToken, $deviceId, $accountNumber);
        if ($initRes['code'] < 200 || $initRes['code'] >= 300) {
            $shouldCreate = true;
        }
    }
    if ($shouldCreate) {
        $deviceId = createAndRegisterDevice($accessToken, $accountNumber);
        $initRes   = postInitData($accessToken, $deviceId, $accountNumber);
        if ($initRes['code'] < 200 || $initRes['code'] >= 300) {
            throw new Exception('Error activando nuevo device: ' . $initRes['body']);
        }
    }

    // Obtener nuevo accessToken desde initData
    if (empty($initRes['json']['accessToken'])) {
        throw new Exception('No se encontró accessToken tras initData.');
    }
    $accessToken = $initRes['json']['accessToken'];

    // 6) Refrescar CDN token
    $cdnUrl = "https://idserver.dof6.com/{$accountNumber}/devices/amazon.tv/cdn/token/refresh";
    $res    = callApi($cdnUrl, 'POST', null, $accessToken);
    if ($res['code'] < 200 || $res['code'] >= 300 || empty($res['json']['access_token'])) {
        throw new Exception('Error obteniendo cdnToken: ' . $res['body']);
    }
    $cdnToken = $res['json']['access_token'];

    // 7) Devolver JSON final
    echo json_encode([
        'deviceId' => $deviceId,
        'cdnToken' => $cdnToken,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
