<?php

class DreameVacuum extends IPSModule
{
    // Domains
    const API_DOMAIN_DREAME   = '.iot.dreame.tech';
    const API_DOMAIN_MOVA     = '.iot.mova-tech.com';
    const API_DOMAIN_TROUVER  = '.iot.trouver-tech.com';
    const API_PORT            = 13267;

    // Auth
    const PASSWORD_SALT       = 'RAylYC%fmSKp7%Tq';
    const AUTH_PATH           = '/dreame-auth/oauth/token';

    // Basic Auth value used by the app/integration
    // (This matches commonly used dreame-vacuum integrations; if login ever fails, we'll adjust it)
    const AUTHORIZATION_VALUE = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    const TENANT_DEFAULT      = '000000';

    // Login form
    const LOGIN_PREFIX        = 'platform=IOS&scope=all&grant_type=';
    const LOGIN_REFRESH       = 'refresh_token&refresh_token=';
    const LOGIN_PASSWORD      = 'password&username=';
    const LOGIN_AND_PASSWORD  = '&password=';
    const LOGIN_TYPE          = '&type=account';

    // Headers (must match integration style)
    const HDR_USER_AGENT      = 'User-Agent';
    const HDR_AUTHORIZATION   = 'Authorization';
    const HDR_TENANT          = 'tenantId';
    const HDR_DREAME_AUTH     = 'dreame-auth';
    const HDR_DREAME_RLC      = 'dreame-rlc';

    // Only CN region
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
        $this->RegisterPropertyString('Host', '');             // e.g. 10000.mt.eu.iot.dreame.tech:19973
        $this->RegisterPropertyString('RefreshToken', '');     // HA: auth_key
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('PollInterval', 60);

        $this->RegisterVariableBoolean('Connected', 'Connected', '~Switch', 1);
        $this->RegisterVariableString('LastError', 'LastError', '~TextBox', 2);
        $this->RegisterVariableString('LastResponse', 'LastResponse', '~TextBox', 3);

