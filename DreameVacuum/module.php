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

    // Basic Auth (dreame_appv1:dreame_appv1)
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

    // --- Profile-Namen ---
    const P_DEVICE_STATUS  = 'DRMV.DeviceStatus';
    const P_CHARGING_STATE = 'DRMV.ChargingState';
    const P_OPERATING_MODE = 'DRMV.OperatingMode';
    const P_CLEANING_MODE  = 'DRMV.CleaningMode';
    const P_WATER_FLOW     = 'DRMV.WaterFlow';
    const P_FAULT_CODE     = 'DRMV.FaultCode';
    const P_PERCENT        = 'DRMV.Percent';
    const P_MINUTES        = 'DRMV.Minutes';
    const P_HOURS          = 'DRMV.Hours';
    const P_SQM            = 'DRMV.SquareMeter';

    public function Create()
    {
        parent::Create();

        // Verbindung / Login
        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('AccountType', 'dreame'); // dreame|mova|trouver
        $this->RegisterPropertyString('DID', '');
        $this->RegisterPropertyString('Host', '');             // z.B. 10000.mt.eu.iot.dreame.tech:19973
        $this->RegisterPropertyString('RefreshToken', '');     // HA: auth_key
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        // Polling
        $this->RegisterPropertyInteger('PollInterval', 60);

        // Debug / Status
        $this->RegisterVariableBoolean('Connected', 'Connected', '~Switch', 1);
        $this->RegisterVariableString('LastError', 'LastError', '~TextBox', 2);
        $this->RegisterVariableString('LastResponse', 'LastResponse', '~TextBox', 3);

        // Sinnige Status-Variablen
        $this->RegisterVariableInteger('Battery', 'Batterie', '~Battery', 10);
        $this->RegisterVariableInteger('ChargingState', 'Ladestatus', self::P_CHARGING_STATE, 11);

        $this->RegisterVariableInteger('DeviceStatus', 'Status', self::P_DEVICE_STATUS, 12);
        $this->RegisterVariableInteger('OperatingMode', 'Betriebsmodus', self::P_OPERATING_MODE, 13);
        $this->RegisterVariableInteger('CleaningMode', 'Saugmodus', self::P_CLEANING_MODE, 14);
        $this->RegisterVariableInteger('WaterFlow', 'Wasserfluss', self::P_WATER_FLOW, 15);

        $this->RegisterVariableInteger('FaultCode', 'Fehlercode', self::P_FAULT_CODE, 16);
        $this->RegisterVariableString('FaultText', 'Fehlertext', '~TextBox', 17);

        $this->RegisterVariableInteger('CleaningTime', 'Reinigungszeit', self::P_MINUTES, 18);
        $this->RegisterVariableFloat('CleaningArea', 'Reinigungsfläche', self::P_SQM, 19);

        $this->RegisterVariableInteger('TotalCleanTime', 'Gesamtzeit', self::P_MINUTES, 20);
        $this->RegisterVariableFloat('TotalCleanArea', 'Gesamtfläche', self::P_SQM, 21);
        $this->RegisterVariableInteger('TotalCleanCount', 'Gesamtanzahl', '', 22);

        $this->RegisterVariableInteger('MainBrushLife', 'Hauptbürste (%)', self::P_PERCENT, 30);
        $this->RegisterVariableInteger('MainBrushLeft', 'Hauptbürste Rest', self::P_HOURS, 31);

        $this->RegisterVariableInteger('SideBrushLife', 'Seitenbürste (%)', self::P_PERCENT, 32);
        $this->RegisterVariableInteger('SideBrushLeft', 'Seitenbürste Rest', self::P_HOURS, 33);

        $this->RegisterVariableInteger('FilterLife', 'Filter (%)', self::P_PERCENT, 34);
        $this->RegisterVariableInteger('FilterLeft', 'Filter Rest', self::P_HOURS, 35);

        $this->RegisterVariableInteger('Volume', 'Lautstärke', self::P_PERCENT, 40);

        $this->RegisterVariableInteger('LastUpdate', 'Letztes Update', '~UnixTimestamp', 90);

        // Timer: Status aktualisieren
        $this->RegisterTimer('UpdateTimer', 0, 'DRMV_UpdateStatus($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();

        $interval = (int)$this->ReadPropertyInteger('PollInterval');
        if ($interval < 10) $interval = 10;
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);
    }

    // ---------------- UI Buttons ----------------

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

            if (is_array($info) && isset($info['code']) && (int)$info['code'] === 0 && isset($info['data']) && is_array($info['data'])) {
                $data = $info['data'];

                if ($this->ReadPropertyString('Host') === '') {
                    if (isset($data['bindDomain'])) $this->SetBuffer('HostFromCloud', strval($data['bindDomain']));
                    if (isset($data['host']))       $this->SetBuffer('HostFromCloud', strval($data['host']));
                }
                if (isset($data['model'])) $this->SetBuffer('Model', strval($data['model']));
            }

            $this->SetConnected(true);
            $this->SetLastError('Device info ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    // Kompatibilität: falls du irgendwo DRMV_Update($id) nutzt
    public function Update()
    {
        $this->UpdateStatus();
    }

    public function UpdateStatus()
    {
        $this->SetLastError('');

        try {
            $this->EnsureLoggedIn(false);

            // Wir fragen bewusst "mehr" ab und nehmen nur das, was mit code=0 zurückkommt.
            // (Je nach Modell sind die SIID/PIID unterschiedlich.)
            $pairs = array(
                // Kandidaten: Batterie/Charge/Status/Fault (u.a. 1C vs F9 Varianten)
                array(2,1), array(2,2), array(3,1), array(3,2),

                // Cleaning / Mode (häufig siid=4)
                array(4,1), array(4,2), array(4,3), array(4,4), array(4,5), array(4,6),

                // Totals (häufig siid=12)
                array(12,2), array(12,3), array(12,4),

                // Consumables (häufig siid=9/10/11)
                array(9,1), array(9,2),
                array(10,1), array(10,2),
                array(11,1), array(11,2),

                // Consumables (Alternative 1C: 26/27/28)
                array(26,1), array(26,2),
                array(27,1), array(27,2),
                array(28,1), array(28,2),

                // Volume (je nach Modell 7:1 oder 24:1)
                array(7,1), array(24,1)
            );

            $results = $this->MiotGetProperties($pairs, 10);
            $this->SetLastResponse(json_encode($results));
            $map = $this->ResultsToMap($results);

            // --- Mapping-Autodetektion (wie in python-miio: je nach Modell anders) ---
            $v21 = $this->GetValueFromMap($map, 2, 1);
            $v31 = $this->GetValueFromMap($map, 3, 1);

            $battery = null;
            $charging = null;
            $fault = null;
            $status = null;

            // Fall A (wie bei dir im Test): battery=2:1, charging=2:2, fault=3:1, status=3:2
            if ($this->IsPercent($v21)) {
                $battery = $v21;
                $charging = $this->GetValueFromMap($map, 2, 2);
                $fault    = $this->GetValueFromMap($map, 3, 1);
                $status   = $this->GetValueFromMap($map, 3, 2);
            } elseif ($this->IsPercent($v31)) {
                // Fall B (F9-Style): battery=3:1, charging=3:2, fault=2:2, status=2:1
                $battery = $v31;
                $charging = $this->GetValueFromMap($map, 3, 2);
                $fault    = $this->GetValueFromMap($map, 2, 2);
                $status   = $this->GetValueFromMap($map, 2, 1);
            } else {
                // Fallback: nimm was da ist
                $battery = $this->PickFirstPercent(array($v21, $v31));
                $charging = $this->PickFirstInt(array(
                    $this->GetValueFromMap($map, 2, 2),
                    $this->GetValueFromMap($map, 3, 2)
                ));
                $status = $this->PickFirstInt(array(
                    $this->GetValueFromMap($map, 3, 2),
                    $this->GetValueFromMap($map, 2, 1)
                ));
                $fault = $this->PickFirstInt(array(
                    $this->GetValueFromMap($map, 3, 1),
                    $this->GetValueFromMap($map, 2, 2)
                ));
            }

            if ($battery !== null) SetValueInteger($this->GetIDForIdent('Battery'), (int)$battery);
            if ($charging !== null) SetValueInteger($this->GetIDForIdent('ChargingState'), (int)$charging);
            if ($status !== null) SetValueInteger($this->GetIDForIdent('DeviceStatus'), (int)$status);

            if ($fault !== null) {
                SetValueInteger($this->GetIDForIdent('FaultCode'), (int)$fault);
                SetValueString($this->GetIDForIdent('FaultText'), ((int)$fault === 0) ? 'OK' : ('Code ' . (int)$fault));
            }

            // Cleaning / Modes: bevorzugt siid=4
            $opMode = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 4, 1),
                $this->GetValueFromMap($map, 18, 1)
            ));
            if ($opMode !== null) SetValueInteger($this->GetIDForIdent('OperatingMode'), (int)$opMode);

            $clMode = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 4, 4),
                $this->GetValueFromMap($map, 18, 6)
            ));
            if ($clMode !== null) SetValueInteger($this->GetIDForIdent('CleaningMode'), (int)$clMode);

            $water = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 4, 5)
            ));
            if ($water !== null) SetValueInteger($this->GetIDForIdent('WaterFlow'), (int)$water);

            $ct = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 4, 2),
                $this->GetValueFromMap($map, 18, 2)
            ));
            if ($ct !== null) SetValueInteger($this->GetIDForIdent('CleaningTime'), (int)$ct);

            $ca = $this->PickFirstNumber(array(
                $this->GetValueFromMap($map, 4, 3),
                $this->GetValueFromMap($map, 18, 4)
            ));
            if ($ca !== null) SetValueFloat($this->GetIDForIdent('CleaningArea'), (float)$ca);

            // Totals (siid=12)
            $tct = $this->GetValueFromMap($map, 12, 2);
            if ($tct !== null) SetValueInteger($this->GetIDForIdent('TotalCleanTime'), (int)$tct);

            $tcc = $this->GetValueFromMap($map, 12, 3);
            if ($tcc !== null) SetValueInteger($this->GetIDForIdent('TotalCleanCount'), (int)$tcc);

            $tca = $this->GetValueFromMap($map, 12, 4);
            if ($tca !== null) SetValueFloat($this->GetIDForIdent('TotalCleanArea'), (float)$tca);

            // Consumables: bevorzugt 9/10/11, fallback 26/27/28
            $mainLeft = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 9, 1),
                $this->GetValueFromMap($map, 26, 1)
            ));
            $mainLife = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 9, 2),
                $this->GetValueFromMap($map, 26, 2)
            ));
            if ($mainLeft !== null) SetValueInteger($this->GetIDForIdent('MainBrushLeft'), (int)$mainLeft);
            if ($mainLife !== null) SetValueInteger($this->GetIDForIdent('MainBrushLife'), (int)$mainLife);

            $sideLeft = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 10, 1),
                $this->GetValueFromMap($map, 28, 1)
            ));
            $sideLife = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 10, 2),
                $this->GetValueFromMap($map, 28, 2)
            ));
            if ($sideLeft !== null) SetValueInteger($this->GetIDForIdent('SideBrushLeft'), (int)$sideLeft);
            if ($sideLife !== null) SetValueInteger($this->GetIDForIdent('SideBrushLife'), (int)$sideLife);

            $filterLife = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 11, 1),
                $this->GetValueFromMap($map, 27, 1)
            ));
            $filterLeft = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 11, 2),
                $this->GetValueFromMap($map, 27, 2)
            ));
            if ($filterLife !== null) SetValueInteger($this->GetIDForIdent('FilterLife'), (int)$filterLife);
            if ($filterLeft !== null) SetValueInteger($this->GetIDForIdent('FilterLeft'), (int)$filterLeft);

            // Volume
            $vol = $this->PickFirstInt(array(
                $this->GetValueFromMap($map, 7, 1),
                $this->GetValueFromMap($map, 24, 1)
            ));
            if ($vol !== null) SetValueInteger($this->GetIDForIdent('Volume'), (int)$vol);

            SetValueInteger($this->GetIDForIdent('LastUpdate'), time());

            $this->SetConnected(true);
            $this->SetLastError('Status ok');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
        }
    }

    // ---------------- Public scripting helpers (optional) ----------------

    public function GetProperties($propsJson)
    {
        $props = json_decode($propsJson, true);
        if (!is_array($props)) throw new Exception('propsJson must be JSON array, z.B. [[2,1],[3,2]]');

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

    // ---------------- Profiles & helpers ----------------

    private function EnsureProfiles()
    {
        // DeviceStatus (Werte wie in python-miio) :contentReference[oaicite:0]{index=0}
        $this->EnsureProfileInteger(self::P_DEVICE_STATUS, '', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 0, 'Unbekannt', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 1, 'Saugen', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 2, 'Bereit', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 3, 'Pausiert', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 4, 'Fehler', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 5, 'Zur Station', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 6, 'Lädt', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 7, 'Wischen', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 8, 'Trocknen', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 9, 'Waschen', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 10, 'Zurück zum Waschen', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 11, 'Karte erstellen', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 12, 'Saugen & Wischen', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 13, 'Voll geladen', '');
        $this->SetAssoc(self::P_DEVICE_STATUS, 14, 'Update', '');

        // ChargingState :contentReference[oaicite:1]{index=1}
        $this->EnsureProfileInteger(self::P_CHARGING_STATE, '', '');
        $this->SetAssoc(self::P_CHARGING_STATE, -1, 'Unbekannt', '');
        $this->SetAssoc(self::P_CHARGING_STATE, 0, 'Unbekannt', '');
        $this->SetAssoc(self::P_CHARGING_STATE, 1, 'Lädt', '');
        $this->SetAssoc(self::P_CHARGING_STATE, 2, 'Entlädt', '');
        $this->SetAssoc(self::P_CHARGING_STATE, 3, 'Voll', '');
        $this->SetAssoc(self::P_CHARGING_STATE, 4, 'Lädt (2)', '');
        $this->SetAssoc(self::P_CHARGING_STATE, 5, 'Zur Station', '');

        // OperatingMode :contentReference[oaicite:2]{index=2}
        $this->EnsureProfileInteger(self::P_OPERATING_MODE, '', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 0, 'Unbekannt', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 1, 'Pausiert', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 2, 'Reinigt', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 3, 'Zur Station', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 6, 'Lädt', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 13, 'Manuell', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 14, 'Schläft', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 17, 'Manuell pausiert', '');
        $this->SetAssoc(self::P_OPERATING_MODE, 19, 'Zonenreinigung', '');

        // CleaningMode :contentReference[oaicite:3]{index=3}
        $this->EnsureProfileInteger(self::P_CLEANING_MODE, '', '');
        $this->SetAssoc(self::P_CLEANING_MODE, 0, 'Leise', '');
        $this->SetAssoc(self::P_CLEANING_MODE, 1, 'Standard', '');
        $this->SetAssoc(self::P_CLEANING_MODE, 2, 'Stark', '');
        $this->SetAssoc(self::P_CLEANING_MODE, 3, 'Turbo', '');

        // WaterFlow :contentReference[oaicite:4]{index=4}
        $this->EnsureProfileInteger(self::P_WATER_FLOW, '', '');
        $this->SetAssoc(self::P_WATER_FLOW, 0, 'Unbekannt', '');
        $this->SetAssoc(self::P_WATER_FLOW, 1, 'Niedrig', '');
        $this->SetAssoc(self::P_WATER_FLOW, 2, 'Mittel', '');
        $this->SetAssoc(self::P_WATER_FLOW, 3, 'Hoch', '');

        // FaultCode: nur 0=OK, Rest als Code sichtbar
        $this->EnsureProfileInteger(self::P_FAULT_CODE, '', '');
        $this->SetAssoc(self::P_FAULT_CODE, 0, 'OK', '');

        // Prozent / Minuten / Stunden / m²
        $this->EnsureProfileInteger(self::P_PERCENT, '', ' %', 0, 100, 0);
        $this->EnsureProfileInteger(self::P_MINUTES, '', ' min', 0, 6000, 0);
        $this->EnsureProfileInteger(self::P_HOURS, '', ' h', 0, 6000, 0);
        $this->EnsureProfileFloat(self::P_SQM, '', ' m²', 0, 20000, 0, 1);
    }

    private function EnsureProfileInteger($name, $prefix, $suffix, $min = 0, $max = 0, $step = 0)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        if ($min != 0 || $max != 0 || $step != 0) {
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }
        IPS_SetVariableProfileText($name, $prefix, $suffix);
    }

    private function EnsureProfileFloat($name, $prefix, $suffix, $min = 0.0, $max = 0.0, $step = 0.0, $digits = 1)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 2);
        }
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
    }

    private function SetAssoc($profile, $value, $text, $icon)
    {
        // Verhindert doppelte Associations: Symcon überschreibt bei gleicher value
        IPS_SetVariableProfileAssociation($profile, (int)$value, (string)$text, (string)$icon, -1);
    }

    private function ResultsToMap($results)
    {
        $map = array();
        if (!is_array($results)) return $map;

        foreach ($results as $item) {
            if (!is_array($item)) continue;
            if (!isset($item['siid']) || !isset($item['piid'])) continue;
            $key = (int)$item['siid'] . ':' . (int)$item['piid'];
            $map[$key] = $item;
        }
        return $map;
    }

    private function GetValueFromMap($map, $siid, $piid)
    {
        $key = (int)$siid . ':' . (int)$piid;
        if (!isset($map[$key])) return null;
        $item = $map[$key];
        if (!is_array($item)) return null;
        if (isset($item['code']) && (int)$item['code'] !== 0) return null;
        if (!array_key_exists('value', $item)) return null;
        return $item['value'];
    }

    private function IsPercent($v)
    {
        if ($v === null) return false;
        if (!is_numeric($v)) return false;
        $i = (int)$v;
        return ($i >= 0 && $i <= 100);
    }

    private function PickFirstPercent($arr)
    {
        foreach ($arr as $v) {
            if ($this->IsPercent($v)) return (int)$v;
        }
        return null;
    }

    private function PickFirstInt($arr)
    {
        foreach ($arr as $v) {
            if ($v === null) continue;
            if (is_numeric($v)) return (int)$v;
        }
        return null;
    }

    private function PickFirstNumber($arr)
    {
        foreach ($arr as $v) {
            if ($v === null) continue;
            if (is_numeric($v)) return $v;
        }
        return null;
    }

    // ---------------- MIoT batching ----------------

    private function MiotGetProperties($pairs, $chunkSize)
    {
        $did = $this->GetDID();
        $all = array();

        $chunk = array();
        foreach ($pairs as $p) {
            $chunk[] = array('did' => $did, 'siid' => (int)$p[0], 'piid' => (int)$p[1]);
            if (count($chunk) >= $chunkSize) {
                $res = $this->SendCommand('get_properties', $chunk);
                if (is_array($res)) $all = array_merge($all, $res);
                $chunk = array();
            }
        }
        if (count($chunk) > 0) {
            $res = $this->SendCommand('get_properties', $chunk);
            if (is_array($res)) $all = array_merge($all, $res);
        }

        return $all;
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
        if (!$this->LoginPassword($user, $pass)) throw new Exception('Login fehlgeschlagen (siehe LastResponse)');
    }

    private function LoginRefresh($refreshToken)
    {
        $data = self::LOGIN_PREFIX . self::LOGIN_REFRESH . $refreshToken;
        $res = $this->HttpPostForm($this->GetApiBase() . self::AUTH_PATH, $data);
        $this->SetBuffer('LastLoginResponse', json_encode($res));

        // Wenn RefreshToken invalid ist, versuchen wir später Passwort
        if (is_array($res) && isset($res['error_description']) && stripos($res['error_description'], 'refresh token') !== false) {
            $this->SetBuffer('RefreshToken', '');
            return false;
        }
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

    private function ApiCall($path, $payload, $retry401 = true)
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

        // 401 -> Token evtl. abgelaufen: einmal neu einloggen und retry
        if ($retry401 && is_array($res) && isset($res['_http_status']) && (int)$res['_http_status'] === 401) {
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
        if ($host === '') throw new Exception('Host/Cluster unbekannt. Setze "Host" aus HA oder führe "Geräteinfos aktualisieren" aus.');

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

        // Windows: CA Store ist oft zickig
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
