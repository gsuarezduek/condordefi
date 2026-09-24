<?php

/** "hace 5 min", "hace 3 h", o la fecha si pasaron más de 2 días. */
function time_ago(string $datetime): string
{
    $seconds = time() - strtotime($datetime);
    if ($seconds < 60) {
        return 'hace instantes';
    }
    if ($seconds < 3600) {
        return 'hace ' . floor($seconds / 60) . ' min';
    }
    if ($seconds < 86400) {
        return 'hace ' . floor($seconds / 3600) . ' h';
    }
    if ($seconds < 172800) {
        return 'hace 1 día';
    }
    return date('d/m/Y H:i', strtotime($datetime));
}

function fmt_usd($n): string
{
    return '$' . number_format((float) $n, 2);
}
