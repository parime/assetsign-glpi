(function () {
    'use strict';

    var containers = document.querySelectorAll('.damage-view[data-mode="local"]');
    if (containers.length === 0) {
        return; // rien a cabler (etat des lieux desactive, ou fiche non concernee)
    }

    // Contrairement a damage-annotation.js (qui persiste chaque action tout
    // de suite par AJAX, cf. front/damagemarker.php), une fiche de maintenance
    // n'existe pas encore au moment ou ces marqueurs sont deposes (formulaire
    // de creation, cf. Maintenance.php - fiche immuable des sa creation,
    // aucune fenetre d'edition ulterieure a offrir). Etat garde entierement
    // cote client, serialise en JSON dans le champ cache #damage_markers_input
    // a chaque modification, et soumis d'un bloc avec le reste du formulaire.
    var markers = []; // { tempId, viewIndex, x, y, description, severity }
    var nextTempId = 1;

    var hiddenInput = document.getElementById('damage_markers_input');

    function syncHiddenInput() {
        if (!hiddenInput) {
            return;
        }
        hiddenInput.value = JSON.stringify(markers.map(function (m) {
            return {
                view_index: m.viewIndex,
                x: m.x,
                y: m.y,
                description: m.description,
                severity: m.severity
            };
        }));
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

    function createMarkerElement(container, marker) {
        var el = document.createElement('div');
        el.className = 'damage-marker' + (marker.severity === 1 ? ' damage-marker-major' : '');
        el.dataset.tempId = marker.tempId;
        el.style.left = marker.x + '%';
        el.style.top = marker.y + '%';
        el.title = marker.description || '';
        container.appendChild(el);
        wireMarker(container, marker, el);
        return el;
    }

    function wireMarker(container, marker, el) {
        var dragging = false;
        var moved = false;

        el.addEventListener('mousedown', function (evt) {
            evt.stopPropagation();
            dragging = true;
            moved = false;
        });

        document.addEventListener('mousemove', function (evt) {
            if (!dragging) {
                return;
            }
            moved = true;
            var pos = percentFromEvent(container, evt.clientX, evt.clientY);
            el.style.left = pos.x + '%';
            el.style.top = pos.y + '%';
        });

        document.addEventListener('mouseup', function (evt) {
            if (!dragging) {
                return;
            }
            dragging = false;
            if (moved) {
                var pos = percentFromEvent(container, evt.clientX, evt.clientY);
                marker.x = pos.x;
                marker.y = pos.y;
                syncHiddenInput();
            } else {
                openMarkerPanel(container, marker, el);
            }
        });
    }

    function openMarkerPanel(container, marker, el) {
        var existing = container.parentElement.querySelector('.damage-marker-panel');
        if (existing) {
            existing.remove();
        }

        var panel = document.createElement('div');
        panel.className = 'damage-marker-panel';
        panel.innerHTML =
            '<label>' + (window.ASSETSIGN_DAMAGE_I18N.description || 'Description') + '</label>' +
            '<input type="text" class="form-control form-control-sm damage-marker-desc" value="' +
                (marker.description || '').replace(/"/g, '&quot;') + '">' +
            '<label>' + (window.ASSETSIGN_DAMAGE_I18N.severity || 'Gravité') + '</label>' +
            '<select class="form-select form-select-sm damage-marker-severity">' +
                '<option value="0"' + (marker.severity === 1 ? '' : ' selected') + '>' +
                    (window.ASSETSIGN_DAMAGE_I18N.minor || 'Mineure') + '</option>' +
                '<option value="1"' + (marker.severity === 1 ? ' selected' : '') + '>' +
                    (window.ASSETSIGN_DAMAGE_I18N.major || 'Majeure') + '</option>' +
            '</select>' +
            '<div class="damage-marker-panel-actions">' +
                '<button type="button" class="btn btn-sm btn-secondary damage-marker-save">' + (window.ASSETSIGN_DAMAGE_I18N.save || 'Enregistrer') + '</button>' +
                '<button type="button" class="btn btn-sm btn-outline-danger damage-marker-delete">' + (window.ASSETSIGN_DAMAGE_I18N.deleteLabel || 'Supprimer') + '</button>' +
            '</div>';

        container.parentElement.appendChild(panel);

        panel.querySelector('.damage-marker-save').addEventListener('click', function () {
            marker.description = panel.querySelector('.damage-marker-desc').value;
            marker.severity = panel.querySelector('.damage-marker-severity').value === '1' ? 1 : 0;
            el.title = marker.description;
            el.classList.toggle('damage-marker-major', marker.severity === 1);
            syncHiddenInput();
            panel.remove();
        });

        panel.querySelector('.damage-marker-delete').addEventListener('click', function () {
            markers = markers.filter(function (m) { return m.tempId !== marker.tempId; });
            el.remove();
            syncHiddenInput();
            panel.remove();
        });
    }

    containers.forEach(function (container) {
        var viewIndex = parseInt(container.dataset.viewIndex, 10);

        container.addEventListener('click', function (evt) {
            if (evt.target !== container && !evt.target.classList.contains('damage-view-img')) {
                return; // clic sur un marqueur existant, deja gere par wireMarker/mouseup
            }
            var pos = percentFromEvent(container, evt.clientX, evt.clientY);
            var marker = {
                tempId: nextTempId++,
                viewIndex: viewIndex,
                x: pos.x,
                y: pos.y,
                description: '',
                severity: 0
            };
            markers.push(marker);
            createMarkerElement(container, marker);
            syncHiddenInput();
        });
    });

    syncHiddenInput(); // champ cache initialise a "[]" des le chargement
})();
