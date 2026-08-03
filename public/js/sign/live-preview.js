/**
 * Apercu PDF en direct, reutilise par la page de configuration
 * (config_form.html.twig) et la page de gabarit (template_form.html.twig) :
 * a chaque modification d'un champ pertinent, renvoie le formulaire (tel
 * quel, pas encore enregistre) a front/preview.php et remplace le contenu de
 * l'iframe par le HTML retourne — rendu strictement identique au vrai PDF
 * (meme gabarit Twig cote serveur), avant meme d'avoir cliqué sur Enregistrer.
 *
 * Convention : un formulaire avec [data-remise-preview-frame="<id de l'iframe>"]
 * est surveille dans son ensemble (tous ses champs sont renvoyes tels quels a
 * front/preview.php, qui n'utilise que ceux qu'il connait).
 *
 * Jeton CSRF : ce canal utilise SON PROPRE jeton (window.REMISE_PREVIEW_CSRF_TOKEN,
 * injecte par la page, cf. Config::showConfigForm()/Template::showForm()),
 * jamais celui du champ _glpi_csrf_token du formulaire lui-meme. Les deux
 * jetons GLPI sont a usage unique : partager le meme champ que le vrai
 * bouton Enregistrer faisait echouer l'un des deux en "Accès refusé" des que
 * l'utilisateur enchainait plusieurs cases a cocher puis cliquait
 * Enregistrer avant qu'une reponse d'apercu en vol n'ait fini de faire
 * tourner ce champ partage (constate en conditions reelles). Un jeton
 * totalement independant, jamais ecrit dans le DOM du formulaire, elimine
 * cette course structurellement.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 400;
    var endpoint = (window.REMISE_ROOT_DOC || '') + '/plugins/remise/front/preview.php';

    document.querySelectorAll('[data-remise-preview-frame]').forEach(function (form) {
        var frame = document.getElementById(form.dataset.remisePreviewFrame);
        if (!frame) {
            return;
        }

        // Jeton propre a CE formulaire d'apercu, jamais lu ni ecrit dans le
        // champ _glpi_csrf_token du formulaire (cf. commentaire d'en-tete) :
        // une variable JS locale, pas le DOM, pour que le vrai bouton
        // Enregistrer ne soit jamais affecte par la rotation de ce jeton.
        var previewToken = window.REMISE_PREVIEW_CSRF_TOKEN || '';

        var timer = null;

        function refresh() {
            // Synchronise les editeurs riches (TinyMCE) vers leur <textarea>
            // sous-jacent avant de serialiser : sans ca, un champ modifie
            // dans l'editeur visuel n'apparait jamais dans le formulaire brut.
            if (window.tinymce) {
                window.tinymce.triggerSave();
            }

            var data = new FormData(form);
            // Ne jamais renvoyer un fichier (ex: logo) a chaque frappe : preview.php
            // ne s'en sert pas, et le reenvoyer sur chaque debounce serait couteux
            // pour rien des qu'un fichier est selectionne dans le formulaire.
            form.querySelectorAll('input[type="file"]').forEach(function (fileInput) {
                data.delete(fileInput.name);
            });
            // Remplace le jeton du formulaire (destine au vrai Enregistrer) par
            // le jeton dedie a l'apercu, quoi que FormData ait recopie depuis
            // le DOM du champ _glpi_csrf_token.
            data.set('_glpi_csrf_token', previewToken);
            var body = new URLSearchParams(data);
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (res) {
                    // Le jeton CSRF de ce POST est a usage unique (deja consomme par le
                    // pare-feu GLPI) : sans le remplacer ici par celui renvoye en
                    // en-tete, le PROCHAIN appel serait rejete (403) des la frappe suivante.
                    // Mis a jour uniquement dans cette variable locale — jamais dans le
                    // DOM du formulaire (cf. commentaire d'en-tete).
                    var freshToken = res.headers.get('X-Remise-Csrf-Token');
                    if (freshToken) {
                        previewToken = freshToken;
                    }
                    return res.text();
                })
                .then(function (html) {
                    frame.srcdoc = html;
                })
                .catch(function () {
                    // Erreur reseau ponctuelle : l'apercu garde simplement son
                    // dernier etat connu, rien de bloquant pour l'utilisateur.
                });
        }

        function scheduleRefresh() {
            window.clearTimeout(timer);
            timer = window.setTimeout(refresh, DEBOUNCE_MS);
        }

        form.addEventListener('input', scheduleRefresh);
        form.addEventListener('change', scheduleRefresh);

        // TinyMCE (champs enable_richtext) ne declenche pas toujours un
        // evenement 'input' natif sur son <textarea> : ecoute aussi son
        // propre systeme d'evenements des qu'il est pret.
        if (window.tinymce) {
            window.tinymce.on('AddEditor', function (e) {
                if (form.contains(e.editor.getElement())) {
                    e.editor.on('input change keyup', scheduleRefresh);
                }
            });
        }
    });
})();
