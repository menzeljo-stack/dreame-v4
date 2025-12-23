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

    // Headers
    const HDR_USER_AGENT      = 'User-Agent';
    const HDR_AUTHORIZATION   = 'Authorization';
    const HDR_TENANT          = 'tenantId';
    const HDR_DREAME_AUTH     = 'dreame-auth';
    const HDR_DREAME_RLC      = 'dreame-rlc';

    // Only CN
    const DREAME_RLC_VALUE    = '1c80b3787b2266776bcdc481f37d8fa42ba10a30af81a6df-1';

    // User agents
    const UA_DREAME  = 'Dreame_Smarthome/2.1.9 (iPhone; iOS 18.4.1; Scale/3.00)';
    const UA_MOVA    = 'Mova_Smarthome/1.2.4 (iPhone; iOS 18.4.1; Scale/3.00)';
    const UA_TROUVER = 'Trouver_Smarthome/1.0.9 (iPhone; iOS 18.4.1; Scale/3.00)';

    public function Create()
    {
        parent::Create();

        // Connection / Auth
        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('AccountType', 'dreame'); // dreame|mova|trouver
        $this->RegisterPropertyString('DID', '');
        $this->RegisterPropertyString('Host', '');             // e.g. 10000.mt.eu.iot.dreame.tech:19973

        // Optional: HA "auth_key" is NOT always a refresh-token.
        $this->RegisterPropertyString('RefreshToken', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterPropertyInteger('PollInterval', 60);

        // Timer calls wrapper function (prefix DRMV)
        $this->RegisterTimer('UpdateTimer', 0, 'DRMV_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Create profiles + variables only when Symcon kernel is ready
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->CreateProfiles();
            $this->SetupVariables();
        }

        $interval = (int)$this->ReadPropertyInteger('PollInterval');
        if ($interval < 10) $interval = 10;
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);
    }

    // ---------------- UI actions ----------------

    public function TestLogin()
    {
        $this->SetLastError('');
        $this->SetLastResponse('');

        try {
            $this->EnsureLoggedIn(true);
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

                // Host
                if ($this->ReadPropertyString('Host') === '') {
                    if (isset($data['bindDomain'])) $this->SetBuffer('HostFromCloud', strval($data['bindDomain']));
                    if (isset($data['host']))       $this->SetBuffer('HostFromCloud', strval($data['host']));
                }

                // Meta vars
                if (isset($data['customName'])) SetValueString($this->GetIDForIdent('DeviceName'), strval($data['customName']));
                if (isset($data['model']))      SetValueString($this->GetIDForIdent('Model'), strval($data['model']));
                if (isset($data['ver']))        SetValueString($this->GetIDForIdent('Firmware'), strval($data['ver']));
                if (isset($data['online']))     SetValueBoolean($this->GetIDForIdent('Online'), (bool)$data['online']);
            }

            $this->SetConnected(true);
            $this->SetLastError('Device info ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    // Main periodic update (timer)
    public function Update()
    {
        $this->SetLastError('');

        try {
            // Ensure we know model/host once
            if (GetValueString($this->GetIDForIdent('Model')) === '' || $this->ReadPropertyString('Host') === '') {
                $this->UpdateDeviceInfo();
            }

            $values = $this->FetchStatus();
            $this->ApplyStatus($values);

            $this->SetConnected(true);
            $this->SetLastError('Status ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    // ---------------- Variable & profile setup ----------------

    private function SetupVariables()
    {
        // Basic
        $this->MaintainVariable('Connected',   'Connected',   VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        $this->MaintainVariable('Online',      'Online',      VARIABLETYPE_BOOLEAN, '~Switch', 2, true);
        $this->MaintainVariable('DeviceName',  'Gerätename',  VARIABLETYPE_STRING,  '~TextBox', 3, true);
        $this->MaintainVariable('Model',       'Modell',      VARIABLETYPE_STRING,  '~TextBox', 4, true);
        $this->MaintainVariable('Firmware',    'Firmware',    VARIABLETYPE_STRING,  '~TextBox', 5, true);

        // Status
        $this->MaintainVariable('Status',        'Status',        VARIABLETYPE_INTEGER, 'DRMV.Status', 10, true);
        $this->MaintainVariable('Charging',      'Ladezustand',   VARIABLETYPE_INTEGER, 'DRMV.Charging', 11, true);
        $this->MaintainVariable('OperatingMode', 'Betriebsmodus', VARIABLETYPE_INTEGER, 'DRMV.OperatingMode', 12, true);

        $this->MaintainVariable('Battery',       'Batterie',      VARIABLETYPE_INTEGER, '~Battery', 13, true);
        $this->MaintainVariable('ErrorCode',     'Fehlercode',    VARIABLETYPE_INTEGER, 'DRMV.ErrorCode', 14, true);
        $this->MaintainVariable('ErrorText',     'Fehlertext',    VARIABLETYPE_STRING,  '~TextBox', 15, true);

        // Cleaning stats
        $this->MaintainVariable('CleaningMode',  'Saugstufe',     VARIABLETYPE_INTEGER, 'DRMV.CleaningMode', 20, true);
        $this->MaintainVariable('CleaningTime',  'Reinigungszeit',VARIABLETYPE_INTEGER, 'DRMV.Minutes', 21, true);
        $this->MaintainVariable('CleaningArea',  'Fläche',        VARIABLETYPE_FLOAT,   'DRMV.Area', 22, true);
        $this->MaintainVariable('WaterFlow',     'Wassermenge',   VARIABLETYPE_INTEGER, 'DRMV.WaterFlow', 23, true);
        $this->MaintainVariable('WaterBox',      'Wassertank',    VARIABLETYPE_INTEGER, 'DRMV.WaterBox', 24, true);

        // Consumables
        $this->MaintainVariable('MainBrushLeft', 'Hauptbürste Rest', VARIABLETYPE_INTEGER, 'DRMV.Hours', 30, true);
        $this->MaintainVariable('MainBrushLife', 'Hauptbürste %',    VARIABLETYPE_INTEGER, 'DRMV.Percent', 31, true);
        $this->MaintainVariable('SideBrushLeft', 'Seitenbürste Rest',VARIABLETYPE_INTEGER, 'DRMV.Hours', 32, true);
        $this->MaintainVariable('SideBrushLife', 'Seitenbürste %',   VARIABLETYPE_INTEGER, 'DRMV.Percent', 33, true);
        $this->MaintainVariable('FilterLeft',    'Filter Rest',      VARIABLETYPE_INTEGER, 'DRMV.Hours', 34, true);
        $this->MaintainVariable('FilterLife',    'Filter %',         VARIABLETYPE_INTEGER, 'DRMV.Percent', 35, true);

        // Totals (often available)
        $this->MaintainVariable('TotalCleanTime',  'Gesamtzeit',    VARIABLETYPE_INTEGER, 'DRMV.Minutes', 40, true);
        $this->MaintainVariable('TotalCleanArea',  'Gesamtfläche',  VARIABLETYPE_FLOAT,   'DRMV.Area', 41, true);
        $this->MaintainVariable('TotalCleanCount', 'Gesamtfahrten', VARIABLETYPE_INTEGER, '', 42, true);

        // Debug
        $this->MaintainVariable('LastError',    'LastError',    VARIABLETYPE_STRING, '~TextBox', 90, true);
        $this->MaintainVariable('LastResponse', 'LastResponse', VARIABLETYPE_STRING, '~TextBox', 91, true);
    }

    private function CreateProfiles()
    {
        // Status (DeviceStatus)
        if (!IPS_VariableProfileExists('DRMV.Status')) {
            IPS_CreateVariableProfile('DRMV.Status', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRMV.Status', 1,  'Saugen', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 2,  'Idle', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 3,  'Pausiert', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 4,  'Fehler', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 5,  'Zur Station', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 6,  'Lädt', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 7,  'Wischt', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 8,  'Trocknet', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 9,  'Wäscht Mop', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 10, 'Zur Mop-Wäsche', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 11, 'Kartiert', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 12, 'Saugen + Wischen', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 13, 'Voll geladen', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Status', 14, 'Update', '', 0);
        }

        // ChargingState
        if (!IPS_VariableProfileExists('DRMV.Charging')) {
            IPS_CreateVariableProfile('DRMV.Charging', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRMV.Charging', 1, 'Lädt', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Charging', 2, 'Entlädt', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Charging', 4, 'Lädt (2)', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Charging', 5, 'Zur Ladestation', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.Charging', -1,'Unbekannt', '', 0);
        }

        // OperatingMode (work-mode)
        if (!IPS_VariableProfileExists('DRMV.OperatingMode')) {
            IPS_CreateVariableProfile('DRMV.OperatingMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRMV.OperatingMode', 1,  'Pausiert', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.OperatingMode', 2,  'Reinigt', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.OperatingMode', 3,  'Zur Station', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.OperatingMode', 6,  'Lädt', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.OperatingMode', 13, 'Manuell', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.OperatingMode', 14, 'Schlafmodus', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.OperatingMode', 17, 'Manuell pausiert', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.OperatingMode', 19, 'Zonenreinigung', '', 0);
        }

        // CleaningMode (suction)
        if (!IPS_VariableProfileExists('DRMV.CleaningMode')) {
            IPS_CreateVariableProfile('DRMV.CleaningMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRMV.CleaningMode', 0, 'Leise', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.CleaningMode', 1, 'Standard', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.CleaningMode', 2, 'Stark', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.CleaningMode', 3, 'Turbo', '', 0);
        }

        // WaterFlow (mop water)
        if (!IPS_VariableProfileExists('DRMV.WaterFlow')) {
            IPS_CreateVariableProfile('DRMV.WaterFlow', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRMV.WaterFlow', 1, 'Niedrig', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.WaterFlow', 2, 'Mittel', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.WaterFlow', 3, 'Hoch', '', 0);
        }

        // WaterBox (unknown values per model -> keep generic but with common ones)
        if (!IPS_VariableProfileExists('DRMV.WaterBox')) {
            IPS_CreateVariableProfile('DRMV.WaterBox', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRMV.WaterBox', 0, 'Nicht eingesetzt', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.WaterBox', 1, 'Eingesetzt', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.WaterBox', 2, 'Unbekannt', '', 0);
        }

        // ErrorCode (basic)
        if (!IPS_VariableProfileExists('DRMV.ErrorCode')) {
            IPS_CreateVariableProfile('DRMV.ErrorCode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRMV.ErrorCode', 0,  'OK', '', 0);
            IPS_SetVariableProfileAssociation('DRMV.ErrorCode', -1, 'Unbekannt', '', 0);
        }

        // Minutes
        if (!IPS_VariableProfileExists('DRMV.Minutes')) {
            IPS_CreateVariableProfile('DRMV.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('DRMV.Minutes', '', ' min');
            IPS_SetVariableProfileDigits('DRMV.Minutes', 0);
        }

        // Hours
        if (!IPS_VariableProfileExists('DRMV.Hours')) {
            IPS_CreateVariableProfile('DRMV.Hours', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('DRMV.Hours', '', ' h');
            IPS_SetVariableProfileDigits('DRMV.Hours', 0);
        }

        // Percent
        if (!IPS_VariableProfileExists('DRMV.Percent')) {
            IPS_CreateVariableProfile('DRMV.Percent', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('DRMV.Percent', '', ' %');
            IPS_SetVariableProfileValues('DRMV.Percent', 0, 100, 1);
            IPS_SetVariableProfileDigits('DRMV.Percent', 0);
        }

        // Area
        if (!IPS_VariableProfileExists('DRMV.Area')) {
            IPS_CreateVariableProfile('DRMV.Area', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('DRMV.Area', '', ' m²');
            IPS_SetVariableProfileDigits('DRMV.Area', 1);
        }
    }

    // ---------------- Status polling ----------------

    private function FetchStatus()
    {
        // siid/piid list based on common Dreame/Trouver MIoT layout
        $props = array(
            array(2, 1),  // device_status
            array(2, 2),  // device_fault
            array(3, 1),  // battery_level
            array(3, 2),  // charging_state
            array(4, 1),  // operating_mode
            array(4, 2),  // cleaning_time
            array(4, 3),  // cleaning_area
            array(4, 4),  // cleaning_mode (suction)
            array(4, 5),  // water_flow
            array(4, 6),  // water_box_carriage_status
            array(9, 1),  // main brush left time
            array(9, 2),  // main brush life %
            array(10, 1), // side brush left time
            array(10, 2), // side brush life %
            array(11, 1), // filter life %
            array(11, 2), // filter left time
            array(12, 2), // total clean time
            array(12, 3), // total clean times
            array(12, 4), // total clean area
        );

        $payload = array();
        foreach ($props as $p) {
            $payload[] = array('did' => $this->GetDID(), 'siid' => (int)$p[0], 'piid' => (int)$p[1]);
        }

        $result = $this->SendCommand('get_properties', $payload);
        $this->SetLastResponse(json_encode($result));
        return $result;
    }

    private function ApplyStatus($result)
    {
        if (!is_array($result)) return;

        $map = array();
        foreach ($result as $item) {
            if (!is_array($item) || !isset($item['siid']) || !isset($item['piid'])) continue;
            $key = strval($item['siid']) . ':' . strval($item['piid']);
            $map[$key] = $item;
        }

        $this->SetIntIfOk($map, '2:1', 'Status');
        $this->SetIntIfOk($map, '2:2', 'ErrorCode');
        $this->SetIntIfOk($map, '3:1', 'Battery');
        $this->SetIntIfOk($map, '3:2', 'Charging');
        $this->SetIntIfOk($map, '4:1', 'OperatingMode');
        $this->SetIntIfOk($map, '4:4', 'CleaningMode');
        $this->SetIntIfOk($map, '4:2', 'CleaningTime');

        // cleaning_area: heuristic scaling
        $area = $this->GetValueIfOk($map, '4:3');
        if ($area !== null) {
            $a = floatval($area);
            if ($a > 10000) $a = $a / 1000.0;
            else if ($a > 500) $a = $a / 100.0;
            SetValueFloat($this->GetIDForIdent('CleaningArea'), $a);
        }

        $this->SetIntIfOk($map, '4:5', 'WaterFlow');
        $this->SetIntIfOk($map, '4:6', 'WaterBox');

        $this->SetIntIfOk($map, '9:1', 'MainBrushLeft');
        $this->SetIntIfOk($map, '9:2', 'MainBrushLife');
        $this->SetIntIfOk($map, '10:1', 'SideBrushLeft');
        $this->SetIntIfOk($map, '10:2', 'SideBrushLife');
        $this->SetIntIfOk($map, '11:1', 'FilterLife');
        $this->SetIntIfOk($map, '11:2', 'FilterLeft');

        $this->SetIntIfOk($map, '12:2', 'TotalCleanTime');

        $totalArea = $this->GetValueIfOk($map, '12:4');
        if ($totalArea !== null) {
            $ta = floatval($totalArea);
            if ($ta > 10000) $ta = $ta / 1000.0;
            else if ($ta > 500) $ta = $ta / 100.0;
            SetValueFloat($this->GetIDForIdent('TotalCleanArea'), $ta);
        }

        $this->SetIntIfOk($map, '12:3', 'TotalCleanCount');

        // Error text
        $code = GetValueInteger($this->GetIDForIdent('ErrorCode'));
        if ($code === 0) {
            SetValueString($this->GetIDForIdent('ErrorText'), '');
        } else {
            SetValueString($this->GetIDForIdent('ErrorText'), 'Fehlercode ' . strval($code));
        }
    }

    private function GetValueIfOk($map, $key)
    {
        if (!isset($map[$key])) return null;
        $item = $map[$key];
        if (!is_array($item)) return null;
        if (isset($item['code']) && (int)$item['code'] !== 0) return null;
        if (!isset($item['value'])) return null;
        return $item['value'];
    }

    private function SetIntIfOk($map, $key, $ident)
    {
        $v = $this->GetValueIfOk($map, $key);
        if ($v === null) return;
        SetValueInteger($this->GetIDForIdent($ident), (int)$v);
    }

    // ---------------- Core helpers ----------------

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

        // Try refresh token first (property or buffer)
        $refresh = trim($this->ReadPropertyString('RefreshToken'));
        if ($refresh === '') $refresh = $this->GetBuffer('RefreshToken');

        if ($refresh !== '') {
            if ($this->LoginRefresh($refresh)) return;
        }

        // Fallback: username/password
        $user = trim($this->ReadPropertyString('Username'));
        $pass = $this->ReadPropertyString('Password');
        if ($user === '' || $pass === '') throw new Exception('RefreshToken ungültig/leer und Username/Password fehlt');
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

        $res = $this->CurlPost($url, json_encode($payload), $headers, 15);

        // If token expired: retry once with a forced relogin
        if (is_array($res) && isset($res['_http_status']) && (int)$res['_http_status'] === 401) {
            $this->SetBuffer('AccessToken', '');
            $this->SetBuffer('AccessTokenExpire', '0');
            $this->EnsureLoggedIn(true);

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

            $res = $this->CurlPost($url, json_encode($payload), $headers, 15);
        }

        return $res;
    }

    private function EnsureHostLoaded()
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host !== '') return $host;

        $host = $this->GetBuffer('HostFromCloud');
        if ($host !== '') return $host;

        // Fetch now
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
        if ($host === '') throw new Exception('Host/Cluster unbekannt. Setze "Host" oder führe "Geräteinfos aktualisieren" aus.');

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

    // ---------------- HTTP helpers ----------------

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

        // Windows installs sometimes miss CA store
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

    // ---------------- Convenience setters ----------------

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
