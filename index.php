<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/examples/Templates.php';

use IvoPdf\Examples\Templates;
use IvoPdf\IvoPdf;

/**
 * Does $ip match $rule (a single IP or an IPv4/IPv6 CIDR)?
 */
function ivopdfIpMatches(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') {
        return false;
    }
    $packedIp = @inet_pton($ip);
    if ($packedIp === false) {
        return false;
    }
    $bits = null;
    if (str_contains($rule, '/')) {
        [$rule, $bitsStr] = explode('/', $rule, 2);
        if (!ctype_digit($bitsStr)) {
            return false;
        }
        $bits = (int) $bitsStr;
    }
    $packedRule = @inet_pton($rule);
    if ($packedRule === false || strlen($packedRule) !== strlen($packedIp)) {
        return false;
    }
    $max = strlen($packedIp) * 8;
    $bits = $bits ?? $max;
    if ($bits > $max) {
        return false;
    }
    $fullBytes = intdiv($bits, 8);
    if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedRule, 0, $fullBytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return ((ord($packedIp[$fullBytes]) ^ ord($packedRule[$fullBytes])) & $mask) === 0;
}

/**
 * Client address. X-Forwarded-For is honoured ONLY when REMOTE_ADDR is listed in
 * IVOPDF_TRUSTED_PROXIES (comma-separated IPs/CIDRs); then the right-most
 * address that is not itself a trusted proxy is used.
 */
function ivopdfClientIp(array $server, string $trustedProxies): string
{
    $remote = (string) ($server['REMOTE_ADDR'] ?? '');
    if ($remote === '') {
        return '127.0.0.1';
    }
    $rules = array_filter(array_map('trim', explode(',', $trustedProxies)), static fn ($r) => $r !== '');
    $isTrusted = static function (string $ip) use ($rules): bool {
        foreach ($rules as $rule) {
            if (ivopdfIpMatches($ip, $rule)) {
                return true;
            }
        }
        return false;
    };
    if ($rules === [] || !$isTrusted($remote)) {
        return $remote;
    }
    $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff === '') {
        return $remote;
    }
    $hops = array_map('trim', explode(',', $xff));
    for ($i = count($hops) - 1; $i >= 0; $i--) {
        $hop = $hops[$i];
        if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
            // Malformed entry: do not guess; treat it as an untrusted (non-local) client.
            return 'invalid';
        }
        if (!$isTrusted($hop)) {
            return $hop;
        }
    }
    return $remote;
}

$clientIp = ivopdfClientIp($_SERVER, (string) getenv('IVOPDF_TRUSTED_PROXIES'));
$isLocalRequest = in_array($clientIp, ['127.0.0.1', '::1'], true);
if (!$isLocalRequest && getenv('IVOPDF_ALLOW_PUBLIC_STUDIO') !== '1') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden: IvoPDF Studio is restricted to localhost by default.';
    exit;
}

// Language: ?lang=bg|en wins; otherwise English only if the browser's primary language is English.
$languages = Templates::languages();
$langParam = isset($_GET['lang']) ? (string) $_GET['lang'] : null;
if ($langParam !== null && isset($languages[strtolower($langParam)])) {
    $lang = strtolower($langParam);
} else {
    $acceptLanguage = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));
    $lang = str_starts_with($acceptLanguage, 'en') ? 'en' : 'bg';
}

$templates = Templates::all($lang);

