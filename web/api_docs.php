<?php
/** Описание внешнего API (docs/API.md) — для администратора, раздел «Пользователи» */
require __DIR__ . '/aut/verificat.php';
require __DIR__ . '/lib/layout.php';
header('Cache-Control: no-store');
if (!relay_is_admin()) {
    relay_forbidden('Описание API доступно администратору');
}

$file = is_readable(__DIR__ . '/lib/API.md') ? __DIR__ . '/lib/API.md' : __DIR__ . '/../docs/API.md';
$md = is_readable($file) ? (string)file_get_contents($file) : "# Описание API\n\nФайл описания не найден — обновите сервер: `sudo relay-update`.";
// адрес этого сервера вместо примера
$base = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'сервер');
$md = str_replace('https://relay.example.ru', $base, $md);

/** Небольшой Markdown → HTML: заголовки, абзацы, списки, таблицы, блоки кода, `код`, **жирный** */
function md_inline(string $s): string
{
    $parts = preg_split('/(`[^`]+`)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $p) {
        if (strlen($p) > 1 && $p[0] === '`' && substr($p, -1) === '`') {
            $out .= '<code>' . h(substr($p, 1, -1)) . '</code>';
        } else {
            $out .= preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', h($p));
        }
    }
    return $out;
}

function md_render(string $md): string
{
    $lines = explode("\n", str_replace("\r", '', $md));
    $html = '';
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $l = $lines[$i];
        if (str_starts_with($l, '```')) {
            $code = [];
            for ($i++; $i < $n && !str_starts_with($lines[$i], '```'); $i++) {
                $code[] = $lines[$i];
            }
            $html .= '<pre class="code-sample">' . h(implode("\n", $code)) . "</pre>\n";
        } elseif (preg_match('/^(#{1,4})\s+(.*)$/', $l, $m)) {
            $lv = strlen($m[1]);
            $html .= "<h$lv>" . md_inline($m[2]) . "</h$lv>\n";
        } elseif (str_starts_with(trim($l), '|')) {
            $rows = [];
            for (; $i < $n && str_starts_with(trim($lines[$i]), '|'); $i++) {
                $rows[] = $lines[$i];
            }
            $i--;
            $html .= '<div class="table-wrap"><table class="tbl doc-tbl">';
            foreach ($rows as $k => $r) {
                if ($k === 1 && preg_match('/^\|[\s\-:|]+\|$/', trim($r))) {
                    continue;
                }
                // «\|» внутри ячейки — символ «|»
                $cells = array_map(fn($c) => str_replace("\u{1}", '|', trim($c)),
                    explode('|', trim(str_replace('\|', "\u{1}", trim($r)), '|')));
                $tag = $k === 0 ? 'th' : 'td';
                $html .= '<tr>' . implode('', array_map(fn($c) => "<$tag>" . md_inline($c) . "</$tag>", $cells)) . '</tr>';
            }
            $html .= "</table></div>\n";
        } elseif (preg_match('/^\s*[*-]\s+/', $l)) {
            $html .= '<ul>';
            for (; $i < $n && preg_match('/^\s*[*-]\s+(.*)$/', $lines[$i], $m); $i++) {
                $item = $m[1];
                while ($i + 1 < $n && preg_match('/^\s{2,}\S/', $lines[$i + 1]) && !preg_match('/^\s*[*-]\s+/', $lines[$i + 1])) {
                    $item .= ' ' . trim($lines[++$i]);
                }
                $html .= '<li>' . md_inline($item) . '</li>';
            }
            $i--;
            $html .= "</ul>\n";
        } elseif (trim($l) !== '') {
            $p = [$l];
            while ($i + 1 < $n && trim($lines[$i + 1]) !== '' && !preg_match('/^(#|```|\||\s*[*-]\s)/', ltrim($lines[$i + 1]))) {
                $p[] = $lines[++$i];
            }
            $html .= '<p>' . md_inline(implode(' ', array_map('trim', $p))) . "</p>\n";
        }
    }
    return $html;
}

layout_head('Описание API', 'users', $login);
?>
<div class="page-head">
  <div><h1>Описание API</h1><p>Подключение сторонних систем · ключи — «Пользователи» → «Пользователи API»</p></div>
  <a class="btn" href="/users.php#api-card">← Пользователи API</a>
</div>
<section class="card"><div class="card-body api-doc"><?= md_render($md) ?></div></section>
<?php layout_foot(); ?>
