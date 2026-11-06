<?php

declare(strict_types=1);

// Complete the connection handshake, then stall the actual GET rather than SELECT or connect().
$server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);

if ($server === false) {
    throw new RuntimeException($message);
}
echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1)."\n";
flush();
while ($client = stream_socket_accept($server, 10)) {
    stream_set_timeout($client, 2);
    while (($line = fgets($client)) !== false) {
        if (! str_starts_with($line, '*')) {
            break;
        }
        $arguments = [];
        for ($i = 0; $i < (int) substr($line, 1); $i++) {
            $length = fgets($client);

            if ($length === false || ! str_starts_with($length, '$')) {
                break 2;
            }
            $remaining = (int) substr($length, 1) + 2;
            $argument = '';
            while ($remaining > 0 && ($bytes = fread($client, $remaining)) !== false && $bytes !== '') {
                $argument .= $bytes;
                $remaining -= strlen($bytes);
            }

            if ($remaining !== 0) {
                break 2;
            }
            $arguments[] = substr($argument, 0, -2);
        }

        if (strtoupper($arguments[0] ?? '') === 'GET') {
            echo json_encode(['command' => 'GET', 'key' => $arguments[1]], JSON_THROW_ON_ERROR)."\n";
            flush();
            usleep(500000);

            break;
        }
        fwrite($client, "+OK\r\n");
    }
    fclose($client);
}
