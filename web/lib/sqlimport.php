<?php
/**
 * Чтение данных устройств из SQL-дампа: резервной копии off-on service или БД первоначальной системы
 * (phpMyAdmin / mysqldump). Файл читается построчно (можно .sql.gz), разбираются только
 * CREATE TABLE и INSERT нужных таблиц — большие журналы в памяти не держатся.
 */

/**
 * Значения одного INSERT: «(1,'a',NULL),(2,'b\'c',3)» → [[1,'a',null], …].
 * Строки в одинарных кавычках с экранированием \ и '', NULL, числа и прочие слова — как есть.
 */
function relay_sql_tuples(string $s): array
{
    $rows = [];
    $len = strlen($s);
    $i = 0;
    while ($i < $len) {
        // начало кортежа
        while ($i < $len && $s[$i] !== '(') {
            $i++;
        }
        if ($i >= $len) {
            break;
        }
        $i++;
        $row = [];
        while ($i < $len) {
            while ($i < $len && ctype_space($s[$i])) {
                $i++;
            }
            if ($i < $len && $s[$i] === "'") {
                $i++;
                $v = '';
                while ($i < $len) {
                    $c = $s[$i];
                    if ($c === '\\' && $i + 1 < $len) {
                        $n = $s[$i + 1];
                        $v .= match ($n) { 'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1a", default => $n };
                        $i += 2;
                        continue;
                    }
                    if ($c === "'") {
                        if ($i + 1 < $len && $s[$i + 1] === "'") {
                            $v .= "'";
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $v .= $c;
                    $i++;
                }
                $row[] = $v;
            } else {
                $start = $i;
                while ($i < $len && $s[$i] !== ',' && $s[$i] !== ')') {
                    $i++;
                }
                $tok = trim(substr($s, $start, $i - $start));
                $row[] = strcasecmp($tok, 'NULL') === 0 ? null : $tok;
            }
            while ($i < $len && ctype_space($s[$i])) {
                $i++;
            }
            if ($i < $len && $s[$i] === ',') {
                $i++;
                continue;
            }
            if ($i < $len && $s[$i] === ')') {
                $i++;
                break;
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Читает дамп и возвращает строки нужных таблиц: ['general_info' => [[col => val], …], 'devices' => …].
 * $tables — имена таблиц. Колонки берутся из списка в INSERT или из CREATE TABLE этого же файла.
 */
function relay_sql_extract(string $path, array $tables): array
{
    $fh = @gzopen($path, 'rb'); // gzopen читает и обычные (несжатые) файлы
    if (!$fh) {
        throw new RuntimeException('Не удалось открыть файл');
    }
    $want = [];
    foreach ($tables as $t) {
        $want[strtolower($t)] = $t; // имя в нижнем регистре → как запросили
    }
    $pending = []; // строки INSERT без списка колонок до CREATE TABLE — разберём в конце
    $cols = [];      // table → [колонки] из CREATE TABLE
    $out = array_fill_keys($tables, []);
    $stmt = null;    // накапливаемый оператор
    $inStr = false;  // внутри строки в кавычках (с прошлых строк)
    $kind = null;
    $table = null;
    $lineNo = 0;
    while (($line = gzgets($fh)) !== false) {
        $lineNo++;
        if ($stmt === null) {
            $t = ltrim($line);
            if (preg_match('/^(INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO)\s+`?(\w+)`?/i', $t, $m) && isset($want[strtolower($m[2])])) {
                $kind = 'insert';
            } elseif (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $t, $m) && isset($want[strtolower($m[1])])) {
                $kind = 'create';
                $m[2] = $m[1];
            } else {
                continue; // чужие таблицы и прочие строки пропускаем, не держа их в памяти
            }
            $table = strtolower($m[2]);
            $stmt = '';
            $inStr = false;
        }
        $stmt .= $line;
        // конец оператора — «;» вне строки
        $done = false;
        $n = strlen($line);
        for ($i = 0; $i < $n; $i++) {
            $c = $line[$i];
            if ($inStr) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === "'") {
                    if ($i + 1 < $n && $line[$i + 1] === "'") {
                        $i++;
                    } else {
                        $inStr = false;
                    }
                }
            } elseif ($c === "'") {
                $inStr = true;
            } elseif ($c === ';') {
                $done = true;
                break;
            }
        }
        if (!$done) {
            if (strlen($stmt) > 64 * 1024 * 1024) {
                throw new RuntimeException("Слишком длинный оператор SQL (строка $lineNo)");
            }
            continue;
        }
        if ($kind === 'create') {
            $c = [];
            foreach (preg_split('/\R/', $stmt) as $l) {
                if (preg_match('/^\s*`(\w+)`\s+\w/', $l, $mm)) {
                    $c[] = $mm[1];
                }
            }
            $cols[$table] = $c;
        } else {
            if (!preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?\w+`?\s*(\(([^)]*)\))?\s*VALUES\s*/is', $stmt, $mm)) {
                $stmt = null;
                continue;
            }
            $names = isset($mm[2]) && $mm[2] !== ''
                ? array_map(fn($x) => trim($x, " `\t\r\n"), explode(',', $mm[2]))
                : ($cols[$table] ?? []);
            $body = substr($stmt, strlen($mm[0]));
            foreach (relay_sql_tuples($body) as $vals) {
                if (!$names) {
                    $pending[] = [$table, $vals];
                } elseif (count($names) === count($vals)) {
                    $out[$want[$table]][] = array_combine($names, $vals);
                }
            }
        }
        $stmt = null;
    }
    gzclose($fh);
    foreach ($pending as [$t, $vals]) {
        $names = $cols[$t] ?? [];
        if ($names && count($names) === count($vals)) {
            $out[$want[$t]][] = array_combine($names, $vals);
        }
    }
    return $out;
}
