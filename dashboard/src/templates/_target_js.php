<?php
/* Skript zu _target_fields.php: passende Felder je Sicherheit/EAP-Methode,
 * Karten hinzufügen/entfernen, Passwort anzeigen. */
?>
<script>
(function () {
    // Gerät: Liste #targets mit Vorlage und "hinzufügen"; SSID-Seite: eine Karte in #targets.
    var container = document.getElementById('targets');
    var template = document.getElementById('targetTemplate');
    var addButton = document.getElementById('addTarget');
    var nextIdx = container.children.length;

    // Blendet je nach Sicherheitstyp/EAP-Methode nur die passenden Felder ein.
    function refresh(card) {
        var security = card.querySelector('.target-security').value;
        var method = card.querySelector('.eap-method').value;
        var isEap = security === 'wpa2-eap';
        card.querySelector('.target-psk').style.display =
            (security === 'open' || isEap) ? 'none' : '';
        card.querySelector('.target-eap').style.display = isEap ? '' : 'none';
        card.querySelectorAll('.eap-pw').forEach(function (el) {
            el.style.display = method === 'tls' ? 'none' : '';
        });
        card.querySelectorAll('.eap-ttls').forEach(function (el) {
            el.style.display = method === 'ttls' ? '' : 'none';
        });
        card.querySelectorAll('.eap-tls').forEach(function (el) {
            el.style.display = method === 'tls' ? '' : 'none';
        });
    }

    Array.prototype.forEach.call(container.children, refresh);

    if (addButton) addButton.addEventListener('click', function () {
        var wrap = document.createElement('div');
        wrap.innerHTML = template.innerHTML.replace(/__IDX__/g, String(nextIdx++)).trim();
        var card = wrap.firstElementChild;
        container.appendChild(card);
        refresh(card);
    });

    container.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-target')) {
            e.target.closest('.target-card').remove();
        }
        if (e.target.classList.contains('pw-toggle')) {
            var input = e.target.closest('.pw-field').querySelector('input');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            e.target.textContent = show ? <?= tjson('Verbergen') ?> : <?= tjson('Anzeigen') ?>;
            e.target.setAttribute('aria-label', show ? <?= tjson('Passwort verbergen') ?> : <?= tjson('Passwort anzeigen') ?>);
        }
    });

    container.addEventListener('change', function (e) {
        if (e.target.classList.contains('target-security') || e.target.classList.contains('eap-method')) {
            refresh(e.target.closest('.target-card'));
        }
    });
})();
</script>
