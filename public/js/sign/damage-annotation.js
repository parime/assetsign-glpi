(function () {
    'use strict';

    var containers = document.querySelectorAll('.damage-view[data-editable="1"]');
    if (containers.length === 0) {
        return; // rien a cabler (aucune vue editable sur cette page)
    }

    // Jeton CSRF partage via window.REMISE_CSRF_TOKEN plutot qu'une variable
    // locale : sur la page de signature, sign.js (soumission de la signature)
    // et le petit script inline de la Remarque font AUSSI des POST vers
    // front/sign.php, avec le meme jeton a usage unique cote session GLPI. Une
    // copie locale ici ne verrait jamais la rotation faite par ces autres
    // appels (et inversement). window.REMISE_CSRF_TOKEN est deja defini par
    // sign_page.html.twig (page de signature) ; sur la fiche admin
    // (remise_form.html.twig, un seul script sur la page), on retombe sur
    // REMISE_DAMAGE_CSRF, absent de ce cas partage.
    if (!window.REMISE_CSRF_TOKEN) {
        window.REMISE_CSRF_TOKEN = window.REMISE_DAMAGE_CSRF || '';
    }

    // File d'attente partagee (idempotent : le premier script charge sur la
    // page — sign.js ou celui-ci selon le contexte — la definit, les suivants
    // la reutilisent telle quelle) : un jeton CSRF GLPI est a usage unique.
    // Sans serialisation, deux clics rapproches (ajouter 2 reperes vite, ou
    // glisser un repere juste apres un ajout) lisent tous les deux LE MEME
    // jeton avant que le premier appel n'ait eu le temps de le faire tourner
    // — le serveur accepte le premier, rejette le second en 403 (rejete par
    // le pare-feu GLPI lui-meme, avant meme d'atteindre notre code, donc pas
    // du JSON : res.json() plante, la promesse est rejetee silencieusement,
    // ET window.REMISE_CSRF_TOKEN ne tourne jamais). Consequence en cascade :
    // TOUT appel suivant (repere, remarque, signature) echoue ensuite avec le
    // meme jeton perime, jusqu'au rechargement de la page. Constate en
    // conditions reelles (2 requetes lancees en parallele avec le meme
    // jeton : la premiere reussit, la seconde recoit bien la page "Acces
    // refuse" de GLPI). Corrige en forcant chaque appel a attendre que le
    // precedent soit termine (succes ou echec) avant de partir, et en ne
    // construisant le corps de la requete (donc en lisant le jeton) qu'a cet
    // instant-la, jamais au moment du clic.
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
        // .then(run, run) : la file continue meme si l'appel precedent a
        // echoue (reseau, 403...) — sinon un seul echec bloquerait tous les
        // appels suivants indefiniment.
        var next = window.REMISE_CSRF_QUEUE.then(run, run);
        window.REMISE_CSRF_QUEUE = next.catch(function () {});
        return next;
    };

    // Endpoint/parametres additionnels surchargeables : la page de signature
    // (sign_page.html.twig) authentifie par jeton de signature (front/sign.php,
    // pas de droit GLPI necessaire pour le beneficiaire) plutot que par
    // front/damagemarker.php (droit RIGHT_REMISE, page admin).
    var endpoint = window.REMISE_DAMAGE_ENDPOINT || ((window.REMISE_ROOT_DOC || '') + '/plugins/remise/front/damagemarker.php');
    var extraParams = window.REMISE_DAMAGE_EXTRA_PARAMS || {};

    function post(action, params) {
        return window.remiseQueuedFetch(endpoint, function () {
            var body = new URLSearchParams(Object.assign({}, extraParams, {
                _glpi_csrf_token: window.REMISE_CSRF_TOKEN
            }, params));
            body.set(action, '1');
            return {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            };
        }).then(function (res) { return res.json(); });
    }

    // Message d'erreur transitoire (pas de panneau ouvert dans ce cas : glisser
    // un repere, ou en ajouter un nouveau) : affiche sous la vue concernee,
    // disparait tout seul apres quelques secondes.
    function showTransientError(container, message) {
        var wrapper = container.parentElement;
        var existing = wrapper.querySelector('.damage-marker-panel-error');
        if (existing) {
            existing.remove();
        }
        var el = document.createElement('p');
        el.className = 'damage-marker-panel-error';
        el.textContent = message;
        wrapper.appendChild(el);
        window.setTimeout(function () {
            el.remove();
        }, 4000);
    }

    function percentFromEvent(container, clientX, clientY) {
        var rect = container.getBoundingClientRect();
        var x = ((clientX - rect.left) / rect.width) * 100;
        var y = ((clientY - rect.top) / rect.height) * 100;
        return {
            x: Math.min(100, Math.max(0, x)),
            y: Math.min(100, Math.max(0, y))
        };
    }

    function createMarkerElement(container, id, x, y, description, severity) {
        var marker = document.createElement('div');
        marker.className = 'damage-marker' + (severity == 1 ? ' damage-marker-major' : '');
        marker.dataset.id = id;
        marker.style.left = x + '%';
        marker.style.top = y + '%';
        marker.title = description || '';
        container.appendChild(marker);
        wireMarker(container, marker);
        return marker;
    }

    function wireMarker(container, marker) {
        var remisesId = container.dataset.remiseId;
        var dragging = false;
        var moved = false;
        var dragStartLeft = null;
        var dragStartTop = null;

        marker.addEventListener('mousedown', function (evt) {
            evt.stopPropagation();
            dragging = true;
            moved = false;
            // Position de depart, pour pouvoir remettre le repere a sa place
            // si l'enregistrement du deplacement echoue cote serveur.
            dragStartLeft = marker.style.left;
            dragStartTop = marker.style.top;
        });

        document.addEventListener('mousemove', function (evt) {
            if (!dragging) {
                return;
            }
            moved = true;
            var pos = percentFromEvent(container, evt.clientX, evt.clientY);
            marker.style.left = pos.x + '%';
            marker.style.top = pos.y + '%';
        });

        document.addEventListener('mouseup', function (evt) {
            if (!dragging) {
                return;
            }
            dragging = false;
            if (moved) {
                var pos = percentFromEvent(container, evt.clientX, evt.clientY);
                // Bug reel corrige ici : la reponse n'etait jamais verifiee, le
                // repere restait visuellement a sa nouvelle position (deja
                // deplace par mousemove ci-dessus) meme quand l'enregistrement
                // echouait cote serveur — meme illusion de succes que le
                // panneau d'edition, cf. commentaire de showPanelError().
                post('update', { id: marker.dataset.id, remises_id: remisesId, x: pos.x, y: pos.y }).then(function (data) {
                    if (!data.success) {
                        marker.style.left = dragStartLeft;
                        marker.style.top = dragStartTop;
                        showTransientError(container, (window.REMISE_DAMAGE_I18N.errorPrefix || 'Erreur') + ' : ' + (data.error || '?'));
                    }
                }).catch(function () {
                    marker.style.left = dragStartLeft;
                    marker.style.top = dragStartTop;
                    showTransientError(container, window.REMISE_DAMAGE_I18N.networkError || 'Erreur réseau');
                });
            } else {
                openMarkerPanel(container, marker);
            }
        });
    }

    function openMarkerPanel(container, marker) {
        var remisesId = container.dataset.remiseId;
        var existing = container.parentElement.querySelector('.damage-marker-panel');
        if (existing) {
            existing.remove();
        }

        var panel = document.createElement('div');
        panel.className = 'damage-marker-panel';
        panel.innerHTML =
            '<label>' + (window.REMISE_DAMAGE_I18N.description || 'Description') + '</label>' +
            '<input type="text" class="form-control form-control-sm damage-marker-desc" value="' +
                (marker.title || '').replace(/"/g, '&quot;') + '">' +
            '<label>' + (window.REMISE_DAMAGE_I18N.severity || 'Gravité') + '</label>' +
            '<select class="form-select form-select-sm damage-marker-severity">' +
                '<option value="0"' + (marker.classList.contains('damage-marker-major') ? '' : ' selected') + '>' +
                    (window.REMISE_DAMAGE_I18N.minor || 'Mineure') + '</option>' +
                '<option value="1"' + (marker.classList.contains('damage-marker-major') ? ' selected' : '') + '>' +
                    (window.REMISE_DAMAGE_I18N.major || 'Majeure') + '</option>' +
            '</select>' +
            '<div class="damage-marker-panel-actions">' +
                '<button type="button" class="btn btn-sm btn-secondary damage-marker-save">' + (window.REMISE_DAMAGE_I18N.save || 'Enregistrer') + '</button>' +
                '<button type="button" class="btn btn-sm btn-outline-danger damage-marker-delete">' + (window.REMISE_DAMAGE_I18N.deleteLabel || 'Supprimer') + '</button>' +
            '</div>';

        container.parentElement.appendChild(panel);

        // Message d'erreur affiche DANS le panneau (pas une alerte bloquante) :
        // le panneau reste ouvert avec la description deja saisie, l'utilisateur
        // n'a qu'a reessayer plutot que tout retaper.
        function showPanelError(message) {
            var existingError = panel.querySelector('.damage-marker-panel-error');
            if (!existingError) {
                existingError = document.createElement('p');
                existingError.className = 'damage-marker-panel-error';
                panel.appendChild(existingError);
            }
            existingError.textContent = message;
        }

        panel.querySelector('.damage-marker-save').addEventListener('click', function () {
            var description = panel.querySelector('.damage-marker-desc').value;
            var severity = panel.querySelector('.damage-marker-severity').value;
            post('update', { id: marker.dataset.id, remises_id: remisesId, description: description, severity: severity }).then(function (data) {
                // Bug reel corrige ici : le panneau se fermait avant meme en cas
                // d'echec (jeton CSRF perime, fiche plus editable...), donnant
                // l'illusion que la description avait ete enregistree alors
                // qu'elle ne l'etait pas — constate en conditions reelles.
                if (data.success) {
                    marker.title = description;
                    marker.classList.toggle('damage-marker-major', severity === '1');
                    panel.remove();
                } else {
                    showPanelError((window.REMISE_DAMAGE_I18N.errorPrefix || 'Erreur') + ' : ' + (data.error || '?'));
                }
            }).catch(function () {
                showPanelError(window.REMISE_DAMAGE_I18N.networkError || 'Erreur réseau');
            });
        });

        panel.querySelector('.damage-marker-delete').addEventListener('click', function () {
            post('delete', { id: marker.dataset.id, remises_id: remisesId }).then(function (data) {
                if (data.success) {
                    marker.remove();
                    panel.remove();
                } else {
                    showPanelError((window.REMISE_DAMAGE_I18N.errorPrefix || 'Erreur') + ' : ' + (data.error || '?'));
                }
            }).catch(function () {
                showPanelError(window.REMISE_DAMAGE_I18N.networkError || 'Erreur réseau');
            });
        });
    }

    containers.forEach(function (container) {
        var remisesId = container.dataset.remiseId;
        var viewIndex = container.dataset.viewIndex;

        // Marqueurs existants deja rendus par Twig (elements .damage-marker sans handlers) :
        // on les cable ici plutot que de tout reconstruire depuis du JSON.
        container.querySelectorAll('.damage-marker').forEach(function (marker) {
            wireMarker(container, marker);
        });

        container.addEventListener('click', function (evt) {
            if (evt.target !== container && !evt.target.classList.contains('damage-view-img')) {
                return; // clic sur un marqueur existant, deja gere par wireMarker/mouseup
            }
            var pos = percentFromEvent(container, evt.clientX, evt.clientY);
            post('add', { remises_id: remisesId, view_index: viewIndex, x: pos.x, y: pos.y, description: '', severity: 0 })
                .then(function (data) {
                    if (data.success) {
                        createMarkerElement(container, data.id, pos.x, pos.y, '', 0);
                    } else {
                        // Meme principe que les deux autres correctifs de ce fichier :
                        // sans ca, un clic qui echoue (fiche plus editable, jeton
                        // perime...) ne fait simplement rien, sans que l'utilisateur
                        // sache si son clic a ete pris en compte ou non.
                        showTransientError(container, (window.REMISE_DAMAGE_I18N.errorPrefix || 'Erreur') + ' : ' + (data.error || '?'));
                    }
                }).catch(function () {
                    showTransientError(container, window.REMISE_DAMAGE_I18N.networkError || 'Erreur réseau');
                });
        });
    });
})();
