#!/usr/bin/env bash
# Matrice de controle d'acces de bout en bout (vraies requetes HTTP, vraie session GLPI).
#
# Usage : access-matrix.sh <url GLPI> <limited|admin> <login> <mot de passe> <json de setup.php>
#   limited : technicien limite a l'entite A -> rien de l'entite B n'est lisible ni modifiable
#   admin   : super-admin -> tout reste accessible (detecte un controle trop strict)
#
# Chaque page de front/ est soit testee ici (le chemin « front/<page>.php » apparait dans ce
# fichier), soit listee avec sa justification dans tests/security/not-covered.txt : la CI le
# verifie (.github/scripts/check-security-matrix-coverage.sh), une nouvelle page ne peut donc pas
# echapper a ce test sans decision explicite.
#
# Les ecritures refusees sont aussi verifiees en base : la CI compare `setup.php snapshot` (etat des
# objets de l'entite B) avant/apres ce script.
set -u
BASE=$1 ROLE=$2 LOGIN=$3 PASSWORD=$4 IDS=$5
JAR=$(mktemp)
FAIL=0
id() { jq -r ".$1" <<< "$IDS"; }
code() { curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$@"; }
page() { curl -s -c "$JAR" -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' "$@"; }
csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE/front/central.php" | grep -o 'glpi:csrf_token" content="[^"]*"' | sed 's/.*content="//;s/"//'; }
expect() { # libelle, attendu, obtenu
  if [ "$2" = "$3" ]; then echo "OK  $1 ($3)"; else echo "::error::$ROLE - $1 : attendu $2, obtenu $3"; FAIL=1; fi
}
shows() { # libelle, texte, attendu (oui|non), contenu
  local got=non; grep -qF "$2" <<< "$4" && got=oui
  expect "$1" "$3" "$got"
}
post() { # page, donnees (le jeton CSRF est ajoute)
  code -d "$2&_glpi_csrf_token=$(csrf)" "$P/$1"
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
if [ "$ROLE" = limited ]; then
  SEE_B=non; NF=404; DENY=403; DENY_POST=403; NF_POST=404; DENY_W=403
else
  SEE_B=oui; NF=200; DENY=200; DENY_POST=302; NF_POST=302; DENY_W=302
fi

# --- Enregistrements designes par leur id (lecture) ---
expect "front/assetsign.form.php : feuille de A"              200   "$(code "$P/assetsign.form.php?id=$(id assetsign_A)")"
expect "front/assetsign.form.php : feuille de B"              $NF   "$(code "$P/assetsign.form.php?id=$(id assetsign_B)")"
expect "front/maintenance.form.php : maintenance de B"        $NF   "$(code "$P/maintenance.form.php?id=$(id maintenance_B)")"
expect "front/movement.form.php : mouvement de B"             $NF   "$(code "$P/movement.form.php?id=$(id movement_B)")"
expect "front/departure.form.php : depart de A"               200   "$(code "$P/departure.form.php?id=$(id departure_A)")"
expect "front/departure.form.php : depart de B"               $DENY "$(code "$P/departure.form.php?id=$(id departure_B)")"
expect "front/campaign.form.php : campagne de A"              200   "$(code "$P/campaign.form.php?id=$(id campaign_A)")"
expect "front/campaign.form.php : campagne de B"              $DENY "$(code "$P/campaign.form.php?id=$(id campaign_B)")"
expect "front/campaign.form.php : export CSV de B"            $DENY "$(code "$P/campaign.form.php?id=$(id campaign_B)&export=csv")"
expect "front/attestation.form.php : attestation de A"        200   "$(code "$P/attestation.form.php?id=$(id attestation_A)")"
expect "front/attestation.form.php : attestation de B"        $DENY "$(code "$P/attestation.form.php?id=$(id attestation_B)")"
expect "front/template.form.php : gabarit de B"               $DENY "$(code "$P/template.form.php?id=$(id templates_B)")"
expect "front/kit.form.php : kit de B"                        $DENY "$(code "$P/kit.form.php?id=$(id kits_B)")"
expect "front/accessory.form.php : accessoire de B"           $DENY "$(code "$P/accessory.form.php?id=$(id accessories_B)")"
expect "front/checklistitem.form.php : point de B"            $DENY "$(code "$P/checklistitem.form.php?id=$(id checklistitems_B)")"
expect "front/maintenancechecklistitem.form.php : point de B" $DENY "$(code "$P/maintenancechecklistitem.form.php?id=$(id maintenancechecklistitems_B)")"
expect "front/qrlabel.php : etiquette d'un PC de B"           $NF   "$(code "$P/qrlabel.php?itemtype=Computer&items_id=$(id computer_B)")"
expect "front/config.php : configuration de B"                $DENY "$(code "$P/config.php?entities_id=$(id b)")"
# Ni technicien ni super-admin ne sont beneficiaires : le PDF d'une feuille d'autrui n'est jamais servi.
expect "front/mysignatures.php : PDF de la feuille d'autrui"  404   "$(code "$P/mysignatures.php?pdf=$(id assetsign_B)")"
# Lien de signature d'un autre compte : page de refus, aucun contenu de la feuille.
shows "front/sign.php : lien de signature d'autrui (contenu)" "PC securite A" non "$(page "$P/sign.php?t=$(id token_A)")"
expect "front/sign.php : PDF via le lien d'autrui"            404   "$(code "$P/sign.php?t=$(id token_A)&action=pdf")"
expect "front/opcache_reset.php : sans jeton de deploiement"  403   "$(code "$P/opcache_reset.php")"

# --- Listes et tableaux : objets de A seulement (et la page ne plante pas) ---
for list in front/assetsign.php front/maintenance.php front/movement.php front/departure.php front/campaign.php front/template.php front/kit.php front/accessory.php front/checklistitem.php front/maintenancechecklistitem.php front/csr.php; do
  body=$(page "$BASE/plugins/assetsign/$list?all=1")
  expect "$list : la liste s'affiche" 200 "$(code "$BASE/plugins/assetsign/$list?all=1")"
  # Ces listes nomment les objets : le super-admin y voit B, le technicien jamais. Les autres ne
  # nomment pas les objets : elles doivent seulement s'afficher sans erreur et sans B.
  case "$list" in
    front/maintenance.php|front/campaign.php|front/template.php|front/kit.php|front/accessory.php|front/checklistitem.php|front/maintenancechecklistitem.php)
      shows "$list : objets de B" "securite B" "$SEE_B" "$body" ;;
    *)
      shows "$list : aucun objet de B" "securite B" non "$body" ;;
  esac
