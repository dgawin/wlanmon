<?php
/*
 * Tabelle der Netze eines Scans plus Kanalbelegung (survey dump). Wird erst
 * geladen, wenn der Scan im Tab "Scans" aufgeklappt wird (Route
 * /devices/<id>/measurements/<mid>/scan-table, handle_scan_table_fragment())
 * - alle Scans vorab ins HTML zu schreiben machte die Geräteseite bei vielen
 * Netzen langsam und mehrere MB groß.
 *
 * @var array $sd       Scan-Daten (JSON der Messung)
 * @var array $networks Netze, nach Signal sortiert
 * @var array<string, true> $targetSsids SSIDs, die schon per Connection-Test getestet wurden
 * @var bool $cirrusEnabled
 */
$bandFromFrequency = function (?float $freq): array {
    if ($freq === null) {
        return ['', '–'];
    }
    $band = $freq < 3000 ? '2.4' : ($freq < 5925 ? '5' : '6');
    return [$band, ['2.4' => __('2,4 GHz'), '5' => '5 GHz', '6' => '6 GHz'][$band]];
};
?>
            <div class="table-scroll">
            <table class="scan-table">
                <thead>
                    <tr>
                        <th data-sort="ssid">SSID</th>
                        <th data-sort="bssid">BSSID</th>
                        <th data-sort="signal" data-dir="desc">Signal</th>
                        <th data-sort="band">Band</th>
                        <th data-sort="channel"><?= te('Kanal') ?></th>
                        <th data-sort="gen" title="<?= te('Wi-Fi-Generation und Kanalbreite laut Beacon (HT/VHT/HE/EHT)') ?>"><?= te('Standard') ?></th>
                        <th data-sort="security" title="<?= te('Sicherheit laut RSN-Element (Authentifizierung, PMF = Protected Management Frames)') ?>"><?= te('Sicherheit') ?></th>
                        <th title="<?= te('Roaming-Unterstützung: 11k Radio Measurement (NR = Neighbor Report), 11v BSS Transition, 11r Fast Transition') ?>">Roaming</th>
                        <th data-sort="stations" title="<?= te('Assoziierte Clients laut BSS-Load-Element des AP') ?>">Clients</th>
                        <th data-sort="util" title="<?= te('Kanalauslastung laut BSS-Load-Element (QBSS) des AP') ?>"><?= te('Auslastung (AP)') ?></th>
                        <?php if ($cirrusEnabled): ?>
                        <th data-sort="cirrus" title="<?= te('Kanalauslastung desselben AP-Radios laut OmniVista Cirrus (letzter Sync)') ?>"><?= te('Auslastung (Cirrus)') ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($networks as $n):
                    [$band, $bandLabel] = $bandFromFrequency($n['frequency_mhz'] ?? null);
                    $signalRaw = $n['signal_dbm'] ?? '';
                    $channelRaw = $n['channel'] ?? '';
                    // Nur vorhanden, wenn der AP ein BSS-Load-Element sendet
                    // (und erst ab der Probe-Version mit QBSS-Auswertung).
                    $stationsRaw = $n['station_count'] ?? '';
                    $utilRaw = $n['channel_utilization_pct'] ?? '';
                    // Aktueller Cirrus-Stand (letzter Sync), nicht der Wert zum
                    // Zeitpunkt dieses Scans - bei älteren Scans also nur grob
                    // vergleichbar, siehe Tooltip mit dem Messzeitpunkt.
                    $cirrusRadio = $cirrusEnabled
                        ? cirrus_lookup_radio((string) ($n['bssid'] ?? ''), isset($n['channel']) ? (int) $n['channel'] : null)
                        : null;
                    $cirrusUtilRaw = $cirrusRadio['channel_utilization'] ?? '';
                    $ssidClean = scan_ssid($n['ssid'] ?? null);
                    $ssidVal = $ssidClean !== '' ? $ssidClean : __('(verborgen)');
                    // Stern = diese SSID wurde schon per Connection-Test getestet
                    // (eigenes Ziel-Netz), nicht nur beim Scan gesehen.
                    $isTargetSsid = isset($targetSsids[$ssidVal]);
                ?>
                    <tr data-ssid="<?= e($ssidVal) ?>" data-bssid="<?= e($n['bssid'] ?? '') ?>"
                        data-band="<?= e($band) ?>" data-channel="<?= e($channelRaw) ?>"
                        data-signal="<?= e($signalRaw) ?>"
                        data-stations="<?= e($stationsRaw) ?>" data-util="<?= e($utilRaw) ?>"
                        data-cirrus="<?= e($cirrusUtilRaw) ?>"
                        data-gen="<?= e(scan_caps_sort_gen($n)) ?>" data-security="<?= e($n['security'] ?? '') ?>">
                        <td>
                            <?php if ($isTargetSsid): ?>
                                <i class="fa-solid fa-star" style="color:var(--accent)" title="<?= te('Ziel-SSID - schon per Connection-Test verbunden') ?>"></i>
                            <?php endif; ?>
                            <?= e($ssidVal) ?>
                        </td>
                        <td>
                            <?= e($n['bssid'] ?? '') ?>
                            <?php $nApInfo = cirrus_lookup_ap((string) ($n['bssid'] ?? '')); ?>
                            <?php if (!empty($nApInfo['ap_name'])): ?>
                                <br><span class="muted"><?= e($nApInfo['ap_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $signalRaw !== '' ? e($signalRaw) . ' dBm' : '–' ?></td>
                        <td><?= e($bandLabel) ?></td>
                        <td><?= $channelRaw !== '' ? e($channelRaw) : '–' ?></td>
                        <td><?= format_scan_standard($n) ?></td>
                        <td><?= format_scan_security($n) ?></td>
                        <td><?= format_scan_roaming($n) ?></td>
                        <td><?= $stationsRaw !== '' ? e($stationsRaw) : '–' ?></td>
                        <td><?= $utilRaw !== '' ? e(number_format((float) $utilRaw, 0)) . ' %' : '–' ?></td>
                        <?php if ($cirrusEnabled): ?>
                        <td>
                            <?php if ($cirrusUtilRaw !== ''): ?>
                                <span title="<?= te('Cirrus, gemessen %s', format_local($cirrusRadio['measured_at'] ?? null, 'd.m. H:i')) ?>">
                                    <?= e(number_format((float) $cirrusUtilRaw, 0)) ?> %
                                </span>
                            <?php else: ?>–<?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php
            $surveys = scan_surveys($sd);
            ?>
            <?php if (!empty($surveys)): ?>
            <p class="muted chart-label">
                <?= te('Kanalbelegung aus Sicht der Probe (iw survey dump): Anteil der Messzeit, in der das eigene Radio den Kanal belegt gesehen hat. Je nach Treiber eine kurze Momentaufnahme je Kanal während des Scans oder ein Langzeitmittel (siehe Messdauer).') ?>
            </p>
            <div class="table-scroll">
            <table class="survey-table">
                <thead>
                    <tr>
                        <th><?= te('Kanal') ?></th>
                        <th>Band</th>
                        <th><?= te('Belegt') ?></th>
                        <th><?= te('Messdauer') ?></th>
                        <th><?= te('Rauschen') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($surveys as $cs): [, $csBandLabel] = $bandFromFrequency($cs['frequency_mhz'] ?? null); ?>
                    <tr>
                        <td>
                            <?= isset($cs['channel']) ? e($cs['channel']) : e($cs['frequency_mhz'] ?? '–') . ' MHz' ?>
                            <?php if (!empty($cs['in_use'])): ?><span class="pill pill-muted"><?= te('aktiv') ?></span><?php endif; ?>
                        </td>
                        <td><?= e($csBandLabel) ?></td>
                        <td><?= isset($cs['busy_pct']) ? e(number_format((float) $cs['busy_pct'], 0)) . ' %' : '–' ?></td>
                        <td><?php if (isset($cs['active_ms'])): ?><?= e(format_survey_duration((int) $cs['active_ms'])) ?>
                            <?php if ((int) $cs['active_ms'] >= SURVEY_LONG_MS): ?><span class="muted"><?= te('Langzeitmittel') ?></span><?php endif; ?>
                            <?php else: ?>–<?php endif; ?></td>
                        <td><?= isset($cs['noise_dbm']) ? e($cs['noise_dbm']) . ' dBm' : '–' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
