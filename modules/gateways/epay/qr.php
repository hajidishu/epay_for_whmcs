<?php
/**
 * Minimal QR Code encoder for EPay payment URI rendering.
 *
 * Implements QR Code Model 2 byte mode, error correction level L, versions 1-10.
 * This code is purpose-built for short payment URLs/URI payloads and has no external
 * runtime dependency or network call.
 */

if (!function_exists('epay_qr_svg')) {
    function epay_qr_svg($text, $pixelSize)
    {
        $bytes = array_values(unpack('C*', (string) $text));
        $version = epay_qr_choose_version(count($bytes));
        $data = epay_qr_make_codewords($bytes, $version);
        $matrix = epay_qr_make_matrix($data, $version, 0);

        $quiet = 4;
        $count = count($matrix);
        $view = $count + ($quiet * 2);
        $pixelSize = max(160, min(512, (int) $pixelSize));
        $path = array();

        for ($y = 0; $y < $count; $y++) {
            for ($x = 0; $x < $count; $x++) {
                if ($matrix[$y][$x]) {
                    $path[] = 'M' . ($x + $quiet) . ',' . ($y + $quiet) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $pixelSize . '" height="' . $pixelSize
            . '" viewBox="0 0 ' . $view . ' ' . $view . '" shape-rendering="crispEdges" role="img" aria-label="支付二维码">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . '<path d="' . implode('', $path) . '" fill="#000"/>'
            . '</svg>';
    }
}

if (!function_exists('epay_qr_choose_version')) {
    function epay_qr_choose_version($byteLength)
    {
        $dataCodewords = array(1 => 19, 2 => 34, 3 => 55, 4 => 80, 5 => 108, 6 => 136, 7 => 156, 8 => 194, 9 => 232, 10 => 274);
        foreach ($dataCodewords as $version => $capacityBytes) {
            $countBits = $version <= 9 ? 8 : 16;
            $requiredBits = 4 + $countBits + ($byteLength * 8);
            if ($requiredBits <= ($capacityBytes * 8)) {
                return $version;
            }
        }
        throw new RuntimeException('QR payload is too long for the built-in encoder. Switch to submit.php mode.');
    }
}

if (!function_exists('epay_qr_append_bits')) {
    function epay_qr_append_bits(&$bits, $value, $length)
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = (($value >> $i) & 1) !== 0 ? 1 : 0;
        }
    }
}

if (!function_exists('epay_qr_make_codewords')) {
    function epay_qr_make_codewords(array $payload, $version)
    {
        $spec = epay_qr_block_spec($version);
        $dataCapacity = $spec['data'];
        $bits = array();

        epay_qr_append_bits($bits, 0x4, 4); // byte mode
        epay_qr_append_bits($bits, count($payload), $version <= 9 ? 8 : 16);
        foreach ($payload as $byte) {
            epay_qr_append_bits($bits, $byte, 8);
        }

        $capacityBits = $dataCapacity * 8;
        $terminator = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }
        while ((count($bits) % 8) !== 0) {
            $bits[] = 0;
        }

        $dataBytes = array();
        for ($i = 0; $i < count($bits); $i += 8) {
            $value = 0;
            for ($j = 0; $j < 8; $j++) {
                $value = ($value << 1) | $bits[$i + $j];
            }
            $dataBytes[] = $value;
        }
        $pad = array(0xEC, 0x11);
        $p = 0;
        while (count($dataBytes) < $dataCapacity) {
            $dataBytes[] = $pad[$p++ % 2];
        }

        $blocks = array();
        $offset = 0;
        foreach ($spec['blocks'] as $dataCount) {
            $chunk = array_slice($dataBytes, $offset, $dataCount);
            $offset += $dataCount;
            $blocks[] = array(
                'data' => $chunk,
                'ec' => epay_qr_rs_remainder($chunk, $spec['ec']),
            );
        }

        $result = array();
        $maxData = max($spec['blocks']);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block['data'])) {
                    $result[] = $block['data'][$i];
                }
            }
        }
        for ($i = 0; $i < $spec['ec']; $i++) {
            foreach ($blocks as $block) {
                $result[] = $block['ec'][$i];
            }
        }
        return $result;
    }
}

