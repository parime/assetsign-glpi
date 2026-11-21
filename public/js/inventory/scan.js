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

// Issue #368 : scan des etiquettes QR par la camera du navigateur, pour une campagne
// d'inventaire physique. Decodage local : BarcodeDetector du navigateur quand il existe, sinon
// jsQR (lib/scan/jsQR.js, servi par le plugin, aucun CDN). Rien n'est envoye ailleurs qu'a
// front/physicalinventoryscan.php ; les textes viennent des attributs data-* (deja traduits).
(function () {
    'use strict';

    var root = document.getElementById('assetsign-inventory-scanner');
    document.querySelectorAll('form[data-assetsign-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.assetsignConfirm)) {
                event.preventDefault();
            }
        });
    });
    if (!root) {
        return;
    }

    var startBtn = root.querySelector('[data-scan-start]');
    var stopBtn = root.querySelector('[data-scan-stop]');
    var video = root.querySelector('[data-scan-video]');
    var canvas = root.querySelector('[data-scan-canvas]');
    var status = root.querySelector('[data-scan-status]');
    var log = root.querySelector('[data-scan-log]');
    var token = root.dataset.token || '';
    var stream = null;
    var timer = null;
    var detector = null;
    var busy = false;
    var recent = {};

    var colors = { found: 'success', moved: 'warning', missing: 'danger', extra: 'secondary' };

    function say(text) {
        status.textContent = text;
    }

    function addLog(message, statusCode) {
        var li = document.createElement('li');
        var badge = document.createElement('span');
        var color = colors[statusCode] || 'info';
        badge.className = 'badge me-2 bg-' + color + '-subtle text-' + color + '-emphasis';
        badge.textContent = new Date().toLocaleTimeString();
        li.appendChild(badge);
        li.appendChild(document.createTextNode(message));
        log.insertBefore(li, log.firstChild);
    }

    function send(code) {
        var now = Date.now();
        // Meme etiquette vue a chaque image : un seul envoi toutes les 4 secondes.
        if (busy || (recent[code] && now - recent[code] < 4000)) {
            return;
        }
        recent[code] = now;
        busy = true;
        var body = new URLSearchParams();
        body.set('id', root.dataset.campaign);
        body.set('code', code);
        body.set('_glpi_csrf_token', token);
        fetch(root.dataset.scanUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (res) {
            var fresh = res.headers.get('X-Assetsign-Csrf-Token');
            if (fresh) {
                token = fresh;
            }
            return res.json();
        }).then(function (data) {
            addLog(String(data.message || ''), data.status || '');
            if (navigator.vibrate && data.success) {
                navigator.vibrate(80);
            }
        }).catch(function () {
            addLog(root.dataset.msgError, '');
        }).then(function () {
            busy = false;
        });
    }

    function decode() {
        if (!video.videoWidth) {
            return;
        }
        if (detector) {
            detector.detect(video).then(function (codes) {
                codes.forEach(function (c) { send(c.rawValue); });
            }).catch(function () {});
            return;
        }
        if (typeof window.jsQR !== 'function') {
            return;
        }
        var scale = Math.min(1, 640 / video.videoWidth);
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        var image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var result = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
        if (result && result.data) {
            send(result.data);
        }
    }

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        }
        video.classList.add('d-none');
        stopBtn.classList.add('d-none');
        startBtn.classList.remove('d-none');
        say('');
    }

    startBtn.addEventListener('click', function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            say(root.dataset.msgNoCamera);
            return;
        }
        if ('BarcodeDetector' in window) {
            try {
                detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            } catch (e) {
                detector = null;
            }
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
            stream = s;
            video.srcObject = s;
            video.classList.remove('d-none');
            startBtn.classList.add('d-none');
            stopBtn.classList.remove('d-none');
            return video.play();
        }).then(function () {
            say(root.dataset.msgReady);
            timer = setInterval(decode, 300);
        }).catch(function () {
            stop();
            say(root.dataset.msgDenied);
        });
    });
    stopBtn.addEventListener('click', stop);
    window.addEventListener('pagehide', stop);
})();
