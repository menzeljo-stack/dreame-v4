<?php

class DreameVacuum extends IPSModule
{
    const API_DOMAIN_DREAME   = '.iot.dreame.tech';
    const API_DOMAIN_MOVA     = '.iot.mova-tech.com';
    const API_DOMAIN_TROUVER  = '.iot.trouver-tech.com';
    const API_PORT            = 13267;

    const PASSWORD_SALT       = 'RAylYC%fmSKp7%Tq';
    const AUTH_PATH           = '/dreame-auth/oauth/token';

    // Diese Basic-Auth wird von der HACS Integration gesetzt (Client-ID/Secret)
    // Falls Login später 401/403 liefert, müssen wir diesen Wert aus deiner HA-Integration übernehmen.
    const AUTHORIZATION_VALUE = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    const TENANT_DEFAULT      = '000000';

    const HDR_USER_AGENT      = 'User-Agent';
    const HDR_AUTHORIZATION   = 'Authorization';
    const HDR_TENANT          = 'Tenant-Id';
    const HDR_DREAME_AUTH     = 'Dreame-Auth';
    const HDR_DREAME_RLC      = 'Dreame-Rlc';

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
        $this->RegisterPropertyString('RefreshToken', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('PollInterval', 60);

        $this->RegisterVariableBoolean('Connected', 'Connected', '~Switch', 1);
        $this->RegisterVariableString('LastError', 'LastError', '~TextBox', 2);
        $this->RegisterVariableString('LastResponse', 'LastResponse', '~TextBox', 3);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function TestLogin()
    {
        try {
            $this->EnsureLoggedIn(true);
            $this->SetConnected(true);
            $this->SetLastError('');
            $this->SetLastResponse('Login OK');
        } catch (Exception $e) {
            $this->SetConnected(false);
            $this->SetLastError($e->getMessage());
            throw $e;
        }
    }

    // ---------- Login ----------
    private function EnsureLoggedIn($force)
    {
        if (!$force) {
            $token = $this->GetBuffer('AccessToken');
            $exp   = (int)$this->GetBuffer('AccessTokenExpire');
            if ($token !== '' && $exp > time()) return;
        }

        // Refresh token first
        $refresh = trim($this->ReadPropertyString('RefreshToken'));
        if ($refresh === '') $refresh = $this->GetBuffer('RefreshToken');

        if ($refresh !== '') {
            if ($this->LoginRefresh($refresh)) return;
        }

        // Fallback username/password
        $user = trim($this->ReadPropertyString('Username'));
        $pass = $this->ReadPropertyString('Password');
        if ($user === '' || $pass === '') {
            throw new Exception('Bitte RefreshToken (HA: auth_key) ODER Username/Password setzen.');
        }
        if (!$this->LoginPassword($user, $pass)) {
            throw new Exception('Login fehlgeschlagen (Password-Flow).');
        }
    }

    private function LoginRefresh($refreshToken)
    {
        $data = http_build_query(array(
            'platform' => 'IOS',
            'scope' => 'all',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ));

        $res = $this->HttpPostForm($this->GetApiBase() . self::AUTH_PATH, $data);
        return $this->HandleLoginResponse($res);
    }

    private function LoginPassword($username, $password)
    {
        $hashed = md5($password . self::PASSWORD_SALT);

        $data = http_build_query(array(
            'platform' => 'IOS',
            'scope' => 'all',
            'grant_type' => 'password',
            'username' => $username,
            'password' => $hashed,
            'type' => 'account'
        ));

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
            return true;
        }

        $this->SendDebug('LoginResponse', json_encode($res), 0);
        return false;
    }

    // ---------- HTTP ----------
    private function HttpPostForm($url, $dataString)
    {
        $tenant = $this->GetBuffer('TenantId');
        if ($tenant === '') $tenant = self::TENANT_DEFAULT;

        $headers = array(
            'Accept: */*',
            'Content-Type: application/x-www-form-urlencoded',
            self::HDR_USER_AGENT . ': ' . $this->GetUserAgent(),
            self::HDR_AUTHORIZATION . ': ' . self::AUTHORIZATION_VALUE,
            self::HDR_TENANT . ': ' . $tenant
        );

        if (strtolower(trim($this->ReadPropertyString('Region'))) === 'cn') {
            $headers[] = self::HDR_DREAME_RLC . ': ' . self::DREAME_RLC_VALUE;
        }

        return $this->CurlPost($url, $dataString, $headers, 20);
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

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

    // ---------- Helpers ----------
    private function GetApiBase()
    {
        $region = strtolower(trim($this->ReadPropertyString('Region')));
        if ($region === '') $region = 'eu';

        return 'https://' . $region . $this->GetDomainSuffix() . ':' . self::API_PORT;
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
