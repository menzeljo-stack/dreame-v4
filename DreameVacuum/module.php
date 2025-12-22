<?php

class DreameVacuum extends IPSModule
{
    // ===== Dreame Cloud (as used by HACS dreame-vacuum) =====

    // Domains
    const API_DOMAIN_DREAME   = '.iot.dreame.tech';
    const API_DOMAIN_MOVA     = '.iot.mova-tech.com';
    const API_DOMAIN_TROUVER  = '.iot.trouver-tech.com';
    const API_PORT            = 13267;

    // Auth / Login
    const PASSWORD_SALT       = 'RAylYC%fmSKp7%Tq';
    const AUTH_PATH           = '/dreame-auth/oauth/token';

    // IMPORTANT: This is the Basic-Auth used by the Dreame app / HACS integration
    const AUTHORIZATION_VALUE = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    const TENANT_DEFAULT      = '000000';

    // Login form fields
    const LOGIN_PREFIX        = 'platform=IOS&scope=all&grant_type=';
    const LOGIN_REFRESH       = 'refresh_token&refresh_token=';
    const LOGIN_PASSWORD      = 'password&username=';
    const LOGIN_AND_PASSWORD  = '&password=';
    const LOGIN_TYPE          = '&type=account';

    // Headers (match HACS integration)
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
        $this->SetLastError('');
        $this->SetLastResponse('');

        try {
            $this->EnsureLoggedIn(true);
            $this->SetConnected(true);

            $last = $this->GetBuffer('LastLoginResponse');
            if ($last !== '') $this->SetLastResponse($last);

            $this->SetLastError('Login ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError('Login fehlgeschlagen: ' . $e->getMessage());

            $last = $this->GetBuffer('LastLoginResponse');
            if ($last !== '') $this->SetLastResponse($last);
            // nicht werfen -> sonst Fatal Error im Symcon UI
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

    public function UpdateDeviceInfo()
    {
        $this->SetLastError('');
        try {
            $this->EnsureLoggedIn(false);

            $info = $this->ApiCall('dreame-user-iot/iotuserbind/device/info', array('did' => $this->GetDID()));
            $this->SetLastResponse(json_encode($info));

            // Save useful fields into buffers if present
            if (is_array($info) && isset($info['code']) && (int)$info['code'] === 0 && isset($info['data']) && is_array($info['data'])) {
                $data = $info['data'];

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
            $this->SendDebug('UpdateDeviceInfo', $e->getMessage(), 0);
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    // ---- MIoT via sendCommand (optional scripting) ----

    public function GetProperties($propsJson)
    {
        $props = json_decode($propsJson, true);
        if (!is_array($props)) throw new Exception('propsJson muss ein JSON Array sein, z.B. [[2,1],[3,1]]');

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
            'did'   => $this->GetDID(),
            'siid'  => (int)$siid,
            'piid'  => (int)$piid,
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
            'did'  => $this->GetDID(),
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
        if (!$this->LoginPassword($user, $pass