        // Timer calls the wrapper function (prefix from module.json)
        $this->RegisterTimer('UpdateTimer', 0, 'DRMV_UpdateDeviceInfo($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $interval = (int)$this->ReadPropertyInteger('PollInterval');
        if ($interval < 10) $interval = 10;
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);
    }

    // ---- Buttons / UI actions ----

    public function TestLogin()
    {
        try {
            $this->EnsureLoggedIn(true);
            $this->SetConnected(true);
            $this->SetLastError('');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
            throw $e;
        }
    }

    public function DebugDeviceInfo()
    {
        $res = $this->ApiCall('dreame-user-iot/iotuserbind/device/info', array('did' => $this->GetDID()));
        $this->SetLastResponse(json_encode($res));
        return $this->SetConnected(true);
    }

    public function UpdateDeviceInfo()
    {
        try {
            $this->EnsureLoggedIn(false);

            $info = $this->ApiCall('dreame-user-iot/iotuserbind/device/info', array('did' => $this->GetDID()));
            $this->SetLastResponse(json_encode($info));

            // Save useful fields into buffers if present
            if (is_array($info) && isset($info['code']) && (int)$info['code'] === 0 && isset($info['data']) && is_array($info['data'])) {
                $data = $info['data'];

                // Different backends may call it "bindDomain" or "host"
                if ($this->ReadPropertyString('Host') === '') {
                    if (isset($data['bindDomain'])) $this->SetBuffer('HostFromCloud', strval($data['bindDomain']));
                    if (isset($data['host']))       $this->SetBuffer('HostFromCloud', strval($data['host']));
                }

                if (isset($data['model']))     $this->SetBuffer('Model', strval($data['model']));
                if (isset($data['masterUid'])) $this->SetBuffer('Uid', strval($data['masterUid']));
                if (isset($data['uid']))       $this->SetBuffer('Uid', strval($data['uid']));
            }

            $this->SetConnected(true);
            $this->SetLastError('');
        } catch (Exception $e) {
            $this->SendDebug('UpdateDeviceInfo', $e->getMessage(), 0);
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    // ---- MIoT via sendCommand (optional scripting) ----

    public function GetProperties($propsJson)
    {
        $props = json_decode($propsJson, true);
        if (!is_array($props)) throw new Exception('propsJson must be JSON array, e.g. [[2,1],[3,1]]');

        $payload = array();
        foreach ($props as $p) {
            if (!is_array($p) || count($p) < 2) continue;
            $payload[] = array('did' => $this->GetDID(), 'siid' => (int)$p[0], 'piid' => (int)$p[1]);
        }

        $result = $this->SendCommand('get_properties', $payload);
        $out = json_encode($result);
        $this->SetLastResponse($out);
        return $out;
    }

    public function SetProperty($siid, $piid, $value)
    {
        $payload = array(array(
            'did' => $this->GetDID(),
            'siid' => (int)$siid,
            'piid' => (int)$piid,
            'value' => $value
        ));
        $result = $this->SendCommand('set_properties', $payload);
        $out = json_encode($result);
        $this->SetLastResponse($out);
        return $out;
    }

    public function Action($siid, $aiid, $inJson)
    {
        if ($inJson === null || $inJson === '') $inJson = '[]';

        $in = json_decode($inJson, true);
        if ($in === null) $in = array();

        $payload = array(
            'did' => $this->GetDID(),
            'siid' => (int)$siid,
            'aiid' => (int)$aiid,
            'in'   => $in
        );
        $result = $this->SendCommand('action', $payload);
        $out = json_encode($result);
        $this->SetLastResponse($out);
        return $out;
    }

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

    // ---- Core helpers ----

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

    private function EnsureLoggedIn($force)
    {
        if (!$force) {
            $token = $this->GetBuffer('AccessToken');
            $exp   = (int)$this->GetBuffer('AccessTokenExpire');
            if ($token !== '' && $exp > time()) return;
        }

        // try refresh token first
        $refresh = trim($this->ReadPropertyString('RefreshToken'));
        if ($refresh === '') $refresh = $this->GetBuffer('RefreshToken');

        if ($refresh !== '') {
            if ($this->LoginRefresh($refresh)) return;
        }

        // fallback username/password
        $user = trim($this->ReadPropertyString('Username'));
        $pass = $this->ReadPropertyString('Password');
        if ($user === '' || $pass === '') throw new Exception('RefreshToken ungültig/leer und Username/Password fehlt');
        if (!$this->LoginPassword($user, $pass)) throw new Exception('Login fehlgeschlagen');
    }

    private function LoginRefresh($refreshToken)
    {
        $data = self::LOGIN_PREFIX . self::LOGIN_REFRESH . $refreshToken;
        $res = $this->HttpPostForm($this->GetApiBase() . self::AUTH_PATH, $data);
        return $this->HandleLoginResponse($res);
    }

    private function LoginPassword($username, $password)
    {
        $hashed = md5($password . self::PASSWORD_SALT);
        $data = self::LOGIN_PREFIX . self::LOGIN_PASSWORD . rawurlencode($username) . self::LOGIN_AND_PASSWORD . $hashed . self::LOGIN_TYPE;
        $res = $this->HttpPostForm($this->GetApiBase() . self::AUTH_PATH, $data);
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

        $this->SendDebug('LoginResponse', json_encode($res), 0);
        return false;
    }

    private function ApiCall($path, $payload)
    {
        $this->EnsureLoggedIn(false);

        $url = $this->GetApiBase() . '/' . ltrim($path, '/');

        $tenant = $this->GetBuffer('TenantId');
        if ($tenant === '') $tenant = self::TENANT_DEFAULT;

        $headers = array(
            'Accept: */*',
            'Accept-Language: en-US;q=0.8',
            'Accept-Encoding: gzip, deflate',
            self::HDR_USER_AGENT . ': ' . $this->GetUserAgent(),
            self::HDR_AUTHORIZATION . ': ' . self::AUTHORIZATION_VALUE,
            self::HDR_TENANT . ': ' . $tenant,
            self::HDR_DREAME_AUTH . ': ' . $this->GetBuffer('AccessToken'),
            'Content-Type: application/json'
        );

        if (strtolower(trim($this->ReadPropertyString('Region'))) === 'cn') {
            $headers[] = self::HDR_DREAME_RLC . ': ' . self::DREAME_RLC_VALUE;
        }

        return $this->CurlPost($url, json_encode($payload), $headers, 15);
    }

    private function EnsureHostLoaded()
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host !== '') return $host;

        $host = $this->GetBuffer('HostFromCloud');
        if ($host !== '') return $host;

        // fetch now
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
        if ($host === '') throw new Exception('Host/Cluster unbekannt. Setze "Host" aus HA oder führe einmal "Geräteinfos aktualisieren" aus.');

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

    // ---- HTTP helper ----

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
            self::HDR_TENANT . ': ' . $tenant
        );

        if (strtolower(trim($this->ReadPropertyString('Region'))) === 'cn') {
            $headers[] = self::HDR_DREAME_RLC . ': ' . self::DREAME_RLC_VALUE;
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

        // On Windows installations without proper CA store this avoids SSL errors.
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

    // ---- Variable setters (use global SetValue* functions) ----
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
