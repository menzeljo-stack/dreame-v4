<?php

class DreameVacuum extends IPSModule
{
    const API_DOMAIN_DREAME   = '.iot.dreame.tech';
    const API_DOMAIN_MOVA     = '.iot.mova-tech.com';
    const API_DOMAIN_TROUVER  = '.iot.trouver-tech.com';
    const API_PORT            = 13267;

    const PASSWORD_SALT       = 'RAylYC%fmSKp7%Tq';
    const AUTH_PATH           = '/dreame-auth/oauth/token';

    // Client: dreame_appv1:dreame_appv1
    const AUTHORIZATION_VALUE = 'Basic ZHJlYW1lX2FwcHYxOmRyZWFtZV9hcHB2MQ==';
    const TENANT_DEFAULT      = '000000';

    const LOGIN_PREFIX        = 'platform=IOS&scope=all&grant_type=';
    const LOGIN_REFRESH       = 'refresh_token&refresh_token=';
    const LOGIN_PASSWORD      = 'password&username=';
    const LOGIN_AND_PASSWORD  = '&password=';
    const LOGIN_TYPE          = '&type=account';

    const HDR_USER_AGENT      = 'User-Agent';
    const HDR_AUTHORIZATION   = 'Authorization';
    const HDR_TENANT          = 'tenantId';
    const HDR_DREAME_AUTH     = 'dreame-auth';
    const HDR_DREAME_RLC      = 'dreame-rlc';

    const DREAME_RLC_VALUE    = '1c80b3787b2266776bcdc481f37d8fa42ba10a30af81a6df-1';

    const UA_DREAME  = 'Dreame_Smarthome/2.1.9 (iPhone; iOS 18.4.1; Scale/3.00)';
    const UA_MOVA    = 'Mova_Smarthome/1.2.4 (iPhone; iOS 18.4.1; Scale/3.00)';
    const UA_TROUVER = 'Trouver_Smarthome/1.0.9 (iPhone; iOS 18.4.1; Scale/3.00)';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('AccountType', 'dreame');
        $this->RegisterPropertyString('DID', '');
        $this->RegisterPropertyString('Host', '');

        // NEU: direktes AuthKey (HA auth_key / JWT)
        $this->RegisterPropertyString('AuthKey', '');