$ui = [
    'bg' => [
        'pageTitle' => 'IvoPDF Studio — Чист PHP HTML & CSS (Flexbox + Grid) към PDF',
        'tagline' => 'Чист PHP Двигател • Flexbox • CSS Grid • Таблици • Снимки & SVG • Кирилица',
        'template' => 'Шаблон:',
        'language' => 'Език',
        'portrait' => 'Portrait (Портрет)',
        'landscape' => 'Landscape (Пейзаж)',
        'render' => '⚡ Рендерирай PDF (Ctrl+Enter)',
        'rendering' => '⏳ Генериране...',
        'download' => '⬇ Изтегли PDF',
        'editorBar' => 'HTML + CSS Редактор (Поддържа <code>display: flex</code>, <code>display: grid</code>, <code>&lt;table&gt;</code>, <code>&lt;svg&gt;</code>, <code>&lt;barcode&gt;</code>)',
        'encoding' => 'UTF-8 / Кирилица',
        'footerLabel' => 'Running Footer HTML:',
        'footerPlaceholder' => 'Остави празно или ползвай {PAGE_NUM} и {PAGE_COUNT}',
        'previewBar' => 'Нативен PDF 1.7 Изглед в реално време',
        'pagesUnit' => 'стр.',
        'pagesUnitOne' => 'стр.',
        'previewTitle' => 'PDF преглед',
        'errorPrefix' => 'Грешка при генериране на PDF: ',
        'errorBadResponse' => 'Сървърът върна невалиден отговор (HTTP {status}).',
        'errorUnknown' => 'Неизвестна грешка.',
        'noPdfYet' => 'Все още няма генериран PDF.',
    ],
    'en' => [
        'pageTitle' => 'IvoPDF Studio — Pure-PHP HTML & CSS (Flexbox + Grid) to PDF',
        'tagline' => 'Pure-PHP Engine • Flexbox • CSS Grid • Tables • Images & SVG • Cyrillic',
        'template' => 'Template:',
        'language' => 'Language',
        'portrait' => 'Portrait',
        'landscape' => 'Landscape',
        'render' => '⚡ Render PDF (Ctrl+Enter)',
        'rendering' => '⏳ Rendering...',
        'download' => '⬇ Download PDF',
        'editorBar' => 'HTML + CSS Editor (supports <code>display: flex</code>, <code>display: grid</code>, <code>&lt;table&gt;</code>, <code>&lt;svg&gt;</code>, <code>&lt;barcode&gt;</code>)',
        'encoding' => 'UTF-8 / Cyrillic',
        'footerLabel' => 'Running Footer HTML:',
        'footerPlaceholder' => 'Leave empty or use {PAGE_NUM} and {PAGE_COUNT}',
        'previewBar' => 'Native PDF 1.7 live preview',
        'pagesUnit' => 'pages',
        'pagesUnitOne' => 'page',
        'previewTitle' => 'PDF preview',
        'errorPrefix' => 'PDF generation failed: ',
        'errorBadResponse' => 'The server returned an invalid response (HTTP {status}).',
        'errorUnknown' => 'Unknown error.',
        'noPdfYet' => 'No PDF has been generated yet.',
    ],
][$lang];

