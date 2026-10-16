<?php

declare(strict_types=1);

namespace IvoPdf\Examples;

/**
 * Ready-to-use showcase templates demonstrating IvoPDF's Flexbox, CSS Grid, Tables,
 * Images, SVG vector graphics, Barcodes, Gradients, and Cyrillic UTF-8 typography.
 *
 * Every template exists in Bulgarian ('bg') and English ('en'). The markup and CSS are
 * written once per template (see layouts()); translatable text lives in per-language
 * string tables (see strings()) and is injected through placeholders:
 *
 *   {{t:key}}  plain text, HTML-escaped on insertion
 *   {{h:key}}  trusted inline HTML from the string table, inserted as-is
 *   {{lang}}   the language code (used for <html lang="…">)
 *   {{png}}    sample transparent PNG data URI
 *
 * Running header/footer placeholders {PAGE_NUM} / {PAGE_COUNT} (single braces) are left
 * untouched for the engine.
 */
class Templates
{
    public const DEFAULT_LANGUAGE = 'bg';

    /**
     * Supported template languages: code => native name.
     *
     * @return array<string, string>
     */
    public static function languages(): array
    {
        return ['bg' => 'Български', 'en' => 'English'];
    }

    /**
     * Normalises a language code; unknown codes fall back to Bulgarian.
     */
    public static function normalizeLanguage(?string $lang): string
    {
        $lang = strtolower(trim((string) $lang));
        return isset(self::languages()[$lang]) ? $lang : self::DEFAULT_LANGUAGE;
    }

    /**
     * Generates a sample 48x48 transparent RGBA PNG icon as a base64 data URI.
     */
    public static function getSampleTransparentPngDataUri(): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(48, 48);
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
            imagefill($im, 0, 0, $trans);
            imagealphablending($im, true);

            $blue = imagecolorallocatealpha($im, 37, 99, 235, 10);
            $cyan = imagecolorallocatealpha($im, 6, 182, 212, 20);
            $white = imagecolorallocatealpha($im, 255, 255, 255, 0);

            imagefilledellipse($im, 22, 24, 36, 36, $blue);
            imagefilledellipse($im, 30, 18, 22, 22, $cyan);
            imagefilledrectangle($im, 14, 21, 30, 27, $white);
            imagefilledrectangle($im, 19, 16, 25, 32, $white);