        // optional klassische Tokens
        $this->RegisterPropertyString('RefreshToken', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterPropertyBoolean('AutoCreateVariables', true);
        $this->RegisterPropertyInteger('StatusPollInterval', 60);
        $this->RegisterPropertyInteger('DeviceInfoPollInterval', 3600);
        $this->RegisterPropertyBoolean('AutoUpdateDeviceInfo', true);

        $this->MaintainVariable('Connected', 'Connected', VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        $this->MaintainVariable('LastError', 'LastError', VARIABLETYPE_STRING, '~TextBox', 2, true);
        $this->MaintainVariable('LastResponse', 'LastResponse', VARIABLETYPE_STRING, '~TextBox', 3, true);

        $this->RegisterTimer('StatusTimer', 0, 'DRMV_UpdateStatus($_IPS["TARGET"]);');
        $this->RegisterTimer('DeviceInfoTimer', 0, 'DRMV_UpdateDeviceInfo($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $statusInterval = (int)$this->ReadPropertyInteger('StatusPollInterval');
        if ($statusInterval < 10) $statusInterval = 10;
        $this->SetTimerInterval('StatusTimer', $statusInterval * 1000);

        if ($this->ReadPropertyBoolean('AutoUpdateDeviceInfo')) {
            $infoInterval = (int)$this->ReadPropertyInteger('DeviceInfoPollInterval');
            if ($infoInterval < 60) $infoInterval = 60;
            $this->SetTimerInterval('DeviceInfoTimer', $infoInterval * 1000);
        } else {
            $this->SetTimerInterval('DeviceInfoTimer', 0);
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->EnsureProfiles();
            if ($this->ReadPropertyBoolean('AutoCreateVariables')) {
                $this->EnsureVariables();
            }
        }
    }

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

                if ($this->ReadPropertyString('Host') === '') {
                    if (isset($data['bindDomain'])) $this->SetBuffer('HostFromCloud', strval($data['bindDomain']));
                    if (isset($data['host']))       $this->SetBuffer('HostFromCloud', strval($data['host']));
                }

                if ($this->ReadPropertyBoolean('AutoCreateVariables')) {
                    $this->EnsureVariables();
                    $this->SetVarString('Model', isset($data['model']) ? strval($data['model']) : '');
                    $this->SetVarString('Firmware', isset($data['ver']) ? strval($data['ver']) : '');
                    $this->SetVarString('Name', isset($data['customName']) ? strval($data['customName']) : '');
                    $this->SetVarString('Mac', isset($data['mac']) ? strval($data['mac']) : '');
                    $this->SetVarBoolean('Online', isset($data['online']) ? (bool)$data['online'] : false);
                }
            }

            $this->SetConnected(true);
            $this->SetLastError('Device info ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    public function UpdateStatus()
    {
        $this->SetLastError('');
        try {
            $this->EnsureLoggedIn(false);

            if ($this->ReadPropertyBoolean('AutoCreateVariables')) {
                $this->EnsureVariables();
            }

            $props = array(
                array('siid' => 2,  'piid' => 1),
                array('siid' => 2,  'piid' => 2),
                array('siid' => 3,  'piid' => 1),
                array('siid' => 3,  'piid' => 2),

                array('siid' => 4,  'piid' => 1),
                array('siid' => 4,  'piid' => 2),
                array('siid' => 4,  'piid' => 3),
                array('siid' => 4,  'piid' => 4),
                array('siid' => 4,  'piid' => 5),
                array('siid' => 4,  'piid' => 6),

                array('siid' => 9,  'piid' => 1),
                array('siid' => 9,  'piid' => 2),
                array('siid' => 10, 'piid' => 1),
                array('siid' => 10, 'piid' => 2),
                array('siid' => 11, 'piid' => 1),
                array('siid' => 11, 'piid' => 2),

                array('siid' => 12, 'piid' => 2),
                array('siid' => 12, 'piid' => 3),
                array('siid' => 12, 'piid' => 4)
            );

            $payload = array();
            foreach ($props as $p) {
                $payload[] = array('did' => $this->GetDID(), 'siid' => (int)$p['siid'], 'piid' => (int)$p['piid']);
            }

            $result = $this->SendCommand('get_properties', $payload);
            $this->SetLastResponse(json_encode($result));

            if (!is_array($result)) throw new Exception('Unerwartete Antwort bei get_properties');

            foreach ($result as $item) {
                if (!is_array($item)) continue;
                if (!isset($item['siid']) || !isset($item['piid'])) continue;
                if (isset($item['code']) && (int)$item['code'] !== 0) continue;
                if (!array_key_exists('value', $item)) continue;

                $key = ((int)$item['siid']) . '-' . ((int)$item['piid']);
                $val = $item['value'];

                switch ($key) {
                    case '2-1': $this->SetVarInt('DeviceStatus', (int)$val); break;
                    case '2-2':
                        $fault = (int)$val;
                        $this->SetVarInt('DeviceFault', $fault);
                        $this->SetVarString('DeviceFaultText', ($fault === 0) ? 'OK' : ('Fehlercode ' . $fault));
                        break;

                    case '3-1': $this->SetVarInt('Battery', (int)$val); break;
                    case '3-2': $this->SetVarInt('ChargingState', (int)$val); break;

                    case '4-1': $this->SetVarInt('OperatingMode', (int)$val); break;
                    case '4-2': $this->SetVarInt('CleaningTime', (int)$val); break;
                    case '4-3': $this->SetVarFloat('CleaningArea', (float)$val); break;
                    case '4-4': $this->SetVarInt('CleaningMode', (int)$val); break;
                    case '4-5': $this->SetVarInt('WaterFlow', (int)$val); break;
                    case '4-6': $this->SetVarBoolean('MopAttached', ((int)$val) === 1); break;

                    case '9-1':  $this->SetVarInt('MainBrushLeftTime', (int)$val); break;
                    case '9-2':  $this->SetVarInt('MainBrushLife', (int)$val); break;
                    case '10-1': $this->SetVarInt('SideBrushLeftTime', (int)$val); break;
                    case '10-2': $this->SetVarInt('SideBrushLife', (int)$val); break;
                    case '11-1': $this->SetVarInt('FilterLife', (int)$val); break;
                    case '11-2': $this->SetVarInt('FilterLeftTime', (int)$val); break;

                    case '12-2': $this->SetVarInt('TotalCleanTime', (int)$val); break;
                    case '12-3': $this->SetVarInt('TotalCleanCount', (int)$val); break;
                    case '12-4': $this->SetVarInt('TotalCleanArea', (int)$val); break;
                }
            }

            $this->SetVarInt('LastUpdate', time());
            $this->SetConnected(true);
            $this->SetLastError('Status ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    private function EnsureProfiles()
    {
        $this->EnsureIntProfile('DRMV.DeviceStatus', array(
            1 => 'Saugen',
            2 => 'Bereit / Idle',
            3 => 'Pausiert',
            4 => 'Fehler',
            5 => 'Fährt zur Station',
            6 => 'Lädt',
            7 => 'Wischt',
            12 => 'Saugen & Wischen',
            13 => 'Laden abgeschlossen'
        ));

        $this->EnsureIntProfile('DRMV.ChargingState', array(
            1 => 'Laden',
            2 => 'Entladen',
            5 => 'Fährt zur Station'
        ));

        $this->EnsureIntProfile('DRMV.OperatingMode', array(
            1 => 'Pausiert',
            2 => 'Reinigt',
            3 => 'Fährt zur Station',
            6 => 'Lädt'
        ));

        $this->EnsureIntProfile('DRMV.CleaningMode', array(
            0 => 'Leise',
            1 => 'Standard',
            2 => 'Stark',
            3 => 'Turbo'
        ));

        $this->EnsureIntProfile('DRMV.WaterFlow', array(
            1 => 'Niedrig',
            2 => 'Mittel',
            3 => 'Hoch'
        ));

        $this->EnsureIntProfileSimple('DRMV.Minutes', ' min');
        $this->EnsureIntProfileSimple('DRMV.Hours', ' h');
        $this->EnsureIntProfileSimple('DRMV.Area', ' m²');
    }

    private function EnsureVariables()
    {
        $this->MaintainVariable('Model', 'Model', VARIABLETYPE_STRING, '~TextBox', 10, true);
        $this->MaintainVariable('Firmware', 'Firmware', VARIABLETYPE_STRING, '~TextBox', 11, true);
        $this->MaintainVariable('Name', 'Name', VARIABLETYPE_STRING, '~TextBox', 12, true);
        $this->MaintainVariable('Mac', 'Mac', VARIABLETYPE_STRING, '~TextBox', 13, true);
        $this->MaintainVariable('Online', 'Online', VARIABLETYPE_BOOLEAN, '~Switch', 14, true);

        $this->MaintainVariable('DeviceStatus', 'Status', VARIABLETYPE_INTEGER, 'DRMV.DeviceStatus', 20, true);
        $this->MaintainVariable('OperatingMode', 'Betriebsmodus', VARIABLETYPE_INTEGER, 'DRMV.OperatingMode', 21, true);
        $this->MaintainVariable('ChargingState', 'Ladestatus', VARIABLETYPE_INTEGER, 'DRMV.ChargingState', 22, true);
        $this->MaintainVariable('Battery', 'Akku', VARIABLETYPE_INTEGER, '~Battery.100', 23, true);

        $this->MaintainVariable('DeviceFault', 'Fehlercode', VARIABLETYPE_INTEGER, '', 24, true);
        $this->MaintainVariable('DeviceFaultText', 'Fehlertext', VARIABLETYPE_STRING, '~TextBox', 25, true);

        $this->MaintainVariable('CleaningTime', 'Reinigungszeit', VARIABLETYPE_INTEGER, 'DRMV.Minutes', 30, true);
        $this->MaintainVariable('CleaningArea', 'Reinigungsfläche', VARIABLETYPE_FLOAT, 'DRMV.Area', 31, true);
        $this->MaintainVariable('CleaningMode', 'Saugstufe', VARIABLETYPE_INTEGER, 'DRMV.CleaningMode', 32, true);
        $this->MaintainVariable('WaterFlow', 'Wasserfluss', VARIABLETYPE_INTEGER, 'DRMV.WaterFlow', 33, true);
        $this->MaintainVariable('MopAttached', 'Wischmodul montiert', VARIABLETYPE_BOOLEAN, '~Switch', 34, true);

        $this->MaintainVariable('MainBrushLife', 'Hauptbürste Rest (%)', VARIABLETYPE_INTEGER, '~Intensity.100', 40, true);
        $this->MaintainVariable('MainBrushLeftTime', 'Hauptbürste Rest (h)', VARIABLETYPE_INTEGER, 'DRMV.Hours', 41, true);
        $this->MaintainVariable('SideBrushLife', 'Seitenbürste Rest (%)', VARIABLETYPE_INTEGER, '~Intensity.100', 42, true);
        $this->MaintainVariable('SideBrushLeftTime', 'Seitenbürste Rest (h)', VARIABLETYPE_INTEGER, 'DRMV.Hours', 43, true);
        $this->MaintainVariable('FilterLife', 'Filter Rest (%)', VARIABLETYPE_INTEGER, '~Intensity.100', 44, true);
        $this->MaintainVariable('FilterLeftTime', 'Filter Rest (h)', VARIABLETYPE_INTEGER, 'DRMV.Hours', 45, true);

        $this->MaintainVariable('TotalCleanTime', 'Gesamt Reinigungszeit', VARIABLETYPE_INTEGER, 'DRMV.Minutes', 50, true);
        $this->MaintainVariable('TotalCleanCount', 'Gesamt Reinigungen', VARIABLETYPE_INTEGER, '', 51, true);
        $this->MaintainVariable('TotalCleanArea', 'Gesamt Reinigungsfläche', VARIABLETYPE_INTEGER, 'DRMV.Area', 52, true);

        $this->MaintainVariable('LastUpdate', 'Letztes Update', VARIABLETYPE_INTEGER, '~UnixTimestamp', 60, true);
    }

    private function EnsureIntProfile($name, $associations)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
        }
        foreach ($associations as $value => $text) {
            IPS_SetVariableProfileAssociation($name, (int)$value, (string)$text, '', 0);
        }
    }

    private function EnsureIntProfileSimple($name, $suffix)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText($name, '', $suffix);
        }
    }

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

    private function IsJwt($token)
    {
        // sehr grob: 3 Base64url-Teile getrennt durch Punkte
        return (substr_count($token, '.') === 2);
    }

    private function JwtPayload($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) $b64 .= str_repeat('=', 4 - $pad);
        $json = base64_decode($b64);
        if ($json === false) return null;
        $obj = json_decode($json, true);
        if (!is_array($obj)) return null;
        return $obj;
    }

