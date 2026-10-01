/* Spektrumansicht im Scan-Tab (device_detail.php): je BSS ein Trapez über
   die belegte Kanalbreite, Höhe = Signal. Ziel-SSIDs (per Connection-Test
   geprüft) in den ersten drei Palettenfarben und direkt beschriftet, alle
   übrigen Netze neutral grau - bei 20-40 Netzen trägt Farbe keine Identität
   mehr, dafür gibt es den Tooltip und die Scan-Tabelle darunter.

   Daten: <script type="application/json" id="spectrumData"> mit
   {targets: [SSID, ...], scans: [{label, nets: [{ssid, bssid, ap, signal,
   freq, center, width, channel}]}]}. Farben kommen aus CSS-Variablen
   (--spec-1..3, --spec-other, style.css), damit der Hell/Dunkel-Umschalter
   ohne Neuzeichnen greift. Namen aus dem Scan sind fremde Daten und landen
   nur per textContent im DOM. */
(function () {
    var SVG_NS = 'http://www.w3.org/2000/svg';
    var Y_MIN = -100, Y_MAX = -20;
    var BANDS = {
        '2.4': { from: 2401, to: 2483, channels: range(1, 13, 1), toFreq: function (c) { return 2407 + 5 * c; } },
        '5': { from: 5150, to: 5895, channels: range(36, 64, 4).concat(range(100, 144, 4), range(149, 177, 4)), toFreq: function (c) { return 5000 + 5 * c; } },
        '6': { from: 5945, to: 7125, channels: range(1, 233, 4), toFreq: function (c) { return 5950 + 5 * c; } }
    };
    var M = { top: 18, right: 12, bottom: 30, left: 46 };

    var data, svg, tip, legend, label, buttons;
    var state = { scan: 0, band: null };
    // Übersetzungen: device_detail.php setzt window.wlanmonI18n (deutscher
    // Text => aktuelle Sprache); %s wird der Reihe nach ersetzt.
    var I18N = window.wlanmonI18n || {};
    function tt(s) {
        var a = arguments, i = 1;
        return (I18N[s] || s).replace(/%s/g, function () { return String(a[i++]); });
    }

    function range(a, b, step) { var r = []; for (var i = a; i <= b; i += step) { r.push(i); } return r; }
    function bandOf(freq) { return freq < 3000 ? '2.4' : (freq < 5925 ? '5' : '6'); }
    function el(name, attrs) {
        var n = document.createElementNS(SVG_NS, name);
        Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        return n;
    }

    function seriesVar(ssid) {
        var i = data.targets.indexOf(ssid);
        return i >= 0 && i < 3 ? 'var(--spec-' + (i + 1) + ')' : null;
    }

    function init() {
        var raw = document.getElementById('spectrumData');
        svg = document.getElementById('spectrumSvg');
        if (!raw || !svg) { return; }
        data = JSON.parse(raw.textContent);
        tip = document.getElementById('spectrumTip');
        legend = document.getElementById('spectrumLegend');
        label = document.getElementById('spectrumScanLabel');
        buttons = Array.prototype.slice.call(document.querySelectorAll('.spectrum-band'));
        buttons.forEach(function (b) {
            b.addEventListener('click', function () { state.band = b.dataset.band; render(); });
        });
        document.querySelectorAll('.spectrum-show').forEach(function (b) {
            b.addEventListener('click', function () {
                state.scan = parseInt(b.dataset.scanIndex, 10) || 0;
                render();
                svg.closest('.spectrum-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        var t;
        window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(render, 150); });
        render();
    }

    function render() {
        var scan = data.scans[state.scan];
        if (!scan || svg.clientWidth === 0) { return; }
        hideTip();  // gehört zu Markierungen, die gleich ersetzt werden
        label.textContent = scan.label;

        // Bänder ohne Netze sperren; Start: erstes Band mit Netzen.
        var present = {};
        scan.nets.forEach(function (n) { present[bandOf(n.freq)] = true; });
        if (!state.band || !present[state.band]) {
            state.band = ['2.4', '5', '6'].filter(function (b) { return present[b]; })[0] || '2.4';
        }
        buttons.forEach(function (b) {
            b.disabled = !present[b.dataset.band];
            b.classList.toggle('active', b.dataset.band === state.band);
            b.setAttribute('aria-pressed', b.dataset.band === state.band ? 'true' : 'false');
        });

        var band = BANDS[state.band];
        var nets = scan.nets.filter(function (n) { return bandOf(n.freq) === state.band; });
        var W = svg.clientWidth, H = svg.clientHeight || 280;
        var iw = W - M.left - M.right, ih = H - M.top - M.bottom;
        var x = function (f) { return M.left + (f - band.from) / (band.to - band.from) * iw; };
        var y = function (dbm) { return M.top + (Y_MAX - Math.max(Y_MIN, Math.min(Y_MAX, dbm))) / (Y_MAX - Y_MIN) * ih; };

        while (svg.firstChild) { svg.removeChild(svg.firstChild); }
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);

        // Raster und Achsen (zurückhaltend: Haarlinien, gedämpfte Schrift).
        var grid = el('g', { 'class': 'spec-grid' });
        for (var d = -90; d <= -30; d += 10) {
            grid.appendChild(el('line', { x1: M.left, x2: W - M.right, y1: y(d), y2: y(d) }));
            var yl = el('text', { x: M.left - 6, y: y(d) + 4, 'text-anchor': 'end', 'class': 'spec-axis' });
            yl.textContent = d;
            grid.appendChild(yl);
        }
        var unit = el('text', { x: M.left - 6, y: M.top - 6, 'text-anchor': 'end', 'class': 'spec-axis' });
        unit.textContent = 'dBm';
        grid.appendChild(unit);
        grid.appendChild(el('line', { x1: M.left, x2: W - M.right, y1: y(Y_MIN), y2: y(Y_MIN), 'class': 'spec-base' }));
        // Kanalbeschriftung ausdünnen, wenn sie zu eng stünde.
        var minGap = 24, lastX = -Infinity;
        band.channels.forEach(function (c) {
            var cx = x(band.toFreq(c));
            if (cx < M.left || cx > W - M.right) { return; }
            grid.appendChild(el('line', { x1: cx, x2: cx, y1: y(Y_MIN), y2: y(Y_MIN) + 4, 'class': 'spec-base' }));
            if (cx - lastX >= minGap) {
                var t = el('text', { x: cx, y: H - M.bottom + 17, 'text-anchor': 'middle', 'class': 'spec-axis' });
                t.textContent = c;
                grid.appendChild(t);
                lastX = cx;
            }
        });
        svg.appendChild(grid);

        // Fremdnetze zuerst (unten), Ziel-SSIDs obenauf; innerhalb jeweils
        // stärkste zuerst, damit kleinere Trapeze darüber hoverbar bleiben.
        nets.sort(function (a, b) {
            var ta = seriesVar(a.ssid) ? 1 : 0, tb = seriesVar(b.ssid) ? 1 : 0;
            return ta - tb || b.signal - a.signal;
        });
        var marks = el('g');
        var labels = [];
        nets.forEach(function (n) {
            var width = n.width || 20;
            var lo = n.center - width / 2, hi = n.center + width / 2, slope = 2;
            var pts = [[lo, Y_MIN], [lo + slope, n.signal], [hi - slope, n.signal], [hi, Y_MIN]]
                .map(function (p) { return x(p[0]).toFixed(1) + ',' + y(p[1]).toFixed(1); }).join(' ');
            var color = seriesVar(n.ssid);
            var poly = el('polygon', { points: pts, 'class': color ? 'spec-mark spec-own' : 'spec-mark spec-other', tabindex: '0' });
            if (color) { poly.style.stroke = color; poly.style.fill = color; }
            poly.addEventListener('pointermove', function (e) { showTip(n, e.clientX, e.clientY); });
            poly.addEventListener('pointerleave', hideTip);
            poly.addEventListener('focus', function () {
                var r = poly.getBoundingClientRect();
                showTip(n, r.left + r.width / 2, r.top);
            });
            poly.addEventListener('blur', hideTip);
            marks.appendChild(poly);
            if (color || data.targets.indexOf(n.ssid) >= 0) {
                labels.push({ text: n.ssid, x: x(n.center), y: y(n.signal) - 6 });
            }
        });
        svg.appendChild(marks);

        // Direkte Beschriftung der Ziel-SSIDs über der Trapezkante. Kollidiert
        // ein Label (z.B. zwei SSIDs desselben Radios, gleicher Kanal und
        // Pegel), weicht es nach unten ins Trapez aus - nach oben ist bei
        // starken Signalen kein Platz. Erst begrenzen, dann ausweichen, sonst
        // schiebt die Begrenzung versetzte Labels wieder übereinander.
        var placed = [];
        var lg = el('g', { 'class': 'spec-labels' });
        labels.sort(function (a, b) { return a.y - b.y; }).forEach(function (l) {
            var w = l.text.length * 6.6 + 8, ly = Math.max(M.top + 10, l.y);
            for (var i = 0; i < 6; i++) {
                var hit = placed.some(function (p) {
                    return Math.abs(p.x - l.x) < (p.w + w) / 2 && Math.abs(p.y - ly) < 13;
                });
                if (!hit) { break; }
                ly += 14;
            }
            placed.push({ x: l.x, y: ly, w: w });
            var t = el('text', { x: l.x, y: ly, 'text-anchor': 'middle', 'class': 'spec-label' });
            t.textContent = l.text;
            lg.appendChild(t);
        });
        svg.appendChild(lg);

        svg.setAttribute('aria-label', tt('Spektrum') + ' ' + state.band + ' GHz, ' + tt('%s Netze', nets.length) + ', Scan ' + scan.label);
        renderLegend(scan);
    }

    function renderLegend(scan) {
        while (legend.firstChild) { legend.removeChild(legend.firstChild); }
        var seen = {};
        scan.nets.forEach(function (n) { seen[n.ssid] = true; });
        var items = data.targets.filter(function (s) { return seen[s] && seriesVar(s); })
            .map(function (s) { return { text: s, color: seriesVar(s) }; });
        items.push({ text: tt('andere Netze'), color: 'var(--spec-other)' });
        items.forEach(function (it) {
            var span = document.createElement('span');
            span.className = 'spec-legend-item';
            var key = document.createElement('span');
            key.className = 'spec-key';
            key.style.background = it.color;
            span.appendChild(key);
            span.appendChild(document.createTextNode(it.text));
            legend.appendChild(span);
        });
    }

    function showTip(n, cx, cy) {
        while (tip.firstChild) { tip.removeChild(tip.firstChild); }
        var v = document.createElement('strong');
        v.textContent = n.signal + ' dBm';
        tip.appendChild(v);
        var lines = [
            n.ssid || tt('(verborgen)'),
            n.bssid + (n.ap ? ' · ' + n.ap : ''),
            tt('Kanal') + ' ' + (n.channel != null ? n.channel : '?') + ' · ' + (n.width || 20) + ' MHz'
        ];
        lines.forEach(function (text, i) {
            var div = document.createElement('div');
            div.textContent = text;
            if (i > 0) { div.className = 'muted'; }
            tip.appendChild(div);
        });
        tip.hidden = false;
        var box = svg.parentNode.getBoundingClientRect();
        var left = cx - box.left + 12, top = cy - box.top + 12;
        if (left + tip.offsetWidth > box.width) { left = cx - box.left - tip.offsetWidth - 12; }
        tip.style.left = Math.max(0, left) + 'px';
        tip.style.top = Math.max(0, top) + 'px';
    }

    function hideTip() { tip.hidden = true; }

    window.wlanmonSpectrum = { init: init, render: render };
})();
