# Dreame Vacuum (Cloud) – IP-Symcon Modul

**Kompatibilitätsbuild (0.3):** absichtlich ohne moderne PHP-Syntax (für ältere IP-Symcon/PHP-Versionen).

## Setup
- Region: `eu`
- AccountType: `dreame`
- DID: aus HA `.storage/core.config_entries`
- RefreshToken: aus HA (`auth_key`) – empfohlen
- Host (optional): `10000.mt.eu.iot.dreame.tech:19973`

## Buttons
- Test Login
- Update device info
- Raw: device/info

## MIoT Calls
- `DREAME_GetProperties($id, '[[2,1],[3,1]]')`
- `DREAME_SetProperty($id, 2, 1, 123)`
- `DREAME_Action($id, 2, 1, '[]')`

siid/piid/aiid findest du in HA unter:
`/config/custom_components/dreame_vacuum/dreame/types.py`
