/* Tab "Verlauf" der Geräteseite: Kanalauslastung der eigenen AP-Radios und
   Latenz je Ziel-SSID über eine Voreinstellung (1-72 h bis jetzt) oder
   einen frei gewählten Zeitraum von/bis, dazu die Zeiträume der eigenen
   iperf3-Tests als graue Bänder in beiden Diagrammen. Daten per JSON von
   /devices/<id>/timeline (src/Timeline.php); Chart.js lädt die Seite schon.

   Farben kommen aus den CSS-Variablen --spec-1..8 (validierte Palette, siehe
   style.css) in fester Reihenfolge je Objekt - ein Radio bzw. eine SSID
   behält ihre Farbe, egal was sonst im Zeitraum auftaucht. Mehr als acht
   Reihen bekommen neutrales Grau statt einer erfundenen Farbe. */
(function () {
    // range: null = Voreinstellung 'hours' bis jetzt, sonst {from, to} in ms.
    var url = null, hours = 24, range = null, data = null, charts = {};
    var MAX_SLOTS = 8;
    // Übersetzungen: device_detail.php setzt window.wlanmonI18n (deutscher
    // Text => aktuelle Sprache); %s wird der Reihe nach ersetzt.
    var I18N = window.wlanmonI18n || {}, LANG = window.wlanmonLang || 'de';
    function tt(s) {
        var a = arguments, i = 1;
        return (I18N[s] || s).replace(/%s/g, function () { return String(a[i++]); });
    }

    function css(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
    function slot(i) { return i < MAX_SLOTS ? css('--spec-' + (i + 1)) : css('--spec-other'); }
    function withAlpha(hex, a) {
        var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!m) { return 'rgba(128,128,128,' + a + ')'; }
        var n = parseInt(m[1], 16);
        return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    // Tag/Monat wie format_local() serverseitig: 31.12. bzw. 12/31.
    function fmtDay(d) {
        return LANG === 'en' ? pad(d.getMonth() + 1) + '/' + pad(d.getDate()) : pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.';
    }
    function fmtTick(ms) {
        var d = new Date(ms);
        var t = pad(d.getHours()) + ':' + pad(d.getMinutes());
        // Datum an der Achse, sobald der Zeitraum über einen Tag geht.
        return data && data.to - data.from > 24 * 3600 * 1000 ? fmtDay(d) + ' ' + t : t;
    }
    function fmtFull(ms) {
        var d = new Date(ms);
        return fmtDay(d) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }
    // Date -> Wert für <input type="datetime-local"> in Ortszeit.
    function toInput(ms) {
        var d = new Date(ms);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function fmtRange(from, to) {
        var a = new Date(from), b = new Date(to);
        var sameDay = a.toDateString() === b.toDateString();
        return fmtDay(a) + ' ' + pad(a.getHours()) + ':' + pad(a.getMinutes()) + ' – '
            + (sameDay ? '' : fmtDay(b) + ' ') + pad(b.getHours()) + ':' + pad(b.getMinutes());
    }
    function pts(list) { return (list || []).map(function (p) { return { x: p[0], y: p[1] }; }); }

    // Größte Lücke, die noch als durchgehende Linie gilt: 2,5 × typischer
    // Abstand der Reihe (mind. 10 min) - so bleibt eine Probe mit
    // 30-min-Scans verbunden, ein echter Ausfall wird aber zur Lücke.
    function gapFor(points) {
        if (points.length < 3) { return 10 * 60 * 1000; }
        var diffs = [];
        for (var i = 1; i < points.length; i++) { diffs.push(points[i][0] - points[i - 1][0]); }
        diffs.sort(function (a, b) { return a - b; });
        return Math.max(10 * 60 * 1000, diffs[Math.floor(diffs.length / 2)] * 2.5);
    }

    // Graue Bänder für die iperf3-Zeiträume, hinter den Linien gezeichnet.
    var bandsPlugin = {
        id: 'iperfBands',
        beforeDatasetsDraw: function (chart) {
            if (!data || !data.iperf.length) { return; }
            var x = chart.scales.x, area = chart.chartArea, ctx = chart.ctx;
            ctx.save();
            ctx.fillStyle = withAlpha(css('--spec-other'), 0.3);
            data.iperf.forEach(function (b) {
                var x1 = Math.max(area.left, x.getPixelForValue(b.from));
                var x2 = Math.min(area.right, x.getPixelForValue(b.to));
                if (x2 < area.left || x1 > area.right) { return; }
                ctx.fillRect(x1, area.top, Math.max(3, x2 - x1), area.bottom - area.top);
            });
            ctx.restore();
        }
    };

    // Scan und Connection-Test teilen sich das Radio (wifi_lock der Probe):
    // während iperf3 läuft, scannt die Probe nicht. Die vom AP gemeldete
    // Auslastung (BSS Load, über einige Zeit gemittelt) zeigt die Last also
    // erst im ersten Scan danach - deshalb zählt "während oder bis 3 min nach".
    var AFTER_MS = 3 * 60 * 1000;

    function iperfAt(ms) {
        // Bänder sind oft nur Sekunden breit - beim Überfahren großzügig treffen.
        var slack = (data.to - data.from) / 300;
        return data.iperf.filter(function (b) {
            return ms >= b.from - slack && ms <= b.to + Math.max(slack, AFTER_MS);
        });
    }

    // Punkte ohne Nachbarn innerhalb der Lücken-Grenze wären als Linie
    // unsichtbar (kein Segment) - dort einen kleinen Punkt zeichnen.
    function isolatedRadii(points, gap) {
        return points.map(function (p, i) {
            var prev = i > 0 ? p[0] - points[i - 1][0] : Infinity;
            var next = i < points.length - 1 ? points[i + 1][0] - p[0] : Infinity;
            return prev > gap && next > gap ? 2.5 : 0;
        });
    }

    // Wie viele Auslastungs-Messpunkte > 50 % fielen in oder kurz nach eigene
    // iperf3-Tests? Beantwortet "Alltag oder eigene Messung?" als Zahl.
    function spikeStats() {
        var total = 0, own = 0;
        data.util.forEach(function (u) {
            u.points.forEach(function (p) {
                if (p[1] <= 50) { return; }
                total++;
                if (data.iperf.some(function (b) { return p[0] >= b.from && p[0] <= b.to + AFTER_MS; })) { own++; }
            });
        });
        return { total: total, own: own };
    }

    function baseOptions(yTitle, yExtra) {
        return {
            animation: false,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', axis: 'x', intersect: false },
            scales: {
                x: {
                    type: 'linear', min: data.from, max: data.to,
                    ticks: { callback: function (v) { return fmtTick(v); }, maxTicksLimit: 9, maxRotation: 0 },
                    grid: { drawTicks: false }
                },
                y: Object.assign({ title: { display: true, text: yTitle } }, yExtra)
            },
            plugins: {
                legend: { position: 'top', align: 'start', labels: { usePointStyle: true, boxHeight: 6 } },
                tooltip: {
                    callbacks: {
                        title: function (items) { return items.length ? fmtFull(items[0].parsed.x) : ''; },
                        footer: function (items) {
                            if (!items.length) { return ''; }
                            var x = items[0].parsed.x;
                            return iperfAt(x).map(function (b) {
                                var after = x - b.to;
                                var when = after > 5000 ? ' (' + tt('endete %s s vorher', Math.round(after / 1000)) + ')' : '';
                                return 'iperf3 ' + b.ssid + when + ': ↑ ' + (b.up != null ? b.up : '–') + ' / ↓ ' + (b.down != null ? b.down : '–')
                                    + ' Mbit/s' + (b.bitrate ? ' (' + tt('begrenzt auf %s', b.bitrate) + ')' : '') + (b.approx ? ' · ' + tt('Zeitraum geschätzt') : '');
                            });
                        }
                    }
                }
            }
        };
    }

    function draw(id, datasets, options, emptyId) {
        var canvas = document.getElementById(id);
        var empty = document.getElementById(emptyId);
        if (charts[id]) { charts[id].destroy(); delete charts[id]; }
        var has = datasets.some(function (d) { return d.data.length; });
        canvas.parentNode.hidden = !has;
        empty.hidden = has;
        if (has) { charts[id] = new Chart(canvas, { type: 'line', data: { datasets: datasets }, options: options, plugins: [bandsPlugin] }); }
    }

    function render() {
        if (!data) { return; }
        var surface = css('--card-bg');
        var util = [];
        data.util.forEach(function (u, i) {
            var c = slot(i);
            var label = u.label + (u.channel != null ? ' ' + tt('K') + u.channel : '');
            var gap = gapFor(u.points);
            if (u.points.length) {  // Radios ohne BSS Load haben nur Probe-Werte
                util.push({
                    label: label, data: pts(u.points), borderColor: c, backgroundColor: c,
                    borderWidth: 2, pointRadius: isolatedRadii(u.points, gap), pointHitRadius: 8, tension: 0, spanGaps: gap,
                    unit: '%'
                });
            }
            if (u.cirrus.length) {
                util.push({
                    label: label + ' · Cirrus', data: pts(u.cirrus), showLine: false,
                    pointStyle: 'circle', pointRadius: 4, pointHoverRadius: 6, borderWidth: 2,
                    borderColor: c, backgroundColor: surface, unit: '%'
                });
            }
            if (u.probe && u.probe.length) {
                // Grundlast, die die Probe beim Verbinden selbst gemessen hat
                // (survey dump zwischen DHCP und Ping-Ende, kaum eigener Verkehr).
                util.push({
                    label: label + ' · ' + tt('Probe verbunden'), data: pts(u.probe), showLine: false,
                    pointStyle: 'triangle', pointRadius: 5, pointHoverRadius: 7, borderWidth: 1,
                    borderColor: c, backgroundColor: c, unit: '%'
                });
            }
        });
        var utilOpts = baseOptions('%', { min: 0, max: 100 });
        utilOpts.plugins.tooltip.callbacks.label = function (item) {
            return ' ' + item.parsed.y + ' % · ' + item.dataset.label;
        };
        draw('timelineUtil', util, utilOpts, 'timelineUtilEmpty');

        var lat = [];
        data.latency.forEach(function (s) {
            // Farbe je SSID wie im Spektrum: Position in der Ziel-SSID-Liste.
            var i = data.targets.indexOf(s.ssid);
            var c = slot(i < 0 ? MAX_SLOTS : i);
            if (s.ping.length) {
                lat.push({ label: s.ssid + ' · Ping', data: pts(s.ping), borderColor: c, backgroundColor: c,
                    borderWidth: 2, pointRadius: 2, pointHoverRadius: 5, tension: 0, spanGaps: gapFor(s.ping) });
            }
            if (s.http.length) {
                lat.push({ label: s.ssid + ' · HTTP', data: pts(s.http), borderColor: c, backgroundColor: c,
                    borderWidth: 2, borderDash: [5, 4], pointRadius: 2, pointHoverRadius: 5, tension: 0, spanGaps: gapFor(s.http) });
            }
        });
        var latOpts = baseOptions('ms', { beginAtZero: true });
        latOpts.plugins.tooltip.callbacks.label = function (item) {
            return ' ' + item.parsed.y + ' ms · ' + item.dataset.label;
        };
        draw('timelineLatency', lat, latOpts, 'timelineLatencyEmpty');

        var status = document.getElementById('timelineStatus');
        var st = spikeStats();
        var spikes = st.total === 0
            ? tt('keine Auslastung über 50 %')
            : tt('Auslastung über 50 %: %s Messpunkt(e), davon %s während oder bis 3 min nach eigenen iperf3-Tests', st.total, st.own)
              + (st.own === st.total ? ' - ' + tt('alle Spitzen stammen von der eigenen Messung') : '');
        status.textContent = tt('Zeitraum:') + ' ' + fmtRange(data.from, data.to) + ' · '
            + tt('%s iperf3-Test(s) im Zeitraum', data.iperf.length) + ' · ' + spikes
            + (data.scan_sample_every > 1 ? ' · ' + tt('jeder %s. Scan ausgewertet (Ausdünnung bei vielen Scans)', data.scan_sample_every) : '');
    }

    function load() {
        document.querySelectorAll('.timeline-range').forEach(function (b) {
            b.classList.toggle('active', !range && parseInt(b.dataset.hours, 10) === hours);
        });
        document.getElementById('timelineCustom').classList.toggle('active', !!range);
        var status = document.getElementById('timelineStatus');
        status.textContent = tt('Lade …');
        var query = range ? '?from=' + range.from + '&to=' + range.to : '?hours=' + hours;
        fetch(url + query, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (body) {
                    if (!r.ok) { throw new Error(body.detail || 'HTTP ' + r.status); }
                    return body;
                });
            })
            .then(function (d) {
                data = d;
                // Felder zeigen immer den angezeigten Zeitraum - Startpunkt für eigene Eingaben.
                document.getElementById('timelineFrom').value = toInput(d.from);
                document.getElementById('timelineTo').value = toInput(d.to);
                render();
            })
            .catch(function (e) { status.textContent = tt('Verlauf nicht ladbar:') + ' ' + e.message; });
    }

    var RANGE_KEY = 'wlanmon-timeline-hours';

    function init(timelineUrl) {
        url = timelineUrl;
        var buttons = Array.prototype.slice.call(document.querySelectorAll('.timeline-range'));
        var allowed = buttons.map(function (b) { return parseInt(b.dataset.hours, 10); });
        // Zuletzt gewählten Zeitraum übernehmen (pro Browser, nur Komfort).
        try {
            var saved = parseInt(localStorage.getItem(RANGE_KEY), 10);
            if (allowed.indexOf(saved) >= 0) { hours = saved; }
        } catch (e) { /* Storage gesperrt - dann Standard */ }
        buttons.forEach(function (b) {
            b.addEventListener('click', function () {
                hours = parseInt(b.dataset.hours, 10) || 24;
                range = null;
                try { localStorage.setItem(RANGE_KEY, String(hours)); } catch (e) { /* egal */ }
                load();
            });
        });
        // Freier Zeitraum: Eingabe in Ortszeit des Browsers, an den Server als ms.
        document.getElementById('timelineCustom').addEventListener('submit', function (ev) {
            ev.preventDefault();
            var from = new Date(document.getElementById('timelineFrom').value).getTime();
            var to = new Date(document.getElementById('timelineTo').value).getTime();
            if (isNaN(from) || isNaN(to) || from >= to) {
                document.getElementById('timelineStatus').textContent = tt('„Von“ muss vor „Bis“ liegen.');
                return;
            }
            range = { from: from, to: to };
            load();
        });
        // Hell/Dunkel-Wechsel (static/theme.js): Farben neu auflösen.
        document.addEventListener('wlanmon-theme', function () { render(); });
        load();
    }

    window.wlanmonTimeline = { init: init };
})();
