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
 * window.remiseQueuedFetch (cf. csrf-queue.js, charge juste avant ce script) :
 * indispensable ici, pas juste une precaution — deux frappes rapprochees
 * declenchent chacune un debounce puis un fetch ; sans serialisation, le
 * second peut lire le jeton CSRF AVANT que le premier ne l'ait fait tourner
 * (rotation via l'en-tete X-Remise-Csrf-Token ci-dessous), et se fait rejeter
 * en 403 par Session::checkCSRF() — la reponse d'erreur (page HTML complete)
 * atterrit alors telle quelle dans l'iframe d'apercu. remiseQueuedFetch()
 * serialise les appels et ne construit le corps de la requete (donc ne lit le
 * jeton) qu'au moment reel de l'envoi, jamais au moment du debounce.
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

        var timer = null;

        function refresh() {
            // Synchronise les editeurs riches (TinyMCE) vers leur <textarea>
            // sous-jacent avant de serialiser : sans ca, un champ modifie
            // dans l'editeur visuel n'apparait jamais dans le formulaire brut.
            if (window.tinymce) {
                window.tinymce.triggerSave();
            }

            // buildOptions n'est appelee par remiseQueuedFetch qu'au moment reel
            // de l'envoi (apres que tout appel precedent en file ait fini de
            // faire tourner le jeton) : construire le FormData ICI, pas avant
            // l'appel a remiseQueuedFetch, est ce qui garantit que le champ
            // cache _glpi_csrf_token est lu a jour plutot qu'au moment du debounce.
            function buildOptions() {
                var data = new FormData(form);
                // Ne jamais renvoyer un fichier (ex: logo) a chaque frappe : preview.php
                // ne s'en sert pas, et le reenvoyer sur chaque debounce serait couteux
                // pour rien des qu'un fichier est selectionne dans le formulaire.
                form.querySelectorAll('input[type="file"]').forEach(function (fileInput) {
                    data.delete(fileInput.name);
                });
                return {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(data).toString()
                };
            }

            window.remiseQueuedFetch(endpoint, buildOptions)
                .then(function (res) {
                    // Le jeton CSRF de ce POST est a usage unique (deja consomme par le
                    // pare-feu GLPI) : sans le remplacer ici par celui renvoye en
                    // en-tete, le PROCHAIN appel serait rejete (403) des la frappe suivante.
                    var freshToken = res.headers.get('X-Remise-Csrf-Token');
                    if (freshToken) {
                        var tokenInput = form.querySelector('[name="_glpi_csrf_token"]');
                        if (tokenInput) {
                            tokenInput.value = freshToken;
                        }
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
