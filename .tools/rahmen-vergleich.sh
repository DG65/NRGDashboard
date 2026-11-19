#!/bin/sh
# Vergleich "vorher" (git-Stand, Standard HEAD) gegen "nachher" (Arbeitsbaum) fuer den Umbau Modulrahmen.
#   sh .tools/rahmen-vergleich.sh [Git-Stand] [Arbeitsordner fuer die Ausgaben]
# Der Forecast rechnet Tageswerte bis "jetzt"; deshalb laufen beide Staende kurz nacheinander und bei Abweichung bis zu dreimal.
REF=${1:-HEAD}
W=${2:-/tmp/rahmen-vergleich}
HERE=$(cd "$(dirname "$0")/.." && pwd)
rm -rf "$W"; mkdir -p "$W"
git -C "$HERE" worktree prune
git -C "$HERE" worktree add -f "$W/alt" "$REF" >/dev/null 2>&1 || { echo "Worktree fehlgeschlagen"; exit 2; }
i=1
while [ $i -le 3 ]; do
  rm -rf "$W/vorher" "$W/nachher"
  php "$HERE/.tools/rahmen-referenz.php" "$W/alt" "$W/vorher" >/dev/null 2>&1
  php "$HERE/.tools/rahmen-referenz.php" "$HERE" "$W/nachher" >/dev/null 2>&1
  if diff -rq "$W/vorher" "$W/nachher" >/dev/null; then echo "REFERENZ GLEICH (Versuch $i): Szenarien Deutsch/Englisch und Methodenlisten aller 7 Module"; git -C "$HERE" worktree remove --force "$W/alt" >/dev/null 2>&1; exit 0; fi
  i=$((i+1))
done
echo "REFERENZ ABWEICHEND:"; diff -rq "$W/vorher" "$W/nachher"; exit 1
