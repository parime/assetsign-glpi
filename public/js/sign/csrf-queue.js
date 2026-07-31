// File d'attente partagee pour tout appel authentifie par le jeton CSRF a
// usage unique de GLPI (cf. README) : sign.js, damage-annotation.js et le
// script inline de la Remarque (sign_page.html.twig) l'utilisent tous les
// trois. Sans elle, deux requetes parties presque en meme temps lisent le
// meme jeton avant que la premiere n'ait eu le temps de le faire tourner —
// le serveur rejette la seconde en 403. remiseQueuedFetch() serialise ces
// appels et ne lit le jeton qu'au moment reel de l'envoi (jamais au clic).
(function () {
    'use strict';

    window.REMISE_CSRF_QUEUE = window.REMISE_CSRF_QUEUE || Promise.resolve();
    window.remiseQueuedFetch = window.remiseQueuedFetch || function (url, buildOptions) {
        var run = function () {
            return fetch(url, buildOptions()).then(function (res) {
                var rotated = res.headers.get('X-Remise-Csrf-Token');
                if (rotated) {
                    window.REMISE_CSRF_TOKEN = rotated;
                }
                return res;
            });
        };
        var next = window.REMISE_CSRF_QUEUE.then(run, run);
        window.REMISE_CSRF_QUEUE = next.catch(function () {});
        return next;
    };
})();