            ob_start();
            imagepng($im);
            $raw = (string) ob_get_clean();
            unset($im);
            return 'data:image/png;base64,' . base64_encode($raw);
        }

        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH5AoQFx4y27r7mQAAABl0RVh0Q29tbWVudABDcmVhdGVkIHdpdGggR0lNUFeBDhcAAAAcSURBVCjPY2RgYPjPgBswMbAwsDAwMjAwkA8AAI8AAf78X6MAAAAASUVORK5CYII=';
    }

    /**
     * @param string $lang 'bg' (default) or 'en'; unknown codes fall back to 'bg'.
     * @return array<string, array{title: string, orientation: string, paper: string, footer: string, html: string}>
     */
    public static function all(string $lang = self::DEFAULT_LANGUAGE): array
    {
        $lang = self::normalizeLanguage($lang);
        $strings = self::strings()[$lang];
        $extra = [
            '{{lang}}' => $lang,
            '{{png}}' => self::getSampleTransparentPngDataUri(),
        ];

        $strings['showcase']['table_rows'] = self::showcaseRows($strings['showcase'], $lang);

        $result = [];
        foreach (self::layouts() as $key => $layout) {
            $t = $strings[$key];
            $result[$key] = [
                'title' => $t['meta_title'],
                'orientation' => $layout['orientation'],
                'paper' => $layout['paper'],
                'footer' => self::fill($layout['footer'], $t, $extra, $lang, $key),
                'html' => self::fill($layout['html'], $t, $extra, $lang, $key),
            ];
        }

        return $result;
    }

    /**
     * Builds the long line-item table of the showcase template (forces a page break with repeated <thead>).
     *
     * @param array<string, string> $t
     */
    private static function showcaseRows(array $t, string $lang): string
    {
        $rows = '';
        for ($i = 1; $i <= 46; $i++) {
            $qty = ($i * 7) % 13 + 1;
            $amount = number_format($qty * (12.5 + ($i % 9) * 7.35), 2, $lang === 'bg' ? ',' : '.', $lang === 'bg' ? "\u{00A0}" : ',');
            $money = $lang === 'bg' ? $amount . "\u{00A0}" . $t['currency'] : $t['currency'] . $amount;
            $rows .= sprintf(
                '<tr><td>%d</td><td>%s %03d</td><td class="num">%d</td><td class="num">%s</td></tr>',
                $i,
                htmlspecialchars($t['item'], ENT_NOQUOTES, 'UTF-8'),
                $i,
                $qty,
                htmlspecialchars($money, ENT_NOQUOTES, 'UTF-8')
            );
        }
        return $rows;
    }

    /**
     * Replaces {{t:key}} (escaped) / {{h:key}} (raw) placeholders and verifies none remain.
     *
     * @param array<string, string> $t
     * @param array<string, string> $extra
     */
    private static function fill(string $markup, array $t, array $extra, string $lang, string $templateKey): string
    {
        $map = $extra;
        foreach ($t as $k => $v) {
            $map['{{t:' . $k . '}}'] = htmlspecialchars($v, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $map['{{h:' . $k . '}}'] = $v;
        }
        $out = strtr($markup, $map);
        if (preg_match('/\{\{(?:[th]:[A-Za-z0-9_]+|lang|png)\}\}/', $out, $m) === 1) {
            throw new \LogicException(sprintf('Missing "%s" translation %s for template "%s".', $lang, $m[0], $templateKey));
        }
        return $out;
    }

    /**
     * Per-language string tables: [lang][template][key] => text.
     * Keys used via {{h:…}} may contain trusted inline HTML; all others are plain text.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private static function strings(): array
    {
        return [
            'bg' => [
                'invoice' => [
                    'meta_title' => 'Фактура (Flexbox + Grid + Таблици + Баркод)',
                    'footer_left' => 'Генерирано с <strong>IvoPDF Pure-PHP Engine</strong> (Shared Hosting Ready)',
                    'page_of' => 'Страница {PAGE_NUM} от {PAGE_COUNT}',
                    'doc_title' => 'Фактура № 0000104829',
                    'heading' => 'ФАКТУРА № 0000104829',
                    'badge' => 'ОРИГИНАЛ',
                    'subtitle' => 'Данъчен документ по ЗДДС',
                    'supplier' => 'Доставчик',
                    'supplier_name' => '„ИвоПДФ Софтуерни Системи“ ООД',
                    'supplier_meta' => 'ЕИК: <strong>206543210</strong> | ДДС №: <strong>BG206543210</strong><br/>
                Адрес: гр. София 1000, бул. „Витоша“ № 89, ет. 5<br/>
                МОЛ: Ивайло Апостолов | Тел: +359 888 123 456',
                    'customer' => 'Получател',
                    'customer_name' => '„Дигитална Търговия България“ АД',
                    'customer_meta' => 'ЕИК: <strong>131987654</strong> | ДДС №: <strong>BG131987654</strong><br/>
                Адрес: гр. Пловдив 4000, ул. „Капитан Райчо“ № 50<br/>
                МОЛ: Георги Димитров | Имейл: billing@digitalbg.example',
                    'issue_date' => 'Дата на издаване',
                    'issue_date_val' => '16.10.2026 г.',
                    'tax_point' => 'Данъчно събитие',
                    'tax_point_val' => '16.10.2026 г.',
                    'payment_method' => 'Начин на плащане',
                    'payment_method_val' => 'Банков превод',
                    'meta4' => 'Валутен курс',
                    'meta4_val' => '1 EUR = 1.95583 BGN',
                    'th_no' => '№',
                    'th_desc' => 'Описание на стоката / услугата',
                    'th_unit' => 'Мярка',
                    'th_qty' => 'К-во',
                    'th_price' => 'Ед. цена',
                    'th_amount' => 'Обща сума',
                    'item1_desc' => '<strong>Лиценз IvoPDF Enterprise Engine</strong> — Чист PHP генератор с поддръжка на Flexbox &amp; CSS Grid за споделен хостинг',
                    'item1_unit' => 'бр.',
                    'item1_price' => '1 200.00 лв.',
                    'item1_total' => '1 200.00 лв.',
                    'item2_desc' => 'Интеграция на динамични шаблони за фактури, товарителници и сертификати',
                    'item2_unit' => 'пакет',
                    'item2_price' => '350.00 лв.',
                    'item2_total' => '700.00 лв.',
                    'item3_desc' => 'Специализирана техническа поддръжка и SLA (12 месеца)',
                    'item3_unit' => 'год.',
                    'item3_price' => '500.00 лв.',
                    'item3_total' => '500.00 лв.',
                    'note' => 'Забележка: Всички цени са без включен ДДС (20%).',
                    'tax_base' => 'Данъчна основа:',
                    'tax_base_val' => '2 400.00 лв.',
                    'bank_heading' => 'Банкова сметка за плащане:',
                    'bank_label' => 'Банка:',
                    'bank_name' => 'УниКредит Булбанк АД',
                    'stamp' => '✓ ПЛАТЕНО ПО БАНКОВ ПЪТ',
                    'total_base' => 'Данъчна основа (BGN):',
                    'total_base_val' => '2 400.00 лв.',
                    'total_vat' => 'Начислен ДДС (20%):',
                    'total_vat_val' => '480.00 лв.',
                    'total_due' => 'СУМА ЗА ПЛАЩАНЕ:',
                    'total_due_val' => '2 880.00 лв.',
                    'total_note' => 'Еквивалент в евро:',
                    'total_note_val' => '€ 1 472.52',
                    'received_by' => 'Получил фактурата:',
                    'received_sig' => 'Г. Димитров (Подпис)',
                    'issued_by' => 'Съставил фактурата:',
                    'issued_sig' => 'И. Апостолов (Подпис и печат)',
                ],
                'dashboard' => [
                    'meta_title' => 'Финансов & KPI Отчет (CSS Grid + Векторни SVG Графики)',
                    'footer_left' => 'Конфиденциален аналитичен отчет — Q3/Q4 2026',
                    'page_of' => 'Страница {PAGE_NUM} от {PAGE_COUNT}',
                    'doc_title' => 'Аналитичен Dashboard Отчет',
                    'heading' => 'Тримесечен Ефективен Отчет (CSS Grid)',
                    'subtitle' => 'Демонстрация на CSS Grid <code>repeat(4, 1fr)</code>, <code>grid-column: span 2</code>, <code>1 / -1</code> и векторни SVG диаграми',
                    'period' => 'Октомври 2026',
                    'kpi1_label' => 'Общ Приход',
                    'kpi1_value' => '428 900 лв.',
                    'kpi1_trend' => '▲ +24.8% спрямо Q2',
                    'kpi2_label' => 'Нови Клиенти',
                    'kpi2_value' => '1 842',
                    'kpi2_trend' => '▲ +18.3% ръст',
                    'kpi3_label' => 'Средна Поръчка',
                    'kpi3_value' => '232.80 лв.',
                    'kpi3_trend' => '▲ +6.4% оптимизация',
                    'kpi4_label' => 'SLA Достъпност',
                    'kpi4_value' => '99.98%',
                    'kpi4_trend' => '✓ Над целта (99.9%)',
                    'chart_title' => 'Динамика на приходите по месеци (Векторна SVG Графика)',
                    'm1' => 'Май',
                    'm2' => 'Юни',
                    'm3' => 'Юли',
                    'm4' => 'Авг',
                    'm5' => 'Сеп',
                    'm6' => 'Окт',
                    'side_label' => 'Акцент на периода',
                    'side_title' => 'Споделен Хостинг Оптимизация',
                    'side_text' => 'IvoPDF работи със <strong>100% чист PHP код</strong> без нужда от Node.js, Puppeteer или wkhtmltopdf бинарни пакети.',
                    'side_metric' => 'Средно време за рендер:',
                    'side_metric_val' => '~18 ms / страница',
                    'table_title' => 'Разпределение по регионални центрове и екипи',
                    'th_office' => 'Регионален Офис',
                    'th_manager' => 'Мениджър',
                    'th_projects' => 'Завършени Проекти',
                    'th_budget' => 'Усвояване на Бюджет',
                    'th_contribution' => 'Принос (BGN)',
                    'r1_office' => 'София — Централа',
                    'r1_manager' => 'инж. Мартин Николов',
                    'r1_projects' => '64 проекта',
                    'r1_budget' => '98.4% Отлично',
                    'r1_amount' => '214 500.00 лв.',
                    'r2_office' => 'Пловдив — Тракия',
                    'r2_manager' => 'Елена Стоянова',
                    'r2_projects' => '41 проекта',
                    'r2_budget' => '94.1% Високо',
                    'r2_amount' => '112 800.00 лв.',
                    'r3_office' => 'Варна — Черноморие',
                    'r3_manager' => 'Стефан Колев',
                    'r3_projects' => '29 проекта',
                    'r3_budget' => '89.5% Стабилно',
                    'r3_amount' => '101 600.00 лв.',
                ],
                'certificate' => [
                    'meta_title' => 'Сертификат A4 Landscape (Flexbox + Декоративна Рамка)',
                    'doc_title' => 'Официален Сертификат',
                    'badge' => 'Академия за Софтуерна Архитектура',
                    'heading' => 'СЕРТИФИКАТ ЗА ОТЛИЧИЕ',
                    'intro' => 'Настоящият документ удостоверява, че',
                    'recipient' => 'инж. Ивайло Апостолов',
                    'body' => 'успешно проектира и внедри високопроизводителна PHP библиотека <strong>IvoPDF</strong> за генериране на PDF документи с пълна поддръжка на <strong>CSS Flexbox</strong>, <strong>CSS Grid</strong>, таблици и векторна графика на споделен хостинг.',
                    'skill3' => 'UTF-8 & Кирилица',
                    'date' => 'Дата: 16 Октомври 2026 г.',
                    'chair' => 'Председател на комисията',
                ],
                'catalog' => [
                    'meta_title' => 'Многостраничен Каталог (Flex-Wrap + Таблици + Списъци + 2 стр.)',
                    'footer_left' => 'Технически Каталог 2026 • IvoPDF Engine',
                    'page_of' => 'Страница {PAGE_NUM} от {PAGE_COUNT}',
                    'doc_title' => 'Продуктов Каталог и Спецификация',
                    'heading' => 'Каталог Сървърни & Хостинг Решения 2026',
                    'subtitle' => 'Демонстрация на <code>flex-wrap: wrap</code> с <code>calc(50% - 6pt)</code>, списъци и странициране',
                    'p1_price' => '14.90 лв./мес.',
                    'p1_f1' => '40 GB NVMe SSD Дисково пространство',
                    'p1_f2' => 'PHP 8.1 / 8.2 / 8.3 / 8.4 / 8.5',
                    'p1_f3' => 'Пълна съвместимост с <strong>IvoPDF</strong>',
                    'p2_price' => '59.00 лв./мес.',
                    'p2_f1' => '8 vCPU AMD EPYC Процесорни ядра',
                    'p2_f2' => '16 GB ECC DDR5 Оперативна памет',
                    'p2_f3' => 'Автоматичен ежедневен бекъп',
                    'p3_price' => '249.00 лв./мес.',
                    'p3_f1' => '2x 1 TB NVMe RAID-1 Масив',
                    'p3_f2' => '64 GB RAM + 10 Gbps Порт',
                    'p3_f3' => '24/7 Приоритетен мониторинг',
                    'p4_price' => '590.00 лв./мес.',
                    'p4_f1' => 'Висока отказоустойчивост (HA)',
                    'p4_f2' => 'Балансиране на натоварването',
                    'p4_f3' => 'Персонален системен архитект',
                    'page2_heading' => 'Страница 2 — Сравнителна матрица (Colspan & Rowspan)',
                    'th_param' => 'Параметър',
                    'th_shared' => 'Споделени Планове',
                    'th_corporate' => 'Корпоративни Планове',
                    'row1_label' => 'IvoPDF Поддръжка',
                    'row1_value' => '✓ 100% Поддържа се на всички планове (Чист PHP без външни зависимости)',
                    'row2_label' => 'Гарантиран SLA',
                ],
                'showcase' => [
                    'meta_title' => 'Възможности на двигателя (линкове, отметки, градиенти, списъци, многостранична таблица)',
                    'footer_left' => 'IvoPDF — демонстрация на възможностите',
                    'page_of' => 'Страница {PAGE_NUM} от {PAGE_COUNT}',
                    'doc_title' => 'IvoPDF — Възможности',
                    'heading' => 'Какво може IvoPDF',
                    'lead' => 'Документ, който показва функциите, нужни за замяна на <strong>dompdf</strong> и <strong>mPDF</strong>: вътрешни линкове, отметки (bookmarks), генерирано съдържание, градиенти, трансформации и таблици през няколко страници.',
                    'toc_title' => 'Съдържание (кликаеми вътрешни линкове)',
                    's1' => 'Типография и текст',
                    's2' => 'Графика и ефекти',
                    's3' => 'Списъци',
                    's4' => 'Многостранична таблица',
                    'typo_p1' => 'Изображението вдясно е с <span class="kbd">float: right</span> и текстът го обтича. Неразделимите интервали пазят „1&nbsp;200,00&nbsp;лв.“ на един ред, а думи като „високо-производителен“ се пренасят след тирето. <span class="mark">Маркираният текст запазва общ фон дори когато пресича края на реда и продължава на следващия.</span>',
                    'typo_p2' => 'Поддържат се <strong>удебелен</strong>, <em>курсив</em>, <u>подчертан</u>, <s>зачертан</s>, H<sub>2</sub>O, E = mc<sup>2</sup>, клавиши <span class="kbd">Ctrl</span> + <span class="kbd">P</span> и значки <span class="badge">ГОТОВО</span>, подравнени по базовата линия.',
                    'typo_note' => 'Иконата пред този ред е генерирана със CSS ::before, а номерата на секциите — с CSS броячи.',
                    'rtl_label' => 'Текст отдясно наляво (арабски, иврит) със съединяване на буквите и двупосочен ред (dir="rtl"):',
                    'rtl_text' => 'مرحبا بالعالم! هذا نص عربي مُشكَّل مع كلمات لاتينية مثل IvoPDF وأرقام ٢٠٢٤ و 2024 — שלום עולם, זה עברית.',
                    'cols_text' => 'Този абзац е в две балансирани колони с <span class="kbd">column-count: 2</span>, <span class="kbd">column-gap</span> и вертикална линия между тях. Текстът тече от края на първата колона към върха на втората.',
                    'ellipsis_label' => 'text-overflow: ellipsis:',
                    'ellipsis_text' => 'Много дълъг ред текст, който не се побира в рамката и затова завършва с многоточие',
                    'hyph_text' => 'Автоматичното пренасяне на думи (hyphens: auto) със TeX шаблони прави подравнения по ширина текст в тесни колони много по-равномерен: електроенергийните системи, документацията и университетските познания се пренасят правилно.',
                    'quote_intro' => 'Кавички от CSS:',
                    'quote' => 'Качеството не е действие, а навик.',
                    'hero_title' => 'radial-gradient()',
                    'hero_text' => 'Векторен радиален градиент (PDF Shading Type 3) със заоблени ъгли.',
                    'fx_label' => 'Още ефекти: text-shadow, outline, clip-path и двойна рамка:',
                    'pattern_label' => 'background-image с background-size и repeat:',
                    'svg_text' => 'Вграден SVG с <span class="kbd">transform</span>, <span class="kbd">opacity</span>, <span class="kbd">stroke-dasharray</span> и текст.',
                    'li1' => 'Буквена номерация (a, b, c)',
                    'li2' => 'Продължава автоматично',
                    'li3' => 'След „z“ идва „aa“',
                    'li4' => 'Римски цифри',
                    'li5' => 'list-style-type: upper-roman',
                    'li6' => 'Подравнени маркери',
                    'li7' => 'Квадратни маркери',
                    'li8' => 'list-style shorthand',
                    'table_intro' => 'Таблицата е дълга и минава на следваща страница: заглавният ред (thead) се повтаря автоматично, редовете не се разрязват, а рамките са слети (border-collapse).',
                    'table_caption' => 'Таблица 1. Примерни позиции',
                    'th_item' => 'Позиция',
                    'th_qty' => 'К-во',
                    'th_total' => 'Сума',
                    'item' => 'Услуга / продукт №',
                    'currency' => 'лв.',
                    'back_to_top' => 'Обратно в началото',
                    's5' => 'Интерактивна форма',
                    'form_intro' => 'HTML контролите стават истински PDF полета (AcroForm): могат да се попълват във всеки PDF четец. Кирилицата във въведените стойности се показва правилно.',
                    'form_name' => 'Име',
                    'form_name_value' => 'Иван Петров',
                    'form_email' => 'Имейл',
                    'form_email_ph' => 'name@example.com',
                    'form_agree' => 'Съгласен съм с условията',
                    'form_country' => 'Държава',
                    'form_c1' => 'България',
                    'form_c2' => 'Германия',
                    'form_c3' => 'Франция',
                    'form_button' => 'Бутон',
                ],
            ],

            'en' => [
                'invoice' => [
                    'meta_title' => 'Invoice (Flexbox + Grid + Tables + Barcode)',
                    'footer_left' => 'Generated with <strong>IvoPDF Pure-PHP Engine</strong> (Shared Hosting Ready)',
                    'page_of' => 'Page {PAGE_NUM} of {PAGE_COUNT}',
                    'doc_title' => 'Invoice No. 0000104829',
                    'heading' => 'INVOICE No. 0000104829',
                    'badge' => 'ORIGINAL',
                    'subtitle' => 'VAT invoice',
                    'supplier' => 'Supplier',
                    'supplier_name' => 'IvoPDF Software Systems Ltd.',
                    'supplier_meta' => 'Company No.: <strong>206543210</strong> | VAT No.: <strong>BG206543210</strong><br/>
                Address: 89 Vitosha Blvd, 5th Floor, Sofia 1000, Bulgaria<br/>
                Director: Ivaylo Apostolov | Tel: +359 888 123 456',
                    'customer' => 'Customer',
                    'customer_name' => 'Northwind Digital Commerce Ltd.',
                    'customer_meta' => 'Company No.: <strong>131987654</strong> | VAT No.: <strong>BG131987654</strong><br/>
                Address: 50 Kapitan Raycho St, Plovdiv 4000, Bulgaria<br/>
                Contact: George Dimitrov | Email: billing@digitalbg.example',
                    'issue_date' => 'Issue date',
                    'issue_date_val' => '16 Oct 2026',
                    'tax_point' => 'Tax point',
                    'tax_point_val' => '16 Oct 2026',
                    'payment_method' => 'Payment method',
                    'payment_method_val' => 'Bank transfer',
                    'meta4' => 'Due date',
                    'meta4_val' => '15 Nov 2026',
                    'th_no' => 'No.',
                    'th_desc' => 'Description of goods / services',
                    'th_unit' => 'Unit',
                    'th_qty' => 'Qty',
                    'th_price' => 'Unit price',
                    'th_amount' => 'Amount',
                    'item1_desc' => '<strong>IvoPDF Enterprise Engine licence</strong> — pure-PHP generator with Flexbox &amp; CSS Grid support for shared hosting',
                    'item1_unit' => 'pc',
                    'item1_price' => '€1,200.00',
                    'item1_total' => '€1,200.00',
                    'item2_desc' => 'Integration of dynamic templates for invoices, waybills and certificates',
                    'item2_unit' => 'package',
                    'item2_price' => '€350.00',
                    'item2_total' => '€700.00',
                    'item3_desc' => 'Dedicated technical support with SLA (12 months)',
                    'item3_unit' => 'year',
                    'item3_price' => '€500.00',
                    'item3_total' => '€500.00',
                    'note' => 'Note: All prices are exclusive of VAT (20%).',
                    'tax_base' => 'Net total:',
                    'tax_base_val' => '€2,400.00',
                    'bank_heading' => 'Bank details for payment:',
                    'bank_label' => 'Bank:',
                    'bank_name' => 'UniCredit Bulbank AD',
                    'stamp' => '✓ PAID BY BANK TRANSFER',
                    'total_base' => 'Net amount (EUR):',
                    'total_base_val' => '€2,400.00',
                    'total_vat' => 'VAT (20%):',
                    'total_vat_val' => '€480.00',
                    'total_due' => 'AMOUNT DUE:',
                    'total_due_val' => '€2,880.00',
                    'total_note' => 'Payment terms:',
                    'total_note_val' => 'Net 30 days',
                    'received_by' => 'Received by:',
                    'received_sig' => 'G. Dimitrov (Signature)',
                    'issued_by' => 'Issued by:',
                    'issued_sig' => 'I. Apostolov (Signature & stamp)',
                ],
                'dashboard' => [
                    'meta_title' => 'Financial & KPI Report (CSS Grid + Vector SVG Charts)',
                    'footer_left' => 'Confidential analytics report — Q3/Q4 2026',
                    'page_of' => 'Page {PAGE_NUM} of {PAGE_COUNT}',
                    'doc_title' => 'Analytics Dashboard Report',
                    'heading' => 'Quarterly Performance Report (CSS Grid)',
                    'subtitle' => 'Showcasing CSS Grid <code>repeat(4, 1fr)</code>, <code>grid-column: span 2</code>, <code>1 / -1</code> and vector SVG charts',
                    'period' => 'October 2026',
                    'kpi1_label' => 'Total Revenue',
                    'kpi1_value' => '€428,900',
                    'kpi1_trend' => '▲ +24.8% vs Q2',
                    'kpi2_label' => 'New Customers',
                    'kpi2_value' => '1,842',
                    'kpi2_trend' => '▲ +18.3% growth',
                    'kpi3_label' => 'Average Order',
                    'kpi3_value' => '€232.80',
                    'kpi3_trend' => '▲ +6.4% improvement',
                    'kpi4_label' => 'SLA Uptime',
                    'kpi4_value' => '99.98%',
                    'kpi4_trend' => '✓ Above target (99.9%)',
                    'chart_title' => 'Monthly revenue trend (vector SVG chart)',
                    'm1' => 'May',
                    'm2' => 'Jun',
                    'm3' => 'Jul',
                    'm4' => 'Aug',
                    'm5' => 'Sep',
                    'm6' => 'Oct',
                    'side_label' => 'Highlight of the period',
                    'side_title' => 'Built for Shared Hosting',
                    'side_text' => 'IvoPDF runs on <strong>100% pure PHP</strong> — no Node.js, Puppeteer or wkhtmltopdf binaries required.',
                    'side_metric' => 'Average render time:',
                    'side_metric_val' => '~18 ms / page',
                    'table_title' => 'Breakdown by regional office and team',
                    'th_office' => 'Regional Office',
                    'th_manager' => 'Manager',
                    'th_projects' => 'Completed Projects',
                    'th_budget' => 'Budget Utilisation',
                    'th_contribution' => 'Contribution (EUR)',
                    'r1_office' => 'Sofia — Headquarters',
                    'r1_manager' => 'Martin Nikolov, MEng',
                    'r1_projects' => '64 projects',
                    'r1_budget' => '98.4% Excellent',
                    'r1_amount' => '€214,500.00',
                    'r2_office' => 'Plovdiv — Thrace',
                    'r2_manager' => 'Elena Stoyanova',
                    'r2_projects' => '41 projects',
                    'r2_budget' => '94.1% High',
                    'r2_amount' => '€112,800.00',
                    'r3_office' => 'Varna — Black Sea',
                    'r3_manager' => 'Stefan Kolev',
                    'r3_projects' => '29 projects',
                    'r3_budget' => '89.5% Stable',
                    'r3_amount' => '€101,600.00',
                ],
                'certificate' => [
                    'meta_title' => 'A4 Landscape Certificate (Flexbox + Decorative Frame)',
                    'doc_title' => 'Official Certificate',
                    'badge' => 'Academy of Software Architecture',
                    'heading' => 'CERTIFICATE OF EXCELLENCE',
                    'intro' => 'This is to certify that',
                    'recipient' => 'Ivaylo Apostolov, MEng',
                    'body' => 'has successfully designed and delivered <strong>IvoPDF</strong>, a high-performance PHP library for generating PDF documents with full support for <strong>CSS Flexbox</strong>, <strong>CSS Grid</strong>, tables and vector graphics on shared hosting.',
                    'skill3' => 'UTF-8 & Cyrillic',
                    'date' => 'Date: 16 October 2026',
                    'chair' => 'Chair of the Committee',
                ],
                'catalog' => [
                    'meta_title' => 'Multi-page Catalogue (Flex-Wrap + Tables + Lists + 2 pages)',
                    'footer_left' => 'Technical Catalogue 2026 • IvoPDF Engine',
                    'page_of' => 'Page {PAGE_NUM} of {PAGE_COUNT}',
                    'doc_title' => 'Product Catalogue & Specifications',
                    'heading' => 'Server & Hosting Solutions Catalogue 2026',
                    'subtitle' => 'Showcasing <code>flex-wrap: wrap</code> with <code>calc(50% - 6pt)</code>, lists and pagination',
                    'p1_price' => '€14.90/mo',
                    'p1_f1' => '40 GB NVMe SSD storage',
                    'p1_f2' => 'PHP 8.1 / 8.2 / 8.3 / 8.4 / 8.5',
                    'p1_f3' => 'Fully compatible with <strong>IvoPDF</strong>',
                    'p2_price' => '€59.00/mo',
                    'p2_f1' => '8 AMD EPYC vCPU cores',
                    'p2_f2' => '16 GB ECC DDR5 memory',
                    'p2_f3' => 'Automatic daily backups',
                    'p3_price' => '€249.00/mo',
                    'p3_f1' => '2× 1 TB NVMe RAID-1 array',
                    'p3_f2' => '64 GB RAM + 10 Gbps port',
                    'p3_f3' => '24/7 priority monitoring',
                    'p4_price' => '€590.00/mo',
                    'p4_f1' => 'High availability (HA)',
                    'p4_f2' => 'Load balancing',
                    'p4_f3' => 'Dedicated solutions architect',
                    'page2_heading' => 'Page 2 — Comparison matrix (Colspan & Rowspan)',
                    'th_param' => 'Feature',
                    'th_shared' => 'Shared Plans',
                    'th_corporate' => 'Business Plans',
                    'row1_label' => 'IvoPDF Support',
                    'row1_value' => '✓ 100% supported on every plan (pure PHP, no external dependencies)',
                    'row2_label' => 'Guaranteed SLA',
                ],
                'showcase' => [
                    'meta_title' => 'Engine feature tour (links, bookmarks, gradients, lists, multi-page table)',
                    'footer_left' => 'IvoPDF — feature showcase',
                    'page_of' => 'Page {PAGE_NUM} of {PAGE_COUNT}',
                    'doc_title' => 'IvoPDF — Feature Showcase',
                    'heading' => 'What IvoPDF can do',
                    'lead' => 'A document exercising the features needed to replace <strong>dompdf</strong> and <strong>mPDF</strong>: internal links, bookmarks, generated content, gradients, transforms and tables spanning several pages.',
                    'toc_title' => 'Contents (clickable internal links)',
                    's1' => 'Typography and text',
                    's2' => 'Graphics and effects',
                    's3' => 'Lists',
                    's4' => 'Multi-page table',
                    'typo_p1' => 'The image on the right uses <span class="kbd">float: right</span> and the text wraps around it. Non-breaking spaces keep “€&nbsp;1,200.00” on one line, while words such as “high-performance” may break after the hyphen. <span class="mark">Highlighted text keeps one continuous background even when it crosses the end of a line and continues on the next one.</span>',
                    'typo_p2' => 'Supported: <strong>bold</strong>, <em>italic</em>, <u>underline</u>, <s>strikethrough</s>, H<sub>2</sub>O, E = mc<sup>2</sup>, keys <span class="kbd">Ctrl</span> + <span class="kbd">P</span> and badges <span class="badge">DONE</span> aligned on the baseline.',
                    'typo_note' => 'The icon before this line comes from CSS ::before, and the section numbers from CSS counters.',
                    'rtl_label' => 'Right-to-left text (Arabic, Hebrew) with contextual letter joining and bidirectional ordering (dir="rtl"):',
                    'rtl_text' => 'مرحبا بالعالم! هذا نص عربي مُشكَّل مع كلمات لاتينية مثل IvoPDF وأرقام ٢٠٢٤ و 2024 — שלום עולם, זה עברית.',
                    'cols_text' => 'This paragraph sits in two balanced columns with <span class="kbd">column-count: 2</span>, <span class="kbd">column-gap</span> and a vertical rule between them. Text flows from the bottom of the first column to the top of the second.',
                    'ellipsis_label' => 'text-overflow: ellipsis:',
                    'ellipsis_text' => 'A very long line of text that does not fit into its box and therefore ends with an ellipsis',
                    'hyph_text' => 'Automatic hyphenation (hyphens: auto) with TeX patterns makes justified text in narrow columns far more even: internationalization, documentation and typography hyphenate correctly instead of leaving large gaps.',
                    'quote_intro' => 'Quotes from CSS:',
                    'quote' => 'Quality is not an act, it is a habit.',
                    'hero_title' => 'radial-gradient()',
                    'hero_text' => 'A vector radial gradient (PDF Shading Type 3) with rounded corners.',
                    'fx_label' => 'More effects: text-shadow, outline, clip-path and a double border:',
                    'pattern_label' => 'background-image with background-size and repeat:',
                    'svg_text' => 'Inline SVG with <span class="kbd">transform</span>, <span class="kbd">opacity</span>, <span class="kbd">stroke-dasharray</span> and text.',
                    'li1' => 'Alphabetic numbering (a, b, c)',
                    'li2' => 'Continues automatically',
                    'li3' => 'After “z” comes “aa”',
                    'li4' => 'Roman numerals',
                    'li5' => 'list-style-type: upper-roman',
                    'li6' => 'Aligned markers',
                    'li7' => 'Square markers',
                    'li8' => 'list-style shorthand',
                    'table_intro' => 'This table is long and continues on the next page: the header row (thead) repeats automatically, rows are never sliced and borders are collapsed.',
                    'table_caption' => 'Table 1. Sample line items',
                    'th_item' => 'Item',
                    'th_qty' => 'Qty',
                    'th_total' => 'Amount',
                    'item' => 'Service / product #',
                    'currency' => '€',
                    'back_to_top' => 'Back to top',
                    's5' => 'Interactive form',
                    'form_intro' => 'HTML controls become real PDF fields (AcroForm) that can be filled in any PDF reader. Cyrillic in entered values displays correctly.',
                    'form_name' => 'Name',
                    'form_name_value' => 'John Smith',
                    'form_email' => 'E-mail',
                    'form_email_ph' => 'name@example.com',
                    'form_agree' => 'I agree to the terms',
                    'form_country' => 'Country',
                    'form_c1' => 'Bulgaria',
                    'form_c2' => 'Germany',
                    'form_c3' => 'France',
                    'form_button' => 'Button',
                ],
            ],
        ];
    }

    /**
     * Language-neutral markup and CSS for each template.
     *
     * @return array<string, array{orientation: string, paper: string, footer: string, html: string}>
     */
    private static function layouts(): array
    {
        return [
            'invoice' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'footer' => '<div style="display:flex; justify-content:space-between; font-size:8.5pt; color:#64748b; border-top:1px solid #e2e8f0; padding-top:6pt;"><span>{{h:footer_left}}</span><span>{{t:page_of}}</span></div>',
                'html' => <<<'HTML'
<!DOCTYPE html>
<html lang="{{lang}}">
<head>
<title>{{t:doc_title}}</title>
<style>
    :root {
        --primary: #1e40af;
        --accent: #0284c7;
        --border: #e2e8f0;
        --muted: #64748b;
    }
    * { box-sizing: border-box; }
    @page { margin: 28pt 32pt 42pt 32pt; size: A4 portrait; }
    body { font-family: 'Inter', sans-serif; font-size: 9.5pt; color: #0f172a; }

    .header-banner {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #0284c7 100%);
        color: #ffffff;
        padding: 18pt 20pt;
        border-radius: 10pt;
        margin-bottom: 14pt;
    }
    .brand-row {
        display: flex;
        align-items: center;
        gap: 10pt;
    }
    .invoice-title {
        font-size: 19pt;
        font-weight: bold;
        letter-spacing: 0.5pt;
        margin: 0 0 3pt 0;
    }
    .badge-original {
        display: inline-block;
        background: rgba(255, 255, 255, 0.18);
        color: #e0f2fe;
        padding: 2pt 8pt;
        border-radius: 99pt;
        font-size: 8pt;
        font-weight: bold;
    }

    /* CSS Grid for Supplier & Client cards */
    .parties-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12pt;
        margin-bottom: 14pt;
    }
    .party-card {
        background: #f8fafc;
        border: 1px solid var(--border);
        border-radius: 8pt;
        padding: 11pt 14pt;
    }
    .party-label {
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 0.8pt;
        color: var(--accent);
        font-weight: bold;
        margin-bottom: 4pt;
    }
    .party-name {
        font-size: 11pt;
        font-weight: bold;
        color: #0f172a;
        margin-bottom: 5pt;
    }
    .party-meta {
        font-size: 8.5pt;
        color: #334155;
        line-height: 1.45;
    }

    /* 4-column CSS Grid for Invoice Metadata */
    .meta-strip {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8pt;
        margin-bottom: 14pt;
    }
    .meta-box {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 6pt;
        padding: 7pt 10pt;
    }
    .meta-box-title {
        font-size: 7.5pt;
        color: #1d4ed8;
        text-transform: uppercase;
    }
    .meta-box-val {
        font-size: 9.5pt;
        font-weight: bold;
        color: #1e3a8a;
        margin-top: 2pt;
    }

    /* Invoice Items Table */
    table.items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14pt;
    }
    table.items-table th {
        background: #0f172a;
        color: #ffffff;
        font-size: 8.5pt;
        padding: 7pt 8pt;
        border: 1px solid #0f172a;
    }
    table.items-table td {
        padding: 7pt 8pt;
        border: 1px solid #cbd5e1;
        font-size: 9pt;
    }
    table.items-table tr:nth-child(even) td {
        background: #f8fafc;
    }

    /* Summary Flexbox section */
    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: stretch;
        gap: 16pt;
    }
    .payment-info {
        flex: 3;
        background: #f8fafc;
        border: 1px dashed #94a3b8;
        border-radius: 8pt;
        padding: 12pt;
        position: relative;
    }
    .totals-box {
        flex: 2;
        background: #0f172a;
        color: #ffffff;
        border-radius: 8pt;
        padding: 12pt 14pt;
    }
    .total-line {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5pt;
        font-size: 9pt;
        color: #cbd5e1;
    }
    .total-grand {
        display: flex;
        justify-content: space-between;
        border-top: 1px solid #334155;
        padding-top: 7pt;
        margin-top: 6pt;
        font-size: 12pt;
        font-weight: bold;
        color: #38bdf8;
    }
    .stamp-paid {
        display: inline-block;
        border: 2pt solid #16a34a;
        color: #15803d;
        background: rgba(220, 252, 231, 0.85);
        padding: 4pt 12pt;
        border-radius: 6pt;
        font-weight: bold;
        font-size: 10pt;
        transform: rotate(-6deg);
    }
</style>
</head>
<body>
    <div class="header-banner">
        <div class="brand-row">
            <img src="{{png}}" width="40" height="40" />
            <div>
                <div class="invoice-title">{{t:heading}}</div>
                <div><span class="badge-original">{{t:badge}}</span> &nbsp; {{t:subtitle}}</div>
            </div>
        </div>
        <div style="display:flex; align-items:center;">
            <div style="background:#ffffff; padding:4pt 5pt; border-radius:6pt;">
                <barcode value="INV-2026-104829" width="105" height="34" color="#0f172a" />
            </div>
            <div style="background:#ffffff; padding:3pt; border-radius:6pt; margin-left:6pt; text-align:center; font-size:5pt; color:#475569;">
                <barcode type="QR" value="BCD&#10;002&#10;1&#10;SCT&#10;UNCRBGSF&#10;Ivo Studio EOOD&#10;BG18UNCR70001522334455&#10;&#10;&#10;&#10;INV-2026-104829" error="M" width="44" disableborder="1" />
            </div>
        </div>
    </div>

    <div class="parties-grid">
        <div class="party-card">
            <div class="party-label">{{t:supplier}}</div>
            <div class="party-name">{{t:supplier_name}}</div>
            <div class="party-meta">
                {{h:supplier_meta}}
            </div>
        </div>
        <div class="party-card">
            <div class="party-label">{{t:customer}}</div>
            <div class="party-name">{{t:customer_name}}</div>
            <div class="party-meta">
                {{h:customer_meta}}
            </div>
        </div>
    </div>

    <div class="meta-strip">
        <div class="meta-box">
            <div class="meta-box-title">{{t:issue_date}}</div>
            <div class="meta-box-val">{{t:issue_date_val}}</div>
        </div>
        <div class="meta-box">
            <div class="meta-box-title">{{t:tax_point}}</div>
            <div class="meta-box-val">{{t:tax_point_val}}</div>
        </div>
        <div class="meta-box">
            <div class="meta-box-title">{{t:payment_method}}</div>
            <div class="meta-box-val">{{t:payment_method_val}}</div>
        </div>
        <div class="meta-box">
            <div class="meta-box-title">{{t:meta4}}</div>
            <div class="meta-box-val">{{t:meta4_val}}</div>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 34pt; text-align:center; white-space:nowrap;">{{t:th_no}}</th>
                <th>{{t:th_desc}}</th>
                <th style="width: 55pt; text-align:center;">{{t:th_unit}}</th>
                <th style="width: 45pt; text-align:right;">{{t:th_qty}}</th>
                <th style="width: 78pt; text-align:right;">{{t:th_price}}</th>
                <th style="width: 85pt; text-align:right;">{{t:th_amount}}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="text-align:center;">1</td>
                <td>{{h:item1_desc}}</td>
                <td style="text-align:center;">{{t:item1_unit}}</td>
                <td style="text-align:right;">1</td>
                <td style="text-align:right;">{{t:item1_price}}</td>
                <td style="text-align:right; font-weight:bold;">{{t:item1_total}}</td>
            </tr>
            <tr>
                <td style="text-align:center;">2</td>
                <td>{{t:item2_desc}}</td>
                <td style="text-align:center;">{{t:item2_unit}}</td>
                <td style="text-align:right;">2</td>
                <td style="text-align:right;">{{t:item2_price}}</td>
                <td style="text-align:right; font-weight:bold;">{{t:item2_total}}</td>
            </tr>
            <tr>
                <td style="text-align:center;">3</td>
                <td>{{t:item3_desc}}</td>
                <td style="text-align:center;">{{t:item3_unit}}</td>
                <td style="text-align:right;">1</td>
                <td style="text-align:right;">{{t:item3_price}}</td>
                <td style="text-align:right; font-weight:bold;">{{t:item3_total}}</td>
            </tr>
            <tr>
                <td colspan="4" style="text-align:right; color:#475569;">{{t:note}}</td>
                <td style="text-align:right; font-weight:bold;">{{t:tax_base}}</td>
                <td style="text-align:right; font-weight:bold;">{{t:tax_base_val}}</td>
            </tr>
        </tbody>
    </table>

    <div class="summary-row">
        <div class="payment-info">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-weight:bold; color:#0f172a; margin-bottom:4pt;">{{t:bank_heading}}</div>
                    <div style="font-size:8.5pt; color:#334155;">
                        {{t:bank_label}} <strong>{{t:bank_name}}</strong><br/>
                        IBAN: <code style="background:#e2e8f0; padding:1pt 4pt; border-radius:3pt;">BG18 UNCR 7000 1522 3344 55</code><br/>
                        BIC: <strong>UNCRBGSF</strong>
                    </div>
                </div>
                <div class="stamp-paid">{{t:stamp}}</div>
            </div>
        </div>

        <div class="totals-box">
            <div class="total-line">
                <span>{{t:total_base}}</span>
                <span>{{t:total_base_val}}</span>
            </div>
            <div class="total-line">
                <span>{{t:total_vat}}</span>
                <span>{{t:total_vat_val}}</span>
            </div>
            <div class="total-grand">
                <span>{{t:total_due}}</span>
                <span>{{t:total_due_val}}</span>
            </div>
            <div style="text-align:right; font-size:8.5pt; color:#94a3b8; margin-top:4pt;">
                {{t:total_note}} <strong style="color:#f8fafc;">{{t:total_note_val}}</strong>
            </div>
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; margin-top:26pt; padding-top:10pt;">
        <div style="width:200pt; text-align:center;">
            <div style="font-size:8.5pt; color:#64748b; margin-bottom:18pt;">{{t:received_by}}</div>
            <div style="border-top:1px solid #94a3b8; padding-top:4pt; font-size:8.5pt;">{{t:received_sig}}</div>
        </div>
        <div style="width:200pt; text-align:center;">
            <div style="font-size:8.5pt; color:#64748b; margin-bottom:18pt;">{{t:issued_by}}</div>
            <div style="border-top:1px solid #94a3b8; padding-top:4pt; font-size:8.5pt;">{{t:issued_sig}}</div>
        </div>
    </div>
</body>
</html>
HTML
            ],

            'dashboard' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'footer' => '<div style="display:flex; justify-content:space-between; font-size:8pt; color:#64748b;"><span>{{t:footer_left}}</span><span>{{t:page_of}}</span></div>',
                'html' => <<<'HTML'
