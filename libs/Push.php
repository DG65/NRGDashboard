<?php

declare(strict_types=1);

/**
 * Aktualisierungen an die Kachel schicken (0.12.0, Auftrag EMS 17.11.2026: Symcon-Connect-Limit bei Paul).
 *
 * Bis 0.11.9 schickten Energiefluss und Prognose bei JEDER Aenderung einer beobachteten Variablen das komplette Datenpaket,
 * das PV-Monitoring alle 5 Minuten. Jeder offene Client bekommt jedes Paket; ueber Symcon Connect zaehlt das gegen das Limit.
 *
 * Hier:
 *  - Drosselung: hoechstens ein Paket je Mindestabstand ($minSec, Eigenschaft "PushMinSec", 0 = aus wie bisher). Das
 *    erste Ereignis geht sofort, weitere innerhalb des Abstands loesen EIN nachlaufendes Paket aus (Timer), damit der
 *    letzte Stand sicher ankommt.
 *  - Aenderungsvergleich: gesendet wird nur, wenn sich das Paket (ohne fluechtige Felder wie den Zeitstempel) gegenueber
 *    dem zuletzt gesendeten geaendert hat. Sonst nur alle 30 s ein winziger Herzschlag ('tick'), damit die Statusanzeige
 *    der Kachel ("aktuell") frisch bleibt.
 *  - Zaehlung: Pakete und Bytes je Stunde und Art (Buffer), abrufbar ueber GetTrafficStats().
 *
 * Statische Variablen gelten in Symcon nur je Ausfuehrungsstrang (siehe NRGDASH_Text): der Zustand liegt deshalb in Buffern der
 * Instanz, nicht in statischen Eigenschaften.
 */
trait NRGDashPush
{
    /** Pakete an die Kachel; zaehlt Aufrufe und Bytes je Stunde und Art. */
    private function pushVisu(string $json, string $kind): void
    {
        $this->UpdateVisualizationValue($json);
        $this->pushStat($kind, strlen($json));
    }

    private function pushStat(string $kind, int $bytes = 0): void
    {
        $h = date('YmdH');
        $st = json_decode((string) $this->GetBuffer('TrafficStats'), true);
        if (!is_array($st)) {
            $st = [];
        }
        $cur = $st[$h][$kind] ?? ['n' => 0, 'b' => 0];
        $st[$h][$kind] = ['n' => (int) $cur['n'] + 1, 'b' => (int) $cur['b'] + $bytes];
        ksort($st);
        $st = array_slice($st, -24, null, true);
        $this->SetBuffer('TrafficStats', (string) json_encode($st));
    }

    /** Gesendete Pakete und Bytes je Stunde und Art (seit dem letzten Start der Instanz, letzte 24 Stunden). */
    public function GetTrafficStats(): string
    {
        $st = json_decode((string) $this->GetBuffer('TrafficStats'), true);
        return (string) json_encode(is_array($st) ? $st : [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param callable $build      liefert das Paket (Array oder fertigen JSON-String)
     * @param string   $timerName  Name des Nachlauf-Timers (in Create() mit RegisterTimer angelegt)
     * @param int      $minSec     Mindestabstand in Sekunden; 0 = jedes Ereignis sofort und ohne Vergleich (Verhalten bis 0.11.9)
     * @param bool     $force      true = sofort senden, ohne Drosselung und ohne Vergleich (z. B. nach einer Bedienung)
     * @param string[] $volatile   Schluessel der obersten Ebene, die im Aenderungsvergleich nicht zaehlen (z. B. 'renderedAt')
     */
    private function pushThrottled(callable $build, string $timerName, int $minSec, bool $force = false, array $volatile = []): void
    {
        $now = microtime(true);
        $last = (float) $this->GetBuffer('PushLast');
        if (!$force && $minSec > 0 && ($now - $last) < $minSec) {
            // Innerhalb des Mindestabstands: nur EIN nachlaufendes Paket einplanen, nichts aufbauen.
            if ($this->GetBuffer('PushPending') !== '1') {
                $this->SetBuffer('PushPending', '1');
                $this->SetTimerInterval($timerName, (int) max(500, ceil(($last + $minSec - $now) * 1000)));
            }
            return;
        }
        if ($this->GetBuffer('PushPending') === '1') {
            $this->SetBuffer('PushPending', '0');
            $this->SetTimerInterval($timerName, 0);
        }
        $payload = $build();
        if (is_string($payload)) {
            $json = $payload;
            $cmp = $payload;
        } else {
            $json = (string) json_encode($payload);
            $cmpArr = $payload;
            foreach ($volatile as $k) {
                unset($cmpArr[$k]);
            }
            $cmp = (string) json_encode($cmpArr);
        }
        $hash = md5($cmp);
        $this->SetBuffer('PushLast', (string) $now);
        if (!$force && $minSec > 0 && $hash === $this->GetBuffer('PushHash')) {
            $this->pushStat('unchanged');
            if (($now - (float) $this->GetBuffer('PushSent')) >= 30) {
                $this->SetBuffer('PushSent', (string) $now);
                $this->pushVisu((string) json_encode(['ok' => true, 'type' => 'tick', 'renderedAt' => time()]), 'tick');
            }
            return;
        }
        $this->SetBuffer('PushHash', $hash);
        $this->SetBuffer('PushSent', (string) $now);
        $this->pushVisu($json, 'full');
    }
}
