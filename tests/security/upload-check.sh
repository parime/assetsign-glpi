#!/usr/bin/env bash
# Test des televersements de bout en bout (vraies requetes multipart, vraie session GLPI) : logo de
# la configuration et pieces jointes d'un mouvement.
#   - un script (.php, .phtml) ou un faux PNG contenant du PHP est refuse, aucun fichier
#     executable n'est stocke ;
#   - une image polyglotte (GIF valide suivi de code PHP) acceptee comme logo est servie telle
#     quelle par document.send.php, jamais executee ;
#   - un technicien limite a l'entite A ne peut rien joindre a un mouvement de l'entite B ;
#   - les televersements legitimes passent (sinon le test ne prouverait rien).
#
# Usage : upload-check.sh <url GLPI> <json de setup.php> <commande affichant `setup.php uploads`>
set -u
BASE=$1 IDS=$2 STATE_CMD=$3
P=$BASE/plugins/assetsign/front
JAR=$(mktemp)
DIR=$(mktemp -d)
FAIL=0
id() { jq -r ".$1" <<< "$IDS"; }
state() { eval "$STATE_CMD" | tail -1; }
field() { jq -r ".$2" <<< "$1"; }
expect() { # libelle, attendu, obtenu
  if [ "$2" = "$3" ]; then echo "OK  $1 ($3)"; else echo "::error::$1 : attendu $2, obtenu $3"; FAIL=1; fi
}
csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE/front/central.php" | grep -o 'glpi:csrf_token" content="[^"]*"' | sed 's/.*content="//;s/"//'; }
login() { # identifiant, mot de passe
  local try token
  for try in 1 2 3 4 5; do
    rm -f "$JAR"
    token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
    curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "login_name=$1" --data-urlencode "login_password=$2" \
      --data-urlencode "_glpi_csrf_token=$token" -d noAUTO=1 "$BASE/front/login.php"
    [ "$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE/front/central.php")" = 200 ] && return
    sleep 3
  done
  echo "::error::connexion impossible pour $1"; exit 1
}
upload_logo() { # fichier local, nom annonce
  curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -F update=1 -F entities_id=0 \
    -F "_glpi_csrf_token=$(csrf)" -F "logo_file=@$1;filename=$2" "$P/config.php"
}
attach() { # id du mouvement, fichier local, nom annonce
  curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -F attach_document=1 -F "id=$1" \
    -F "_glpi_csrf_token=$(csrf)" -F "document_file=@$2;filename=$3" "$P/movement.form.php?id=$1"
}

printf '<?php echo "UPLOAD-EXECUTE"; ?>\n' > "$DIR/script"
printf 'piece jointe legitime\n' > "$DIR/note"
# GIF 1x1 valide (getimagesize() l'accepte) suivi de code PHP : la charge polyglotte classique.
{ base64 -d <<< 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'; cat "$DIR/script"; } > "$DIR/polyglot"

login glpi glpi
s0=$(state); echo "etat initial : $s0"

# --- Logo de la configuration ---
for name in shell.php shell.phtml logo.png; do
  upload_logo "$DIR/script" "$name" > /dev/null
  s=$(state)
  expect "logo « $name » contenant du PHP : refuse" "$(field "$s0" logo)" "$(field "$s" logo)"
  expect "logo « $name » : aucun document cree" "$(field "$s0" documents)" "$(field "$s" documents)"
done

upload_logo "$DIR/polyglot" logo.gif > /dev/null
s1=$(state)
logo=$(field "$s1" logo)
if [ "$logo" = "$(field "$s0" logo)" ] || [ "$logo" = 0 ]; then
  echo "::error::un vrai GIF n'est pas accepte comme logo : le test ne prouve rien"; FAIL=1
else
  echo "OK  GIF polyglotte accepte comme logo (document $logo)"
  headers=$(curl -s -D - -o "$DIR/served" -c "$JAR" -b "$JAR" "$BASE/front/document.send.php?docid=$logo")
  expect "logo polyglotte servi" 200 "$(head -1 <<< "$headers" | awk '{print $2}')"
  served=non; grep -q 'UPLOAD-EXECUTE"; ?>' "$DIR/served" && served=oui
  expect "logo polyglotte servi tel quel, code PHP non execute" oui "$served"
  type=$(grep -i '^content-type:' <<< "$headers" | tr -d '\r' | awk '{print $2}')
  case "$type" in
    image/*) echo "OK  logo polyglotte servi en $type" ;;
    *) echo "::error::logo polyglotte servi en « $type », pas comme une image"; FAIL=1 ;;
  esac
fi

# --- Pieces jointes d'un mouvement ---
for name in evil.php evil.phtml evil.phar; do
  attach "$(id movement_A)" "$DIR/script" "$name" > /dev/null
done
s2=$(state)
expect "piece jointe executable (.php, .phtml, .phar) : refusee" "$(field "$s1" movement_links)" "$(field "$s2" movement_links)"
# GLPI refuse le fichier mais creait quand meme un Document vide, joint sans message d'erreur.
expect "piece jointe executable : aucun document vide cree" "$(field "$s1" documents)" "$(field "$s2" documents)"
expect "aucun fichier executable stocke" 0 "$(field "$s2" executable)"

attach "$(id movement_A)" "$DIR/note" note.txt > /dev/null
s3=$(state)
expect "piece jointe legitime acceptee" $(($(field "$s2" movement_links) + 1)) "$(field "$s3" movement_links)"

login securite-tech 'Secrev#2026'
expect "technicien de A : joindre a un mouvement de B" 404 "$(attach "$(id movement_B)" "$DIR/note" note.txt)"
s4=$(state)
expect "technicien de A : rien joint au mouvement de B" "$(field "$s3" movement_links)" "$(field "$s4" movement_links)"
attach "$(id movement_A)" "$DIR/note" note.txt > /dev/null
s5=$(state)
expect "technicien de A : joindre a un mouvement de A" $(($(field "$s4" movement_links) + 1)) "$(field "$s5" movement_links)"

rm -rf "$JAR" "$DIR"
exit $FAIL
