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

    // window.remiseQueuedFetch : serialise les appels touchant le jeton CSRF
    // a usage unique (defini par csrf-queue.js, charge avant ce script aussi
    // bien sur remise_form.html.twig que sign_page.html.twig — cf. le
    // commentaire de csrf-queue.js pour le detail du bug de course qu'il evite).

    // Endpoint/parametres additionnels surchargeables : la page de signature
    // (sign_page.html.twig) authentifie par jeton de signature (front/sign.php,
    // pas de droit GLPI necessaire pour le beneficiaire) plutot que par
    // front/damagemarker.php (droit RIGHT_REMISE, page admin).
    var endpoint = window.REMISE_DAMAGE_ENDPOINT || ((window.REMISE_ROOT_DOC || '') + '/plugins/remise/front/damagemarker.php');
    var extraParams = window.REMISE_DAMAGE_EXTRA_PARAMS || {};

    // Message d'erreur transitoire (pas de panneau ouvert : glisser un repere,
    // ou en ajouter un nouveau) : affiche sous la vue concernee, disparait
    // tout seul apres quelques secondes.
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

    /**
     * Trois bugs reels corriges dans ce fichier (glisser un repere, en
     * ajouter un, et le panneau d'edition) partageaient la meme cause : post()
     * se contentait de renvoyer la reponse du serveur, laissant chaque appelant
     * decider s'il verifiait vraiment `data.success` — certains l'ont fait,
     * d'autres non, avec pour consequence un echec (jeton perime, fiche plus
     * modifiable...) totalement invisible pour l'utilisateur. Plutot que de
     * compter sur la discipline de chaque futur appel, post() affiche
     * desormais un message d'erreur PAR DEFAUT des que `data.success` est
     * faux ou que la requete echoue (reseau/CSRF) : un appelant doit
     * explicitement passer `{ silent: true }` pour desactiver ce comportement
     * et gerer lui-meme l'affichage (cas du panneau d'edition, qui affiche
     * l'erreur DANS le panneau plutot que sous la vue). Un nouvel appel qui
     * oublierait de gerer les erreurs echoue donc desormais de façon visible
     * par defaut, jamais silencieusement.
     *
     * @param {object} [opts.container] Element sous lequel afficher l'erreur
     *                                   par defaut (ignore si opts.silent).
     * @param {boolean} [opts.silent] Desactive l'affichage automatique.
     */
    function post(action, params, opts) {
        opts = opts || {};
        function reportError(message) {
            if (!opts.silent && opts.container) {
                showTransientError(opts.container, message);
            }
        }
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
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data.success) {
                reportError((window.REMISE_DAMAGE_I18N.errorPrefix || 'Erreur') + ' : ' + (data.error || '?'));
            }
            return data;
        }).catch(function (err) {
            reportError(window.REMISE_DAMAGE_I18N.networkError || 'Erreur réseau');
            throw err;
        });
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
            // Repere pas encore confirme par le serveur (cf. affichage optimiste
            // dans le handler 'click' du conteneur) : son dataset.id ('pending')
            // ne correspond a aucune ligne reelle, le glisser/cliquer ici
            // echouerait cote serveur. Ignore silencieusement plutot que
            // d'envoyer une requete vouee a l'echec.
            if (marker.dataset.id === 'pending') {
                return;
            }
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
                post('update', { id: marker.dataset.id, remises_id: remisesId, x: pos.x, y: pos.y }, { container: container })
                    .then(function (data) {
                        if (!data.success) {
                            marker.style.left = dragStartLeft;
                            marker.style.top = dragStartTop;
                        }
                    })
                    .catch(function () {
                        marker.style.left = dragStartLeft;
                        marker.style.top = dragStartTop;
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

        // Message d'erreur affiche DANS le panneau (pas la transitoire sous la
        // vue) : le panneau reste ouvert avec la description deja saisie,
        // l'utilisateur n'a qu'a reessayer plutot que tout retaper. D'ou
        // { silent: true } sur les deux appels post() ci-dessous : le
        // comportement par defaut (message sous la vue) ne convient pas ici.
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
            post('update', { id: marker.dataset.id, remises_id: remisesId, description: description, severity: severity }, { silent: true })
                .then(function (data) {
                    if (data.success) {
                        marker.title = description;
                        marker.classList.toggle('damage-marker-major', severity === '1');
                        panel.remove();
                    } else {
                        showPanelError((window.REMISE_DAMAGE_I18N.errorPrefix || 'Erreur') + ' : ' + (data.error || '?'));
                    }
                })
                .catch(function () {
                    showPanelError(window.REMISE_DAMAGE_I18N.networkError || 'Erreur réseau');
                });
        });

        panel.querySelector('.damage-marker-delete').addEventListener('click', function () {
            post('delete', { id: marker.dataset.id, remises_id: remisesId }, { silent: true })
                .then(function (data) {
                    if (data.success) {
                        marker.remove();
                        panel.remove();
                    } else {
                        showPanelError((window.REMISE_DAMAGE_I18N.errorPrefix || 'Erreur') + ' : ' + (data.error || '?'));
                    }
                })
                .catch(function () {
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
            // Affichage optimiste : le repere apparait immediatement au point
            // clique, sans attendre la reponse serveur — celle-ci regenere le
            // PDF non signe en entier (cf. Remise::refreshDamageAnnotationPdf()),
            // ce qui peut prendre plusieurs secondes et donnait l'impression
            // d'un clic sans effet, voire d'un repere qui "rate" sa position si
            // l'utilisateur cliquait ailleurs avant que la reponse n'arrive.
            // dataset.id vaut 'pending' jusqu'a confirmation ; wireMarker()
            // ignore toute interaction sur un repere encore a cet etat.
            var marker = createMarkerElement(container, 'pending', pos.x, pos.y, '', 0);
            marker.classList.add('damage-marker-pending');
            post('add', { remises_id: remisesId, view_index: viewIndex, x: pos.x, y: pos.y, description: '', severity: 0 }, { container: container })
                .then(function (data) {
                    if (data.success) {
                        marker.dataset.id = data.id;
                        marker.classList.remove('damage-marker-pending');
                    } else {
                        marker.remove();
                    }
                })
                .catch(function () {
                    marker.remove();
                });
        });
    });
})();
