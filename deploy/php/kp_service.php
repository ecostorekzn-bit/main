<?php

function kp_default_prices() {
    return [
        'mix_premium' => 20000,
        'mix_plus' => 16000,
        'mix_standard' => 12500,
        'yagel_premium' => 19000,
        'yagel_plus' => 17200,
        'yagel_standard' => 15200,
        'plants_art30' => 6500,
        'plants_art50' => 9500,
        'plants_art100' => 15000,
        'plants_stab5' => 5000,
        'plants_stab15' => 9000,
        'plants_stab30' => 14000,
        'frame_rect_mdf' => 800,
        'frame_rect_pine' => 1100,
        'frame_rect_aluminum' => 1600,
        'frame_rect_assembly' => 4000,
        'light_rect_led_hidden' => 850,
        'light_rect_aluminum_profile' => 1150,
        'light_rect_cob' => 1150,
        'light_rect_rgb' => 1350,
        'customFrameColor' => 2000,
        'remoteDimmer' => 2000,
        'depositPercent' => 20,
    ];
}

function kp_round_money($value) {
    return (int)round((float)$value);
}

function kp_calculate_three_variants($input) {
    $prices = kp_default_prices();
    $width = max(1, (float)($input['width_cm'] ?? 0));
    $height = max(1, (float)($input['height_cm'] ?? 0));
    $quantity = max(1, (int)($input['quantity'] ?? 1));
    $group = ($input['group'] ?? 'mix') === 'yagel' ? 'yagel' : 'mix';
    $plants = (string)($input['plants'] ?? 'none');
    $kit = in_array(($input['kit'] ?? 'none'), ['none', 'frame', 'light'], true)
        ? (string)$input['kit'] : 'none';
    $frame = in_array(($input['frame'] ?? 'mdf'), ['mdf', 'pine', 'aluminum'], true)
        ? (string)$input['frame'] : 'mdf';
    $light = in_array(($input['light'] ?? 'led_hidden'), ['led_hidden', 'aluminum_profile', 'cob', 'rgb'], true)
        ? (string)$input['light'] : 'led_hidden';
    $remote = !empty($input['remote']);
    $customFrameColor = !empty($input['custom_frame_color']);

    $area = ($width * $height) / 10000;
    $perimeter = 2 * ($width + $height) / 100;
    $plantPrice = [
        'none' => 0,
        'art30' => $prices['plants_art30'] * $area,
        'art50' => $prices['plants_art50'] * $area,
        'art100' => $prices['plants_art100'] * $area,
        'stab5' => $prices['plants_stab5'],
        'stab15' => $prices['plants_stab15'] * $area,
        'stab30' => $prices['plants_stab30'] * $area,
    ][$plants] ?? 0;

    $frameRate = $prices['frame_rect_' . $frame];
    $frameTotal = $kit === 'none' ? 0 : ($perimeter * $frameRate + $prices['frame_rect_assembly']);
    $lightTotal = $kit === 'light' ? $perimeter * $prices['light_rect_' . $light] : 0;
    $addons = ($remote ? $prices['remoteDimmer'] : 0) +
        ($customFrameColor ? $prices['customFrameColor'] : 0);

    $tiers = [
        ['key' => 'standard', 'name' => 'Стандарт'],
        ['key' => 'plus', 'name' => 'Стандарт+'],
        ['key' => 'premium', 'name' => 'Премиум'],
    ];
    $variants = [];
    foreach ($tiers as $tier) {
        $base = $area * $prices[$group . '_' . $tier['key']];
        $totalOne = $base + $plantPrice + $frameTotal + $lightTotal + $addons;
        $total = $totalOne * $quantity;
        $variants[] = [
            'key' => $tier['key'],
            'name' => $tier['name'],
            'base' => kp_round_money($base * $quantity),
            'plants' => kp_round_money($plantPrice * $quantity),
            'frame' => kp_round_money($frameTotal * $quantity),
            'lighting' => kp_round_money($lightTotal * $quantity),
            'addons' => kp_round_money($addons * $quantity),
            'total' => kp_round_money($total),
            'deposit' => kp_round_money($total * $prices['depositPercent'] / 100),
        ];
    }

    return [
        'input' => [
            'width_cm' => $width,
            'height_cm' => $height,
            'quantity' => $quantity,
            'group' => $group,
            'plants' => $plants,
            'kit' => $kit,
            'frame' => $frame,
            'light' => $light,
            'remote' => $remote,
            'custom_frame_color' => $customFrameColor,
        ],
        'area_m2' => round($area, 4),
        'perimeter_m' => round($perimeter, 3),
        'deposit_percent' => $prices['depositPercent'],
        'variants' => $variants,
        'calculator_version' => 'kp-v10-2026-07-23',
    ];
}

