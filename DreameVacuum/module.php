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
    const AUTHORIZATION_VALUE = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    const TENANT_DEFAULT      = '000000';

    // Login form
    const LOGIN_PREFIX        = 'platform=IOS&scope=all&grant_type=';
    const LOGIN_REFRESH       = 'refresh_token&refresh_token=';
    const LOGIN_PASSWORD      = 'password&username=';
    const LOGIN_AND_PASSWORD  = '&password=';
    const LOGIN_TYPE          = '&type=account';

    // Headers
    const HDR_USER_AGENT      = 'User-Agent';
    const HDR_AUTHORIZATION   = 'Authorization';
    const HDR_TENANT          = 'Tenant-Id';
    const HDR_DREAME_AUTH     = 'Dreame-Auth';
    const HDR_DREAME_RLC      = 'Dreame-Rlc';

    // Only CN
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

        // Prefix angepasst:
        $this->RegisterTimer('UpdateTimer', 0, 'DRMV_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $interval = (int)$this->ReadPropertyInteger('PollInterval');
        if ($interval < 10) $interval = 10;
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);
    }

    // ---- UI actions ----

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
    }

    public function Update()
    {
        try {
            $this->EnsureLoggedIn(false);

            if ($this->GetBuffer('DeviceInfoLoaded') !== '1') {
                $info = $this->ApiCall('dreame-user-iot/iotuserbind/device/info', array('did' => $this->GetDID()));
                if (is_array($info) && isset($info['code']) && (int)$info['code'] === 0 && isset($info['data']) && is_array($info['data'])) {
                    $data = $info['data'];
                    if (isset($data['bindDomain']) && $this->ReadPropertyString('Host') === '') {
                        $this->SetBuffer('HostFromCloud', strval($data['bindDomain']));
                    }
                    if (isset($data['model'])) $this->SetBuffer('Model', strval($data['model']));
                    if (isset($data['masterUid'])) $this->SetBuffer('Uid', strval($data['masterUid']));
                    $this->SetBuffer('DeviceInfoLoaded', '1');
                }
            }

            $this->SetConnected(true);
            $this->SetLastError('');
        } catch (Exception $e) {
            $this->SendDebug('Update', $e->getMessage(), 0);
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    // ---- MIoT via sendCommand ----

    public function GetProperties($propsJson)
    {
        $props = json_decode($propsJson, true);
        if (!is_array($props)) {
            throw new Exception('propsJson must be a JSON array');
        }
        $res = $this->MiotCall('get_properties', $props);
        $this->SetLastResponse(json_encode($res));
        return $res;
    }

    public function SetProperties($propsJson)
    {
        $props = json_decode($propsJson, true);
        if (!is_array($props)) {
            throw new Exception('propsJson must be a JSON array');
        }
        $res = $this->MiotCall('set_properties', $props);
        $this->SetLastResponse(json_encode($res));
        return $res;
    }

    public function Action($method, $paramsJson = '[]')
    {
        $params = json_decode($paramsJson, true);
        if (!is_array($params)) $params = array();
        $res = $this->MiotCall($method, $params);
        $this->SetLastResponse(json_encode($res));
        return $res;
    }

    // ---- Internal helpers ----

    private function GetRegion()
    {
        $r = trim(strtolower($this->ReadPropertyString('Region')));
        if ($r === '') $r = 'eu';
        return $r;
    }

    private function GetAccountType()
    {
        $t = trim(strtolower($this->ReadPropertyString('AccountType')));
        if ($t !== 'dreame' && $t !== 'mova' && $t !== 'trouver') $t = 'dreame';
        return $t;
    }

    private function GetDID()
    {
        $did = trim($this->ReadPropertyString('DID'));
        if ($did === '') throw new Exception('DID is empty');
        return $did;
    }

    private function GetApiHost()
    {
        // Prefer explicit Host, else use last known from device info if available
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') $host = trim($this->GetBuffer('HostFromCloud'));
        if ($host === '') {
            // fallback domain by account type + region
            $region = $this->GetRegion();
            $acc    = $this->GetAccountType();
            $domain = self::API_DOMAIN_DREAME;
            if ($acc === 'mova') $domain = self::API_DOMAIN_MOVA;
            if ($acc === 'trouver') $domain = self::API_DOMAIN_TROUVER;

            // Default: 10000.mt.<region><domain>:19973 (works for many EU devices, but not guaranteed)
            $host = '10000.mt.' . $region . $domain . ':19973';
        }
        return $host;
    }

    private function GetUserAgent()
    {
        $acc = $this->GetAccountType();
        if ($acc === 'mova') return self::UA_MOVA;
        if ($acc === 'trouver') return self::UA_TROUVER;
        return self::UA_DREAME;
    }

    private function SetConnected($v)
    {
        $this->SetValueBoolean('Connected', (bool)$v);
    }

    private function SetLastError($msg)
    {
        $this->SetValueString('LastError', strval($msg));
    }

    private function SetLastResponse($msg)
    {
        $this->SetValueString('LastResponse', strval($msg));
    }

    private function EnsureLoggedIn($force = false)
    {
        $token = $this->GetBuffer('AccessToken');
        $exp   = (int)$this->GetBuffer('AccessTokenExp');

        if (!$force && $token !== '' && $exp > time() + 30) {
            return;
        }

        $refresh = trim($this->ReadPropertyString('RefreshToken'));
        $user    = trim($this->ReadPropertyString('Username'));
        $pass    = $this->ReadPropertyString('Password');

        if ($refresh === '' && ($user === '' || $pass === '')) {
            throw new Exception('Set RefreshToken (recommended) or Username+Password in instance properties.');
        }

        $region = $this->GetRegion();
        $acc    = $this->GetAccountType();

        $domain = self::API_DOMAIN_DREAME;
        if ($acc === 'mova') $domain = self::API_DOMAIN_MOVA;
        if ($acc === 'trouver') $domain = self::API_DOMAIN_TROUVER;

        // Auth host differs from miot host: use fixed API_PORT
        $authHost = 'https://api.' . $region . $domain . ':' . self::API_PORT . self::AUTH_PATH;

        $headers = array(
            self::HDR_USER_AGENT . ': ' . $this->GetUserAgent(),
            self::HDR_AUTHORIZATION . ': ' . self::AUTHORIZATION_VALUE,
            self::HDR_TENANT . ': ' . self::TENANT_DEFAULT
        );

        if ($region === 'cn') {
            $headers[] = self::HDR_DREAME_RLC . ': ' . self::DREAME_RLC_VALUE;
        }

        if ($refresh !== '') {
            $body = self::LOGIN_PREFIX . self::LOGIN_REFRESH . urlencode($refresh);
        } else {
            $hashed = strtoupper(md5($pass . self::PASSWORD_SALT));
            $body = self::LOGIN_PREFIX . self::LOGIN_PASSWORD . urlencode($user) . self::LOGIN_AND_PASSWORD . urlencode($hashed) . self::LOGIN_TYPE;
        }

        $resp = $this->httpRequest($authHost, 'POST', $headers, $body);
        $this->SetLastResponse(json_encode($resp));

        if (!is_array($resp) || !isset($resp['access_token'])) {
            $msg = 'Login failed';
            if (isset($resp['message'])) $msg .= ': ' . $resp['message'];
            throw new Exception($msg);
        }

        $this->SetBuffer('AccessToken', strval($resp['access_token']));
        $this->SetBuffer('RefreshTokenStored', isset($resp['refresh_token']) ? strval($resp['refresh_token']) : $refresh);

        $expiresIn = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 3600;
        $this->SetBuffer('AccessTokenExp', strval(time() + $expiresIn));
    }

    private function ApiCall($path, $payloadArr)
    {
        $this->EnsureLoggedIn(false);

        $region = $this->GetRegion();
        $acc    = $this->GetAccountType();

        $domain = self::API_DOMAIN_DREAME;
        if ($acc === 'mova') $domain = self::API_DOMAIN_MOVA;
        if ($acc === 'trouver') $domain = self::API_DOMAIN_TROUVER;

        $url = 'https://api.' . $region . $domain . ':' . self::API_PORT . '/' . ltrim($path, '/');

        $headers = array(
            self::HDR_USER_AGENT . ': ' . $this->GetUserAgent(),
            'Content-Type: application/json',
            self::HDR_DREAME_AUTH . ': ' . $this->GetBuffer('AccessToken'),
            self::HDR_TENANT . ': ' . self::TENANT_DEFAULT
        );

        if ($region === 'cn') {
            $headers[] = self::HDR_DREAME_RLC . ': ' . self::DREAME_RLC_VALUE;
        }

        $body = json_encode($payloadArr);
        return $this->httpRequest($url, 'POST', $headers, $body);
    }

    private function MiotCall($method, $params)
    {
        $this->EnsureLoggedIn(false);

        $host = $this->GetApiHost();
        $did  = $this->GetDID();

        $url = 'https://' . $host . '/miotspec/prop/get';
        // Many Dreame endpoints differ; for now we use sendCommand style endpoint:
        // https://<host>/miotspec/action
        // But to keep it robust across models, we call the cloud "sendCommand" proxy:
        $path = 'dreame-user-iot/iotuserbind/device/sendCommand';
        $payload = array(
            'did' => $did,
            'id'  => 1,
            'data' => array(
                'did' => $did,
                'id'  => 1,
                'method' => $method,
                'params' => $params
            )
        );

        // Cloud proxy call:
        return $this->ApiCall($path, $payload);
    }

    private function httpRequest($url, $method, $headers, $body = '')
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception('HTTP error: ' . $err);
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            // return raw to allow debugging
            return array('http_code' => $code, 'raw' => $raw);
        }

        $decoded['http_code'] = $code;
        return $decoded;
    }
}