    private function EnsureLoggedIn($force)
    {
        // 1) AuthKey (JWT) direkt verwenden (HA auth_key)
        $authKey = trim($this->ReadPropertyString('AuthKey'));
        if ($authKey !== '' && $this->IsJwt($authKey) && $this->GetBuffer('DisableAuthKey') !== '1') {
            $payload = $this->JwtPayload($authKey);
            if (is_array($payload) && isset($payload['tenant_id'])) {
                $this->SetBuffer('TenantId', strval($payload['tenant_id']));
            }
            if (is_array($payload) && isset($payload['exp'])) {
                $this->SetBuffer('AccessTokenExpire', strval(((int)$payload['exp']) - 60));
            } else {
                $this->SetBuffer('AccessTokenExpire', strval(time() + 3600));
            }
            $this->SetBuffer('AccessToken', $authKey);
            return;
        }

        // 2) vorhandenes AccessToken nutzen
        if (!$force) {
            $token = $this->GetBuffer('AccessToken');
            $exp   = (int)$this->GetBuffer('AccessTokenExpire');
            if ($token !== '' && $exp > time()) return;
        }

        // 3) RefreshToken-Flow (nur wenn es KEIN JWT ist)
        $refresh = trim($this->ReadPropertyString('RefreshToken'));
        if ($refresh !== '' && !$this->IsJwt($refresh)) {
            if ($this->LoginRefresh($refresh)) {
                $this->SetBuffer('DisableAuthKey', ''); // falls wir vorher umgeschaltet hatten
                return;
            }
        }

        // 4) Passwort-Login
        $user = trim($this->ReadPropertyString('Username'));
        $pass = $this->ReadPropertyString('Password');
        if ($user === '' || $pass === '') throw new Exception('Kein gültiger Login: AuthKey oder Username/Password setzen');
        if (!$this->LoginPassword($user, $pass)) throw new Exception('Login fehlgeschlagen');
        $this->SetBuffer('DisableAuthKey', '');
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
            return true;
        }
        return false;
    }

    private function ApiCall($path, $payload, $retry = true)
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

        // Bei 401: einmal AuthKey deaktivieren (damit Passwort-Login greifen kann) und retry
        if ($retry && is_array($res) && isset($res['_http_status']) && (int)$res['_http_status'] === 401) {
            $this->SetBuffer('DisableAuthKey', '1');
            $this->SetBuffer('AccessToken', '');
            $this->SetBuffer('AccessTokenExpire', '0');
            $this->EnsureLoggedIn(true);
            return $this->ApiCall($path, $payload, false);
        }

        return $res;
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
        $host = $this->EnsureHostLoaded();
        if ($host === '') throw new Exception('Host/Cluster unbekannt. Setze "Host" oder nutze "Geräteinfos aktualisieren".');

        $parts = explode('.', $host);
        $cluster = (count($parts) > 0) ? ('-' . $parts[0]) : '';

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

        // gzip/deflate automatisch dekodieren
        curl_setopt($ch, CURLOPT_ENCODING, '');

        // Windows CA issues
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

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

    private function SetConnected($state) { $this->SetVarBoolean('Connected', (bool)$state); }
    private function SetLastError($msg)   { $this->SetVarString('LastError', (string)$msg); }
    private function SetLastResponse($msg){ $this->SetVarString('LastResponse', (string)$msg); }

    private function SetVarBoolean($ident, $val)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id) SetValueBoolean($id, (bool)$val);
    }
    private function SetVarInt($ident, $val)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id) SetValueInteger($id, (int)$val);
    }
    private function SetVarFloat($ident, $val)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id) SetValueFloat($id, (float)$val);
    }
    private function SetVarString($ident, $val)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id) SetValueString($id, (string)$val);
    }
}