done
# Onglet « Passeport utilisateur » (requete restreinte par entite) : sur la fiche du technicien
# lui-meme, visible des deux profils. Un profil hors racine faisait planter la requete SQL.
user_tab_url="$BASE/ajax/common.tabs.php?_target=%2Ffront%2Fuser.form.php&_itemtype=User&_glpi_tab=GlpiPlugin%5CAssetsign%5CPassportEvent%241&id=$(id technician)"
expect "onglet passeport d'un utilisateur : code HTTP" 200 "$(code -H 'X-Requested-With: XMLHttpRequest' "$user_tab_url")"
expect "onglet passeport d'un utilisateur : contenu affiche" oui "$([ -n "$(page "$user_tab_url")" ] && echo oui || echo non)"

# --- Ecritures sur des objets de B (etat en base verifie par la CI) ---
expect "front/assetsign.form.php : annuler la feuille de B"  $NF_POST "$(post assetsign.form.php "cancel_request=1&id=$(id assetsign_B)")"
expect "front/assign_user_asset.php : attribuer un PC de B"  $NF_POST "$(post assign_user_asset.php "assign=1&itemtype=Computer&items_id=$(id computer_B)&users_id=$(id partant_A)")"
expect "front/maintenance.form.php : maintenance d'un PC de B" $NF_POST "$(post maintenance.form.php "create=1&itemtype=Computer&items_id=$(id computer_B)")"
expect "front/movement.form.php : mouvement d'un PC de B"    $NF_POST "$(post movement.form.php "create=1&itemtype=Computer&items_id=$(id computer_B)&locations_id_to=0")"
if [ "$ROLE" = limited ]; then
  # Appel AJAX : le refus est rendu en JSON ("success":false), pas par un code HTTP.
  marker=$(curl -s -c "$JAR" -b "$JAR" \
    -d "assetsigns_id=$(id assetsign_B)&action=add&view_index=0&x_percent=10&y_percent=10&_glpi_csrf_token=$(csrf)" \
    "$P/damagemarker.php")
  shows "front/damagemarker.php : marqueur sur la feuille de B refuse" '"success":false' oui "$marker"
fi
expect "front/ticket_handover.php : ticket de B"             $NF_POST "$(post ticket_handover.php "handover=1&tickets_id=$(id ticket_B)&available_item=Computer:$(id computer_B)&users_id=$(id partant_B)")"
expect "front/preview.php : apercu avec la configuration de B" $DENY "$(post preview.php "entities_id=$(id b)&type=0")"
expect "front/config.php : modifier la configuration de B"   $DENY_W "$(post config.php "update=1&entities_id=$(id b)&link_validity_days=1")"
expect "front/campaign.form.php : lancer une campagne en B"  $DENY_POST "$(post campaign.form.php "launch=1&name=securite&entities_id=$(id b)&date_deadline=2099-01-01")"
expect "front/departure.form.php : depart d'une personne de B" $DENY_POST "$(post departure.form.php "prepare=1&users_id=$(id partant_B)")"
# Issue #221 : preparer l'arrivee d'une personne de B (ticket cree dans son entite).
expect "front/arrival.form.php : arrivee d'une personne de B" $DENY_POST "$(post arrival.form.php "prepare=1&users_id=$(id partant_B)")"
if [ "$ROLE" = limited ]; then
  # Ces trois controleurs repondent toujours par une redirection : le refus se voit en base.
  post residualvalue.form.php "itemtype=Computer&items_id=$(id computer_B)&manual_value=1" > /dev/null
  post environmentaldata.form.php "itemtype=Computer&items_id=$(id computer_B)&carbon_footprint_manufacturing=1" > /dev/null
  post passport_backfill.php "itemtype=Computer&items_id=$(id computer_B)" > /dev/null
  echo "    front/residualvalue.form.php, front/environmentaldata.form.php, front/passport_backfill.php : verifies en base par la CI"
fi

rm -f "$JAR"
exit $FAIL
