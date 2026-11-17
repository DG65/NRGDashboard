<?php

declare(strict_types=1);

/**
 * Unterschiede zwischen zwei Datenpaketen der PV-Monitoring-Kachel (0.12.0, Auftrag EMS 17.11.2026: Symcon-Connect-Limit).
 *
 * Reine Funktionen ohne Symcon-Bezug (testbar mit tests/delta.test.php). Beide Pakete muessen gleich normalisiert sein
 * (json_decode(json_encode(...), true)), damit der strenge Vergleich zuverlaessig ist.
 *
 * Form des Ergebnisses (ein Teil fehlt, wenn er sich nicht geaendert hat):
 *   ['top'    => [Feld => neuer Wert, ...],                      // oberste Ebene ausser days/energy
 *    'days'   => [Tages-id => [Feld => neuer Wert | Schwanz]],   // Punktlisten als Schwanz, mppt je Strang
 *    'energy' => [Karte => [Datum => kWh]]]                      // nur geaenderte/neue Eintraege, mppt je Strang
 * Ein Schwanz ist ['__tail' => [Stelle, neue Punkte]]: der Client behaelt die ersten Stelle Punkte und haengt die neuen an.
 * Jeder Teil bedeutet "ersetze durch den aktuellen Wert", nie "addiere": ein Client, der zwischendurch neu geladen hat, bleibt
 * dadurch konsistent.
 */
final class NRGDASH_Delta
{
    /**
     * @param string[] $seriesFields Felder eines Tages, die Punktlisten sind (als Schwanz uebertragen)
     * @return array|null|false null = nichts anders; false = nicht als Delta darstellbar (Tageswechsel, entfernte Felder) -> voller Satz
     */
    public static function diff(array $old, array $new, array $seriesFields)
    {
        $out = [];
        $top = [];
        foreach ($new as $k => $v) {
            if ($k === 'days' || $k === 'energy') {
                continue;
            }
            if (!array_key_exists($k, $old) || $old[$k] !== $v) {
                $top[$k] = $v;
            }
        }
        foreach ($old as $k => $v) {
            if ($k !== 'days' && $k !== 'energy' && !array_key_exists($k, $new)) {
                return false;
            }
        }
        if ($top !== []) {
            $out['top'] = $top;
        }

        $oldDays = [];
        foreach ((array) ($old['days'] ?? []) as $d) {
            $oldDays[(string) ($d['id'] ?? '')] = $d;
        }
        $newIds = array_map(static function ($d) {
            return (string) ($d['id'] ?? '');
        }, (array) ($new['days'] ?? []));
        if ($newIds !== array_keys($oldDays)) {
            return false;
        }
        $days = [];
        foreach ((array) ($new['days'] ?? []) as $d) {
            $id = (string) ($d['id'] ?? '');
            $o = $oldDays[$id];
            foreach ($o as $f => $v) {
                if (!array_key_exists($f, $d)) {
                    return false;
                }
            }
            $chg = [];
            foreach ($d as $f => $v) {
                if (in_array($f, $seriesFields, true) && is_array($v) && isset($o[$f]) && is_array($o[$f])) {
                    $t = self::tail($o[$f], $v);
                    if ($t !== null) {
                        $chg[$f] = $t;
                    }
                } elseif ($f === 'mppt' && is_array($v) && is_array($o['mppt'] ?? null)) {
                    foreach ($o['mppt'] as $n => $x) {
                        if (!array_key_exists($n, $v)) {
                            return false;
                        }
                    }
                    foreach ($v as $n => $series) {
                        $t = self::tail((array) ($o['mppt'][$n] ?? []), (array) $series);
                        if ($t !== null) {
                            $chg['mppt'][$n] = $t;
                        }
                    }
                } elseif (!array_key_exists($f, $o) || $o[$f] !== $v) {
                    $chg[$f] = $v;
                }
            }
            if ($chg !== []) {
                $days[$id] = $chg;
            }
        }
        if ($days !== []) {
            $out['days'] = $days;
        }

        $energy = [];
        $oe = (array) ($old['energy'] ?? []);
        $ne = (array) ($new['energy'] ?? []);
        foreach ($oe as $k => $x) {
            if (!array_key_exists($k, $ne)) {
                return false;
            }
        }
        foreach ($ne as $k => $map) {
            if ($k === 'mppt') {
                foreach ((array) ($oe['mppt'] ?? []) as $n => $m) {
                    if (!array_key_exists($n, (array) $map)) {
                        return false;
                    }
                }
                foreach ((array) $map as $n => $m) {
                    $dm = self::mapDiff((array) ($oe['mppt'][$n] ?? []), (array) $m);
                    if ($dm === false) {
                        return false;
                    }
                    if ($dm !== []) {
                        $energy['mppt'][$n] = $dm;
                    }
                }
                continue;
            }
            $dm = self::mapDiff((array) ($oe[$k] ?? []), (array) $map);
            if ($dm === false) {
                return false;
            }
            if ($dm !== []) {
                $energy[$k] = $dm;
            }
        }
        if ($energy !== []) {
            $out['energy'] = $energy;
        }
        return $out === [] ? null : $out;
    }

    /** Geaenderte/neue Eintraege einer Datum->kWh-Karte; false, wenn Eintraege entfallen sind. */
    public static function mapDiff(array $old, array $new)
    {
        foreach ($old as $k => $v) {
            if (!array_key_exists($k, $new)) {
                return false;
            }
        }
        $out = [];
        foreach ($new as $k => $v) {
            if (!array_key_exists($k, $old) || $old[$k] !== $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Punktliste: null = gleich, sonst ['__tail' => [Stelle, neue Punkte]] (der gemeinsame Anfang bleibt beim Client). */
    public static function tail(array $old, array $new): ?array
    {
        $n = min(count($old), count($new));
        $c = 0;
        while ($c < $n && $old[$c] === $new[$c]) {
            $c++;
        }
        if ($c === count($old) && $c === count($new)) {
            return null;
        }
        return ['__tail' => [$c, array_slice($new, $c)]];
    }
}
