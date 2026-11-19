#!/bin/sh
# Ein Umbau-Schritt fuer ein Modul: verschieben, Syntax, Zeilenpruefung in beide Richtungen, Referenzvergleich gegen HEAD.
#   sh .tools/rahmen-schritt.sh <Modul> [Arbeitsordner]
M=$1; W=${2:-/tmp/rahmen-schritt}
HERE=$(cd "$(dirname "$0")/.." && pwd)
cd "$HERE" || exit 2
php .tools/rahmen-trait.php verschiebe "$M" || exit 1
php -l "NRGDashboard$M/module.php" >/dev/null || { echo "Syntaxfehler"; exit 1; }
python3 - "$M" <<'PY' || exit 1
import subprocess,sys,re
from collections import Counter
m=sys.argv[1]
d=subprocess.run(['git','diff','-U0',f'NRGDashboard{m}/module.php'],capture_output=True,text=True).stdout.split('\n')
rem=[l[1:] for l in d if l.startswith('-') and not l.startswith('---')]
add=[l[1:] for l in d if l.startswith('+') and not l.startswith('+++')]
src=open(f'NRGDashboard{m}/module.php').read()
hook=re.search(r"HOOK_PATH = '([^']+)'",src).group(1); pre=re.search(r"FN_PREFIX = '([^']+)'",src).group(1)
tr=open('libs/ModuleBase.php').read()
tr=tr.replace('self::HOOK_PATH',"'"+hook+"'").replace('self::FN_PREFIX . $applyMethod',"'"+pre+"' . $applyMethod")
body=tr[tr.index('trait NRGDashModuleBase'):]
bl=Counter(l for l in body.split('\n') if l.strip())
rl=Counter(l for l in rem if l.strip())
fehlt=rl-bl
komm={k:v for k,v in fehlt.items() if k.strip().startswith(('/*','*','//'))}
for k in komm: print('  nur Kommentar weicht ab:',k.strip()[:110])
fehlt=Counter({k:v for k,v in fehlt.items() if k not in komm})
print('Zeilenpruefung: entfernt',sum(rl.values()),'| davon nicht im Trait:',sum(fehlt.values()),'| hinzugefuegt (nicht leer):',len([a for a in add if a.strip()]))
for k in list(fehlt)[:5]: print('  nicht im Trait:',k.strip()[:100])
sys.exit(1 if fehlt else 0)
PY
sh .tools/rahmen-vergleich.sh HEAD "$W" || exit 1