<!DOCTYPE html>
<html lang="{{lang}}">
<head>
<title>{{t:doc_title}}</title>
<style>
    * { box-sizing: border-box; }
    @page { margin: 26pt 30pt 38pt 30pt; size: A4 portrait; }
    body { font-family: 'Inter', sans-serif; font-size: 9.5pt; color: #0f172a; }

    .top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2pt solid #4f46e5;
        padding-bottom: 10pt;
        margin-bottom: 14pt;
    }
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10pt;
        margin-bottom: 14pt;
    }
    .kpi-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8pt;
        padding: 10pt 12pt;
        box-shadow: 0 3px 8px rgba(15, 23, 42, 0.06);
    }
    .kpi-label { font-size: 7.5pt; color: #64748b; text-transform: uppercase; }
    .kpi-value { font-size: 15pt; font-weight: bold; color: #0f172a; margin: 4pt 0; }
    .kpi-trend-up { font-size: 8pt; color: #16a34a; font-weight: bold; }

    /* Asymmetric 3-column CSS Grid with span 2 */
    .bento-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12pt;
        margin-bottom: 14pt;
    }
    .bento-wide {
        grid-column: span 2;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 8pt;
        padding: 12pt;
    }
    .bento-side {
        background: linear-gradient(160deg, #312e81 0%, #4f46e5 100%);
        color: #ffffff;
        border-radius: 8pt;
        padding: 12pt;
    }
    .bento-full {
        grid-column: 1 / -1;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8pt;
        padding: 12pt;
    }
    table.perf-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 6pt;
    }
    table.perf-table th {
        background: #f1f5f9;
        color: #334155;
        font-size: 8pt;
        text-transform: uppercase;
        padding: 6pt 8pt;
        border-bottom: 1.5pt solid #cbd5e1;
    }
    table.perf-table td {
        padding: 6pt 8pt;
        border-bottom: 1px solid #e2e8f0;
        font-size: 8.8pt;
    }
</style>
</head>
<body>
    <div class="top-bar">
        <div>
            <h1 style="margin:0; font-size:18pt; color:#1e1b4b;">{{t:heading}}</h1>
            <p style="margin:2pt 0 0 0; color:#64748b; font-size:9pt;">{{h:subtitle}}</p>
        </div>
        <div style="background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; padding:5pt 10pt; border-radius:6pt; font-weight:bold; font-size:8.5pt;">
            {{t:period}}
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">{{t:kpi1_label}}</div>
            <div class="kpi-value">{{t:kpi1_value}}</div>
            <div class="kpi-trend-up">{{t:kpi1_trend}}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">{{t:kpi2_label}}</div>
            <div class="kpi-value">{{t:kpi2_value}}</div>
            <div class="kpi-trend-up">{{t:kpi2_trend}}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">{{t:kpi3_label}}</div>
            <div class="kpi-value">{{t:kpi3_value}}</div>
            <div class="kpi-trend-up">{{t:kpi3_trend}}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">{{t:kpi4_label}}</div>
            <div class="kpi-value">{{t:kpi4_value}}</div>
            <div class="kpi-trend-up">{{t:kpi4_trend}}</div>
        </div>
    </div>

    <div class="bento-grid">
        <div class="bento-wide">
            <div style="font-weight:bold; font-size:10.5pt; margin-bottom:8pt; color:#1e293b;">{{t:chart_title}}</div>
            <svg viewBox="0 0 340 120" width="330" height="115">
                <line x1="30" y1="15" x2="330" y2="15" stroke="#e2e8f0" stroke-width="1"/>
                <line x1="30" y1="50" x2="330" y2="50" stroke="#e2e8f0" stroke-width="1"/>
                <line x1="30" y1="85" x2="330" y2="85" stroke="#cbd5e1" stroke-width="1.5"/>

                <rect x="45"  y="52" width="28" height="33" rx="3" fill="#818cf8"/>
                <rect x="90"  y="42" width="28" height="43" rx="3" fill="#6366f1"/>
                <rect x="135" y="34" width="28" height="51" rx="3" fill="#4f46e5"/>
                <rect x="180" y="28" width="28" height="57" rx="3" fill="#4338ca"/>
                <rect x="225" y="21" width="28" height="64" rx="3" fill="#3730a3"/>
                <rect x="270" y="12" width="28" height="73" rx="3" fill="#0284c7"/>

                <text x="59"  y="100" font-size="8" text-anchor="middle" fill="#475569">{{t:m1}}</text>
                <text x="104" y="100" font-size="8" text-anchor="middle" fill="#475569">{{t:m2}}</text>
                <text x="149" y="100" font-size="8" text-anchor="middle" fill="#475569">{{t:m3}}</text>
                <text x="194" y="100" font-size="8" text-anchor="middle" fill="#475569">{{t:m4}}</text>
                <text x="239" y="100" font-size="8" text-anchor="middle" fill="#475569">{{t:m5}}</text>
                <text x="284" y="100" font-size="8" text-anchor="middle" fill="#0284c7" font-weight="bold">{{t:m6}}</text>
            </svg>
        </div>

        <div class="bento-side">
            <div style="font-size:8pt; text-transform:uppercase; color:#a5b4fc; letter-spacing:0.6pt;">{{t:side_label}}</div>
            <div style="font-size:13pt; font-weight:bold; margin:6pt 0;">{{t:side_title}}</div>
            <p style="font-size:8.5pt; color:#e0e7ff; line-height:1.45;">
                {{h:side_text}}
            </p>
            <div style="margin-top:10pt; padding:8pt; background:rgba(255,255,255,0.12); border-radius:6pt; font-size:8pt;">
                {{t:side_metric}}<br/>
                <strong style="font-size:12pt; color:#38bdf8;">{{t:side_metric_val}}</strong>
            </div>
        </div>

        <div class="bento-full">
            <div style="font-weight:bold; font-size:10.5pt; color:#0f172a;">{{t:table_title}}</div>
            <table class="perf-table">
                <thead>
                    <tr>
                        <th>{{t:th_office}}</th>
                        <th>{{t:th_manager}}</th>
                        <th>{{t:th_projects}}</th>
                        <th>{{t:th_budget}}</th>
                        <th style="text-align:right;">{{t:th_contribution}}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>{{t:r1_office}}</strong></td>
                        <td>{{t:r1_manager}}</td>
                        <td>{{t:r1_projects}}</td>
                        <td><span style="background:#dcfce7; color:#166534; padding:2pt 6pt; border-radius:4pt; font-weight:bold;">{{t:r1_budget}}</span></td>
                        <td style="text-align:right; font-weight:bold;">{{t:r1_amount}}</td>
                    </tr>
                    <tr>
                        <td><strong>{{t:r2_office}}</strong></td>
                        <td>{{t:r2_manager}}</td>
                        <td>{{t:r2_projects}}</td>
                        <td><span style="background:#dbeafe; color:#1e40af; padding:2pt 6pt; border-radius:4pt; font-weight:bold;">{{t:r2_budget}}</span></td>
                        <td style="text-align:right; font-weight:bold;">{{t:r2_amount}}</td>
                    </tr>
                    <tr>
                        <td><strong>{{t:r3_office}}</strong></td>
                        <td>{{t:r3_manager}}</td>
                        <td>{{t:r3_projects}}</td>
                        <td><span style="background:#fef9c3; color:#854d0e; padding:2pt 6pt; border-radius:4pt; font-weight:bold;">{{t:r3_budget}}</span></td>
                        <td style="text-align:right; font-weight:bold;">{{t:r3_amount}}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
HTML
            ],

            'certificate' => [
                'orientation' => 'landscape',
                'paper' => 'A4',
                'footer' => '',
                'html' => <<<'HTML'
<!DOCTYPE html>
<html lang="{{lang}}">
<head>
<title>{{t:doc_title}}</title>
<style>
    * { box-sizing: border-box; }
    @page { margin: 24pt; size: A4 landscape; }
    body { font-family: 'DejaVu Serif', serif; color: #0f172a; margin: 0; }
    .outer-frame {
        border: 3pt solid #1e3a8a;
        border-radius: 12pt;
        padding: 10pt;
        background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
    }
    .inner-frame {
        border: 1pt dashed #3b82f6;
        border-radius: 8pt;
        padding: 26pt 36pt;
        text-align: center;
    }
    .cert-badge {
        display: inline-block;
        background: #1e3a8a;
        color: #ffffff;
        font-family: 'Inter', sans-serif;
        font-size: 8.5pt;
        letter-spacing: 2pt;
        text-transform: uppercase;
        padding: 4pt 14pt;
        border-radius: 99pt;
    }
    .cert-heading {
        font-size: 28pt;
        font-weight: bold;
        color: #1e3a8a;
        margin: 10pt 0 4pt 0;
    }
    .cert-recipient {
        font-size: 22pt;
        font-weight: bold;
        font-style: italic;
        color: #0284c7;
        margin: 10pt 0;
    }
    .skills-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10pt;
        margin: 16pt 0 22pt 0;
        font-family: 'Inter', sans-serif;
    }
    .skill-pill {
        background: #ffffff;
        border: 1px solid #bfdbfe;
        border-radius: 6pt;
        padding: 7pt;
        font-size: 8.5pt;
        font-weight: bold;
        color: #1e40af;
    }
    .footer-flex {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 14pt;
        font-family: 'Inter', sans-serif;
    }
</style>
</head>
<body>
    <div class="outer-frame">
        <div class="inner-frame">
            <div><span class="cert-badge">{{t:badge}}</span></div>
            <div class="cert-heading">{{t:heading}}</div>
            <div style="font-size:11pt; color:#475569;">{{t:intro}}</div>

            <div class="cert-recipient">{{t:recipient}}</div>

            <p style="font-size:10.5pt; color:#334155; max-width:580pt; margin:0 auto; line-height:1.5;">
                {{h:body}}
            </p>

            <div class="skills-grid">
                <div class="skill-pill">✓ CSS Flexbox Layout</div>
                <div class="skill-pill">✓ CSS Grid Engine</div>
                <div class="skill-pill">✓ {{t:skill3}}</div>
                <div class="skill-pill">✓ Pure-PHP PDF 1.7</div>
            </div>

            <div class="footer-flex">
                <div style="width:170pt; text-align:center;">
                    <div style="border-top:1px solid #64748b; padding-top:5pt; font-size:9pt; color:#334155;">
                        {{t:date}}
                    </div>
                </div>

                <div>
                    <svg viewBox="0 0 80 80" width="68" height="68">
                        <circle cx="40" cy="40" r="34" fill="#fef3c7" stroke="#d97706" stroke-width="2.5"/>
                        <circle cx="40" cy="40" r="27" fill="none" stroke="#f59e0b" stroke-width="1"/>
                        <text x="40" y="38" font-size="9" text-anchor="middle" fill="#92400e">IVOPDF</text>
                        <text x="40" y="50" font-size="7" text-anchor="middle" fill="#b45309">VERIFIED</text>
                    </svg>
                </div>

                <div style="width:170pt; text-align:center;">
                    <div style="border-top:1px solid #64748b; padding-top:5pt; font-size:9pt; color:#334155;">
                        {{t:chair}}
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
HTML
            ],

            'catalog' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'footer' => '<div style="display:flex; justify-content:space-between; font-size:8.5pt; color:#64748b; border-top:1px solid #cbd5e1; padding-top:5pt;"><span>{{t:footer_left}}</span><span>{{t:page_of}}</span></div>',
                'html' => <<<'HTML'
<!DOCTYPE html>
<html lang="{{lang}}">
<head>
<title>{{t:doc_title}}</title>
<style>
    * { box-sizing: border-box; }
    @page { margin: 28pt 32pt 42pt 32pt; size: A4 portrait; }
    body { font-family: 'Inter', sans-serif; font-size: 9.5pt; color: #0f172a; }
    .catalog-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 12pt;
        margin-top: 10pt;
    }
    .product-card {
        width: calc(50% - 6pt);
        border: 1px solid #cbd5e1;
        border-radius: 8pt;
        padding: 12pt;
        background: #ffffff;
        page-break-inside: avoid;
    }
    .price-tag {
        display: inline-block;
        background: #dcfce7;
        color: #166534;
        padding: 3pt 8pt;
        border-radius: 6pt;
        font-weight: bold;
        font-size: 10pt;
    }
</style>
</head>
<body>
    <div style="background:linear-gradient(90deg, #0f172a, #334155); color:#fff; padding:16pt; border-radius:8pt;">
        <h1 style="margin:0; font-size:17pt;">{{t:heading}}</h1>
        <p style="margin:4pt 0 0 0; color:#cbd5e1;">{{h:subtitle}}</p>
    </div>

    <div class="catalog-wrap">
        <div class="product-card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; color:#1e3a8a;">Shared Hosting Pro</h3>
                <span class="price-tag">{{t:p1_price}}</span>
            </div>
            <ul>
                <li>{{t:p1_f1}}</li>
                <li>{{t:p1_f2}}</li>
                <li>{{h:p1_f3}}</li>
            </ul>
            <barcode value="SKU-HOST-PRO-01" width="130" height="32" />
        </div>

        <div class="product-card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; color:#1e3a8a;">Managed VPS Cloud</h3>
                <span class="price-tag">{{t:p2_price}}</span>
            </div>
            <ul>
                <li>{{t:p2_f1}}</li>
                <li>{{t:p2_f2}}</li>
                <li>{{t:p2_f3}}</li>
            </ul>
            <barcode value="SKU-VPS-CLOUD-02" width="130" height="32" />
        </div>

        <div class="product-card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; color:#1e3a8a;">Dedicated BareMetal</h3>
                <span class="price-tag">{{t:p3_price}}</span>
            </div>
            <ul>
                <li>{{t:p3_f1}}</li>
                <li>{{t:p3_f2}}</li>
                <li>{{t:p3_f3}}</li>
            </ul>
            <barcode value="SKU-DED-BM-03" width="130" height="32" />
        </div>

        <div class="product-card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; color:#1e3a8a;">Enterprise Cluster</h3>
                <span class="price-tag">{{t:p4_price}}</span>
            </div>
            <ul>
                <li>{{t:p4_f1}}</li>
                <li>{{t:p4_f2}}</li>
                <li>{{t:p4_f3}}</li>
            </ul>
            <barcode value="SKU-ENT-CL-04" width="130" height="32" />
        </div>
    </div>

    <div style="page-break-before: always;">
        <h2 style="color:#0f172a; border-bottom:2pt solid #0284c7; padding-bottom:4pt;">{{t:page2_heading}}</h2>
        <table style="width:100%; border-collapse:collapse; margin-top:10pt;">
            <thead>
                <tr style="background:#0f172a; color:#ffffff;">
                    <th rowspan="2" style="border:1px solid #334155; padding:7pt;">{{t:th_param}}</th>
                    <th colspan="2" style="border:1px solid #334155; padding:7pt; text-align:center;">{{t:th_shared}}</th>
                    <th colspan="2" style="border:1px solid #334155; padding:7pt; text-align:center;">{{t:th_corporate}}</th>
                </tr>
                <tr style="background:#1e293b; color:#38bdf8;">
                    <th style="border:1px solid #334155; padding:6pt;">Start</th>
                    <th style="border:1px solid #334155; padding:6pt;">Pro</th>
                    <th style="border:1px solid #334155; padding:6pt;">VPS Cloud</th>
                    <th style="border:1px solid #334155; padding:6pt;">Dedicated</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="border:1px solid #cbd5e1; padding:6pt;"><strong>{{t:row1_label}}</strong></td>
                    <td colspan="4" style="border:1px solid #cbd5e1; padding:6pt; text-align:center; background:#dcfce7; color:#166534; font-weight:bold;">{{t:row1_value}}</td>
                </tr>
                <tr>
                    <td style="border:1px solid #cbd5e1; padding:6pt;"><strong>{{t:row2_label}}</strong></td>
                    <td style="border:1px solid #cbd5e1; padding:6pt;">99.5%</td>
                    <td style="border:1px solid #cbd5e1; padding:6pt;">99.9%</td>
                    <td style="border:1px solid #cbd5e1; padding:6pt;">99.95%</td>
                    <td style="border:1px solid #cbd5e1; padding:6pt;">99.99%</td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>
HTML
            ],
            'showcase' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'footer' => '<div style="display:flex; justify-content:space-between; font-size:8pt; color:#64748b; border-top:1px solid #e2e8f0; padding-top:5pt;"><span>{{t:footer_left}}</span><span>{{t:page_of}}</span></div>',
                'html' => <<<'HTML'