function kp_money($value) {
    return number_format((int)round((float)$value), 0, ',', ' ') . ' ₽';
}

function kp_html_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kp_photo_html($imageDataUri) {
    if ($imageDataUri === '' || strpos($imageDataUri, 'base64,') === false) {
        return '<div class="photo-empty">Выбранный пример панно</div>';
    }
    $parts = explode('base64,', $imageDataUri, 2);
    $binary = base64_decode($parts[1], true);
    $size = is_string($binary) ? @getimagesizefromstring($binary) : false;
    if (!$size || empty($size[0]) || empty($size[1])) {
        return '<div class="photo-empty">Выбранный пример панно</div>';
    }
    $sourceRatio = $size[0] / $size[1];
    $boxRatio = 237 / 76;
    if ($sourceRatio >= $boxRatio) {
        $width = 237;
        $height = 237 / $sourceRatio;
        $left = 0;
        $top = (76 - $height) / 2;
    } else {
        $height = 76;
        $width = 76 * $sourceRatio;
        $left = (237 - $width) / 2;
        $top = 0;
    }
    return '<img src="' . kp_html_escape($imageDataUri) . '" alt="Выбранный пример" style="' .
        'position:absolute;left:' . round($left, 2) . 'mm;top:' . round($top, 2) . 'mm;' .
        'width:' . round($width, 2) . 'mm;height:' . round($height, 2) . 'mm">';
}

