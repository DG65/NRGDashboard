Danke für den Hinweis! Du hattest recht - bei frei eingetragenen "Weiteren Verbrauchern" (dazu zählt auch eine manuell angelegte Wallbox) habe ich bisher überall Watt angenommen, weil die Hub-Module (MeterHub, ChargerHub, OCPPHub, ...) das auch immer so liefern. Eine frei gewählte Variable kann aber natürlich auch in Kilowatt stehen.

Ich habe der Verbraucherliste ("Weitere Verbraucher (optional)") jetzt eine Spalte "Einheit" spendiert (Watt/Kilowatt, Vorgabe bleibt Watt). Bei deiner Wallbox einfach auf "Kilowatt" umstellen, dann rechnet das Dashboard automatisch mit Faktor 1000 - auch im Gestern-Vergleichsring und beim Tagesspitzenwert.

Ist ab Version 0.9.145-beta.1 (Build 272) im beta-Branch drin. Modulverwaltung aktualisieren, dann sollte es bei dir passen - meld dich, falls nicht.
