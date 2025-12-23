<?php
class DreameVacuum extends IPSModule
{
    const API_DOMAIN_DREAME   = '.iot.dreame.tech';
    const API_DOMAIN_MOVA     = '.iot.mova-tech.com';
    const API_DOMAIN_TROUVER  = '.iot.trouver-tech.com';
    const API_PORT            = 13267;

    const PASSWORD_SALT       = 'RAylYC%fmSKp7%Tq';
    const AUTH_PATH           = '/dreame-auth/oauth/token';

    // dreame_appv1:dreame_appv1
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

    // Common MIoT action
    const ACTION_SIID_START_CLEAN = 4;
    const ACTION_AIID_START_CLEAN = 1;

    // Common values for piid=1 within Start-Clean action (varies by model/firmware)
    const CMD_START      = 2;
    const CMD_PAUSE      = 3;
    const CMD_STOP       = 4;
    const CMD_DOCK       = 5;
    const CMD_SPOT       = 6;
    const CMD_LOCATE     = 21;
    const CMD_SHORTCUT   = 25;
    const CMD_ROOMS      = 18;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('AccountType', 'dreame');
        $this->RegisterPropertyString('DID', '');
        $this->RegisterPropertyString('Host', '');

        // IMPORTANT: HA auth_key is a JWT ACCESS token (NOT a refresh token)
        $this->RegisterPropertyString('AuthKey', '');
        $this->RegisterPropertyString('RefreshToken', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterPropertyBoolean('AutoCreateVariables', true);
        $this->RegisterPropertyInteger('StatusPollInterval', 60);
        $this->RegisterPropertyInteger('DeviceInfoPollInterval', 3600);
        $this->RegisterPropertyBoolean('AutoUpdateDeviceInfo', true);

        // Debug vars
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

    // Visualisierung/Bedienung: Variablenaktionen abfangen
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Command') {
            $cmd = (int)$Value;
            try {
                switch ($cmd) {
                    case 1: $this->StartCleaning(); break;
                    case 2: $this->PauseCleaning(); break;
                    case 3: $this->StopCleaning(); break;
                    case 4: $this->Dock(); break;
                    case 5: $this->SpotClean(); break;
                    case 6: $this->Locate(); break;
                    default: break;
                }
                $vid = @$this->GetIDForIdent('Command');
                if ($vid) SetValueInteger($vid, 0);
            } catch (Exception $e) {
                $this->SetLastError($e->getMessage());
            }
            return;
        }

        if ($Ident === 'ShortcutSelected') {
            $vid = @$this->GetIDForIdent('ShortcutSelected');
            if ($vid) SetValueInteger($vid, (int)$Value);
            return;
        }

        if ($Ident === 'StartSelectedShortcut') {
            $on = (bool)$Value;
            if ($on) {
                try {
                    $sid = $this->GetSelectedShortcutId();
                    if ($sid <= 0) throw new Exception('Kein Shortcut ausgewählt');
                    $this->StartShortcut($sid);
                } catch (Exception $e) {
                    $this->SetLastError($e->getMessage());
                }
                $vid = @$this->GetIDForIdent('StartSelectedShortcut');
                if ($vid) SetValueBoolean($vid, false);
            }
            return;
        }

        if (strpos($Ident, 'SC_') === 0) {
            $on = (bool)$Value;
            $sid = (int)substr($Ident, 3);
            if ($sid > 0 && $on) {
                try {
                    $this->StartShortcut($sid);
                } catch (Exception $e) {
                    $this->SetLastError($e->getMessage());
                }
                $vid = @$this->GetIDForIdent($Ident);
                if ($vid) SetValueBoolean($vid, false);
            }
            return;
        }

