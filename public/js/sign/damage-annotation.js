(function () {
    'use strict';

    var containers = document.querySelectorAll('.damage-view[data-editable="1"]');
    if (containers.length === 0) {
        return; // rien a cabler (aucune vue editable sur cette page)
    }

    var csrfToken = window.REMISE_DAMAGE_CSRF || '';
    var endpoint = (window.REMISE_ROOT_DOC || '') + '/plugins/remise/front/damagemarker.php';

    function post(action, params) {
        var body = new URLSearchParams(Object.assign({
            _glpi_csrf_token: csrfToken
        }, params));
        body.set(action, '1');
        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (res) { return res.json(); });
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

        marker.addEventListener('mousedown', function (evt) {
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
                post('update', { id: marker.dataset.id, remises_id: remisesId, x: pos.x, y: pos.y });
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

        panel.querySelector('.damage-marker-save').addEventListener('click', function () {
            var description = panel.querySelector('.damage-marker-desc').value;
            var severity = panel.querySelector('.damage-marker-severity').value;
            post('update', { id: marker.dataset.id, remises_id: remisesId, description: description, severity: severity }).then(function (data) {
                if (data.success) {
                    marker.title = description;
                    marker.classList.toggle('damage-marker-major', severity === '1');
                }
                panel.remove();
            });
        });

        panel.querySelector('.damage-marker-delete').addEventListener('click', function () {
            post('delete', { id: marker.dataset.id, remises_id: remisesId }).then(function (data) {
                if (data.success) {
                    marker.remove();
                }
                panel.remove();
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
                    }
                });
        });
    });
})();
