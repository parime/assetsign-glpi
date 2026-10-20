#!/usr/bin/env bash
# Matrice de controle d'acces de bout en bout (vraies requetes HTTP, vraie session GLPI).
#
# Usage : access-matrix.sh <url GLPI> <limited|admin> <login> <mot de passe> <json de setup.php>
#   limited : technicien limite a l'entite A -> tout ce qui vise l'entite B doit etre refuse (403)
#   admin   : super-admin -> tout doit rester accessible (detecte un controle trop strict)
#
# Couvre la famille de defauts relevee par la revue de securite de la marketplace GLPI : un
# enregistrement designe par son id, ou une entite lue dans la requete, autorises sur le seul
# droit global du plugin sans controle de l'entite.
set -u
BASE=$1 ROLE=$2 LOGIN=$3 PASSWORD=$4 IDS=$5
JAR=$(mktemp)
FAIL=0
id() { jq -r ".$1" <<< "$IDS"; }
code() { curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$@"; }
csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE/front/central.php" | grep -o 'glpi:csrf_token" content="[^"]*"' | sed 's/.*content="//;s/"//'; }
expect() { # libelle, code attendu, code obtenu
  if [ "$2" = "$3" ]; then echo "OK  $1 ($3)"; else echo "::error::$ROLE - $1 : attendu $2, obtenu $3"; FAIL=1; fi
}

for try in 1 2 3 4 5; do
  rm -f "$JAR"
  token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "login_name=$LOGIN" --data-urlencode "login_password=$PASSWORD" \
    --data-urlencode "_glpi_csrf_token=$token" -d noAUTO=1 "$BASE/front/login.php"
  [ "$(code "$BASE/front/central.php")" = 200 ] && break
  [ "$try" = 5 ] && { echo "::error::connexion impossible pour $LOGIN"; exit 1; }
  sleep 3
done

P=$BASE/plugins/assetsign/front
if [ "$ROLE" = limited ]; then DENY=403; DENY_POST=403; DENY_PDF=403; else DENY=200; DENY_POST=302; DENY_PDF=404; fi

expect "depart, entite A"              200          "$(code "$P/departure.form.php?id=$(id departure_A)")"
expect "depart, entite B"              $DENY        "$(code "$P/departure.form.php?id=$(id departure_B)")"
expect "depart, PDF entite B"          $DENY_PDF    "$(code "$P/departure.form.php?id=$(id departure_B)&pdf=1")"
expect "campagne, entite A"            200          "$(code "$P/campaign.form.php?id=$(id campaign_A)")"
expect "campagne, entite B"            $DENY        "$(code "$P/campaign.form.php?id=$(id campaign_B)")"
expect "campagne, export CSV entite B" $DENY        "$(code "$P/campaign.form.php?id=$(id campaign_B)&export=csv")"
expect "attestation, entite A"         200          "$(code "$P/attestation.form.php?id=$(id attestation_A)")"
expect "attestation, entite B"         $DENY        "$(code "$P/attestation.form.php?id=$(id attestation_B)")"
expect "relance campagne entite B"     $DENY_POST   "$(code -d "remind=1&id=$(id campaign_B)&_glpi_csrf_token=$(csrf)" "$P/campaign.form.php")"
expect "lancement campagne en B"       $DENY_POST   "$(code -d "launch=1&name=securite&entities_id=$(id b)&date_deadline=2099-01-01&_glpi_csrf_token=$(csrf)" "$P/campaign.form.php")"
expect "depart d'une personne de B"    $DENY_POST   "$(code -d "prepare=1&users_id=$(id partant_B)&_glpi_csrf_token=$(csrf)" "$P/departure.form.php")"
expect "depart d'une personne de A"    302          "$(code -d "prepare=1&users_id=$(id partant_A)&_glpi_csrf_token=$(csrf)" "$P/departure.form.php")"

rm -f "$JAR"
exit $FAIL
