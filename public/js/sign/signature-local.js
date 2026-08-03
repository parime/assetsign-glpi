(function () {
    'use strict';

    var wrappers = document.querySelectorAll('.remise-signature-pad-wrapper[data-mode="local"]');
    if (wrappers.length === 0) {
        return; // signature de maintenance desactivee, rien a cabler
    }

    // Contrairement a sign.js (qui envoie la signature par AJAX vers une fiche
    // de Remise deja existante), une fiche de maintenance n'existe pas encore
    // au moment ou cette signature est tracee (formulaire de creation, cf.
    // Maintenance::createWithChecklist() - fiche immuable des sa creation).
    // Etat garde entierement cote client (SignaturePad), serialise dans le
    // champ cache au moment de la soumission du formulaire, exactement comme
    // damage-annotation-local.js le fait pour les marqueurs d'etat des lieux.
    wrappers.forEach(function (wrapper) {
        var canvas = wrapper.querySelector('.remise-signature-pad');
        // hiddenInput/clearBtn sont des FRERES de wrapper (tous deux enfants
        // directs du meme conteneur .mb-3, cf. signature_edit.html.twig), pas
        // des descendants de wrapper lui-meme (qui ne contient que le
        // <canvas>) : chercher via wrapper.querySelector() ici renverrait
        // toujours null et ferait echouer silencieusement la mise a jour du
        // champ cache a la soumission (bug reel constate : signature tracee
        // mais jamais transmise au serveur, rejetee comme "obligatoire").
        var hiddenInput = wrapper.parentElement.querySelector('.remise-signature-input');
        var clearBtn = wrapper.parentElement.querySelector('.remise-signature-clear');
        var required = wrapper.dataset.required === '1';

        var pad = new SignaturePad(canvas, { penColor: '#16232f' });

        function resizeCanvas() {
            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            pad.clear();
        }
        window.addEventListener('resize', resizeCanvas);
        resizeCanvas();

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                pad.clear();
            });
        }

        var form = wrapper.closest('form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (evt) {
            if (required && pad.isEmpty()) {
                evt.preventDefault();
                window.alert(window.REMISE_SIGNATURE_I18N.required);
                return;
            }
            hiddenInput.value = pad.isEmpty() ? '' : pad.toDataURL('image/png');
        });
    });
})();
