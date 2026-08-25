<?php

declare(strict_types=1);

class PoolHeatPump extends IPSModule
{
    private const REGIONS = [
        'eu' => 'https://api-eu.fairlandiot.com',
        'us' => 'https://api-us.fairlandiot.com',
        'cn' => 'https://api-cn.fairlandiot.com',
        'hk' => 'https://api-hk.fairlandiot.com'
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Region', 'auto');
        $this->RegisterPropertyString('CountryCode', 'DE');
        $this->RegisterPropertyString('PhoneCode', '49');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyBoolean('CreateRawVariables', true);

        $this->RegisterAttributeString('DetectedRegion', '');
        $this->RegisterAttributeString('DetectedDeviceID', '');
        $this->RegisterAttributeString('DeviceName', '');
        $this->RegisterAttributeString('ScaleMap', '{}');
        $this->RegisterAttributeInteger('LastEnergyTimestamp', 0);
        $this->RegisterAttributeFloat('EnergyKWh', 0.0);

        $this->RegisterTimer('UpdateTimer', 0, 'IGDN_Update($_IPS[\'TARGET\']);');

        $this->registerProfiles();
        $this->RegisterVariableBoolean('Online', 'Cloud erreichbar', '~Switch', 1);
        $this->RegisterVariableBoolean('Power', 'Wärmepumpe', '~Switch', 2);
        $this->EnableAction('Power');
        $this->RegisterVariableInteger('OperatingMode', 'Betriebsart', 'IGDN.OperatingMode', 3);
        $this->EnableAction('OperatingMode');
        $this->RegisterVariableInteger('PerformanceMode', 'Leistungsmodus', '', 4);
        $this->EnableAction('PerformanceMode');
        $this->RegisterVariableFloat('CurrentTemperature', 'Wassertemperatur', '~Temperature', 5);
        $this->RegisterVariableFloat('TargetTemperature', 'Solltemperatur', '~Temperature', 6);
        $this->EnableAction('TargetTemperature');
        $this->RegisterVariableBoolean('Running', 'Verdichter aktiv', '~Switch', 7);
        $this->RegisterVariableFloat('CurrentPower', 'Leistungsaufnahme', 'IGDN.Power', 8);
        $this->RegisterVariableFloat('Energy', 'Energieverbrauch', 'IGDN.Energy', 9);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 90);
        $this->RegisterVariableString('Device', 'Gerät', '', 91);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 92);
        $this->RegisterVariableString('RawData', 'Rohdaten', '', 99);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $interval = max(15, $this->ReadPropertyInteger('Interval'));
        $configured = trim($this->ReadPropertyString('Username')) !== '' && $this->ReadPropertyString('Password') !== '';
        $this->SetTimerInterval('UpdateTimer', $configured ? $interval * 1000 : 0);

        if (!$configured) {
            $this->SetStatus(104);
            $this->setValue('Online', false);
            return;
        }

        $this->Update();
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Power':
                $this->writeDataPoint('101', (bool) $Value);
                break;
            case 'OperatingMode':
                if (!in_array((int) $Value, [0, 1, 2], true)) {
                    throw new InvalidArgumentException('Ungültige Betriebsart');
                }
                $this->writeDataPoint('106', (int) $Value);
                break;
            case 'PerformanceMode':
                $this->writeDataPoint('102', (int) $Value);
                break;
            case 'TargetTemperature':
                $temperature = (float) $Value;
                if ($temperature < 8 || $temperature > 40) {
                    throw new InvalidArgumentException('Die Solltemperatur muss zwischen 8 und 40 °C liegen');
                }
                $this->writeDataPoint('107', $this->scaleForWrite('107', $temperature));
                break;
            default:
                throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
        }

        $this->setValue($Ident, $Value);
        IPS_Sleep(750);
        $this->Update();
    }

    public function Discover(): string
    {
        try {
            $this->login(true);
            $devices = $this->loadDevices();
            $heatPumps = array_values(array_filter($devices, static function (array $device): bool {
                return ($device['categoryCode'] ?? '') === 'heatPump';
            }));

            if ($heatPumps === []) {
                throw new RuntimeException('Im iGarden-Konto wurde keine Wärmepumpe gefunden');
            }

            $device = $heatPumps[0];
            $id = (string) ($device['id'] ?? $device['deviceId'] ?? '');
            $name = (string) ($device['deviceName'] ?? 'iGarden Wärmepumpe');
            if ($id === '') {
                throw new RuntimeException('Die Cloud-Antwort enthält keine Geräte-ID');
            }

            $this->WriteAttributeString('DetectedDeviceID', $id);
            $this->WriteAttributeString('DeviceName', $name);
            $this->setValue('Device', $name . ' (' . $id . ')');
            $this->setValue('LastError', '');
            $this->SetStatus(102);
            $this->Update();
            return sprintf('%s [%s]', $name, $id);
        } catch (Throwable $e) {
            $this->handleError($e);
            return 'Fehler: ' . $e->getMessage();
        }
    }

    public function Update(): bool
    {
        if (trim($this->ReadPropertyString('Username')) === '' || $this->ReadPropertyString('Password') === '') {
            return false;
        }

        try {
            $this->ensureLogin();
            $deviceId = $this->getDeviceId();
            if ($deviceId === '') {
                $this->Discover();
                $deviceId = $this->getDeviceId();
            }
            if ($deviceId === '') {
                throw new RuntimeException('Keine Wärmepumpe ausgewählt');
            }

            $data = $this->apiRequest('/fyld-device-api/deviceDataPointApi/deviceDataPointInfo', ['deviceId' => $deviceId]);
            $dps = $this->findDataPoints($data);
            if ($dps === []) {
                throw new RuntimeException('Die Cloud lieferte keine Datenpunkte');
            }

            $this->processDataPoints($dps);
            $this->setValue('RawData', json_encode($dps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->setValue('Online', true);
            $this->setValue('LastUpdate', time());
            $this->setValue('LastError', '');
            $this->SetStatus(102);
            return true;
        } catch (Throwable $e) {
            $this->handleError($e);
            return false;
        }
    }

    private function ensureLogin(): void
    {
        if ($this->GetBuffer('Token') === '' || $this->GetBuffer('BaseURL') === '') {
            $this->login(false);
        }
    }

    private function login(bool $force): void
    {
        if (!$force && $this->GetBuffer('Token') !== '' && $this->GetBuffer('BaseURL') !== '') {
            return;
        }

        $configuredRegion = $this->ReadPropertyString('Region');
        $regions = $configuredRegion === 'auto' ? array_keys(self::REGIONS) : [$configuredRegion];
        $lastError = 'Anmeldung fehlgeschlagen';

        foreach ($regions as $region) {
            if (!isset(self::REGIONS[$region])) {
                continue;
            }
            try {
                $response = $this->httpPost(
                    self::REGIONS[$region] . '/fyld-user-api/user/loginByPassword',
                    [
                        'phoneCode' => $this->ReadPropertyString('PhoneCode'),
                        'accountName' => $this->ReadPropertyString('Username'),
                        'password' => $this->ReadPropertyString('Password'),
                        'countryCode' => strtoupper($this->ReadPropertyString('CountryCode')),
                        'randStr' => '',
                        'ticket' => ''
                    ],
                    false
                );
                if ((int) ($response['code'] ?? 0) !== 200000) {
                    $lastError = sprintf('%s: %s', (string) ($response['code'] ?? ''), (string) ($response['msg'] ?? 'Login fehlgeschlagen'));
                    continue;
                }
                $token = (string) ($response['data']['authorization'] ?? '');
                if ($token === '') {
                    throw new RuntimeException('Anmeldung erfolgreich, aber ohne Autorisierungstoken');
                }
                $this->SetBuffer('Token', $token);
                $this->SetBuffer('BaseURL', self::REGIONS[$region]);
                $this->WriteAttributeString('DetectedRegion', $region);
                return;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException('iGarden-Anmeldung fehlgeschlagen: ' . $lastError);
    }

    private function apiRequest(string $path, array $payload, bool $retry = true)
    {
        $baseUrl = $this->GetBuffer('BaseURL');
        if ($baseUrl === '') {
            $this->login(false);
            $baseUrl = $this->GetBuffer('BaseURL');
        }
        $response = $this->httpPost($baseUrl . $path, $payload, true);
        $code = (int) ($response['code'] ?? 0);
        if (($code === 401 || $code === 403 || $code === 400003) && $retry) {
            $this->SetBuffer('Token', '');
            $this->login(true);
            return $this->apiRequest($path, $payload, false);
        }
        if ($code !== 200000) {
            throw new RuntimeException(sprintf('Cloud-Fehler %s: %s', $code, (string) ($response['msg'] ?? 'Unbekannt')));
        }
        return $response['data'] ?? [];
    }

    private function httpPost(string $url, array $payload, bool $authenticated): array
    {
        $headers = [
            'Content-Type: application/json',
            'terminal: 2',
            'User-Agent: Dart/3.5 (dart:io)',
            'Accept: application/json;charset=UTF-8'
        ];
        if ($authenticated) {
            $headers[] = 'Authorization: ' . $this->GetBuffer('Token');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $error !== '') {
            throw new RuntimeException('Netzwerkfehler: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP-Fehler ' . $status);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ungültige JSON-Antwort der iGarden-Cloud');
        }
        return $decoded;
    }

    private function loadDevices(): array
    {
        $groupsData = $this->apiRequest('/fyld-device-api/deviceGroupApi/allGroupInfo', ['needDeviceCount' => true]);
        $groups = $this->findGroups($groupsData);
        $devices = [];
        foreach ($groups as $group) {
            $groupId = (string) ($group['id'] ?? $group['deviceGroupId'] ?? '');
            if ($groupId === '') {
                continue;
            }
            $data = $this->apiRequest('/fyld-device-api/deviceApi/deviceAllGroupInfo', [
                'deviceGroupId' => $groupId,
                'shareId' => null
            ]);
            foreach (['bindDeviceInfos', 'shareDeviceInfos'] as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    foreach ($data[$key] as $device) {
                        if (is_array($device)) {
                            $devices[] = $device;
                        }
                    }
                }
            }
        }
        if ($devices === []) {
            $devices = $this->findDevicesRecursive($groupsData);
        }
        return $devices;
    }

    private function findDevicesRecursive($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $devices = [];
        if (($data['categoryCode'] ?? '') === 'heatPump' && (isset($data['id']) || isset($data['deviceId']))) {
            $devices[] = $data;
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $devices = array_merge($devices, $this->findDevicesRecursive($value));
            }
        }
        return $devices;
    }

    private function findGroups($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, static fn($v): bool => is_array($v)));
        }
        foreach (['deviceGroupInfos', 'groupInfos', 'list', 'records'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $this->findGroups($data[$key]);
            }
        }
        if (isset($data['id']) || isset($data['deviceGroupId'])) {
            return [$data];
        }
        return [];
    }

    private function findDataPoints($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['dps']) && is_array($data['dps'])) {
            return $data['dps'];
        }
        if (isset($data['dataPointInfos']) && is_array($data['dataPointInfos'])) {
            return $data['dataPointInfos'];
        }
        if (array_is_list($data) && ($data === [] || isset($data[0]['dpId']))) {
            return $data;
        }
        foreach ($data as $value) {
            $found = $this->findDataPoints($value);
            if ($found !== []) {
                return $found;
            }
        }
        return [];
    }

    private function processDataPoints(array $dps): void
    {
        $scales = [];
        $powerKW = null;
        foreach ($dps as $dp) {
            if (!is_array($dp) || !isset($dp['dpId'])) {
                continue;
            }
            $id = (string) $dp['dpId'];
            $property = $this->parseProperty($dp['dpProperty'] ?? null);
            $scale = max(0, (int) ($property['scale'] ?? 0));
            $scales[$id] = $scale;
            $raw = $dp['dpValue'] ?? null;
            $value = $this->scaleForRead($raw, $scale);

            switch ($id) {
                case '101': $this->setValue('Power', $this->toBool($raw)); break;
                case '102': $this->setValue('PerformanceMode', (int) $raw); $this->updatePerformanceProfile($property); break;
                case '103': $this->setValue('CurrentTemperature', (float) $value); break;
                case '106': $this->setValue('OperatingMode', (int) $raw); break;
                case '107': $this->setValue('TargetTemperature', (float) $value); break;
                case '113': $this->setValue('Running', (int) $raw === 1); break;
            }

            $name = (string) ($dp['dpName'] ?? $dp['name'] ?? ('Datenpunkt ' . $id));
            $unit = (string) ($property['unit'] ?? $dp['unit'] ?? '');
            if ($this->isPowerPoint($name, $unit, $value)) {
                $powerKW = $this->normalizePowerToKW((float) $value, $unit);
            }
            if ($this->ReadPropertyBoolean('CreateRawVariables') && !in_array($id, ['101', '102', '103', '106', '107', '113'], true)) {
                $this->updateDynamicVariable($id, $name, $value, $unit);
            }
        }
        $this->WriteAttributeString('ScaleMap', json_encode($scales));
        if ($powerKW !== null) {
            $this->setValue('CurrentPower', $powerKW);
            $this->integrateEnergy($powerKW);
        }
    }

    private function updateDynamicVariable(string $dpId, string $name, $value, string $unit): void
    {
        $ident = 'DP_' . preg_replace('/[^A-Za-z0-9_]/', '_', $dpId);
        if (is_bool($value)) {
            $this->RegisterVariableBoolean($ident, $name, '~Switch', 50 + (int) $dpId);
        } elseif (is_int($value)) {
            $profile = $unit === '%' ? '~Intensity.100' : '';
            $this->RegisterVariableInteger($ident, $name . ($unit !== '' && $unit !== '%' ? ' (' . $unit . ')' : ''), $profile, 50 + (int) $dpId);
        } elseif (is_float($value) || is_numeric($value)) {
            $profile = in_array($unit, ['°C', '℃', 'C'], true) ? '~Temperature' : '';
            $this->RegisterVariableFloat($ident, $name . ($unit !== '' && $profile === '' ? ' (' . $unit . ')' : ''), $profile, 50 + (int) $dpId);
        } else {
            $this->RegisterVariableString($ident, $name, '', 50 + (int) $dpId);
            $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }
        $this->setValue($ident, $value);
    }

    private function writeDataPoint(string $dpId, $value): void
    {
        $this->ensureLogin();
        $deviceId = $this->getDeviceId();
        if ($deviceId === '') {
            throw new RuntimeException('Keine Geräte-ID vorhanden; bitte zuerst Gerät suchen');
        }
        $this->apiRequest('/fyld-device-api/devicePropertySetApi/set', [
            'deviceId' => $deviceId,
            'dpIdValues' => [['type' => '', 'dpId' => $dpId, 'value' => $value]]
        ]);
    }

    private function getDeviceId(): string
    {
        $configured = trim($this->ReadPropertyString('DeviceID'));
        return $configured !== '' ? $configured : $this->ReadAttributeString('DetectedDeviceID');
    }

    private function parseProperty($property): array
    {
        if (is_array($property)) {
            return $property;
        }
        if (is_string($property) && $property !== '') {
            $decoded = json_decode($property, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function scaleForRead($value, int $scale)
    {
        if ($scale > 0 && is_numeric($value)) {
            return (float) $value / (10 ** $scale);
        }
        return $value;
    }

    private function scaleForWrite(string $dpId, float $value)
    {
        $scales = json_decode($this->ReadAttributeString('ScaleMap'), true);
        $scale = is_array($scales) ? (int) ($scales[$dpId] ?? 0) : 0;
        return $scale > 0 ? (int) round($value * (10 ** $scale)) : $value;
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on'], true);
    }

    private function isPowerPoint(string $name, string $unit, $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        $haystack = strtolower($name . ' ' . $unit);
        return (str_contains($haystack, 'power') || str_contains($haystack, 'leistung'))
            && !str_contains($haystack, 'mode') && !str_contains($haystack, 'modus')
            && (str_contains($haystack, 'kw') || preg_match('/(^|[^a-z])w([^a-z]|$)/', $haystack));
    }

    private function normalizePowerToKW(float $value, string $unit): float
    {
        return strtolower(trim($unit)) === 'w' ? $value / 1000 : $value;
    }

    private function integrateEnergy(float $powerKW): void
    {
        $now = time();
        $last = $this->ReadAttributeInteger('LastEnergyTimestamp');
        $energy = $this->ReadAttributeFloat('EnergyKWh');
        if ($last > 0 && $now > $last && ($now - $last) <= max(300, $this->ReadPropertyInteger('Interval') * 3)) {
            $energy += $powerKW * (($now - $last) / 3600);
            $this->WriteAttributeFloat('EnergyKWh', $energy);
        }
        $this->WriteAttributeInteger('LastEnergyTimestamp', $now);
        $this->setValue('Energy', $energy);
    }

    private function updatePerformanceProfile(array $property): void
    {
        $mapping = $property;
        if (isset($property['range']) && is_array($property['range'])) {
            $mapping = $property['range'];
        }
        foreach ($mapping as $value => $caption) {
            if (is_numeric($value) && is_scalar($caption)) {
                IPS_SetVariableProfileAssociation('IGDN.PerformanceMode', (int) $value, (string) $caption, '', -1);
            }
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('PerformanceMode'), 'IGDN.PerformanceMode');
    }

    private function registerProfiles(): void
    {
        if (!IPS_VariableProfileExists('IGDN.OperatingMode')) {
            IPS_CreateVariableProfile('IGDN.OperatingMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('IGDN.OperatingMode', 0, 'Automatik', '', 0x3498DB);
            IPS_SetVariableProfileAssociation('IGDN.OperatingMode', 1, 'Heizen', '', 0xE67E22);
            IPS_SetVariableProfileAssociation('IGDN.OperatingMode', 2, 'Kühlen', '', 0x2980B9);
        }
        if (!IPS_VariableProfileExists('IGDN.PerformanceMode')) {
            IPS_CreateVariableProfile('IGDN.PerformanceMode', VARIABLETYPE_INTEGER);
        }
        if (!IPS_VariableProfileExists('IGDN.Power')) {
            IPS_CreateVariableProfile('IGDN.Power', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('IGDN.Power', 3);
            IPS_SetVariableProfileText('IGDN.Power', '', ' kW');
        }
        if (!IPS_VariableProfileExists('IGDN.Energy')) {
            IPS_CreateVariableProfile('IGDN.Energy', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('IGDN.Energy', 3);
            IPS_SetVariableProfileText('IGDN.Energy', '', ' kWh');
        }
    }

    private function setValue(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0 && GetValue($id) !== $value) {
            SetValue($id, $value);
        }
    }

    private function handleError(Throwable $e): void
    {
        $message = preg_replace('/password[^,]*/i', 'password=[geschützt]', $e->getMessage());
        $this->SendDebug('Fehler', $message, 0);
        $this->setValue('Online', false);
        $this->setValue('LastError', $message);
        $this->SetStatus(200);
    }
}
