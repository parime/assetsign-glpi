/**
 * Apercu PDF en direct, reutilise par la page de configuration
 * (config_form.html.twig) et la page de gabarit (template_form.html.twig) :
 * a chaque modification d'un champ pertinent, renvoie le formulaire (tel
 * quel, pas encore enregistre) a front/preview.php et remplace le contenu de
 * l'iframe par le HTML retourne — rendu strictement identique au vrai PDF
 * (meme gabarit Twig cote serveur), avant meme d'avoir cliqué sur Enregistrer.
 *
 * Convention : un formulaire avec [data-assetsign-preview-frame="<id de l'iframe>"]
 * est surveille dans son ensemble (tous ses champs sont renvoyes tels quels a
 * front/preview.php, qui n'utilise que ceux qu'il connait).
 *
 * Jeton CSRF : ce canal utilise SON PROPRE jeton (window.ASSETSIGN_PREVIEW_CSRF_TOKEN,
 * injecte par la page, cf. Config::showConfigForm()/Template::showForm()),
 * jamais celui du champ _glpi_csrf_token du formulaire lui-meme. Une premiere
 * version se contentait de serialiser les appels d'apercu ENTRE EUX
 * (window.assetsignQueuedFetch, cf. csrf-queue.js) tout en continuant a lire/
 * ecrire le meme champ que le vrai bouton Enregistrer — insuffisant : la
 * vraie soumission du formulaire est une navigation de page classique,
 * entierement hors de ce JS et de sa file d'attente, donc jamais serialisee
 * avec elle. Un appel d'apercu en vol au moment ou l'utilisateur clique
 * Enregistrer pouvait donc toujours consommer le jeton juste avant, faisant
 * echouer la vraie sauvegarde en "Accès refusé" (bug reel constate meme
 * apres ce premier correctif, cf. TROUBLESHOOTING.md). Un jeton totalement
 * independant, jamais lu ni ecrit dans le DOM du formulaire, elimine cette
 * course a la racine — assetsignQueuedFetch() reste utilisee pour serialiser
 * les appels d'apercu entre eux (utile independamment de cette course-la),
 * mais seulement le corps de la requete change, jamais le champ du formulaire.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 400;
    var endpoint = (window.ASSETSIGN_ROOT_DOC || '') + '/plugins/assetsign/front/preview.php';

    document.querySelectorAll('[data-assetsign-preview-frame]').forEach(function (form) {
        var frame = document.getElementById(form.dataset.assetsignPreviewFrame);
        if (!frame) {
            return;
        }

        // Jeton propre a CE formulaire d'apercu, jamais lu ni ecrit dans le
        // champ _glpi_csrf_token du formulaire (cf. commentaire d'en-tete) :
        // une variable JS locale, pas le DOM, pour que le vrai bouton
        // Enregistrer ne soit jamais affecte par la rotation de ce jeton.
        var previewToken = window.ASSETSIGN_PREVIEW_CSRF_TOKEN || '';

        var timer = null;

        function refresh() {
            // Synchronise les editeurs riches (TinyMCE) vers leur <textarea>
            // sous-jacent avant de serialiser : sans ca, un champ modifie
            // dans l'editeur visuel n'apparait jamais dans le formulaire brut.
            if (window.tinymce) {
                window.tinymce.triggerSave();
            }

            // buildOptions n'est appelee par assetsignQueuedFetch qu'au moment reel
            // de l'envoi (apres que tout appel precedent en file ait fini de
            // faire tourner le jeton) : construire le FormData ICI, pas avant
            // l'appel a assetsignQueuedFetch, est ce qui garantit que previewToken
            // est lu a jour plutot qu'au moment du debounce.
            function buildOptions() {
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
                return {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(data).toString()
                };
            }

            window.assetsignQueuedFetch(endpoint, buildOptions)
                .then(function (res) {
                    // Le jeton CSRF de ce POST est a usage unique (deja consomme par le
                    // pare-feu GLPI) : sans le remplacer ici par celui renvoye en
                    // en-tete, le PROCHAIN appel serait rejete (403) des la frappe suivante.
                    // Mis a jour uniquement dans cette variable locale — jamais dans le
                    // DOM du formulaire (cf. commentaire d'en-tete).
                    var freshToken = res.headers.get('X-Assetsign-Csrf-Token');
                    if (freshToken) {
                        previewToken = freshToken;
                    }
                    return res.text();
                })
                .then(function (html) {
                    frame.srcdoc = html;
                    // Point d'extension optionnel : certains contenus affiches dans
                    // l'apercu ne viennent pas du serveur et seraient donc perdus a
                    // chaque rafraichissement (ex: logo pas encore envoye, choisi
                    // localement via FileReader - cf. config_form.html.twig). Si la
                    // page en definit un, il est rappele une fois le nouveau contenu
                    // charge, pour le reappliquer par-dessus.
                    if (window.ASSETSIGN_ON_PREVIEW_REFRESH) {
                        frame.addEventListener('load', function onLoad() {
                            frame.removeEventListener('load', onLoad);
                            window.ASSETSIGN_ON_PREVIEW_REFRESH();
                        });
                    }
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