// Handle AJAX PDF Generation or Direct Download
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($_GET['template_pdf'])) {
    $action = $_GET['action'] ?? 'json';
    $downloadName = 'ivopdf-document.pdf';

    try {
        if (isset($_GET['template_pdf'])) {
            $tplKey = (string) $_GET['template_pdf'];
            if (!isset($templates[$tplKey])) {
                $tplKey = 'invoice';
            }
            $tpl = $templates[$tplKey];
            $html = $tpl['html'];
            $paper = $tpl['paper'];
            $orientation = $tpl['orientation'];
            $footer = $tpl['footer'];
            $action = 'stream';
            $downloadName = 'ivopdf-' . $tplKey . '-' . $lang . '.pdf';
        } else {
            $rawBody = (string) file_get_contents('php://input', false, null, 0, 524288);
            $payload = json_decode($rawBody, true);
            if (!is_array($payload)) {
                $payload = $_POST;
            }
            $html = (string) ($payload['html'] ?? $templates['invoice']['html']);
            $paper = (string) ($payload['paper'] ?? 'A4');
            $orientation = (string) ($payload['orientation'] ?? 'portrait');
            $footer = (string) ($payload['footer'] ?? '');
        }

        $t0 = microtime(true);
        $pdf = new IvoPdf([
            'paperSize' => $paper,
            'orientation' => $orientation,
            'isRemoteEnabled' => false,
            'allowedPaths' => [
                __DIR__ . '/examples',
                __DIR__ . '/resources',
            ],
        ]);
        if (trim($footer) !== '') {
            $pdf->setFooterHtml($footer);
        }
        $binary = $pdf->loadHtml($html)->output();
        $elapsedMs = round((microtime(true) - $t0) * 1000, 1);

        if ($action === 'stream') {
            $pdf->stream($downloadName, ['Attachment' => isset($_GET['download'])]);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'pdfBase64' => base64_encode($binary),
            'renderTimeMs' => $elapsedMs,
            'pageCount' => $pdf->getPageCount(),
            'sizeKb' => round(strlen($binary) / 1024, 1),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            // Internal details (paths, library messages) only on localhost.
            'error' => $isLocalRequest ? $e->getMessage() : 'PDF generation failed.',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$templatesJson = json_encode($templates, $jsonFlags);
$uiJson = json_encode($ui, $jsonFlags);
$langJson = json_encode($lang, $jsonFlags);
$languagesJson = json_encode(array_keys($languages), $jsonFlags);
$initialTemplate = isset($_GET['template'], $templates[(string) $_GET['template']]) ? (string) $_GET['template'] : 'invoice';
$initialTemplateJson = json_encode($initialTemplate, $jsonFlags);
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $esc($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esc($ui['pageTitle']) ?></title>
    <script>
        // Restore the remembered language when the URL does not specify one.
        (function () {
            try {
                var params = new URLSearchParams(window.location.search);
                var stored = window.localStorage.getItem('ivopdf.lang');
                if (!params.has('lang') && stored && stored !== <?= $langJson ?> && <?= $languagesJson ?>.indexOf(stored) !== -1) {
                    params.set('lang', stored);
                    window.location.replace(window.location.pathname + '?' + params.toString());
                }
            } catch (e) { /* storage unavailable: keep server-side choice */ }
        })();
    </script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: #0b0f19;
            color: #e2e8f0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            background: #111827;
            border-bottom: 1px solid #1e293b;
            gap: 12px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .logo-badge {
            background: linear-gradient(135deg, #2563eb, #06b6d4);
            color: #fff;
            font-weight: 800;
            font-size: 15px;
            padding: 6px 11px;
            border-radius: 8px;
            letter-spacing: 0.5px;
        }
        .brand h1 {
            font-size: 16px;
            font-weight: 700;
            color: #f8fafc;
        }
        .brand span {
            font-size: 12px;
            color: #94a3b8;
        }
        .toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        select, input[type="text"] {
            background: #1e293b;
            color: #f8fafc;
            border: 1px solid #334155;
            padding: 7px 11px;
            border-radius: 6px;
            font-size: 13px;
            outline: none;
        }
        select:focus, input:focus { border-color: #38bdf8; }
        .btn {
            background: linear-gradient(135deg, #2563eb, #0284c7);
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: opacity 0.15s;
        }
        .btn:hover { opacity: 0.92; }
        .btn-secondary {
            background: #1e293b;
            border: 1px solid #334155;
            color: #e2e8f0;
        }
        .lang-switch {
            display: inline-flex;
            border: 1px solid #334155;
            border-radius: 6px;
            overflow: hidden;
        }
        .lang-switch button {
            background: #1e293b;
            color: #94a3b8;
            border: none;
            padding: 7px 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .lang-switch button + button { border-left: 1px solid #334155; }
        .lang-switch button[aria-pressed="true"] {
            background: linear-gradient(135deg, #2563eb, #0284c7);
            color: #fff;
            cursor: default;
        }
        .workspace {
            flex: 1;
            display: grid;
            grid-template-columns: 46% 54%;
            height: calc(100vh - 57px);
        }
        .editor-pane {
            display: flex;
            flex-direction: column;
            border-right: 1px solid #1e293b;
            background: #0f172a;
        }
        .pane-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 14px;
            background: #162033;
            border-bottom: 1px solid #1e293b;
            font-size: 12px;
            color: #94a3b8;
        }
        textarea#htmlEditor {
            flex: 1;
            width: 100%;
            background: #0b0f19;
            color: #e2e8f0;
            border: none;
            padding: 14px;
            font-family: 'Consolas', 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 12.5px;
            line-height: 1.5;
            resize: none;
            outline: none;
        }
        .footer-input-bar {
            padding: 8px 14px;
            background: #111827;
            border-top: 1px solid #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }
        .footer-input-bar input { flex: 1; }
        .preview-pane {
            display: flex;
            flex-direction: column;
            background: #1e293b;
        }
        .metrics {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .metric-pill {
            background: #0f172a;
            border: 1px solid #334155;
            color: #38bdf8;
            padding: 3px 9px;
            border-radius: 99px;
            font-size: 11.5px;
            font-weight: 600;
        }
        .error-bar {
            display: none;
            padding: 8px 14px;
            background: #450a0a;
            border-bottom: 1px solid #7f1d1d;
            color: #fecaca;
            font-size: 12.5px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .error-bar.visible { display: block; }
        iframe#pdfFrame {
            flex: 1;
            width: 100%;
            border: none;
            background: #334155;
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <div class="logo-badge">IvoPDF</div>
            <div>
                <h1>IvoPDF Playground</h1>
                <span><?= $esc($ui['tagline']) ?></span>
            </div>
        </div>
        <div class="toolbar">
            <div class="lang-switch" role="group" aria-label="<?= $esc($ui['language']) ?>">
                <?php foreach ($languages as $code => $name): ?>
                    <button type="button" data-lang="<?= $esc($code) ?>" lang="<?= $esc($code) ?>" title="<?= $esc($name) ?>" aria-pressed="<?= $code === $lang ? 'true' : 'false' ?>"><?= $esc(strtoupper($code)) ?></button>
                <?php endforeach; ?>
            </div>

            <label for="templateSelect" style="font-size:12px; color:#94a3b8;"><?= $esc($ui['template']) ?></label>
            <select id="templateSelect">
                <?php foreach ($templates as $key => $tpl): ?>
                    <option value="<?= $esc($key) ?>"<?= $key === $initialTemplate ? ' selected' : '' ?>><?= $esc($tpl['title']) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="paperSelect">
                <option value="A4">A4</option>
                <option value="A3">A3</option>
                <option value="A5">A5</option>
                <option value="LETTER">Letter</option>
            </select>

            <select id="orientationSelect">
                <option value="portrait"><?= $esc($ui['portrait']) ?></option>
                <option value="landscape"><?= $esc($ui['landscape']) ?></option>
            </select>

            <button class="btn" id="renderBtn" type="button"><?= $esc($ui['render']) ?></button>
            <button class="btn btn-secondary" id="downloadBtn" type="button"><?= $esc($ui['download']) ?></button>
        </div>
    </header>

    <div class="workspace">
        <div class="editor-pane">
            <div class="pane-bar">
                <span><?= $ui['editorBar'] /* trusted static markup */ ?></span>
                <span><?= $esc($ui['encoding']) ?></span>
            </div>
            <textarea id="htmlEditor" spellcheck="false"></textarea>
            <div class="footer-input-bar">
                <span style="color:#94a3b8; white-space:nowrap;"><?= $esc($ui['footerLabel']) ?></span>
                <input type="text" id="footerEditor" placeholder="<?= $esc($ui['footerPlaceholder']) ?>" />
            </div>
        </div>

        <div class="preview-pane">
            <div class="pane-bar">
                <span><?= $esc($ui['previewBar']) ?></span>
                <div class="metrics">
                    <span class="metric-pill" id="metricTime">⏱ -- ms</span>
                    <span class="metric-pill" id="metricPages">📄 -- <?= $esc($ui['pagesUnit']) ?></span>
                    <span class="metric-pill" id="metricSize">📦 -- KB</span>
                </div>
            </div>
            <div class="error-bar" id="errorBar" role="alert"></div>
            <iframe id="pdfFrame" title="<?= $esc($ui['previewTitle']) ?>"></iframe>
        </div>
    </div>

    <script>
        const LANG = <?= $langJson ?>;
        const UI = <?= $uiJson ?>;
        const TEMPLATES = <?= $templatesJson ?>;
        const templateSelect = document.getElementById('templateSelect');
        const paperSelect = document.getElementById('paperSelect');
        const orientationSelect = document.getElementById('orientationSelect');
        const htmlEditor = document.getElementById('htmlEditor');
        const footerEditor = document.getElementById('footerEditor');
        const renderBtn = document.getElementById('renderBtn');
        const downloadBtn = document.getElementById('downloadBtn');
        const pdfFrame = document.getElementById('pdfFrame');
        const metricTime = document.getElementById('metricTime');
        const metricPages = document.getElementById('metricPages');
        const metricSize = document.getElementById('metricSize');
        const errorBar = document.getElementById('errorBar');

        let currentPdfBlobUrl = null;

        try { window.localStorage.setItem('ivopdf.lang', LANG); } catch (e) { /* ignore */ }

        function syncUrl() {
            try {
                const params = new URLSearchParams(window.location.search);
                params.set('lang', LANG);
                params.set('template', templateSelect.value);
                window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
            } catch (e) { /* ignore */ }
        }

        function showError(message, withPrefix = true) {
            errorBar.textContent = (withPrefix ? UI.errorPrefix : '') + (message || UI.errorUnknown);
            errorBar.classList.add('visible');
        }

        function clearError() {
            errorBar.textContent = '';
            errorBar.classList.remove('visible');
        }

        function loadTemplate(key) {
            const tpl = TEMPLATES[key];
            if (!tpl) return;
            htmlEditor.value = tpl.html;
            paperSelect.value = tpl.paper || 'A4';
            orientationSelect.value = tpl.orientation || 'portrait';
            footerEditor.value = tpl.footer || '';
            syncUrl();
            renderPdf();
        }

        async function renderPdf() {
            renderBtn.disabled = true;
            renderBtn.textContent = UI.rendering;
            try {
                const res = await fetch('index.php?action=json&lang=' + encodeURIComponent(LANG), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        html: htmlEditor.value,
                        paper: paperSelect.value,
                        orientation: orientationSelect.value,
                        footer: footerEditor.value
                    })
                });
                const text = await res.text();
                let data = null;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    data = null;
                }
                if (!data || typeof data !== 'object') {
                    const snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
                    showError(UI.errorBadResponse.replace('{status}', String(res.status)) + (snippet ? '\n' + snippet : ''));
                    return;
                }
                if (!data.ok || !data.pdfBase64) {
                    showError(data.error ? String(data.error) : UI.errorBadResponse.replace('{status}', String(res.status)));
                    return;
                }

                clearError();
                const byteChars = atob(data.pdfBase64);
                const byteArray = new Uint8Array(byteChars.length);
                for (let i = 0; i < byteChars.length; i++) {
                    byteArray[i] = byteChars.charCodeAt(i);
                }
                const blob = new Blob([byteArray], { type: 'application/pdf' });
                if (currentPdfBlobUrl) {
                    URL.revokeObjectURL(currentPdfBlobUrl);
                }
                currentPdfBlobUrl = URL.createObjectURL(blob);
                pdfFrame.src = currentPdfBlobUrl;

                metricTime.textContent = `⏱ ${data.renderTimeMs} ms`;
                metricPages.textContent = `📄 ${data.pageCount} ${data.pageCount === 1 ? UI.pagesUnitOne : UI.pagesUnit}`;
                metricSize.textContent = `📦 ${data.sizeKb} KB`;
            } catch (err) {
                console.error(err);
                showError(err && err.message ? err.message : String(err));
            } finally {
                renderBtn.disabled = false;
                renderBtn.textContent = UI.render;
            }
        }

        document.querySelectorAll('.lang-switch button[data-lang]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const target = btn.getAttribute('data-lang');
                if (!target || target === LANG) return;
                try { window.localStorage.setItem('ivopdf.lang', target); } catch (e) { /* ignore */ }
                const params = new URLSearchParams(window.location.search);
                params.set('lang', target);
                params.set('template', templateSelect.value);
                window.location.href = window.location.pathname + '?' + params.toString();
            });
        });

        templateSelect.addEventListener('change', () => loadTemplate(templateSelect.value));
        paperSelect.addEventListener('change', renderPdf);
        orientationSelect.addEventListener('change', renderPdf);
        renderBtn.addEventListener('click', renderPdf);

        downloadBtn.addEventListener('click', () => {
            if (!currentPdfBlobUrl) {
                showError(UI.noPdfYet, false);
                return;
            }
            const a = document.createElement('a');
            a.href = currentPdfBlobUrl;
            a.download = `ivopdf-${templateSelect.value}-${LANG}.pdf`;
            a.click();
        });

        window.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                renderPdf();
            }
        });

        loadTemplate(<?= $initialTemplateJson ?>);
    </script>
</body>
</html>