        throw new Exception('Invalid Ident');
    }

    private function GetSelectedShortcutId()
    {
        $vid = @$this->GetIDForIdent('ShortcutSelected');
        if (!$vid) return 0;
        return (int)GetValueInteger($vid);
    }

    // ---------------- UI Actions ----------------

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
            if ($this->ReadPropertyBoolean('AutoCreateVariables')) $this->EnsureVariables();

            $props = array(
                array('siid' => 2,  'piid' => 1),
                array('siid' => 2,  'piid' => 2),
                array('siid' => 3,  'piid' => 1),
                array('siid' => 3,  'piid' => 2),

                array('siid' => 4,  'piid' => 2),
                array('siid' => 4,  'piid' => 3),
                array('siid' => 4,  'piid' => 23),
                array('siid' => 4,  'piid' => 48),

                array('siid' => 9,  'piid' => 1),
                array('siid' => 9,  'piid' => 2),
                array('siid' => 10, 'piid' => 1),
                array('siid' => 10, 'piid' => 2),
                array('siid' => 11, 'piid' => 1),
                array('siid' => 11, 'piid' => 2),
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

                $siid = (int)$item['siid'];
                $piid = (int)$item['piid'];
                $val  = $item['value'];
                $key = $siid . '-' . $piid;

                switch ($key) {
                    case '2-1': $this->SetVarInt('DeviceStatus', (int)$val); break;
                    case '2-2':
                        $fault = (int)$val;
                        $this->SetVarInt('DeviceFault', $fault);
                        $this->SetVarString('DeviceFaultText', ($fault === 0) ? 'OK' : ('Fehlercode ' . $fault));
                        break;
                    case '3-1': $this->SetVarInt('Battery', (int)$val); break;
                    case '3-2': $this->SetVarInt('ChargingState', (int)$val); break;

                    case '4-2': $this->SetVarInt('CleaningTime', (int)$val); break;
                    case '4-3': $this->SetVarFloat('CleaningArea', (float)$val); break;
                    case '4-23': $this->SetVarInt('CleaningMode', (int)$val); break;

                    case '9-1':  $this->SetVarInt('MainBrushLeftTime', (int)$val); break;
                    case '9-2':  $this->SetVarInt('MainBrushLife', (int)$val); break;
                    case '10-1': $this->SetVarInt('SideBrushLeftTime', (int)$val); break;
                    case '10-2': $this->SetVarInt('SideBrushLife', (int)$val); break;
                    case '11-1': $this->SetVarInt('FilterLife', (int)$val); break;
                    case '11-2': $this->SetVarInt('FilterLeftTime', (int)$val); break;

                    case '4-48':
                        $this->SetVarString('ShortcutsRaw', (string)$val);
                        $decoded = $this->DecodeShortcutList((string)$val);
                        if ($decoded !== null) {
                            $normalized = $this->NormalizeShortcuts($decoded);
                            $this->SetVarString('ShortcutsJson', json_encode($normalized, JSON_UNESCAPED_UNICODE));
                            $this->SetVarString('ShortcutsText', $this->ShortcutsToText($normalized));
                            $this->UpdateShortcutProfile($normalized);
                            $this->EnsureShortcutVariables($normalized);
                        }
                        break;
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

    public function UpdateShortcuts()
    {
        $this->SetLastError('');
        try {
            $normalized = $this->GetShortcuts();
            if ($this->ReadPropertyBoolean('AutoCreateVariables')) {
                $this->EnsureVariables();
                $this->SetVarString('ShortcutsJson', json_encode($normalized, JSON_UNESCAPED_UNICODE));
                $this->SetVarString('ShortcutsText', $this->ShortcutsToText($normalized));
                $this->UpdateShortcutProfile($normalized);
                $this->EnsureShortcutVariables($normalized);
            }
            $this->SetLastError('Shortcuts ok');
        } catch (Exception $e) {
            $this->SetLastError($e->getMessage());
        }
    }

    // ---------------- Commands (Public) ----------------

    public function StartCleaning()
    {
        $res = $this->SendAction(self::ACTION_SIID_START_CLEAN, self::ACTION_AIID_START_CLEAN, array(
            array('piid' => 1, 'value' => self::CMD_START)
        ));
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    public function PauseCleaning()
    {
        $res = $this->SendAction(self::ACTION_SIID_START_CLEAN, self::ACTION_AIID_START_CLEAN, array(
            array('piid' => 1, 'value' => self::CMD_PAUSE)
        ));
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    public function StopCleaning()
    {
        $res = $this->SendAction(self::ACTION_SIID_START_CLEAN, self::ACTION_AIID_START_CLEAN, array(
            array('piid' => 1, 'value' => self::CMD_STOP)
        ));
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    public function Dock()
    {
        $res = $this->SendAction(self::ACTION_SIID_START_CLEAN, self::ACTION_AIID_START_CLEAN, array(
            array('piid' => 1, 'value' => self::CMD_DOCK)
        ));
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    public function SpotClean()
    {
        $res = $this->SendAction(self::ACTION_SIID_START_CLEAN, self::ACTION_AIID_START_CLEAN, array(
            array('piid' => 1, 'value' => self::CMD_SPOT)
        ));
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    public function Locate()
    {
        $res = $this->SendAction(self::ACTION_SIID_START_CLEAN, self::ACTION_AIID_START_CLEAN, array(
            array('piid' => 1, 'value' => self::CMD_LOCATE)
        ));
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    public function StartShortcut($shortcutId)
    {
        $in = array(
            array('piid' => 1, 'value' => self::CMD_SHORTCUT),
            array('piid' => 10, 'value' => strval($shortcutId))
        );
        $res = $this->SendAction(self::ACTION_SIID_START_CLEAN, self::ACTION_AIID_START_CLEAN, $in);
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    public function StartRooms($roomsJson, $repeats, $suction, $water)
    {
        $rooms = json_decode($roomsJson, true);
        if (!is_array($rooms) || count($rooms) === 0) throw new Exception('roomsJson muss ein JSON Array sein, z.B. [4,6]');

        $selects = array();
        $idx = 1;
        foreach ($rooms as $rid) {
            $selects[] = array((int)$rid, (int)$repeats, (int)$suction, (int)$water, $idx);
            $idx++;
        }

        $payload = array('selects' => $selects);

        $in = array(
            array('piid' => 1, 'value' => self::CMD_ROOMS),
            array('piid' => 10, 'value' => json_encode($payload))
        );
        $res = $this->SendAction(self::ACTION_SIID_START_CLEAN, self::ACTION_AIID_START_CLEAN, $in);
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    public function SendActionRaw($siid, $aiid, $inJson)
    {
        $in = json_decode($inJson, true);
        if (!is_array($in)) throw new Exception('inJson muss JSON Array sein');
        $res = $this->SendAction((int)$siid, (int)$aiid, $in);
        $this->SetLastResponse(json_encode($res));
        return json_encode($res);
    }

    // ---------------- Shortcuts helpers ----------------

    private function GetShortcuts()
    {
        $payload = array(array('did' => $this->GetDID(), 'siid' => 4, 'piid' => 48));
        $result = $this->SendCommand('get_properties', $payload);

        if (!is_array($result) || count($result) === 0) return array();
        $item = $result[0];
        if (!is_array($item) || !array_key_exists('value', $item)) return array();

        $raw = (string)$item['value'];
        $this->SetVarString('ShortcutsRaw', $raw);

        $decoded = $this->DecodeShortcutList($raw);
        if ($decoded === null) return array();

        return $this->NormalizeShortcuts($decoded);
    }

    private function DecodeShortcutList($raw)
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        $b = base64_decode($raw, true);
        if ($b === false) {
            $j = json_decode($raw, true);
            return is_array($j) ? $j : null;
        }

        $j = json_decode($b, true);
        return is_array($j) ? $j : null;
    }

    private function DecodeShortcutName($maybeBase64)
    {
        $s = trim((string)$maybeBase64);
        if ($s === '') return '';

        $b = base64_decode($s, true);
        if ($b === false) return $s;

        $ctrl = 0;
        for ($i = 0; $i < strlen($b); $i++) {
            $o = ord($b[$i]);
            if ($o < 9 || ($o > 13 && $o < 32)) $ctrl++;
        }
        if ($ctrl > 0) return $s;

        return $b;
    }

    private function NormalizeShortcuts($decoded)
    {
        $out = array();
        if (!is_array($decoded)) return $out;

        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) continue;

            $id = (int)$entry['id'];
            $name = '';
            if (isset($entry['name'])) $name = $this->DecodeShortcutName($entry['name']);
            if ($name === '') $name = 'Shortcut ' . $id;

            $out[] = array('id' => $id, 'name' => $name);
        }
        return $out;
    }

    private function ShortcutsToText($normalized)
    {
        if (!is_array($normalized)) return '';
        $lines = array();
        foreach ($normalized as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) continue;
            $lines[] = strval($entry['id']) . ': ' . (isset($entry['name']) ? strval($entry['name']) : '');
        }
        return implode("\n", $lines);
    }

    private function UpdateShortcutProfile($shortcuts)
    {
        $name = 'DRMV.Shortcuts';
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
        }

        IPS_SetVariableProfileAssociation($name, 0, '—', '', 0);

        if (!is_array($shortcuts)) return;
        foreach ($shortcuts as $sc) {
            if (!is_array($sc) || !isset($sc['id'])) continue;
            $id = (int)$sc['id'];
            $caption = isset($sc['name']) ? (string)$sc['name'] : ('Shortcut ' . $id);
            IPS_SetVariableProfileAssociation($name, $id, $caption, '', 0);
        }
    }

    /**
     * IMPORTANT FIX:
     * IP-Symcon expects IPS_SetVariableCustomAction() to point to a SCRIPT.
     * Therefore we keep the real shortcut-variables directly under the instance (MaintainVariable + EnableAction),
     * and only create LINKS in the "Shortcuts" category for nicer visualization.
     */
    private function EnsureShortcutVariables($shortcuts)
    {
        if (!is_array($shortcuts)) return;

        // A) Selection + start button (instance vars)
        $this->MaintainVariable('ShortcutSelected', 'Shortcut auswählen', VARIABLETYPE_INTEGER, 'DRMV.Shortcuts', 100, true);
        $this->EnableAction('ShortcutSelected');

        $this->MaintainVariable('StartSelectedShortcut', 'Shortcut starten', VARIABLETYPE_BOOLEAN, '~Switch', 101, true);
        $this->EnableAction('StartSelectedShortcut');

        // B) Per-shortcut "buttons" (instance vars) + links in category
        $catId = $this->EnsureCategory('Shortcuts', 'Shortcuts', 200);

        $pos = 1;
        foreach ($shortcuts as $sc) {
            if (!is_array($sc) || !isset($sc['id'])) continue;
            $id = (int)$sc['id'];
            $name = isset($sc['name']) ? (string)$sc['name'] : ('Shortcut ' . $id);
            $ident = 'SC_' . $id;

            // real variable under instance
            $this->MaintainVariable($ident, $name, VARIABLETYPE_BOOLEAN, '~Switch', 210 + $pos, true);
            $this->EnableAction($ident);

            // link under category for visualization
            $varId = @$this->GetIDForIdent($ident);
            if ($varId) $this->EnsureLink('L_' . $ident, $name, $varId, $catId, $pos);

            $pos++;
        }
    }

    private function EnsureCategory($ident, $name, $pos)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id && $id > 0) {
            IPS_SetName($id, $name);
            IPS_SetPosition($id, $pos);
            return $id;
        }

        $id = IPS_CreateCategory();
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetIdent($id, $ident);
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $pos);
        return $id;
    }

    private function EnsureLink($ident, $name, $targetId, $parentId, $pos)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if (!$id || $id <= 0) {
            $id = IPS_CreateLink();
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $pos);
        IPS_SetLinkTargetID($id, $targetId);
        return $id;
    }

    // ---------------- Profiles / Variables ----------------

    private function EnsureProfiles()
    {
        $this->EnsureIntProfile('DRMV.DeviceStatus', array(
            1 => 'Saugen',
            2 => 'Bereit / Idle',
            3 => 'Pausiert',
            4 => 'Fehler',
            5 => 'Fährt zur Station',
            6 => 'Lädt',
            12 => 'Saugen & Wischen',
            13 => 'Laden abgeschlossen'
        ));

        $this->EnsureIntProfile('DRMV.ChargingState', array(
            1 => 'Laden',
            2 => 'Entladen',
            5 => 'Fährt zur Station'
        ));

        $this->EnsureIntProfile('DRMV.Command', array(
            0 => '—',
            1 => 'Start',
            2 => 'Pause',
            3 => 'Stop',
            4 => 'Zur Station',
            5 => 'Spot Clean',
            6 => 'Suchen / Beep'
        ));

        if (!IPS_VariableProfileExists('DRMV.Shortcuts')) {
            IPS_CreateVariableProfile('DRMV.Shortcuts', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('DRMV.Shortcuts', 0, '—', '', 0);
        }

        $this->EnsureIntProfileSimple('DRMV.Minutes', ' min');
        $this->EnsureIntProfileSimple('DRMV.Hours', ' h');
        $this->EnsureIntProfileSimple('DRMV.Area', ' m²');
    }

    private function EnsureVariables()
    {
        // Info
        $this->MaintainVariable('Model', 'Model', VARIABLETYPE_STRING, '~TextBox', 10, true);
        $this->MaintainVariable('Firmware', 'Firmware', VARIABLETYPE_STRING, '~TextBox', 11, true);
        $this->MaintainVariable('Name', 'Name', VARIABLETYPE_STRING, '~TextBox', 12, true);
        $this->MaintainVariable('Mac', 'Mac', VARIABLETYPE_STRING, '~TextBox', 13, true);
        $this->MaintainVariable('Online', 'Online', VARIABLETYPE_BOOLEAN, '~Switch', 14, true);

        // Status
        $this->MaintainVariable('DeviceStatus', 'Status', VARIABLETYPE_INTEGER, 'DRMV.DeviceStatus', 20, true);
        $this->MaintainVariable('ChargingState', 'Ladestatus', VARIABLETYPE_INTEGER, 'DRMV.ChargingState', 22, true);
        $this->MaintainVariable('Battery', 'Akku', VARIABLETYPE_INTEGER, '~Battery.100', 23, true);

        $this->MaintainVariable('DeviceFault', 'Fehlercode', VARIABLETYPE_INTEGER, '', 24, true);
        $this->MaintainVariable('DeviceFaultText', 'Fehlertext', VARIABLETYPE_STRING, '~TextBox', 25, true);

        $this->MaintainVariable('CleaningTime', 'Reinigungszeit', VARIABLETYPE_INTEGER, 'DRMV.Minutes', 30, true);
        $this->MaintainVariable('CleaningArea', 'Reinigungsfläche', VARIABLETYPE_FLOAT, 'DRMV.Area', 31, true);
        $this->MaintainVariable('CleaningMode', 'Cleaning Mode', VARIABLETYPE_INTEGER, '', 32, true);

        // Consumables
        $this->MaintainVariable('MainBrushLife', 'Hauptbürste Rest (%)', VARIABLETYPE_INTEGER, '~Intensity.100', 40, true);
        $this->MaintainVariable('MainBrushLeftTime', 'Hauptbürste Rest (h)', VARIABLETYPE_INTEGER, 'DRMV.Hours', 41, true);
        $this->MaintainVariable('SideBrushLife', 'Seitenbürste Rest (%)', VARIABLETYPE_INTEGER, '~Intensity.100', 42, true);
        $this->MaintainVariable('SideBrushLeftTime', 'Seitenbürste Rest (h)', VARIABLETYPE_INTEGER, 'DRMV.Hours', 43, true);
        $this->MaintainVariable('FilterLife', 'Filter Rest (%)', VARIABLETYPE_INTEGER, '~Intensity.100', 44, true);
        $this->MaintainVariable('FilterLeftTime', 'Filter Rest (h)', VARIABLETYPE_INTEGER, 'DRMV.Hours', 45, true);

        // Shortcuts RAW/JSON/TEXT
        $this->MaintainVariable('ShortcutsRaw', 'Shortcuts Raw (4-48)', VARIABLETYPE_STRING, '~TextBox', 70, true);
        $this->MaintainVariable('ShortcutsJson', 'Shortcuts (JSON)', VARIABLETYPE_STRING, '~TextBox', 71, true);
        $this->MaintainVariable('ShortcutsText', 'Shortcuts (Text)', VARIABLETYPE_STRING, '~TextBox', 72, true);

        // Command selector for visualization
        $this->MaintainVariable('Command', 'Command', VARIABLETYPE_INTEGER, 'DRMV.Command', 80, true);
        $this->EnableAction('Command');

        // Update timestamp
        $this->MaintainVariable('LastUpdate', 'Letztes Update', VARIABLETYPE_INTEGER, '~UnixTimestamp', 90, true);
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

    // ---------------- Cloud core ----------------

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

    private function MaybeUseAuthKeyAsAccessToken()
    {
        $authKey = trim($this->ReadPropertyString('AuthKey'));
        if ($authKey === '') return false;

        $this->SetBuffer('AccessToken', $authKey);

        $exp = $this->ParseJwtExp($authKey);
        if ($exp > 0) {
            $this->SetBuffer('AccessTokenExpire', strval($exp - 120));
        } else {
            $this->SetBuffer('AccessTokenExpire', strval(time() + 43200));
        }
        return true;
    }

    private function ParseJwtExp($jwt)
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return 0;

        $payload = strtr($parts[1], '-_', '+/');
        $pad = strlen($payload) % 4;
        if ($pad > 0) $payload .= str_repeat('=', 4 - $pad);

        $decoded = base64_decode($payload, true);
        if ($decoded === false) return 0;

        $json = json_decode($decoded, true);
        if (!is_array($json) || !isset($json['exp'])) return 0;

        return (int)$json['exp'];
    }

    private function EnsureLoggedIn($force)
    {
        if (!$force) {
            $token = $this->GetBuffer('AccessToken');
            $exp   = (int)$this->GetBuffer('AccessTokenExpire');
            if ($token !== '' && $exp > time()) return;
        }

        if ($this->MaybeUseAuthKeyAsAccessToken()) {
            $token = $this->GetBuffer('AccessToken');
            $exp   = (int)$this->GetBuffer('AccessTokenExpire');
            if ($token !== '' && $exp > time()) return;
        }

        $refresh = trim($this->ReadPropertyString('RefreshToken'));
        if ($refresh === '') $refresh = $this->GetBuffer('RefreshToken');
        if ($refresh !== '') {
            if ($this->LoginRefresh($refresh)) return;
        }

        $user = trim($this->ReadPropertyString('Username'));
        $pass = $this->ReadPropertyString('Password');
        if ($user === '' || $pass === '') {
            throw new Exception('Kein gültiger auth_key/AccessToken und kein RefreshToken und kein Username/Password hinterlegt');
        }
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

        if ($retry && is_array($res) && isset($res['_http_status']) && (int)$res['_http_status'] === 401) {
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
        $cluster = '';
        if (count($parts) > 0) $cluster = '-' . $parts[0];

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
                'id' => $id,
                'method' => $method,
                'params' => $params
            )
        );

        $path = 'dreame-iot-com' . $cluster . '/device/sendCommand';
        $res = $this->ApiCall($path, $payload);

        if (is_array($res) && isset($res['data']) && is_array($res['data']) && isset($res['data']['result'])) {
            return $res['data']['result'];
        }
        return $res;
    }

    private function SendAction($siid, $aiid, $in)
    {
        $payload = array(
            'did' => $this->GetDID(),
            'siid' => (int)$siid,
            'aiid' => (int)$aiid,
            'in' => $in
        );
        return $this->SendCommand('action', $payload);
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

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $resp === null) return array('_http_status' => $code, '_error' => $err);

        $decoded = json_decode($resp, true);
        if ($decoded === null) return array('_http_status' => $code, '_raw' => $resp);
        $decoded['_http_status'] = $code;
        return $decoded;
    }

    // ---------------- Variable setters ----------------

    private function SetConnected($state)  { $this->SetVarBoolean('Connected', (bool)$state); }
    private function SetLastError($msg)    { $this->SetVarString('LastError', (string)$msg); }
    private function SetLastResponse($msg) { $this->SetVarString('LastResponse', (string)$msg); }

    private function SetVarBoolean($ident, $val)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === 0 || $id === false) return;
        SetValueBoolean($id, (bool)$val);
    }

    private function SetVarInt($ident, $val)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === 0 || $id === false) return;
        SetValueInteger($id, (int)$val);
    }

    private function SetVarFloat($ident, $val)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === 0 || $id === false) return;
        SetValueFloat($id, (float)$val);
    }

    private function SetVarString($ident, $val)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === 0 || $id === false) return;
        SetValueString($id, (string)$val);
    }
}
