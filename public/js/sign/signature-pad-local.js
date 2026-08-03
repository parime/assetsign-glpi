(function () {
    'use strict';

    var canvas = document.getElementById('technician-signature-pad');
    if (!canvas) {
        return; // signature desactivee pour cette entite : rien a cabler
    }

    var hiddenInput = document.getElementById('technician_signature_input');
    var btnClear = document.getElementById('btn-clear-technician-signature');
    var form = canvas.closest('form');

    function resizeCanvas() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        signaturePad.clear();
    }

    var signaturePad = new SignaturePad(canvas, { penColor: '#16232f' });
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    if (btnClear) {
        btnClear.addEventListener('click', function () {
            signaturePad.clear();
        });
    }

    // Contrairement a damage-annotation-local.js (qui synchronise le champ
    // cache a chaque modification), la signature n'est ecrite dans le champ
    // cache qu'AU MOMENT de la soumission : rien ne sert de la serialiser a
    // chaque trait, et cela permet de bloquer la soumission (preventDefault)
    // si le canevas est encore vide au moment ou l'utilisateur clique sur
    // "Créer la fiche" - le controle serveur (SignatureImageValidator)
    // recontrole de toute facon une signature trop peu tracee.
    if (form) {
        form.addEventListener('submit', function (evt) {
            if (signaturePad.isEmpty()) {
                evt.preventDefault();
                window.alert((window.REMISE_SIGNATURE_I18N && window.REMISE_SIGNATURE_I18N.required) || 'Merci de signer avant de créer la fiche.');
                return;
            }
            hiddenInput.value = signaturePad.toDataURL('image/png');
        });
    }
})();
