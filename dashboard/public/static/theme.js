/* Hell/Dunkel-Umschalter (Kopfzeile, siehe _nav.php). Drei Stufen:
   "auto" folgt dem Betriebssystem (prefers-color-scheme), "light"/"dark"
   erzwingen das jeweilige Schema per data-theme auf <html>. Die Wahl gilt
   pro Browser (localStorage), nicht pro Benutzerkonto.

   Wird im <head> synchron VOR dem Rendern geladen, damit beim Seitenaufbau
   nicht kurz das falsche Schema aufblitzt. */
(function () {
    var KEY = 'wlanmon-theme';
    var ORDER = ['auto', 'light', 'dark'];
    var LABELS = { auto: 'Design: automatisch (System)', light: 'Design: hell', dark: 'Design: dunkel' };
    var ICONS = { auto: 'fa-circle-half-stroke', light: 'fa-sun', dark: 'fa-moon' };
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        try {
            var v = localStorage.getItem(KEY);
            return ORDER.indexOf(v) >= 0 ? v : 'auto';
        } catch (e) {
            return 'auto';
        }
    }

    function isDark(mode) {
        return mode === 'dark' || (mode === 'auto' && !!(media && media.matches));
    }

    // Chart.js kennt kein automatisches Dark Mode - Achsenbeschriftung und
    // Gitternetzlinien nutzen sonst feste dunkle Standardfarben, die vor
    // dunklem Seitenhintergrund praktisch unsichtbar waeren. Bestehende
    // Diagramme werden beim Umschalten neu gezeichnet.
    var chartLight = null;
    function applyCharts(dark) {
        if (!window.Chart) {
            return;
        }
        if (chartLight === null) {
            chartLight = { color: Chart.defaults.color, borderColor: Chart.defaults.borderColor };
        }
        Chart.defaults.color = dark ? '#c7cfda' : chartLight.color;
        Chart.defaults.borderColor = dark ? '#333d4f' : chartLight.borderColor;
        Object.values(Chart.instances || {}).forEach(function (c) { c.update(); });
    }

    function updateButton(mode) {
        var btn = document.getElementById('theme-toggle');
        if (!btn) {
            return;
        }
        // Übersetzte Texte liefert _nav.php als data-label-auto/-light/-dark.
        var label = btn.getAttribute('data-label-' + mode) || LABELS[mode];
        btn.title = label;
        btn.setAttribute('aria-label', label);
        var icon = btn.querySelector('i');
        if (icon) {
            icon.className = 'fa-solid ' + ICONS[mode];
        }
        // Im Benutzermenü steht der Zustand zusätzlich als Text.
        var text = btn.querySelector('.theme-toggle-label');
        if (text) {
            text.textContent = label;
        }
    }

    function apply(mode) {
        if (mode === 'auto') {
            document.documentElement.removeAttribute('data-theme');
        } else {
            document.documentElement.setAttribute('data-theme', mode);
        }
        applyCharts(isDark(mode));
        updateButton(mode);
        // Für Diagramme mit eigenen Farben aus CSS-Variablen (z.B. timeline.js).
        try {
            document.dispatchEvent(new CustomEvent('wlanmon-theme', { detail: { mode: mode } }));
        } catch (e) {
            // sehr alte Browser ohne CustomEvent-Konstruktor: nur ohne Neufärbung
        }
    }

    var current = stored();
    apply(current);

    // Fuer Seiten mit Diagrammen: Chart.js wird erst nach diesem Skript
    // geladen, daher dort vor dem Erzeugen der Diagramme aufrufen.
    window.wlanmonApplyChartTheme = function () { applyCharts(isDark(current)); };

    if (media && media.addEventListener) {
        media.addEventListener('change', function () {
            if (current === 'auto') {
                apply('auto');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateButton(current);
        var btn = document.getElementById('theme-toggle');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function () {
            current = ORDER[(ORDER.indexOf(current) + 1) % ORDER.length];
            try {
                localStorage.setItem(KEY, current);
            } catch (e) {
                // Ohne localStorage gilt die Wahl nur bis zum naechsten Seitenaufruf.
            }
            apply(current);
        });
    });
})();
