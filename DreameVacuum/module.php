<?php

class DreameVacuum extends IPSModule
{
    // Domains
    const API_DOMAIN_DREAME   = '.iot.dreame.tech';
    const API_DOMAIN_MOVA     = '.iot.mova-tech.com';
    const API_DOMAIN_TROUVER  = '.iot.trouver-tech.com';
    const API_PORT            = 13267;

    // Auth / Login
    const PASSWORD_SALT       = 'RAylYC%fmSKp7%Tq';
    const AUTH_PATH           = '/dreame-auth/oauth/token';

    // Basic-Auth (dreame_appv1:dreame_appv1)
    const AUTHORIZATION_VALUE = 'Basic ZHJlYW1lX2FwcHYxOmRyZWFtZV9hcHB2MQ==';
    const TENANT_DEFAULT      = '000000';

    // Login form fields
    const LOGIN_PREFIX        = 'platform=IOS&scope=all&grant_type=';
    const LOGIN_REFRESH       = 'refresh_token&refresh_token=';
    const LOGIN_PASSWORD      = 'password&username=';
    const LOGIN_AND_PASSWORD  = '&password=';
    const LOGIN_TYPE          = '&type=account';

    // Header names (wir senden beide Varianten, um 401 zu vermeiden)
    const HDR_USER_AGENT      = 'User-Agent';
    const HDR_AUTHORIZATION   = 'Authorization';

    // tenant header variants
    const HDR_TENANT_LC       = 'tenantId';
    const HDR_TENANT_UC       = 'Tenant-Id';

    // access token header variants
    const HDR_DREAME_AUTH_LC  = 'dreame-auth';
    const HDR_DREAME_AUTH_UC  = 'Dreame-Auth';

    // Only CN
    const HDR_DREAME_RLC_LC   = 'dreame-rlc';
    const HDR_DREAME_RLC_UC   = 'Dreame-Rlc';
    const DREAME_RLC_VALUE    = '1c80b3787b2266776bcdc481f37d8fa42ba10a30af81a6df-1';

    // User agents
    const UA_DREAME  = 'Dreame_Smarthome/2.1.9 (iPhone; iOS 18.4.1; Scale/3.00)';
    const UA_MOVA    = 'Mova_Smarthome/1.2.4 (iPhone; iOS 18.4.1; Scale/3.00)';
    const UA_TROUVER = 'Trouver_Smarthome/1.0.9 (iPhone; iOS 18.4.1; Scale/3.00)';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('AccountType', 'dreame'); // dreame|mova|trouver
        $this->RegisterPropertyString('DID', '');
        $this->RegisterPropertyString('Host', '');             // optional: 10000.mt.eu.iot.dreame.tech:19973
        $this->RegisterPropertyString('RefreshToken', '');     // HA auth_key (kann JWT sein!)
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('PollInterval', 60);

        $this->RegisterVariableBoolean('Connected', 'Connected', '~Switch', 1);
        $this->RegisterVariableString('LastError', 'LastError', '~TextBox', 2);
        $this->RegisterVariableString('LastResponse', 'LastResponse', '~TextBox', 3);

