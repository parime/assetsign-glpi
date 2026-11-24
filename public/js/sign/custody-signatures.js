/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

/*
 * Issue #326 : deux signatures sur place dans un même formulaire (la personne qui remet, celle qui
 * reçoit), même principe que signature-pad-local.js : chaque tracé n'est écrit dans son champ
 * caché qu'à l'envoi, et l'envoi est bloqué tant qu'un des deux cadres est vide. Plusieurs
 * formulaires peuvent cohabiter sur la page (transfert, clôture).
 */
(function () {
    'use strict';

    function setup(form) {
        if (form.dataset.assetsignPadsReady === '1') {
            return;
        }
        form.dataset.assetsignPadsReady = '1';
        var pads = [];
        form.querySelectorAll('canvas[data-assetsign-pad]').forEach(function (canvas) {
            var pad = new SignaturePad(canvas, { penColor: '#16232f' });
            var entry = { canvas: canvas, pad: pad, width: 0 };
            pads.push(entry);
            var input = form.querySelector('input[name="' + canvas.dataset.assetsignPad + '"]');
            var clear = form.querySelector('[data-assetsign-pad-clear="' + canvas.dataset.assetsignPad + '"]');
            if (clear) {
                clear.addEventListener('click', function () {
                    pad.clear();
                });
            }
            entry.input = input;
        });

        // Un cadre masqué (onglet replié) n'a pas de taille : il est dimensionné dès qu'il apparaît,
        // et seulement si sa largeur change (redimensionner efface le tracé).
        function resize() {
            pads.forEach(function (entry) {
                var width = entry.canvas.offsetWidth;
                if (width === 0 || width === entry.width) {
                    return;
                }
                var ratio = Math.max(window.devicePixelRatio || 1, 1);
                entry.canvas.width = width * ratio;
                entry.canvas.height = entry.canvas.offsetHeight * ratio;
                entry.canvas.getContext('2d').scale(ratio, ratio);
                entry.pad.clear();
                entry.width = width;
            });
        }
        window.addEventListener('resize', resize);
        if (window.ResizeObserver) {
            var observer = new ResizeObserver(resize);
            pads.forEach(function (entry) {
                observer.observe(entry.canvas);
            });
        }
        resize();

        form.addEventListener('submit', function (evt) {
            for (var i = 0; i < pads.length; i++) {
                if (pads[i].pad.isEmpty()) {
                    evt.preventDefault();
                    window.alert(form.dataset.assetsignPadsRequired || '');
                    return;
                }
            }
            pads.forEach(function (entry) {
                if (entry.input) {
                    entry.input.value = entry.pad.toDataURL('image/png');
                }
            });
        });
    }

    function init() {
        document.querySelectorAll('form[data-assetsign-pads]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