function kp_build_html($calculation, $clientName = '') {
    $clientName = trim((string)$clientName);
    $title = 'Панно из стабилизированного мха';
    if ($clientName !== '') $title .= ' для ' . $clientName;
    $input = $calculation['input'];
    $size = rtrim(rtrim(number_format($input['width_cm'], 2, '.', ''), '0'), '.') . '×' .
        rtrim(rtrim(number_format($input['height_cm'], 2, '.', ''), '0'), '.') . ' см';
    $descriptions = [
        'standard' => 'Спокойный фактурный вариант с мягкой стоимостью.',
        'plus' => 'Баланс объёма, выразительности и стоимости.',
        'premium' => 'Максимально объёмная и насыщенная композиция.',
    ];
    $cards = '';
    foreach ($calculation['variants'] as $variant) {
        $recommended = $variant['key'] === 'plus' ? '<div class="badge">РЕКОМЕНДУЕМ</div>' : '';
        $cards .= '<td class="card ' . ($variant['key'] === 'plus' ? 'recommended' : '') . '">' .
            $recommended .
            '<h2>' . kp_html_escape($variant['name']) . '</h2>' .
            '<div class="price">' . kp_money($variant['total']) . '</div>' .
            '<p class="desc">' . kp_html_escape($descriptions[$variant['key']]) . '</p>' .
            '<div class="row"><span>Композиция</span><b>' . kp_money($variant['base'] + $variant['plants']) . '</b></div>' .
            '<div class="row"><span>Рама</span><b>' . kp_money($variant['frame']) . '</b></div>' .
            '<div class="row"><span>Подсветка</span><b>' . kp_money($variant['lighting']) . '</b></div>' .
            ($variant['addons'] > 0 ? '<div class="row"><span>Дополнения</span><b>' . kp_money($variant['addons']) . '</b></div>' : '') .
            '<div class="row deposit"><span>Аванс 20%</span><b>' . kp_money($variant['deposit']) . '</b></div>' .
            '</td>';
    }
    return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #213128; }
        .page { page-break-after: always; }
        .page.first { height: 180mm; position: relative; }
        .page:last-child { page-break-after: auto; }
        .header { height: 18mm; margin: -10mm -10mm 10mm; padding: 7mm 10mm 4mm; background: #173d2a; color: white; }
        .header-table { width: 100%; border-collapse: collapse; }
        .brand { width: 38%; font-size: 16pt; font-weight: bold; }
        .doc-title { width: 62%; font-size: 16pt; font-weight: bold; text-align: right; white-space: nowrap; }
        h1 { margin: 0 0 3mm; color: #173d2a; font-size: 22pt; line-height: 1.18; }
        .intro { margin: 0 0 8mm; font-size: 10pt; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 3mm 0; table-layout: fixed; }
        .first .cards { position: absolute; left: 0; top: 62mm; }
        .card { vertical-align: top; width: 33.33%; padding: 5mm 6mm; background: #edf6ef; }
        .card.recommended { background: #e0f2e4; }
        .badge { margin-bottom: 3mm; padding: 1.5mm; background: #5eac72; color: white; font-size: 7pt; font-weight: bold; text-align: center; }
        .card h2 { margin: 0 0 3mm; color: #173d2a; font-size: 16pt; }
        .price { color: #173d2a; font-size: 21pt; font-weight: bold; margin-bottom: 3mm; }
        .desc { min-height: 12mm; font-size: 8.5pt; line-height: 1.3; }
        .row { clear: both; padding: 1.5mm 0; color: #65736a; font-size: 8pt; }
        .row span { float: left; }
        .row b { float: right; color: #213128; }
        .deposit { margin-top: 2mm; padding-top: 4mm; border-top: 0.3mm solid #b9d5bf; color: #173d2a; font-weight: bold; }
        .foot { margin-top: 5mm; color: #65736a; font-size: 7.5pt; }
        .first .foot { display: none; }
        .second-title { margin: 0 0 9mm; color: #173d2a; font-size: 22pt; }
        .grid { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; table-layout: fixed; }
        .info { vertical-align: top; width: 50%; padding: 7mm; background: #edf6ef; }
        .info h3 { margin: 0 0 5mm; color: #173d2a; font-size: 13pt; }
        .info p { margin: 0; font-size: 9.5pt; line-height: 1.45; }
    </style></head><body>
    <section class="page first">
      <div class="header"><table class="header-table"><tr><td class="brand">ECO-STORE</td><td class="doc-title">КОММЕРЧЕСКОЕ ПРЕДЛОЖЕНИЕ</td></tr></table></div>
      <h1>' . kp_html_escape($title) . '</h1>
      <p class="intro">Размер ' . kp_html_escape($size) . '. Три варианта наполнения в одинаковой комплектации.</p>
      <table class="cards"><tr>' . $cards . '</tr></table>
      <div class="foot">Предварительный расчёт. Итоговая стоимость фиксируется после подтверждения комплектации.</div>
    </section>
    <section class="page">
      <div class="header"><table class="header-table"><tr><td class="brand">ECO-STORE</td><td class="doc-title">КАЗАНЬ · ДОСТАВКА ПО РОССИИ</td></tr></table></div>
      <h1 class="second-title">Что входит в предложение</h1>
      <table class="grid">
        <tr><td class="info"><h3>Материал</h3><p>Натуральный стабилизированный мох собственного производства из Татарстана.</p></td>
        <td class="info"><h3>Гарантия</h3><p>5 лет на сохранность цвета и текстуры ягеля.</p></td></tr>
        <tr><td class="info"><h3>Оплата</h3><p>20% для запуска заказа, остаток после готовности и фото или видео перед отправкой.</p></td>
        <td class="info"><h3>Доставка</h3><p>Надёжная упаковка и отправка по России. Доставка оплачивается отдельно при получении.</p></td></tr>
        <tr><td class="info"><h3>Согласование</h3><p>Перед изготовлением подтверждаем выбранный вариант, размер, раму, подсветку и детали.</p></td>
        <td class="info"><h3>Следующий шаг</h3><p>Напишите менеджеру, какой вариант вам ближе: Стандарт, Стандарт+ или Премиум.</p></td></tr>
      </table>
    </section></body></html>';
}

function kp_asset_data_uri($filename, $mime = 'image/png') {
    $path = __DIR__ . '/assets/' . basename($filename);
    if (!is_file($path)) {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
}

function kp_build_exact_html($calculation, $clientName = '', $imageDataUri = '') {
    $input = $calculation['input'];
    $size = rtrim(rtrim(number_format($input['width_cm'], 2, '.', ''), '0'), '.') . '×' .
        rtrim(rtrim(number_format($input['height_cm'], 2, '.', ''), '0'), '.') . ' см';
    $photo = kp_photo_html($imageDataUri);
    $logo = kp_asset_data_uri('kp_logo.jpg', 'image/jpeg');
    $advantagesPage = kp_asset_data_uri('kp_page_3.png');
    $signature = kp_asset_data_uri('embedded_02_768528095065.png');
    $itemLabel = trim((string)$clientName) !== '' ? ' · ' . kp_html_escape($clientName) : '';
    $kitLabel = ($input['kit'] ?? 'none') === 'light' ? 'рама + подсветка' :
        (($input['kit'] ?? 'none') === 'frame' ? 'рама, без подсветки' : 'без рамы и подсветки');
    $cards = '';
    $orderedVariants = array_reverse($calculation['variants']);
    foreach ($orderedVariants as $index => $variant) {
        $descriptions = [
            'premium' => 'Самый объёмный и выразительный вариант: насыщенная фактура, дорогой визуальный эффект и максимальная глубина композиции.',
            'plus' => 'Красивый баланс фактур: объёмный мох и примерно 20% более спокойного плоского ягеля. Универсальный вариант для большинства задач.',
            'standard' => 'Аккуратный декоративный вариант с мягким балансом цены и красоты: менее объёмный, но всё ещё живой и фактурный визуально.',
        ];
        $cards .= '<div class="price-card c' . $index . '">' .
            '<table class="card-table"><tr><th colspan="2">' . kp_html_escape($variant['name']) .
            ($variant['key'] === 'plus' ? ' <small>Рекомендуем</small>' : '') . '</th></tr>' .
            '<tr><td class="big-price" colspan="2">' . kp_money($variant['total']) . '</td></tr>' .
            '<tr><td>Композиция</td><td>' . kp_money($variant['base'] + $variant['plants']) . '</td></tr>' .
            '<tr><td>Рама</td><td>' . kp_money($variant['frame']) . '</td></tr>' .
            '<tr><td>Подсветка</td><td>' . kp_money($variant['lighting']) . '</td></tr>' .
            '<tr><td>Итого</td><td>' . kp_money($variant['total']) . '</td></tr>' .
            '<tr class="advance"><td>Мин. аванс 20%</td><td>' . kp_money($variant['deposit']) . '</td></tr></table>' .
            '<div class="card-desc">' . kp_html_escape($descriptions[$variant['key']] ?? '') . '</div>' .
            '</div>';
    }
    return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>
      @page{size:A4 landscape;margin:0}
      *{box-sizing:border-box}body{margin:0;font-family:DejaVu Sans,sans-serif;color:#142216}
      .kp-page{width:297mm;height:210mm;position:relative;overflow:hidden;page-break-after:always;background:#fff}
      .kp-page:last-child{page-break-after:auto}
      .head-band{position:absolute;left:6.6mm;top:6.5mm;width:284mm;height:20mm;background:#fff}
      .main-title{position:absolute;left:6.6mm;top:10.5mm;font-size:9mm;line-height:1;font-weight:900}
      .date{position:absolute;left:6.6mm;top:22mm;font-size:3mm;color:#69756c}
      .logo{position:absolute;right:10mm;top:6.8mm;width:54mm;height:20.5mm;background:#fff;border:.3mm solid #e0ebe2;
        color:#173d2a;text-align:center;font-size:5mm;font-weight:900;overflow:hidden}
      .logo img{width:50mm;height:20.4mm}
      .calc-title{position:absolute;left:6.6mm;top:31.8mm;font-size:6.2mm;font-weight:900}
      .calc-note{position:absolute;left:6.6mm;top:39.3mm;font-size:3.15mm;color:#1f2b23}
      .config{position:absolute;left:6.6mm;top:44mm;width:280mm;font-size:2.5mm;color:#6b8570}
      .config b{color:#1f2b23;margin-right:7mm}
      .hero{position:absolute;left:30mm;top:50mm;width:237mm;height:76mm;border-radius:5mm;overflow:hidden;background:#edf8ee;text-align:center}
      .photo-empty{padding-top:34mm;color:#6d7d71;font-size:4mm;font-weight:900}
      .price-card{position:absolute;top:131mm;width:88mm;height:68mm;border:.35mm solid #d6e8d8;border-radius:6mm;background:#fff}
      .price-card.c0{left:6.6mm}.price-card.c1{left:104.5mm;border-color:#e1b94d}.price-card.c2{left:202.4mm}
      .card-table{width:74mm;margin:3mm 7mm;border-collapse:collapse}
      .card-table th{text-align:left;font-size:5mm;line-height:6mm;font-weight:900;white-space:nowrap;padding:0 0 1.2mm}
      .card-table th small{color:#9a7200;font-size:1.8mm;font-weight:700;margin-left:2mm}
      .card-table td{font-size:2.05mm;color:#66746a;padding:.55mm 0;border-bottom:.2mm dashed #d8e5da}
      .card-table td:last-child{text-align:right;color:#354638;font-weight:700}
      .card-table .big-price{font-size:8mm;line-height:10mm;font-weight:900;color:#087a29;text-align:left;
        white-space:nowrap;border:0;padding:0 0 1.5mm}
      .card-table .advance td{border-bottom:0;color:#173d2a;font-weight:900}
      .card-desc{position:absolute;left:7mm;right:7mm;bottom:3mm;font-size:1.95mm;line-height:1.15;color:#303b32}
      .variant-name{position:absolute;left:7mm;right:7mm;top:5mm;font-size:5.8mm;font-weight:900;white-space:nowrap}
      .recommend{color:#9a7200;font-size:1.8mm;font-weight:700;margin-left:2mm}
      .variant-price{position:absolute;left:7mm;right:7mm;top:20mm;font-size:8mm;font-weight:900;color:#087a29;white-space:nowrap}
      .variant-unit{display:none}
      .line{position:absolute;left:7mm;right:7mm;padding:0 0 .7mm;border-bottom:.2mm dashed #d8e5da;font-size:2.35mm;color:#66746a}
      .line span{float:left}.line b{float:right;color:#354638}.line.l1{top:31mm}.line.l2{top:38mm}.line.l3{top:45mm}
      .line.l1{top:41mm}.line.l2{top:47mm}.line.l3{top:53mm}
      .line.l4{top:59mm;border-top:.25mm solid #bad8bf;border-bottom:0;padding-top:2mm;color:#173d2a;font-weight:900}
      .page2-title{position:absolute;left:6.6mm;top:34mm;font-size:7mm;font-weight:900}
      .info{position:absolute;width:137mm;height:37mm;background:#eef8ef;border:.3mm solid #d6e8d8;border-radius:4mm;padding:6mm}
      .info h3{margin:0 0 4mm;font-size:4.8mm;color:#168037}.info p{margin:0;font-size:3.2mm;line-height:1.35}
      .i1{left:6.6mm;top:52mm}.i2{left:153.4mm;top:52mm}.i3{left:6.6mm;top:96mm}.i4{left:153.4mm;top:96mm}
      .i5{left:6.6mm;top:140mm}.i6{left:153.4mm;top:140mm}
      .choose-title{position:absolute;left:6.6mm;top:33mm;font-size:7mm;color:#168037;font-weight:900}
      .choose-note{position:absolute;left:6.6mm;top:44mm;font-size:3mm;color:#303b32}
      .choice{position:absolute;top:64mm;width:76.4mm;height:26.6mm;border:.3mm solid #d6e8d8;border-radius:4mm;
        padding:7mm 5.2mm;background:#fff}
      .choice.p0{left:6.6mm}.choice.p1{left:105.2mm}.choice.p2{left:203.8mm}
      .choice h3{margin:0 0 4mm;color:#168037;font-size:5mm}.choice p{margin:0;font-size:3.4mm;line-height:1.35}
      .static-page{width:297mm;height:210mm}
      .static-head-cover{position:absolute;left:0;top:0;width:297mm;height:32mm;background:#fff}
      .validity{position:absolute;left:6.6mm;top:28mm;color:#168037;font-size:5.3mm;font-weight:900}
      .final-title{position:absolute;left:6.6mm;top:38mm;font-size:6.8mm;font-weight:900}
      .final-cards{position:absolute;left:6.6mm;top:49mm;width:284mm}
      .final-card{display:inline-block;vertical-align:top;width:54mm;height:23mm;margin-right:2mm;border:.3mm solid #d6e8d8;
        border-radius:4mm;padding:5mm;background:#fff}
      .final-card h3{margin:0 0 3mm;color:#168037;font-size:4.6mm}.final-card p{margin:0;font-size:2.7mm;line-height:1.3}
      .continue{position:absolute;left:6.6mm;top:87mm;width:272mm;height:23mm;background:#208b3b;color:#fff;border-radius:6mm;padding:6mm}
      .continue h3{margin:0 0 2mm;font-size:5.5mm}.continue p{margin:0;width:160mm;font-size:3mm;line-height:1.4}
      .pill{position:absolute;right:11mm;top:100mm;width:95mm;height:13mm;border:.3mm solid #fff;border-radius:8mm;
        background:#6eb47c;color:#fff;text-align:center;padding-top:3.4mm;font-size:3mm;font-weight:900}
      .after{position:absolute;left:6.6mm;top:126mm;width:130mm;height:34mm;border:.3mm solid #d6e8d8;border-radius:4mm;padding:5mm}
      .after h3{margin:0 0 2mm;color:#168037;font-size:4mm}.after p,.after li{font-size:2.4mm;line-height:1.35}
      .after ul{margin:2mm 0 0;padding-left:5mm}.sign{position:absolute;right:11mm;top:126mm;width:112mm;height:67mm;overflow:hidden}
      .sign img{width:100%;height:100%;object-fit:contain}.footnote{position:absolute;left:6.6mm;bottom:6mm;font-size:2.2mm;color:#718077}
    </style></head><body>
      <section class="kp-page">
        <div class="head-band"></div><div class="main-title">Коммерческое предложение</div>
        <div class="date">КП от ' . date('d.m.Y') . '</div><div class="logo">' . ($logo ? '<img src="' . $logo . '" alt="Eco-Store">' : 'ECO-STORE') . '</div>
        <div class="calc-title">Расчёт на изделие · ' . kp_html_escape($size) . $itemLabel . '</div>
        <div class="calc-note">Одна выбранная композиция в трёх вариантах наполнения.</div>
        <div class="config"><span>Основа:</span> <b>Микс мха</b><span>Комплектация:</span> <b>' . kp_html_escape($kitLabel) . '</b></div>
        <div class="hero">' . $photo . '</div>' . $cards . '
      </section>
      <section class="kp-page">
        <div class="head-band"></div><div class="main-title">Коммерческое предложение</div><div class="logo">' . ($logo ? '<img src="' . $logo . '" alt="Eco-Store">' : 'ECO-STORE') . '</div>
        <div class="choose-title">Какой вариант выбрать?</div>
        <div class="choose-note">Ниже - короткие подсказки по вариантам. Напишите мне, какой вариант вам ближе - я пришлю дополнительные фото и видео.</div>
        <div class="choice p0"><h3>Премиум</h3><p>Когда нужен самый выразительный, дорогой и объёмный визуальный эффект.</p></div>
        <div class="choice p1"><h3>Стандарт+</h3><p>Оптимальный баланс цены, фактуры и презентабельности.</p></div>
        <div class="choice p2"><h3>Стандарт</h3><p>Аккуратный вариант без переплаты, когда важно сохранить бюджет.</p></div>
      </section>
      <section class="kp-page">' .
        ($advantagesPage ? '<img class="static-page" src="' . $advantagesPage . '" alt="Наши преимущества">' :
        '<div class="main-title">Наши преимущества</div>') . '
        <div class="static-head-cover"></div><div class="main-title">Коммерческое предложение</div>
        <div class="logo">' . ($logo ? '<img src="' . $logo . '" alt="Eco-Store">' : 'ECO-STORE') . '</div>
      </section>
      <section class="kp-page">
        <div class="head-band"></div><div class="main-title">Коммерческое предложение</div><div class="logo">' . ($logo ? '<img src="' . $logo . '" alt="Eco-Store">' : 'ECO-STORE') . '</div>
        <div class="validity">Предложение действительно до ' . date('d.m.Y', strtotime('+7 days')) . '</div>
        <div class="final-title">Комплектация в этом расчёте</div>
        <div class="final-cards">
          <div class="final-card"><h3>Рама</h3><p>МДФ, цвет согласовывается с клиентом.</p></div>
          <div class="final-card"><h3>Подсветка</h3><p>LED скрытая подсветка.</p></div>
          <div class="final-card"><h3>Доставка</h3><p>Надёжная упаковка и отправка по России. Оплата при получении.</p></div>
          <div class="final-card"><h3>Монтаж</h3><p>Подробная видеоинструкция. Панели собираются как пазл.</p></div>
        </div>
        <div class="continue"><h3>Как продолжить?</h3><p>Ответьте в мессенджере, какой вариант вам ближе. Я пришлю дополнительные фото и видео и помогу определиться окончательно.</p></div>
        <div class="pill">Напишите: какой вариант выбираете</div>
        <div class="after"><h3>После выбора варианта</h3><p>В финале сверяем комплектацию, размер, цвет рамы и подсветку.</p>
          <ul><li>после аванса передаём заказ в производство;</li><li>присылаем фото и видео раскладки;</li><li>отправляем согласованный вариант.</li></ul>
        </div>
        <div class="sign">' . ($signature ? '<img src="' . $signature . '" alt="Подпись и печать">' : '') . '</div>
        <div class="footnote">Расчёт предварительный. Финальная стоимость фиксируется в счёте после подтверждения комплектации.</div>
      </section>
    </body></html>';
}

function kp_build_multi_exact_html($items, $imageDataUri = '') {
    if (!$items) throw new InvalidArgumentException('kp_items_missing');
    $documents = [];
    foreach ($items as $index => $item) {
        $documents[] = kp_build_exact_html(
            $item['calculation'],
            $item['label'] ?? ('Вариант ' . ($index + 1)),
            $imageDataUri
        );
    }
    $head = '';
    if (!preg_match('/^(.*?<body>)/s', $documents[0], $headMatch)) {
        throw new RuntimeException('kp_multi_head_missing');
    }
    $head = $headMatch[1];
    $firstPages = [];
    $commonPages = [];
    foreach ($documents as $documentIndex => $document) {
        preg_match_all('/<section class="kp-page">.*?<\/section>/s', $document, $matches);
        if (empty($matches[0][0])) throw new RuntimeException('kp_multi_page_missing');
        $firstPages[] = $matches[0][0];
        if ($documentIndex === 0) $commonPages = array_slice($matches[0], 1);
    }
    return $head . implode('', $firstPages) . implode('', $commonPages) . '</body></html>';
}

function kp_render_pdf($calculation, $clientName = '', $imageDataUri = '') {
    $autoload = __DIR__ . '/vendor/dompdf/autoload.inc.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('dompdf_missing');
    }
    require_once $autoload;
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml(kp_build_exact_html($calculation, $clientName, $imageDataUri), 'UTF-8');
    $dompdf->render();
    return $dompdf->output();
}

function kp_render_multi_pdf($items, $imageDataUri = '') {
    $autoload = __DIR__ . '/vendor/dompdf/autoload.inc.php';
    if (!is_file($autoload)) throw new RuntimeException('dompdf_missing');
    require_once $autoload;
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml(kp_build_multi_exact_html($items, $imageDataUri), 'UTF-8');
    $dompdf->render();
    return $dompdf->output();
}
