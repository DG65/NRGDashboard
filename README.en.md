# NRG-Stack Dashboard

![Symcon](https://img.shields.io/badge/Symcon-PHPModule-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)

Part of the **NRG-Stack** (DG65). Deutsche Fassung: [README.md](README.md) (ausführlich, mit Entwicklungsstand und technischen Verträgen).

## What it is

NRGDashboard is the presentation layer of the NRG-Stack. It shows what the other NRG-Stack modules (InverterHub, MeterHub, ChargerHub, OCPPHub, HeishaMon, WPHub, forecast, EMS, Tibber, spot price, …) provide in devices and values:

- an **animated energy-flow tile** (PV, battery, grid, house consumption, wallboxes, other consumers) with click-through details per device,
- a **3D map** of the same devices,
- **history charts** for PV, battery, grid, wallbox and more,
- a **health overview** of all installed NRG-Stack modules.

There is no data contract of its own. Every device is discovered automatically through the `*_GetFunctions` contracts of the partner modules. When an instance disappears or a new one appears, the dashboard follows by itself - no variable has to be re-linked anywhere.

## Modules in this library

| Module | Purpose |
|---|---|
| NRGDashboardTile | Main tile: the complete energy flow as one animated view, with detail pages per device |
| NRGDashboardMap | The same devices as a spatial 3D map, clustered by category |
| NRGDashboardTopology | Health of all installed NRG-Stack modules at a glance, arranged radially around the EMS |
| NRGDashboardPVMonitor | Detailed history for PV, battery, grid, wallbox and more, with spot-price overlay and year comparison |
| NRGDashboardWPMonitor | History and key figures of a heat pump |
| NRGDashboardHeatSchema | Principle diagram of a heat pump |
| NRGDashboardForecast | PV and load forecast compared with the values actually measured |

## Requirements

- Symcon 9.0 or newer
- At least one NRG-Stack partner module that provides a `*_GetFunctions` contract (InverterHub, MeterHub, ChargerHub, OCPPHub, HeishaMon, WPHub, WPEEBUSHub, ChargerEEBUSHub, …). Without any source the tile shows a hint, not an error.

## Installation

1. Install **NRG-Stack Dashboard** from the Symcon Module Store (the beta channel is found by searching the exact name).
2. Create an instance of the module you want, e.g. *NRGDashboardTile*. Discovery finds the partner modules by itself.
3. Place the tile in the WebFront (tile editor) like any other visualisation tile.

Coming from the old `InverterHubTile`: a tile already placed in the WebFront cannot be re-pointed by any Symcon API. Remove the old tile from the dashboard screen and place the new *NRGDashboardTile* at the same spot.

## Language

All seven modules are bilingual (German/English) since version 0.11.0. The console, messages, status lines and the tiles follow the Symcon language. The German text is unchanged. Names of variables and profiles are created in the language active at creation time; existing names are not renamed.

## Standalone web page

The forecast, PV monitor, heat pump monitor, heating schematic and topology modules can additionally be opened as a stand-alone web page (browser, IPSView) through a WebHook. The option *Enable standalone web page for browser and IPSView* is on by default. If you only use the tile in the WebFront, switch it off: the WebHook is then no longer registered and there is no access from outside without login. The energy-flow tile and the 3D map have no such option; they need their hook for the detail page, the controls and the tour.

## Season effects

Around Halloween and New Year the energy-flow tile can show playful overlay effects (including a small game). They are a pure layer above the tile and can be set to *automatic by date*, *off* or *always on* in the tile menu. Sounds are off by default. With "reduce motion" enabled in the operating system the effects stand still.

## Partner modules

| Partner | Contract |
|---|---|
| InverterHub | `IHUB_GetFunctions` |
| MeterHub | `MHUB_GetFunctions` |
| ChargerHub | `CHUB_GetFunctions` |
| OCPPHub | `OHUB_GetFunctions` |
| HeishaMon, WPHub, WPBsbLan | heat pump contracts |
| WPEEBUSHub, ChargerEEBUSHub | heat pumps and wallboxes via EEBUS |
| Tibber Grid Rewards | `TIBBERGR_GetPriceCurve` |
| Forecast | `PVF_GetForecast`, `LFC_GetForecast` |

The full list with GUIDs and field details is in the German [README.md](README.md).

## Changes

See [CHANGELOG.md](CHANGELOG.md) (German).

## License

PolyForm Noncommercial 1.0.0, see [LICENSE](LICENSE).
