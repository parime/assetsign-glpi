#!/usr/bin/env bash
# Test XSS de bout en bout : apres `setup.php xss`, chaque champ texte libre des objets de l'entite
# A contient <svg/onload=alert('xss-canary')>. Aucune page ni onglet ne doit restituer cette
# charge telle quelle (non echappee).
#
# Usage : xss-check.sh <url GLPI> <json de setup.php>
set -u
BASE=$1 IDS=$2
CANARY="<svg/onload=alert('xss-canary')>"
JAR=$(mktemp)
FAIL=0
RENDERED=0
id() { jq -r ".$1" <<< "$IDS"; }
login() {
  for try in 1 2 3 4 5; do
    rm -f "$JAR"
    token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
    curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "login_name=$1" --data-urlencode "login_password=$2" \
      --data-urlencode "_glpi_csrf_token=$token" -d noAUTO=1 "$BASE/front/login.php"
    # preference.php : accessible aux deux interfaces (un profil Self-Service est redirige depuis central.php).
    [ "$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE/front/preference.php")" = 200 ] && return 0
    sleep 3
  done
  echo "::error::connexion impossible pour $1"; exit 1
}
check() { # libelle, url
  local body status
  body=$(curl -s -w '\n%{http_code}' -c "$JAR" -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' "$2")
  status=${body##*$'\n'}
  body=${body%$'\n'*}
  if [ "$status" != 200 ]; then
    echo "::error::$1 : code HTTP $status (page non verifiee)"; FAIL=1; return
  fi
  if grep -qF "$CANARY" <<< "$body"; then
    echo "::error::$1 : charge XSS restituee sans echappement"; FAIL=1; return
  fi
  grep -q "xss-canary" <<< "$body" && RENDERED=$((RENDERED + 1))
  echo "OK  $1"
}
tab() { # itemtype, onglet (classe), id
  echo "$BASE/ajax/common.tabs.php?_target=%2Ffront%2Fx.php&_itemtype=$1&_glpi_tab=$(sed 's/\\/%5C/g' <<< "$2")%241&id=$3"
}

P=$BASE/plugins/assetsign/front
login glpi glpi
for url in \
  "assetsign.form.php?id=$(id assetsign_A)" "campaign.form.php?id=$(id campaign_A)" \
  "attestation.form.php?id=$(id attestation_A)" "departure.form.php?id=$(id departure_A)" \
  "maintenance.form.php?id=$(id maintenance_A)" "movement.form.php?id=$(id movement_A)" \
  "template.form.php?id=$(id templates_A)" "kit.form.php?id=$(id kits_A)" "accessory.form.php?id=$(id accessories_A)" \
  "checklistitem.form.php?id=$(id checklistitems_A)" "maintenancechecklistitem.form.php?id=$(id maintenancechecklistitems_A)" \
  "config.php?entities_id=$(id a)" "qrlabel.php?itemtype=Computer&items_id=$(id computer_A)" \
  assetsign.php maintenance.php movement.php departure.php campaign.php template.php kit.php accessory.php \
  checklistitem.php maintenancechecklistitem.php csr.php; do
  check "front/$url" "$P/$url"
done
for t in 'GlpiPlugin\Assetsign\Assetsign' 'GlpiPlugin\Assetsign\PassportEvent' 'GlpiPlugin\Assetsign\Maintenance' 'GlpiPlugin\Assetsign\Movement'; do
  check "onglet $t (ordinateur)" "$(tab Computer "$t" "$(id computer_A)")"
done
for t in 'GlpiPlugin\Assetsign\Assetsign' 'GlpiPlugin\Assetsign\PassportEvent'; do
  check "onglet $t (utilisateur)" "$(tab User "$t" "$(id partant_A)")"
done

# Cote beneficiaire : page de signature et « Mon materiel ».
login securite-partant-a 'Secrev#2026'
check "front/sign.php (beneficiaire)" "$P/sign.php?t=$(id token_A)"
check "front/mysignatures.php (beneficiaire)" "$P/mysignatures.php"

rm -f "$JAR"
if [ "$RENDERED" -lt 5 ]; then
  echo "::error::la charge n'apparait (echappee) que sur $RENDERED page(s) : les donnees piegees ne sont pas affichees, le test ne prouve rien"
  FAIL=1
else
  echo "OK  charge affichee echappee sur $RENDERED pages"
fi
exit $FAIL