if (!function_exists('epay_qr_block_spec')) {
    function epay_qr_block_spec($version)
    {
        $table = array(
            1 => array('data' => 19,  'ec' => 7,  'blocks' => array(19)),
            2 => array('data' => 34,  'ec' => 10, 'blocks' => array(34)),
            3 => array('data' => 55,  'ec' => 15, 'blocks' => array(55)),
            4 => array('data' => 80,  'ec' => 20, 'blocks' => array(80)),
            5 => array('data' => 108, 'ec' => 26, 'blocks' => array(108)),
            6 => array('data' => 136, 'ec' => 18, 'blocks' => array(68, 68)),
            7 => array('data' => 156, 'ec' => 20, 'blocks' => array(78, 78)),
            8 => array('data' => 194, 'ec' => 24, 'blocks' => array(97, 97)),
            9 => array('data' => 232, 'ec' => 30, 'blocks' => array(116, 116)),
            10 => array('data' => 274, 'ec' => 18, 'blocks' => array(68, 68, 69, 69)),
        );
        if (!isset($table[(int) $version])) {
            throw new RuntimeException('Unsupported QR version.');
        }
        return $table[(int) $version];
    }
}

if (!function_exists('epay_qr_gf_tables')) {
    function epay_qr_gf_tables()
    {
        static $tables = null;
        if ($tables !== null) {
            return $tables;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        $tables = array($exp, $log);
        return $tables;
    }
}

if (!function_exists('epay_qr_gf_mul')) {
    function epay_qr_gf_mul($a, $b)
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        list($exp, $log) = epay_qr_gf_tables();
        return $exp[$log[$a] + $log[$b]];
    }
}

if (!function_exists('epay_qr_generator')) {
    function epay_qr_generator($degree)
    {
        list($exp,) = epay_qr_gf_tables();
        $poly = array(1);
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= epay_qr_gf_mul($coef, $exp[$i]);
            }
            $poly = $next;
        }
        return $poly;
    }
}

if (!function_exists('epay_qr_rs_remainder')) {
    function epay_qr_rs_remainder(array $data, $ecCount)
    {
        $generator = epay_qr_generator($ecCount);
        $work = array_merge($data, array_fill(0, $ecCount, 0));
        $dataCount = count($data);
        for ($i = 0; $i < $dataCount; $i++) {
            $factor = $work[$i];
            if ($factor === 0) {
                continue;
            }
            for ($j = 0; $j < count($generator); $j++) {
                $work[$i + $j] ^= epay_qr_gf_mul($generator[$j], $factor);
            }
        }
        return array_slice($work, $dataCount);
    }
}

if (!function_exists('epay_qr_make_matrix')) {
    function epay_qr_make_matrix(array $codewords, $version, $mask)
    {
        $size = 17 + (4 * $version);
        $m = array();
        for ($y = 0; $y < $size; $y++) {
            $m[$y] = array_fill(0, $size, null);
        }

        epay_qr_place_finder($m, 0, 0);
        epay_qr_place_finder($m, $size - 7, 0);
        epay_qr_place_finder($m, 0, $size - 7);

        // Alignment patterns must be placed before timing patterns. On version 7+,
        // valid alignment centers can lie on row/column 6 and intentionally replace
        // timing modules around their 5x5 footprint.
        $positions = epay_qr_alignment_positions($version);
        foreach ($positions as $cy) {
            foreach ($positions as $cx) {
                if ($m[$cy][$cx] !== null) {
                    continue;
                }
                epay_qr_place_alignment($m, $cx, $cy);
            }
        }

        for ($i = 8; $i < $size - 8; $i++) {
            if ($m[6][$i] === null) {
                $m[6][$i] = ($i % 2) === 0;
            }
            if ($m[$i][6] === null) {
                $m[$i][6] = ($i % 2) === 0;
            }
        }

        epay_qr_place_format($m, $mask);
        if ($version >= 7) {
            epay_qr_place_version($m, $version);
        }

        $bits = array();
        foreach ($codewords as $byte) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = (($byte >> $i) & 1) !== 0 ? 1 : 0;
            }
        }

        $bitIndex = 0;
        $upward = true;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                $y = $upward ? ($size - 1 - $vert) : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if ($m[$y][$x] !== null) {
                        continue;
                    }
                    $bit = $bitIndex < count($bits) ? $bits[$bitIndex++] : 0;
                    if (epay_qr_mask($mask, $x, $y)) {
                        $bit ^= 1;
                    }
                    $m[$y][$x] = $bit === 1;
                }
            }
            $upward = !$upward;
        }

        return $m;
    }
}