<!DOCTYPE html>
<html lang="{{lang}}">
<head>
<title>{{t:doc_title}}</title>
<style>
    @page { margin: 30pt 34pt 40pt 34pt; size: A4 portrait; }
    body { font-family: 'Inter', sans-serif; font-size: 9.5pt; color: #1e293b; line-height: 1.45; counter-reset: section; }
    h1 { font-size: 20pt; color: #0f172a; margin: 0 0 4pt 0; }
    h2 { font-size: 13pt; color: #1e3a8a; margin: 14pt 0 6pt 0; padding-bottom: 3pt; border-bottom: 1.5pt solid #bfdbfe; counter-increment: section; }
    h2::before { content: counter(section) ". "; color: #0284c7; }
    .lead { color: #475569; margin-bottom: 8pt; }
    .toc { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6pt; padding: 8pt 12pt; }
    .toc a { color: #1d4ed8; text-decoration: none; }
    .toc a::after { content: leader('.') target-counter(attr(href), page); color: #64748b; }
    .toc li { margin-bottom: 1pt; }
    .hero {
        height: 70pt; border-radius: 10pt; color: #fff; padding: 14pt 18pt;
        background: radial-gradient(circle at 85% 20%, #38bdf8 0%, #2563eb 45%, #0f172a 100%);
    }
    .hero strong { font-size: 15pt; }
    .pattern {
        height: 46pt; border-radius: 6pt; border: 1px solid #cbd5e1;
        background: #f1f5f9 url({{png}}) repeat; background-size: 22pt 22pt; background-position: center;
    }
    .float-img { float: right; width: 70pt; margin: 0 0 6pt 10pt; border-radius: 6pt; border: 1px solid #e2e8f0; }
    .badge { display: inline-block; background: #dcfce7; color: #166534; border-radius: 99pt; padding: 1pt 7pt; font-size: 8pt; font-weight: bold; }
    .mark { background: #fef08a; border-radius: 3pt; padding: 0 2pt; }
    .kbd { font-family: 'DejaVu Sans Mono', monospace; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 3pt; padding: 0 3pt; font-size: 8.5pt; }
    .grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8pt; }
    .tile { border: 1px solid #e2e8f0; border-radius: 6pt; padding: 8pt; text-align: center; }
    .tilt { transform: rotate(-4deg) scale(0.95); background: #fef3c7; }
    .skew { transform: skewX(-8deg); background: #ede9fe; }
    .shadow { box-shadow: 0 2pt 6pt rgba(15,23,42,0.18), 0 0 0 2pt #e0e7ff; }
    .fx { display: flex; gap: 12pt; align-items: center; margin: 4pt 4pt 0 4pt; }
    .fx > div { flex: 1; text-align: center; padding: 8pt; font-size: 8.5pt; }
    .fx-ts { font-size: 14pt !important; font-weight: bold; color: #1e3a8a; text-shadow: 1pt 1pt 2pt rgba(15,23,42,0.45); }
    .fx-ol { outline: 2pt solid #0284c7; outline-offset: 3pt; border-radius: 4pt; background: #f0f9ff; }
    .fx-clip img { width: 46pt; height: 46pt; clip-path: circle(50%); }
    .fx-dbl { border: 5pt double #be123c; }
    .ellipsis { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border: 1px dashed #94a3b8; padding: 4pt 6pt; width: 230pt; }
    ol.alpha { list-style-type: lower-alpha; }
    ol.cyr { list-style-type: upper-roman; }
    .note::before { content: "ℹ "; color: #0284c7; font-weight: bold; }
    .quote { font-style: italic; color: #334155; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data caption { font-weight: bold; color: #334155; text-align: left; }
    table.data th { background: #1e293b; color: #fff; border: 1px solid #1e293b; padding: 5pt 6pt; font-size: 8.5pt; }
    table.data td { border: 1px solid #cbd5e1; padding: 4pt 6pt; font-size: 8.5pt; }
    table.data tr:nth-child(even) td { background: #f8fafc; }
    .num { text-align: right; white-space: nowrap; }
</style>
</head>
<body>
    <h1>{{t:heading}}</h1>
    <p class="lead">{{h:lead}}</p>

    <div class="toc">
        <strong>{{t:toc_title}}</strong>
        <ol>
            <li><a href="#typography">{{t:s1}}</a></li>
            <li><a href="#graphics">{{t:s2}}</a></li>
            <li><a href="#lists">{{t:s3}}</a></li>
            <li><a href="#forms">{{t:s5}}</a></li>
            <li><a href="#tables">{{t:s4}}</a></li>
        </ol>
    </div>

    <h2 id="typography">{{t:s1}}</h2>
    <img class="float-img" src="{{png}}" />
    <p>{{h:typo_p1}}</p>
    <p>{{h:typo_p2}}</p>
    <p class="note">{{t:typo_note}}</p>
    <p>{{t:rtl_label}}</p>
    <div dir="rtl" style="border:1px solid #cbd5e1; border-radius:4pt; padding:4pt 8pt; margin-bottom:6pt; font-size:11pt;">{{t:rtl_text}}</div>
    <p>{{t:ellipsis_label}}</p>
    <div class="ellipsis">{{t:ellipsis_text}}</div>
    <p class="quote">{{t:quote_intro}} <q>{{t:quote}}</q></p>
    <div style="width:150pt; border:1px solid #cbd5e1; padding:4pt; text-align:justify; hyphens:auto; font-size:9pt; margin-bottom:6pt;">{{t:hyph_text}}</div>
    <div style="column-count:2; column-gap:18pt; column-rule:1px solid #94a3b8; text-align:justify;"><p style="margin:0; font-size:9pt;">{{h:cols_text}}</p></div>

    <h2 id="graphics">{{t:s2}}</h2>
    <div class="hero"><strong>{{t:hero_title}}</strong><br/>{{t:hero_text}}</div>
    <p style="margin-top:8pt;">{{t:pattern_label}}</p>
    <div class="pattern"></div>
    <div class="grid3" style="margin-top:10pt;">
        <div class="tile tilt">rotate(-4deg) scale(.95)</div>
        <div class="tile skew">skewX(-8deg)</div>
        <div class="tile shadow">box-shadow ×2</div>
    </div>
    <p style="margin:12pt 0 6pt 0;">{{t:fx_label}}</p>
    <div class="fx">
        <div class="fx-ts">text-shadow</div>
        <div class="fx-ol">outline-offset</div>
        <div class="fx-clip"><img src="{{png}}" /><br/>clip-path: circle()</div>
        <div class="fx-dbl">border: double</div>
    </div>
    <div style="display:flex; gap:12pt; align-items:center; margin-top:10pt;">
        <svg width="120" height="80" viewBox="0 0 120 80">
            <g transform="translate(60 40)">
                <circle r="34" fill="#2563eb" opacity="0.15"/>
                <g transform="rotate(-30)">
                    <rect x="-28" y="-9" width="56" height="18" rx="9" fill="#0284c7" fill-opacity="0.85"/>
                </g>
                <circle r="30" fill="none" stroke="#1e3a8a" stroke-width="2" stroke-dasharray="6 4" stroke-linecap="round"/>
                <text y="4" font-size="10" text-anchor="middle" fill="#fff" font-weight="bold">SVG</text>
            </g>
        </svg>
        <p style="margin:0;">{{h:svg_text}}</p>
    </div>

    <h2 id="lists">{{t:s3}}</h2>
    <div style="display:flex; gap:16pt;">
        <div style="flex:1;">
            <ol class="alpha">
                <li>{{t:li1}}</li>
                <li>{{t:li2}}</li>
                <li>{{t:li3}}</li>
            </ol>
        </div>
        <div style="flex:1;">
            <ol class="cyr">
                <li>{{t:li4}}</li>
                <li>{{t:li5}}</li>
                <li>{{t:li6}}</li>
            </ol>
        </div>
        <div style="flex:1;">
            <ul style="list-style: square;">
                <li>{{t:li7}}</li>
                <li>{{t:li8}} <span class="badge">NEW</span></li>
            </ul>
        </div>
    </div>

    <h2 id="forms">{{t:s5}}</h2>
    <p>{{t:form_intro}}</p>
    <form style="display:flex; flex-wrap:wrap; gap:6pt 14pt; align-items:center;">
        <label>{{t:form_name}}: <input type="text" name="name" value="{{t:form_name_value}}" style="width:120pt;"></label>
        <label>{{t:form_email}}: <input type="email" name="email" placeholder="{{t:form_email_ph}}" style="width:130pt;"></label>
        <label><input type="checkbox" name="agree" checked> {{t:form_agree}}</label>
        <label>{{t:form_country}}: <select name="country"><option value="bg">{{t:form_c1}}</option><option value="de">{{t:form_c2}}</option><option value="fr">{{t:form_c3}}</option></select></label>
        <button type="button">{{t:form_button}}</button>
    </form>

    <h2 id="tables">{{t:s4}}</h2>
    <p>{{t:table_intro}}</p>
    <table class="data">
        <caption>{{t:table_caption}}</caption>
        <colgroup><col style="width:40pt"><col><col style="width:70pt"><col style="width:80pt"></colgroup>
        <thead>
            <tr><th>#</th><th>{{t:th_item}}</th><th class="num">{{t:th_qty}}</th><th class="num">{{t:th_total}}</th></tr>
        </thead>
        <tbody>
            {{h:table_rows}}
        </tbody>
    </table>
    <p style="margin-top:10pt;"><a href="#typography">↑ {{t:back_to_top}}</a></p>
</body>
</html>
HTML
            ],
        ];
    }
}