        $this->RegisterTimer('UpdateTimer', 0, 'DRMV_UpdateDeviceInfo($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = (int)$this->ReadPropertyInteger('PollInterval');
        if ($interval < 10) $interval = 10;
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);
    }

    // ---------- Buttons ----------

    public function TestLogin()
    {
        $this->SetLastError('');
        $this->SetLastResponse('');

        try {
            // KEIN Force-OAuth mehr nötig: wenn JWT vorhanden, nutzen wir es direkt
            $this->EnsureLoggedIn(false);

            $this->SetConnected(true);
            $this->SetLastError('Login ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError('Login fehlgeschlagen: ' . $e->getMessage());

            $last = $this->GetBuffer('LastLoginResponse');
            if ($last !== '') $this->SetLastResponse($last);
        }
    }

    public function UpdateDeviceInfo()
    {
        $this->SetLastError('');
        try {
            $this->EnsureLoggedIn(false);

            $info = $this->ApiCall('dreame-user-iot/iotuserbind/device/info', array('did' => $this->GetDID()));
            $this->SetLastResponse(json_encode($info));

            if (is_array($info) && isset($info['code']) && (int)$info['code'] === 0 && isset($info['data']) && is_array($info['data'])) {
                $data = $info['data'];

                // bindDomain/host in Buffer merken, falls Property leer ist
                if ($this->ReadPropertyString('Host') === '') {
                    if (isset($data['bindDomain'])) $this->SetBuffer('HostFromCloud', strval($data['bindDomain']));
                    if (isset($data['host']))       $this->SetBuffer('HostFromCloud', strval($data['host']));
                }

                if (isset($data['model']))     $this->SetBuffer('Model', strval($data['model']));
                if (isset($data['masterUid'])) $this->SetBuffer('Uid', strval($data['masterUid']));
                if (isset($data['uid']))       $this->SetBuffer('Uid', strval($data['uid']));
            }

            $this->SetConnected(true);
            $this->SetLastError('Device info ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    public function DebugDeviceInfo()
    {
        $this->SetLastError('');
        try {
            $res = $this->ApiCall('dreame-user-iot/iotuserbind/device/info', array('did' => $this->GetDID()));
            $this->SetLastResponse(json_encode($res));
            $this->SetConnected(true);
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    // ---------- Optional: Raw Call / MIoT ----------

    public function RawSend($method, $paramsJson)
    {
        if ($paramsJson === null || $paramsJson === '') $paramsJson = 'null';

        $params = null;
        if ($paramsJson !== 'null') {
            $params = json_decode($paramsJson, true);
            if ($params === null) $params = $paramsJson;
        }

        $result = $this->SendCommand($method, $params);
        $out = json_encode($result);
        $this->SetLastResponse($out);
        return $out;
    }

    // ---------- Core helpers ----------

    private function GetDID()
    {
        $did = trim($this->ReadPropertyString('DID'));
        if ($did === '') throw new Exception('DID fehlt');
        return $did;
    }

    private function GetDomainSuffix()
    {
        $type = strtolower(trim($this->ReadPropertyString('AccountType')));
        if ($type === 'mova') return self::API_DOMAIN_MOVA;
        if ($type === 'trouver') return self::API_DOMAIN_TROUVER;
        return self::API_DOMAIN_DREAME;
    }

    private function GetUserAgent()
    {
        $type = strtolower(trim($this->ReadPropertyString('AccountType')));
        if ($type === 'mova') return self::UA_MOVA;
        if ($type === 'trouver') return self::UA_TROUVER;
        return self::UA_DREAME;
    }

    private function GetApiBase()
    {
        $region = strtolower(trim($this->ReadPropertyString('Region')));
        if ($region === '') $region = 'eu';
        return 'https://' . $region . $this->GetDomainSuffix() . ':' . self::API_PORT;
    }

    private function LooksLikeJwt($s)
    {
        if (!is_string($s)) return false;
        $s = trim($s);
        if ($s === '') return false;
        // typischer JWT: eyJ...<dot>...<dot>...
        if (strpos($s, '.') === false) return false;
        if (substr($s, 0, 3) !== 'eyJ') return false;
        return (substr_count($s, '.') >= 2);
    }

    private function Base64UrlDecode($data)
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad > 0) $data .= str_repeat('=', 4 - $pad);
        return base64_decode($data);
    }

    private function JwtGetPayload($jwt)
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return null;
        $payloadJson = $this->Base64UrlDecode($parts[1]);
        if ($payloadJson === false || $payloadJson === null) return null;
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) return null;
        return $payload;
    }

    private function EnsureLoggedIn($force)
    {
        // 1) wenn wir bereits ein AccessToken im Buffer haben und es nicht abgelaufen ist → ok
        if (!$force) {
            $token = $this->GetBuffer('AccessToken');
            $exp   = (int)$this->GetBuffer('AccessTokenExpire');
            if ($token !== '' && $exp > time()) return;
        }

        // 2) HA auth_key ist bei dir sehr wahrscheinlich ein JWT Access Token → direkt nutzen
        $authKey = trim($this->ReadPropertyString('RefreshToken'));
        if ($authKey !== '' && $this->LooksLikeJwt($authKey)) {
            $payload = $this->JwtGetPayload($authKey);

            $tenant = self::TENANT_DEFAULT;
            $exp = time() + 3600;

            if (is_array($payload)) {
                if (isset($payload['tenant_id'])) $tenant = strval($payload['tenant_id']);
                if (isset($payload['tenantId']))  $tenant = strval($payload['tenantId']);
                if (isset($payload['exp']))       $exp = (int)$payload['exp'];
            }

            $this->SetBuffer('AccessToken', $authKey);
            $this->SetBuffer('TenantId', $tenant);
            $this->SetBuffer('AccessTokenExpire', strval($exp - 120));
            $this->SetBuffer('LastLoginResponse', '{"info":"Using JWT auth_key directly"}');
            return;
        }

        // 3) sonst: Refresh-Token Flow versuchen
        if ($authKey !== '') {
            if ($this->LoginRefresh($authKey)) return;
        }

        // 4) fallback: username/password
        $user = trim($this->ReadPropertyString('Username'));
        $pass = $this->ReadPropertyString('Password');
        if ($user === '' || $pass === '') throw new Exception('Kein gültiger AuthKey/JWT und Username/Password fehlt');

        if (!$this->LoginPassword($user, $pass)) throw new Exception('Login fehlgeschlagen');
    }

    private function LoginRefresh($refreshToken)
    {
        $data = self::LOGIN_PREFIX . self::LOGIN_REFRESH . $refreshToken;
        $res = $this->HttpPostForm($this->GetApiBase() . self::AUTH_PATH, $data);
        $this->SetBuffer('LastLoginResponse', json_encode($res));
        return $this->HandleLoginResponse($res);
    }

    private function LoginPassword($username, $password)
    {
        $hashed = md5($password . self::PASSWORD_SALT);
        $data = self::LOGIN_PREFIX . self::LOGIN_PASSWORD . rawurlencode($username) . self::LOGIN_AND_PASSWORD . $hashed . self::LOGIN_TYPE;
        $res = $this->HttpPostForm($this->GetApiBase() . self::AUTH_PATH, $data);
        $this->SetBuffer('LastLoginResponse', json_encode($res));
        return $this->HandleLoginResponse($res);
    }

    private function HandleLoginResponse($res)
    {
        if (!is_array($res)) return false;

        if (isset($res['access_token'])) {
            $this->SetBuffer('AccessToken', strval($res['access_token']));
            if (isset($res['refresh_token'])) $this->SetBuffer('RefreshToken', strval($res['refresh_token']));
            if (isset($res['expires_in'])) $this->SetBuffer('AccessTokenExpire', strval(time() + (int)$res['expires_in'] - 120));
            if (isset($res['tenant_id'])) $this->SetBuffer('TenantId', strval($res['tenant_id']));
            if (isset($res['uid'])) $this->SetBuffer('Uuid', strval($res['uid']));
            return true;
        }
        return false;
    }

    private function ApiCall($path, $payload)
    {
        $this->EnsureLoggedIn(false);

        $url = $this->GetApiBase() . '/' . ltrim($path, '/');

        $tenant = $this->GetBuffer('TenantId');
        if ($tenant === '') $tenant = self::TENANT_DEFAULT;

        $token = $this->GetBuffer('AccessToken');

        $headers = array(
            'Accept: */*',
            'Accept-Language: en-US;q=0.8',
            'Accept-Encoding: gzip, deflate',
            self::HDR_USER_AGENT . ': ' . $this->GetUserAgent(),
            self::HDR_AUTHORIZATION . ': ' . self::AUTHORIZATION_VALUE,

            // beide Tenant Header
            self::HDR_TENANT_LC . ': ' . $tenant,
            self::HDR_TENANT_UC . ': ' . $tenant,

            // beide Token Header
            self::HDR_DREAME_AUTH_LC . ': ' . $token,
            self::HDR_DREAME_AUTH_UC . ': ' . $token,

            'Content-Type: application/json'
        );

        if (strtolower(trim($this->ReadPropertyString('Region'))) === 'cn') {
            $headers[] = self::HDR_DREAME_RLC_LC . ': ' . self::DREAME_RLC_VALUE;
            $headers[] = self::HDR_DREAME_RLC_UC . ': ' . self::DREAME_RLC_VALUE;
        }

        return $this->CurlPost($url, json_encode($payload), $headers, 15);
    }

    private function EnsureHostLoaded()
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host !== '') return $host;

        $host = $this->GetBuffer('HostFromCloud');
        if ($host !== '') return $host;

        $info = $this->ApiCall('dreame-user-iot/iotuserbind/device/info', array('did' => $this->GetDID()));
        if (is_array($info) && isset($info['code']) && (int)$info['code'] === 0 && isset($info['data']) && is_array($info['data'])) {
            if (isset($info['data']['bindDomain'])) $host = strval($info['data']['bindDomain']);
            if ($host === '' && isset($info['data']['host'])) $host = strval($info['data']['host']);
            if ($host !== '') {
                $this->SetBuffer('HostFromCloud', $host);
                return $host;
            }
        }
        return '';
    }

    private function SendCommand($method, $params)
    {
        $this->EnsureLoggedIn(false);

        $host = $this->EnsureHostLoaded();
        if ($host === '') throw new Exception('Host/Cluster unbekannt. Setze Host oder klicke "Geräteinfos aktualisieren".');

        $parts = explode('.', $host);
        $cluster = '';
        if (count($parts) > 0) $cluster = '-' . $parts[0]; // "-10000"

        $id = (int)$this->GetBuffer('RequestId');
        if ($id <= 0) $id = mt_rand(1, 100);
        $id++;
        $this->SetBuffer('RequestId', strval($id));

        $did = $this->GetDID();

        $payload = array(
            'did' => $did,
            'id'  => $id,
            'data' => array(
                'did' => $did,
                'id'  => $id,
                'method' => $method,
                'params'  => $params
            )
        );

        $path = 'dreame-iot-com' . $cluster . '/device/sendCommand';
        $res = $this->ApiCall($path, $payload);

        if (is_array($res) && isset($res['data']) && is_array($res['data']) && isset($res['data']['result'])) {
            return $res['data']['result'];
        }
        return $res;
    }

    private function HttpPostForm($url, $dataString)
    {
        $tenant = $this->GetBuffer('TenantId');
        if ($tenant === '') $tenant = self::TENANT_DEFAULT;

        $headers = array(
            'Accept: */*',
            'Accept-Language: en-US;q=0.8',
            'Accept-Encoding: gzip, deflate',
            'Content-Type: application/x-www-form-urlencoded',
            self::HDR_USER_AGENT . ': ' . $this->GetUserAgent(),
            self::HDR_AUTHORIZATION . ': ' . self::AUTHORIZATION_VALUE,

            // beide Tenant Header
            self::HDR_TENANT_LC . ': ' . $tenant,
            self::HDR_TENANT_UC . ': ' . $tenant
        );

        if (strtolower(trim($this->ReadPropertyString('Region'))) === 'cn') {
            $headers[] = self::HDR_DREAME_RLC_LC . ': ' . self::DREAME_RLC_VALUE;
            $headers[] = self::HDR_DREAME_RLC_UC . ': ' . self::DREAME_RLC_VALUE;
        }

        return $this->CurlPost($url, $dataString, $headers, 15);
    }

    private function CurlPost($url, $body, $headers, $timeoutSec)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Windows: SSL sonst oft zickig ohne CA Store
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $resp === null) {
            return array('_http_status' => $code, '_error' => $err);
        }

        $decoded = json_decode($resp, true);
        if ($decoded === null) {
            return array('_http_status' => $code, '_raw' => $resp);
        }
        $decoded['_http_status'] = $code;
        return $decoded;
    }

    // ---- Variable setters ----
    private function SetConnected($state)
    {
        SetValueBoolean($this->GetIDForIdent('Connected'), (bool)$state);
    }

    private function SetLastError($msg)
    {
        SetValueString($this->GetIDForIdent('LastError'), (string)$msg);
    }

    private function SetLastResponse($msg)
    {
        SetValueString($this->GetIDForIdent('LastResponse'), (string)$msg);
    }
}