if (!function_exists('epay_qr_place_finder')) {
    function epay_qr_place_finder(&$m, $x, $y)
    {
        $size = count($m);
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx < 0 || $yy < 0 || $xx >= $size || $yy >= $size) {
                    continue;
                }
                $inside = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                $dark = $inside && (
                    $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6
                    || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4)
                );
                $m[$yy][$xx] = $dark;
            }
        }
    }
}

if (!function_exists('epay_qr_place_alignment')) {
    function epay_qr_place_alignment(&$m, $cx, $cy)
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $m[$cy + $dy][$cx + $dx] = $dist !== 1;
            }
        }
    }
}

if (!function_exists('epay_qr_alignment_positions')) {
    function epay_qr_alignment_positions($version)
    {
        $table = array(
            1 => array(),
            2 => array(6, 18),
            3 => array(6, 22),
            4 => array(6, 26),
            5 => array(6, 30),
            6 => array(6, 34),
            7 => array(6, 22, 38),
            8 => array(6, 24, 42),
            9 => array(6, 26, 46),
            10 => array(6, 28, 50),
        );
        return $table[(int) $version];
    }
}

if (!function_exists('epay_qr_bch_remainder')) {
    function epay_qr_bch_remainder($value, $polynomial)
    {
        $degree = epay_qr_bit_length($polynomial) - 1;
        while (epay_qr_bit_length($value) - 1 >= $degree) {
            $value ^= $polynomial << ((epay_qr_bit_length($value) - 1) - $degree);
        }
        return $value;
    }
}

if (!function_exists('epay_qr_bit_length')) {
    function epay_qr_bit_length($value)
    {
        $length = 0;
        while ($value > 0) {
            $length++;
            $value >>= 1;
        }
        return $length;
    }
}

if (!function_exists('epay_qr_place_format')) {
    function epay_qr_place_format(&$m, $mask)
    {
        $size = count($m);
        // Error correction level L has format indicator 01.
        $data = (1 << 3) | ((int) $mask & 7);
        $bits = (($data << 10) | epay_qr_bch_remainder($data << 10, 0x537)) ^ 0x5412;
        $get = function ($i) use ($bits) {
            return (($bits >> $i) & 1) !== 0;
        };

        for ($i = 0; $i <= 5; $i++) {
            $m[$i][8] = $get($i);
        }
        $m[7][8] = $get(6);
        $m[8][8] = $get(7);
        $m[8][7] = $get(8);
        for ($i = 9; $i < 15; $i++) {
            $m[8][14 - $i] = $get($i);
        }

        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 1 - $i] = $get($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $m[$size - 15 + $i][8] = $get($i);
        }
        $m[$size - 8][8] = true;
    }
}

if (!function_exists('epay_qr_place_version')) {
    function epay_qr_place_version(&$m, $version)
    {
        $size = count($m);
        $bits = ($version << 12) | epay_qr_bch_remainder($version << 12, 0x1F25);
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) !== 0;
            $a = $size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $m[$b][$a] = $bit;
            $m[$a][$b] = $bit;
        }
    }
}

if (!function_exists('epay_qr_mask')) {
    function epay_qr_mask($mask, $x, $y)
    {
        switch ((int) $mask) {
            case 0: return (($x + $y) % 2) === 0;
            case 1: return ($y % 2) === 0;
            case 2: return ($x % 3) === 0;
            case 3: return (($x + $y) % 3) === 0;
            case 4: return ((intdiv($y, 2) + intdiv($x, 3)) % 2) === 0;
            case 5: return (($x * $y) % 2 + ($x * $y) % 3) === 0;
            case 6: return (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0;
            case 7: return (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0;
        }
        return false;
    }
}
